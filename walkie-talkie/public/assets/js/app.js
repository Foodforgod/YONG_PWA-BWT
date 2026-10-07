/**
 * app.js - the walkie-talkie screen. Connects all the other modules:
 *   signaling.js (server)  <->  webrtc.js (audio)  <->  ptt.js (button)  +  audio-level.js (meter)
 */
import { SignalingClient, ConnState } from './signaling.js';
import { WebRTCManager } from './webrtc.js';
import { PushToTalk } from './ptt.js';
import { AudioLevelMeter } from './audio-level.js';

const root = document.getElementById('walkie-app');
if (!root) throw new Error('Walkie-talkie root element not found');
const cfg = JSON.parse(root.dataset.config);
const $ = (id) => document.getElementById(id);

const el = {
  connStatus: $('conn-status'),
  connText: $('conn-text'),
  userCount: $('user-count-text'),
  speakerPanel: $('speaker-panel'),
  speakerLive: $('speaker-live'),
  speakerText: $('speaker-text'),
  pttButton: $('ptt-button'),
  pttLabel: $('ptt-label'),
  pttIcon: $('ptt-icon'),
  pttMessage: $('ptt-message'),
  timer: $('talk-timer'),
  micStatus: $('mic-status'),
  rxStatus: $('rx-status'),
  list: $('participant-list'),
  notice: $('notice'),
  leaveForm: $('leave-form'),
  leaveBtn: $('leave-btn'),
  unlock: $('audio-unlock'),
  unlockBtn: $('audio-unlock-btn'),
  fatal: $('fatal-overlay'),
  fatalText: $('fatal-text'),
  remoteAudio: $('remote-audio'),
};

/** Latest room state received from the server. */
let state = { peers: [], speaker: null };
const peerLinks = new Map(); // peerId -> 'connecting' | 'connected' | 'failed'
let leaving = false;
let noticeTimer = null;

/* ---------------------------------------------------------------- UI helpers */

const STATUS_TEXT = {
  [ConnState.CONNECTING]: 'CONNECTING',
  [ConnState.CONNECTED]: 'CONNECTED',
  [ConnState.DISCONNECTED]: 'DISCONNECTED',
  [ConnState.RECONNECTING]: 'RECONNECTING',
};

function setConnStatus(s) {
  el.connStatus.className = `status-pill status-${s}`;
  el.connText.textContent = STATUS_TEXT[s] || s.toUpperCase();
  const connected = s === ConnState.CONNECTED;
  ptt.setEnabled(connected);
  if (s === ConnState.DISCONNECTED || s === ConnState.RECONNECTING) {
    setMessage('Connection lost. Reconnecting...', 'warn');
  } else if (connected && el.pttMessage.dataset.sticky === 'conn') {
    setMessage('', 'info');
  }
}

function setMessage(text, level = 'info') {
  el.pttMessage.textContent = text;
  el.pttMessage.dataset.level = level;
  el.pttMessage.dataset.sticky = text.startsWith('Connection lost') ? 'conn' : '';
}

function showNotice(text) {
  el.notice.textContent = text;
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(() => { el.notice.textContent = ''; }, 5000);
}

const PTT_LABELS = {
  disabled: ['HOLD TO TALK', 'fa-microphone'],
  idle: ['HOLD TO TALK', 'fa-microphone'],
  requesting: ['PLEASE WAIT...', 'fa-circle-notch fa-spin'],
  transmitting: ['TRANSMITTING', 'fa-microphone-lines'],
  busy: ['CHANNEL BUSY', 'fa-ban'],
};

function renderPttState(s) {
  const [label, icon] = PTT_LABELS[s] || PTT_LABELS.idle;
  el.pttButton.dataset.state = s;
  el.pttLabel.textContent = label;
  el.pttIcon.className = `fa-solid ${icon} ptt-icon`;
  el.pttButton.setAttribute('aria-pressed', s === 'transmitting' ? 'true' : 'false');
  el.pttButton.setAttribute('aria-disabled', s === 'disabled' ? 'true' : 'false');
  renderSpeaker();
}

