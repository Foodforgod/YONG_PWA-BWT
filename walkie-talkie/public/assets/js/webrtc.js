/**
 * webrtc.js - audio-only WebRTC "mesh": every user has one RTCPeerConnection to every other user.
 *
 * Who creates the offer?  The user who JOINS creates offers to everybody already in the channel.
 * The users already inside just answer. This avoids both sides offering at the same time ("glare").
 *
 * Microphone trick: each connection is created with one audio transceiver (sendrecv) but WITHOUT a track.
 * When the microphone becomes available we use sender.replaceTrack(micTrack) - no new negotiation needed.
 * The mic track is kept DISABLED (silence) until the server grants the floor.
 */

export class MicError extends Error {
  /** @param {'denied'|'notfound'|'unsupported'|'insecure'|'error'} reason */
  constructor(reason) {
    super(reason);
    this.reason = reason;
  }
}

export class WebRTCManager {
  /**
   * @param {object} o
   * @param {object[]} o.iceServers
   * @param {(to:string, data:object)=>void} o.sendSignal
   * @param {HTMLElement} o.audioContainer
   * @param {(id:string, stream:MediaStream)=>void} [o.onRemoteStream]
   * @param {(id:string)=>void} [o.onRemoteEnded]
   * @param {(id:string, status:string)=>void} [o.onPeerStatus]
   * @param {(stream:MediaStream|null)=>void} [o.onLocalStream]
   * @param {()=>void} [o.onAutoplayBlocked]
   */
  constructor(o) {
    this.iceServers = o.iceServers;
    this.sendSignal = o.sendSignal;
    this.audioContainer = o.audioContainer;
    this.onRemoteStream = o.onRemoteStream || (() => {});
    this.onRemoteEnded = o.onRemoteEnded || (() => {});
    this.onPeerStatus = o.onPeerStatus || (() => {});
    this.onLocalStream = o.onLocalStream || (() => {});
    this.onAutoplayBlocked = o.onAutoplayBlocked || (() => {});

    /** @type {Map<string, object>} */
    this.peers = new Map();
    this.earlyIce = new Map(); // ICE that arrived before its offer
    this.queue = Promise.resolve(); // incoming signals are processed one by one
    this.micStream = null;
    this.micTrack = null;
  }

  /* ------------------------------------------------------------------ microphone */

