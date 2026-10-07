/** pwa.js - service worker registration and the "Install app" button. */
const base = document.body.dataset.base || '';
const assets = document.body.dataset.assets || '/assets/';

if ('serviceWorker' in navigator && window.isSecureContext) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(`${base}/sw.js?assets=${encodeURIComponent(assets)}`, { scope: `${base}/` })
      .catch((err) => console.warn('Service worker not registered:', err));
  });
}

// "Install app" button (Chrome / Edge / Android).
let deferredPrompt = null;
const installBtn = document.getElementById('install-btn');
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  if (installBtn) installBtn.classList.remove('d-none');
});
if (installBtn) {
  installBtn.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    await deferredPrompt.userChoice.catch(() => {});
    deferredPrompt = null;
    installBtn.classList.add('d-none');
  });
}
window.addEventListener('appinstalled', () => {
  if (installBtn) installBtn.classList.add('d-none');
});

// iPhone/iPad Safari has no install prompt: show a short instruction instead.
const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const iosHint = document.getElementById('ios-hint');
if (iosHint && isIos && !standalone) iosHint.classList.remove('d-none');