function renderMic(mic) {
  const map = {
    off: ['MICROPHONE: OFF', 'fa-microphone-slash'],
    on: ['MICROPHONE: ON', 'fa-microphone'],
    blocked: ['MICROPHONE: BLOCKED', 'fa-microphone-slash'],
    unsupported: ['MICROPHONE: NOT AVAILABLE', 'fa-microphone-slash'],
    insecure: ['MICROPHONE: NEEDS HTTPS', 'fa-lock'],
  };
  const [text, icon] = map[mic] || map.off;
  el.micStatus.dataset.mic = mic;
  el.micStatus.innerHTML = `<i class="fa-solid ${icon}" aria-hidden="true"></i> <span></span>`;
  el.micStatus.lastElementChild.textContent = text;
}

function renderSpeaker() {
  const sp = state.speaker;
  const me = sp && sp.id === cfg.peerId;
  el.speakerPanel.classList.toggle('is-live', !!sp);
  el.speakerPanel.classList.toggle('is-me', !!me);
  el.speakerPanel.classList.toggle('is-idle', !sp);
  el.speakerLive.classList.toggle('d-none', !sp);
  if (!sp) el.speakerText.textContent = 'Waiting for a speaker';
  else if (me) el.speakerText.textContent = 'You are transmitting';
  else el.speakerText.textContent = `${sp.nick} is talking`;
  el.rxStatus.textContent = sp && !me ? 'RECEIVING' : me ? 'SENDING' : 'SILENT';
}

function renderParticipants() {
  el.list.replaceChildren();
  for (const p of state.peers) {
    const li = document.createElement('li');
    const speaking = state.speaker && state.speaker.id === p.id;
    li.className = `participant${speaking ? ' speaking' : ''}`;

    const dot = document.createElement('span');
    dot.className = 'p-dot';
    dot.setAttribute('aria-hidden', 'true');

    const name = document.createElement('span');
    name.className = 'p-name';
    name.textContent = p.nick + (p.id === cfg.peerId ? ' (you)' : '');

    const link = document.createElement('i');
    const ls = peerLinks.get(p.id);
    if (p.id !== cfg.peerId && ls) {
      link.className = `fa-solid fa-link p-link link-${ls}`;
      link.title = ls === 'connected' ? 'Audio link ready' : ls === 'failed' ? 'Audio link failed' : 'Connecting audio link';
      link.setAttribute('aria-hidden', 'true');
    }

    const badge = document.createElement('span');
    badge.className = 'p-badge';
    badge.textContent = speaking ? 'SPEAKING' : 'LISTENING';

    li.append(dot, name, link, badge);
    el.list.appendChild(li);
  }
  const n = state.peers.length;
  el.userCount.textContent = `${n} USER${n === 1 ? '' : 'S'}`;
}

function applyState(s) {
  state = { peers: s.peers || [], speaker: s.speaker || null };
  renderParticipants();
  renderSpeaker();
  ptt.onServerState(state.speaker);
}

function showFatal(err) {
  let text = err.message || 'Unable to connect to the communication server.';
  if (err.code === 'token_expired') text = 'The communication session has expired.';
  if (err.code === 'room_full') text = 'This channel is currently full. Maximum ' + cfg.maxPeers + ' users are allowed.';
  el.fatalText.textContent = text;
  el.fatal.classList.remove('d-none');
  ptt.setEnabled(false);
  webrtc.releaseMic();
  webrtc.closeAll();
}

/* ---------------------------------------------------------------- modules */

const meter = new AudioLevelMeter($('audio-canvas'), $('vu-meter'));
meter.start();

const webrtc = new WebRTCManager({
  iceServers: cfg.iceServers,
  audioContainer: el.remoteAudio,
  sendSignal: (to, data) => signaling.sendSignal(to, data),
  onRemoteStream: (id, stream) => meter.addStream(id, stream),
  onRemoteEnded: (id) => { meter.removeStream(id); peerLinks.delete(id); },
  onPeerStatus: (id, status) => { peerLinks.set(id, status); renderParticipants(); },
  onLocalStream: (stream) => { if (stream) meter.addStream('self', stream); else meter.removeStream('self'); },
  onAutoplayBlocked: () => el.unlock.classList.remove('d-none'),
});

