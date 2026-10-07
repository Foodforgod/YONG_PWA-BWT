/**
 * ptt.js - Push-to-Talk controller.
 *
 * Press:    check busy -> microphone permission -> ask the server for the floor -> mic ON.
 * Release:  mic OFF -> tell the server to release the floor.
 * Inputs:   mouse, touch, pointer events and the Space key.
 *
 * States: disabled | idle | requesting | transmitting | busy
 */
export class PushToTalk {
  /**
   * @param {object} o
   * @param {HTMLElement} o.button
   * @param {import('./signaling.js').SignalingClient} o.signaling
   * @param {import('./webrtc.js').WebRTCManager} o.webrtc
   * @param {string} o.selfId
   * @param {()=>({id:string,nick:string}|null)} o.getSpeaker  who is talking right now (from the server state)
   * @param {(state:string)=>void} o.onState
   * @param {(text:string, level:'info'|'warn'|'error')=>void} o.onMessage
   * @param {(seconds:number|null)=>void} o.onCountdown
   * @param {(mic:'off'|'on'|'blocked'|'unsupported'|'insecure')=>void} o.onMic
   */
  constructor(o) {
    Object.assign(this, o);
    this.state = 'disabled';
    this.enabled = false;
    this.held = false;
    this.pressId = 0;
    this.grantedAt = 0;
    this.deadline = 0;
    this.timer = null;
    this.busyTimer = null;
  }

