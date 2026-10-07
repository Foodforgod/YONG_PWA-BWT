/**
 * audio-level.js - VU meter + bars + waveform using AudioContext, AnalyserNode and <canvas>.
 * No external library. Every audio stream (our microphone, remote speakers) is routed into ONE analyser.
 * The analyser is NOT connected to the speakers, so it never creates echo.
 */
export class AudioLevelMeter {
  /**
   * @param {HTMLCanvasElement} canvas
   * @param {HTMLElement} vuEl  container with one <span> per VU segment
   * @param {(level:number)=>void} [onLevel]
   */
  constructor(canvas, vuEl, onLevel) {
    this.canvas = canvas;
    this.ctx2d = canvas.getContext('2d');
    this.segments = [...vuEl.children];
    this.onLevel = onLevel || (() => {});
    this.audioCtx = null;
    this.analyser = null;
    this.sources = new Map();
    this.level = 0;
    this.running = false;
  }

  ensureContext() {
    if (this.audioCtx) return true;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return false;
    this.audioCtx = new AC();
    this.analyser = this.audioCtx.createAnalyser();
    this.analyser.fftSize = 256;
    this.analyser.smoothingTimeConstant = 0.75;
    this.freq = new Uint8Array(this.analyser.frequencyBinCount);
    this.wave = new Uint8Array(this.analyser.fftSize);
    // Some browsers only process an analyser that reaches the destination: use a muted gain node.
    const mute = this.audioCtx.createGain();
    mute.gain.value = 0;
    this.analyser.connect(mute);
    mute.connect(this.audioCtx.destination);
    return true;
  }

  addStream(id, stream) {
    if (!stream || !this.ensureContext()) return;
    this.removeStream(id);
    this.resume();
    try {
      const src = this.audioCtx.createMediaStreamSource(stream);
      src.connect(this.analyser);
      this.sources.set(id, src);
    } catch (e) {
      /* stream without audio */
    }
  }

  removeStream(id) {
    const src = this.sources.get(id);
    if (src) {
      try { src.disconnect(); } catch (e) { /* ignore */ }
      this.sources.delete(id);
    }
  }

  /** Call from a user gesture (tap) so the browser lets the AudioContext run. */
  resume() {
    if (this.audioCtx && this.audioCtx.state === 'suspended') this.audioCtx.resume().catch(() => {});
  }

  start() {
    if (this.running) return;
    this.running = true;
    const loop = () => {
      if (!this.running) return;
      this.draw();
      this.raf = requestAnimationFrame(loop);
    };
    loop();
  }

  stop() {
    this.running = false;
    cancelAnimationFrame(this.raf);
  }

  draw() {
    const canvas = this.canvas;
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth;
    const h = canvas.clientHeight || 72;
    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
    }
    const g = this.ctx2d;
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, w, h);

    let rms = 0;
    if (this.analyser && this.audioCtx.state === 'running') {
      this.analyser.getByteFrequencyData(this.freq);
      this.analyser.getByteTimeDomainData(this.wave);
      let sum = 0;
      for (let i = 0; i < this.wave.length; i += 1) {
        const v = (this.wave[i] - 128) / 128;
        sum += v * v;
      }
      rms = Math.sqrt(sum / this.wave.length);
    }
    // Smooth: rises fast, falls slowly (like a real VU meter).
    const target = Math.min(1, rms * 3.2);
    this.level = target > this.level ? target : this.level * 0.9;

    // Bars (frequency)
    const bars = 32;
    const gap = 3;
    const bw = (w - gap * (bars - 1)) / bars;
    for (let i = 0; i < bars; i += 1) {
      const v = this.freq ? this.freq[Math.floor((i / bars) * 56)] / 255 : 0;
      const bh = Math.max(2, v * (h - 6));
      g.fillStyle = v > 0.75 ? '#ff4d4f' : v > 0.5 ? '#ffb020' : '#35d07f';
      g.fillRect(i * (bw + gap), h - bh, bw, bh);
    }
    // Waveform line
    if (this.wave) {
      g.beginPath();
      g.lineWidth = 1.5;
      g.strokeStyle = 'rgba(232,238,246,.85)';
      for (let i = 0; i < this.wave.length; i += 1) {
        const x = (i / (this.wave.length - 1)) * w;
        const y = (this.wave[i] / 255) * h;
        if (i === 0) g.moveTo(x, y); else g.lineTo(x, y);
      }
      g.stroke();
    }

    // VU segments
    const lit = Math.round(this.level * this.segments.length);
    this.segments.forEach((s, i) => s.classList.toggle('on', i < lit));
    this.onLevel(this.level);
  }
}
