/*
 * Walkie Talkie service worker (basic).
 * - Caches the app shell: CSS, JavaScript, icons, manifest (stale-while-revalidate).
 * - NEVER caches /signal, /channel or any HTML page (they contain private, per-user data).
 * - Shows a small offline page when the network is down.
 * The assets URL prefix is passed in the registration URL: sw.js?assets=/walkie-talkie/public/assets/
 */
const params = new URL(self.location.href).searchParams;
const ASSETS = params.get('assets') || '/assets/';
const SCOPE = self.registration.scope; // e.g. https://host/walkie-talkie/
const CACHE = 'walkie-talkie-v1';
const ASSETS_PATH = new URL(ASSETS, self.location.origin).pathname;

const PRECACHE = [
  'css/app.css',
  'js/app.js', 'js/ptt.js', 'js/webrtc.js', 'js/signaling.js', 'js/audio-level.js', 'js/pwa.js', 'js/join.js',
  'icons/logo.svg', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/apple-touch-icon.png',
  'offline.html',
].map((p) => ASSETS + p).concat([SCOPE + 'manifest.webmanifest']);

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => Promise.all(PRECACHE.map((url) => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // CDN files: let the browser handle them

  const isShellFile = url.pathname.startsWith(ASSETS_PATH) || url.href === SCOPE + 'manifest.webmanifest';
  if (isShellFile) {
    event.respondWith(
      caches.open(CACHE).then(async (cache) => {
        const cached = await cache.match(req);
        const network = fetch(req).then((res) => {
          if (res && res.ok) cache.put(req, res.clone());
          return res;
        }).catch(() => cached);
        return cached || network;
      })
    );
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(ASSETS + 'offline.html'))
    );
  }
});