  bind() {
    const b = this.button;

    b.addEventListener('pointerdown', (e) => {
      if (e.button !== undefined && e.button > 0) return;
      e.preventDefault();
      try { b.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      this.press();
    });
    const up = (e) => {
      e.preventDefault();
      this.release();
    };
    b.addEventListener('pointerup', up);
    b.addEventListener('pointercancel', up);
    b.addEventListener('lostpointercapture', () => this.release());
    b.addEventListener('contextmenu', (e) => e.preventDefault()); // long-press menu on phones

    // Keyboard: Space = push to talk (anywhere on the page, except while typing or on other buttons).
    document.addEventListener('keydown', (e) => {
      if (e.code !== 'Space' || e.repeat) return;
      if (!this.isKeyTarget(e.target)) return;
      e.preventDefault();
      this.press();
    });
    document.addEventListener('keyup', (e) => {
      if (e.code !== 'Space') return;
      if (!this.isKeyTarget(e.target) && !this.held) return;
      e.preventDefault();
      this.release();
    });

    // Never keep transmitting when the user switches away.
    window.addEventListener('blur', () => this.release());
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) this.release();
    });
  }

  isKeyTarget(t) {
    if (!t || t === document.body || t === this.button) return true;
    if (t.closest && t.closest('input, textarea, select, button, a, [contenteditable="true"]')) return false;
    return true;
  }

  setEnabled(on) {
    this.enabled = on;
    if (!on) {
      if (this.state === 'transmitting' || this.state === 'requesting') this.forceStop(null);
      this.setState('disabled');
    } else if (this.state === 'disabled') {
      this.setState('idle');
    }
  }

  setState(s) {
    this.state = s;
    this.onState(s);
  }

  async press() {
    if (this.held) return;
    this.held = true;
    if (this.state === 'transmitting') return;
    const id = ++this.pressId;

    if (!this.enabled) {
      this.onMessage('Connection lost. Reconnecting...', 'warn');
      return;
    }

    // Quick local check (the server still decides, in case two people press at the same moment).
    const speaker = this.getSpeaker();
    if (speaker && speaker.id !== this.selfId) {
      this.showBusy(speaker);
      return;
    }

    this.clearBusyTimer();
    this.setState('requesting');
    this.onMessage('', 'info');

    // 1) microphone permission
    try {
      await this.webrtc.ensureMic();
      this.onMic('off');
    } catch (err) {
      if (id !== this.pressId) return;
      const reason = err && err.reason;
      if (reason === 'denied') {
        this.onMic('blocked');
        this.onMessage('Microphone permission is required to talk.', 'error');
      } else if (reason === 'insecure') {
        this.onMic('insecure');
        this.onMessage('The microphone needs HTTPS (or localhost). You can still listen.', 'error');
      } else if (reason === 'notfound') {
        this.onMic('unsupported');
        this.onMessage('No microphone was found on this device.', 'error');
      } else {
        this.onMic('unsupported');
        this.onMessage('The microphone is not available. You can still listen.', 'error');
      }
      this.setState('idle');
      return;
    }
    if (id !== this.pressId) return; // a newer press owns the state
    if (!this.held) {
      this.setState('idle');
      this.onMessage('Microphone ready. Press and hold to talk.', 'info');
      return;
    }

    // 2) ask the server for the floor
    let res;
    try {
      res = await this.signaling.pttRequest();
    } catch (err) {
      if (id !== this.pressId) return;
      this.onMessage(err.code === 'network' ? 'Connection lost. Reconnecting...' : 'Unable to talk right now. Please try again.', 'warn');
      this.setState('idle');
      return;
    }
    if (id !== this.pressId) {
      if (res.granted) this.signaling.pttRelease().catch(() => {});
      return;
    }
    if (!res.granted) {
      this.showBusy(res.speaker || null);
      return;
    }
    if (!this.held) {
      // The user already let go while we were waiting.
      this.signaling.pttRelease().catch(() => {});
      this.setState('idle');
      return;
    }

    // 3) we own the floor: microphone ON
    this.webrtc.setTransmitting(true);
    this.grantedAt = Date.now();
    this.deadline = this.grantedAt + (res.remaining_ms || res.timeout_ms || 30000);
    this.setState('transmitting');
    this.onMic('on');
    if (navigator.vibrate) navigator.vibrate(30);
    const secs = Math.round((res.remaining_ms || res.timeout_ms) / 1000);
    this.onMessage(`You have ${secs} seconds to talk.`, 'info');
    this.startCountdown();
  }

  async release() {
    this.held = false;
    if (this.state === 'transmitting') {
      this.stopLocal();
      this.setState(this.enabled ? 'idle' : 'disabled');
      this.onMessage('Channel available', 'info');
      try { await this.signaling.pttRelease(); } catch (e) { /* the server also times out by itself */ }
    } else if (this.state === 'busy') {
      this.clearBusyTimer();
      this.setState(this.enabled ? 'idle' : 'disabled');
    }
    // state "requesting": press() notices !this.held after its await and cleans up.
  }

  /** Stop transmitting because the server (or a timer) ended our turn. */
  forceStop(message) {
    const wasTransmitting = this.state === 'transmitting';
    this.held = false;
    this.pressId += 1;
    this.stopLocal();
    if (wasTransmitting && this.enabled) this.signaling.pttRelease().catch(() => {});
    if (this.state !== 'disabled') this.setState(this.enabled ? 'idle' : 'disabled');
    if (message) this.onMessage(message, 'warn');
  }

  /** Called with every server state: detect that our floor ended without us noticing. */
  onServerState(speaker) {
    if (this.state !== 'transmitting') return;
    if (Date.now() - this.grantedAt < 2500) return; // ignore answers that were created before our grant
    if (!speaker || speaker.id !== this.selfId) this.forceStop('Your transmission ended.');
  }

  stopLocal() {
    this.webrtc.setTransmitting(false);
    this.onMic('off');
    clearInterval(this.timer);
    this.timer = null;
    this.onCountdown(null);
  }

  showBusy(speaker) {
    this.setState('busy');
    this.onMessage(speaker && speaker.nick ? `CHANNEL BUSY - ${speaker.nick} is currently speaking` : 'CHANNEL BUSY', 'warn');
    if (navigator.vibrate) navigator.vibrate([60, 40, 60]);
    this.clearBusyTimer();
    this.busyTimer = setTimeout(() => {
      if (this.state === 'busy') this.setState(this.enabled ? 'idle' : 'disabled');
    }, 1500);
  }

  clearBusyTimer() {
    clearTimeout(this.busyTimer);
    this.busyTimer = null;
  }

  startCountdown() {
    clearInterval(this.timer);
    const tick = () => {
      const left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000));
      this.onCountdown(left);
      if (left <= 0) this.forceStop('Talk time ended');
    };
    tick();
    this.timer = setInterval(tick, 250);
  }
}