const signaling = new SignalingClient({
  url: cfg.signalUrl,
  token: cfg.token,
  onStatus: setConnStatus,
  onHello: (res, first) => {
    applyState(res.state);
    const others = res.state.peers.map((p) => p.id).filter((id) => id !== cfg.peerId);
    if (first || !res.rejoined) {
      // New page (or the server had forgotten us): we are the newcomer, so WE send the offers.
      webrtc.closeAll();
      peerLinks.clear();
      others.forEach((id) => webrtc.connectTo(id).catch((e) => console.warn('offer failed', e)));
    } else {
      // Short network glitch: keep existing connections, drop the ones whose user left.
      for (const id of [...webrtc.peers.keys()]) if (!others.includes(id)) webrtc.closePeer(id);
    }
    if (res.config && res.config.floor_timeout_ms) cfg.floorTimeoutMs = res.config.floor_timeout_ms;
  },
  onPoll: (res) => {
    applyState(res.state);
    for (const ev of res.events) handleEvent(ev);
  },
  onFatal: showFatal,
});

const ptt = new PushToTalk({
  button: el.pttButton,
  signaling,
  webrtc,
  selfId: cfg.peerId,
  getSpeaker: () => state.speaker,
  onState: renderPttState,
  onMessage: setMessage,
  onCountdown: (sec) => {
    el.timer.textContent = sec === null ? '' : `Time left: ${sec}s`;
    el.timer.classList.toggle('low', sec !== null && sec <= 5);
  },
  onMic: renderMic,
});

function handleEvent(ev) {
  switch (ev.type) {
    case 'signal':
      webrtc.handleSignal(ev.from, ev.data);
      break;
    case 'peer_joined':
      showNotice(`${ev.data.nick} joined the channel`);
      break;
    case 'peer_left':
      webrtc.closePeer(ev.from);
      showNotice(`${ev.data.nick} left the channel`);
      break;
    case 'ptt_timeout':
      ptt.forceStop('Talk time ended');
      break;
    default:
      break;
  }
}

/* ---------------------------------------------------------------- wiring */

ptt.bind();
renderPttState('disabled');
renderMic('off');
signaling.start();

// Browsers only play audio after the user touched the page once.
const unlock = () => {
  meter.resume();
  webrtc.unlockAudio();
  el.unlock.classList.add('d-none');
};
el.unlockBtn.addEventListener('click', unlock);
document.addEventListener('pointerdown', unlock);

// Leave: stop mic, close connections, tell the server, then return to the join page.
el.leaveForm.addEventListener('submit', async (e) => {
  if (leaving) return;
  e.preventDefault();
  leaving = true;
  el.leaveBtn.disabled = true;
  ptt.forceStop(null);
  webrtc.releaseMic();
  webrtc.closeAll();
  signaling.stop();
  await Promise.race([signaling.leave(), new Promise((r) => setTimeout(r, 1500))]);
  el.leaveForm.submit();
});

// Page closed / navigated away: notify the server with sendBeacon.
window.addEventListener('pagehide', () => {
  if (!leaving) signaling.leaveBeacon();
});
// Restored from the back/forward cache: our server session is gone, so start fresh.
window.addEventListener('pageshow', (e) => {
  if (e.persisted) location.reload();
});

// Keep the phone screen awake while the walkie-talkie is open (where supported).
let wakeLock = null;
async function requestWakeLock() {
  try {
    if ('wakeLock' in navigator && !document.hidden) wakeLock = await navigator.wakeLock.request('screen');
  } catch (err) {
    /* optional feature */
  }
}
document.addEventListener('visibilitychange', () => { if (!document.hidden) requestWakeLock(); });
requestWakeLock();
