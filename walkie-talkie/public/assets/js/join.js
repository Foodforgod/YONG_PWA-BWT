/** join.js - small client-side checks for the join form + QR code. */
const form = document.getElementById('join-form');
const errorBox = document.getElementById('form-error');

if (form) {
  form.addEventListener('submit', (e) => {
    const nick = form.nickname.value.replace(/\s+/g, ' ').trim();
    const chan = form.channel.value.trim();
    let msg = '';
    if (!nick) msg = 'Please enter a nickname.';
    else if (nick.length > 24) msg = 'Nickname must be 24 characters or fewer.';
    else if (!chan) msg = 'Please enter a channel name.';
    else if (chan.length > 64) msg = 'Channel name must be 64 characters or fewer.';
    if (msg) {
      e.preventDefault();
      errorBox.textContent = msg;
      (nick && nick.length <= 24 ? form.channel : form.nickname).focus();
    }
  });
}

// QR code (contains ONLY the public application URL).
const qrBox = document.getElementById('qr-box');
if (qrBox && typeof window.qrcode === 'function') {
  try {
    const qr = window.qrcode(0, 'M');
    qr.addData(qrBox.dataset.url);
    qr.make();
    qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
  } catch (err) {
    qrBox.textContent = '';
  }
}