  /** Ask for the microphone (only when the user wants to talk). */
  async ensureMic() {
    if (this.micTrack && this.micTrack.readyState === 'live') return this.micTrack;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      throw new MicError(window.isSecureContext ? 'unsupported' : 'insecure');
    }
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        video: false,
      });
    } catch (e) {
      if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError' || e.name === 'PermissionDeniedError')) throw new MicError('denied');
      if (e && (e.name === 'NotFoundError' || e.name === 'OverconstrainedError')) throw new MicError('notfound');
      throw new MicError('error');
    }
    this.micStream = stream;
    this.micTrack = stream.getAudioTracks()[0];
    this.micTrack.enabled = false; // microphone stays OFF until we may transmit
    this.micTrack.addEventListener('ended', () => {
      this.micStream = null;
      this.micTrack = null;
      this.onLocalStream(null);
    });
    for (const entry of this.peers.values()) this.attachLocalTrack(entry);
    this.onLocalStream(stream);
    return this.micTrack;
  }

  /** true = the mic track sends real audio, false = silence. */
  setTransmitting(on) {
    if (this.micTrack) this.micTrack.enabled = !!on;
  }

  /** Fully release the microphone (browser "recording" indicator goes away). */
  releaseMic() {
    if (this.micStream) this.micStream.getTracks().forEach((t) => t.stop());
    this.micStream = null;
    this.micTrack = null;
    for (const entry of this.peers.values()) {
      if (entry.transceiver) entry.transceiver.sender.replaceTrack(null).catch(() => {});
    }
    this.onLocalStream(null);
  }

  attachLocalTrack(entry) {
    if (entry.transceiver && this.micTrack) {
      entry.transceiver.sender.replaceTrack(this.micTrack).catch(() => {});
    }
  }

  /* ------------------------------------------------------------------ connections */

  createPeer(id, isOfferer) {
    const pc = new RTCPeerConnection({ iceServers: this.iceServers });
    const entry = { id, pc, isOfferer, pendingIce: [], remoteSet: false, retries: 0, audioEl: null, transceiver: null, closed: false };
    this.peers.set(id, entry);

    // Send our network candidates to the other side through the signaling server.
    pc.onicecandidate = (e) => {
      if (e.candidate) this.sendSignal(id, { kind: 'ice', candidate: e.candidate.toJSON() });
    };
    pc.ontrack = (e) => {
      const stream = e.streams && e.streams[0] ? e.streams[0] : new MediaStream([e.track]);
      this.attachRemote(entry, stream);
    };
    pc.onconnectionstatechange = () => {
      if (entry.closed) return;
      const s = pc.connectionState;
      if (s === 'connected') {
        entry.retries = 0;
        this.onPeerStatus(id, 'connected');
      } else if (s === 'connecting' || s === 'new') {
        this.onPeerStatus(id, 'connecting');
      } else if (s === 'disconnected') {
        this.onPeerStatus(id, 'connecting'); // often recovers by itself
      } else if (s === 'failed') {
        this.onPeerStatus(id, 'failed');
        // The side that created the offer tries again (max 3 times).
        if (entry.isOfferer && entry.retries < 3) {
          const retries = entry.retries + 1;
          setTimeout(() => {
            if (this.peers.get(id) !== entry || entry.closed) return;
            this.connectTo(id).then(() => {
              const fresh = this.peers.get(id);
              if (fresh) fresh.retries = retries;
            });
          }, 2000);
        }
      }
    };

    if (isOfferer) {
      entry.transceiver = pc.addTransceiver('audio', { direction: 'sendrecv' });
      this.attachLocalTrack(entry);
    }
    // ICE that arrived early (before the offer) is replayed after the remote description is set.
    const early = this.earlyIce.get(id);
    if (early) {
      entry.pendingIce.push(...early);
      this.earlyIce.delete(id);
    }
    return entry;
  }

  /** Called by the newcomer: create an offer for an existing user. */
  async connectTo(id) {
    this.closePeer(id);
    const entry = this.createPeer(id, true);
    this.onPeerStatus(id, 'connecting');
    const offer = await entry.pc.createOffer();
    await entry.pc.setLocalDescription(offer);
    this.sendSignal(id, { kind: 'offer', sdp: entry.pc.localDescription.sdp });
  }

  /** Incoming offer / answer / ICE from another user. Processed strictly in order. */
  handleSignal(from, data) {
    this.queue = this.queue.then(() => this.processSignal(from, data)).catch((e) => console.warn('signal error', e));
    return this.queue;
  }

  async processSignal(from, data) {
    if (!data || typeof data !== 'object') return;

    if (data.kind === 'offer') {
      this.closePeer(from);
      const entry = this.createPeer(from, false);
      const pc = entry.pc;
      await pc.setRemoteDescription({ type: 'offer', sdp: data.sdp });
      entry.remoteSet = true;
      // The transceiver created by the remote offer: allow sending AND receiving.
      const tr = pc.getTransceivers().find((t) => t.receiver && t.receiver.track && t.receiver.track.kind === 'audio');
      if (tr) {
        tr.direction = 'sendrecv';
        entry.transceiver = tr;
        this.attachLocalTrack(entry);
      }
      await this.flushIce(entry);
      const answer = await pc.createAnswer();
      await pc.setLocalDescription(answer);
      this.sendSignal(from, { kind: 'answer', sdp: pc.localDescription.sdp });
      this.onPeerStatus(from, 'connecting');
    } else if (data.kind === 'answer') {
      const entry = this.peers.get(from);
      if (!entry || entry.pc.signalingState !== 'have-local-offer') return;
      await entry.pc.setRemoteDescription({ type: 'answer', sdp: data.sdp });
      entry.remoteSet = true;
      await this.flushIce(entry);
    } else if (data.kind === 'ice') {
      const entry = this.peers.get(from);
      if (!entry) {
        const list = this.earlyIce.get(from) || [];
        list.push(data.candidate);
        this.earlyIce.set(from, list);
        return;
      }
      if (!entry.remoteSet) entry.pendingIce.push(data.candidate);
      else await entry.pc.addIceCandidate(data.candidate).catch(() => {});
    }
  }

  async flushIce(entry) {
    const list = entry.pendingIce.splice(0);
    for (const c of list) await entry.pc.addIceCandidate(c).catch(() => {});
  }

  /* ------------------------------------------------------------------ remote audio */

  attachRemote(entry, stream) {
    if (!entry.audioEl) {
      const el = document.createElement('audio');
      el.autoplay = true;
      el.setAttribute('playsinline', '');
      this.audioContainer.appendChild(el);
      entry.audioEl = el;
    }
    entry.audioEl.srcObject = stream;
    const p = entry.audioEl.play();
    if (p && p.catch) p.catch(() => this.onAutoplayBlocked());
    this.onRemoteStream(entry.id, stream);
  }

  /** Browsers only start audio after a tap/click: call this from a user gesture. */
  unlockAudio() {
    for (const entry of this.peers.values()) {
      if (entry.audioEl) entry.audioEl.play().catch(() => {});
    }
  }

  /* ------------------------------------------------------------------ cleanup */

  closePeer(id) {
    const entry = this.peers.get(id);
    this.earlyIce.delete(id);
    if (!entry) return;
    entry.closed = true;
    this.peers.delete(id);
    try {
      entry.pc.onicecandidate = null;
      entry.pc.ontrack = null;
      entry.pc.onconnectionstatechange = null;
      entry.pc.close();
    } catch (e) {
      /* ignore */
    }
    if (entry.audioEl) {
      entry.audioEl.srcObject = null;
      entry.audioEl.remove();
    }
    this.onRemoteEnded(id);
  }

  closeAll() {
    for (const id of [...this.peers.keys()]) this.closePeer(id);
  }

  connectedCount() {
    let n = 0;
    for (const e of this.peers.values()) if (e.pc.connectionState === 'connected') n += 1;
    return n;
  }
}
