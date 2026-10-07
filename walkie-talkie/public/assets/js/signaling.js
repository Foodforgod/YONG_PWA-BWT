/**
 * signaling.js - talks to the PHP endpoint POST /signal using HTTP polling.
 *
 * Flow:  hello  ->  poll, poll, poll ...   (+ ptt_request / ptt_release / signal / leave when needed)
 * If the server cannot be reached the client goes DISCONNECTED -> RECONNECTING
 * (exponential backoff, max 15 s) -> CONNECTED again.
 */

export const ConnState = Object.freeze({
  CONNECTING: 'connecting',
  CONNECTED: 'connected',
  DISCONNECTED: 'disconnected',
  RECONNECTING: 'reconnecting',
});

/** Errors that make retrying pointless. */
const FATAL_CODES = new Set(['invalid_token', 'token_expired', 'room_full', 'forbidden']);

export class SignalError extends Error {
  constructor(code, message, { fatal = false, status = 0, retryAfter = 0 } = {}) {
    super(message);
    this.code = code;
    this.fatal = fatal;
    this.status = status;
    this.retryAfter = retryAfter;
  }
}

export class SignalingClient {
  /**
   * @param {object} o
   * @param {string} o.url        signaling endpoint
   * @param {string} o.token      signed token from the server
   * @param {number} [o.pollMs]   polling interval
   * @param {(state:string)=>void} o.onStatus
   * @param {(res:object, first:boolean)=>void} o.onHello
   * @param {(res:object)=>void} o.onPoll
   * @param {(err:SignalError)=>void} o.onFatal
   */
  constructor({ url, token, pollMs = 600, onStatus, onHello, onPoll, onFatal }) {
    this.url = url;
    this.token = token;
    this.pollMs = pollMs;
    this.onStatus = onStatus;
    this.onHello = onHello;
    this.onPoll = onPoll;
    this.onFatal = onFatal;

    this.status = null;
    this.joined = false;
    this.helloCount = 0;
    this.since = 0;
    this.stopped = true;
    this.wake = null;
    // Signals (offer/answer/ICE) must arrive in order, so they are sent one after another.
    this.sendChain = Promise.resolve();
  }

  start() {
    this.stopped = false;
    this.setStatus(ConnState.CONNECTING);
    this.loop();
  }

  stop() {
    this.stopped = true;
    if (this.wake) this.wake();
  }

  setStatus(s) {
    if (this.status === s) return;
    this.status = s;
    if (this.onStatus) this.onStatus(s);
  }

  async loop() {
    let failures = 0;
    while (!this.stopped) {
      try {
        if (!this.joined) {
          const res = await this.send({ type: 'hello' });
          this.joined = true;
          this.since = res.seq;
          this.helloCount += 1;
          this.setStatus(ConnState.CONNECTED);
          if (this.onHello) this.onHello(res, this.helloCount === 1);
        } else {
          const res = await this.send({ type: 'poll', since: this.since });
          this.since = res.seq;
          this.setStatus(ConnState.CONNECTED);
          if (this.onPoll) this.onPoll(res);
        }
        failures = 0;
        await this.sleep(this.pollMs);
      } catch (err) {
        if (this.stopped) return;
        if (err.code === 'not_joined') {
          // The server forgot us (we were silent too long): join again right away.
          this.joined = false;
          continue;
        }
        if (err.fatal) {
          this.stopped = true;
          this.setStatus(ConnState.DISCONNECTED);
          if (this.onFatal) this.onFatal(err);
          return;
        }
        failures += 1;
        this.setStatus(ConnState.DISCONNECTED);
        const wait = err.retryAfter
          ? err.retryAfter * 1000
          : Math.min(1000 * 2 ** (failures - 1), 15000) + Math.random() * 400;
        await this.sleep(wait);
        if (this.stopped) return;
        this.setStatus(ConnState.RECONNECTING);
      }
    }
  }

  sleep(ms) {
    return new Promise((resolve) => {
      const done = () => {
        clearTimeout(timer);
        this.wake = null;
        resolve();
      };
      const timer = setTimeout(done, ms);
      this.wake = done;
    });
  }

  /** One request to the server. Throws SignalError on any problem. */
  async send(body) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 10000);
    let resp;
    try {
      resp = await fetch(this.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...body, token: this.token }),
        cache: 'no-store',
        credentials: 'same-origin',
        signal: ctrl.signal,
      });
    } catch (e) {
      throw new SignalError('network', 'Unable to connect to the communication server.');
    } finally {
      clearTimeout(timer);
    }

    let data = null;
    try {
      data = await resp.json();
    } catch (e) {
      /* not JSON */
    }
    if (!resp.ok || !data || data.ok !== true) {
      const code = (data && data.error) || (resp.status >= 500 ? 'server_error' : 'bad_response');
      throw new SignalError(code, (data && data.message) || 'Unable to connect to the communication server.', {
        fatal: FATAL_CODES.has(code),
        status: resp.status,
        retryAfter: Number(resp.headers.get('Retry-After')) || 0,
      });
    }
    if (data.token) this.token = data.token; // renewed token
    return data;
  }

  /** Ask for permission to talk. Resolves { granted, ... }. */
  pttRequest() {
    return this.send({ type: 'ptt_request' });
  }

  pttRelease() {
    return this.send({ type: 'ptt_release' });
  }

  /** Relay WebRTC data (offer / answer / ICE) to another peer, keeping the order. */
  sendSignal(to, data) {
    this.sendChain = this.sendChain
      .then(() => this.send({ type: 'signal', to, data }))
      .catch(() => {});
    return this.sendChain;
  }

  leave() {
    return this.send({ type: 'leave' }).catch(() => {});
  }

  /** Used while the page is closing (fetch may be cancelled, sendBeacon is not). */
  leaveBeacon() {
    try {
      const blob = new Blob([JSON.stringify({ type: 'leave', token: this.token })], { type: 'application/json' });
      navigator.sendBeacon(this.url, blob);
    } catch (e) {
      /* ignore */
    }
  }
}
