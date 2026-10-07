# Walkie Talkie – PWA Push-to-Talk Voice Communication

A browser-based digital walkie-talkie. Users type a **nickname** and a **channel name**, join the same channel and talk with a big **Push-to-Talk (PTT)** button. Only **one person can transmit at a time**. Voice travels directly between browsers with **WebRTC**; PHP is only used for the web pages and for *signaling* (introducing the browsers to each other).

Built for programming students: plain **PHP 8.3+**, **vanilla JavaScript**, Bootstrap 5 + Font Awesome from a CDN. **No database, no framework, no Node.js required.**

---

## Features

- Landing page with nickname + channel, input validation and sanitizing
- Channels created on the fly (same name = same room), 2–8 users per channel (configurable)
- Large circular **Push-to-Talk** button – mouse, touch, pointer events and the **Space** key
- **One speaker at a time** (floor control with file locking → no race conditions)
- "CHANNEL BUSY" / "Alex is currently speaking" feedback, live speaker banner
- Participant list with speaking / listening status and user counter
- Audio-only **WebRTC** (mesh), microphone stays **OFF** until you are allowed to talk
- Talk-time limit (default 30 s, configurable) with countdown
- Audio visualisation: bars + waveform + VU meter (`AudioContext`, `AnalyserNode`, `<canvas>`)
- Connection states: `CONNECTING`, `CONNECTED`, `DISCONNECTED`, `RECONNECTING` with exponential back-off
- Leave button + `navigator.sendBeacon()` cleanup when the tab is closed
- **PWA**: manifest, service worker (app shell cache), installable, offline page
- QR code on the join page (contains only the public URL)
- Security: signed short-lived tokens (HMAC-SHA256), CSRF protection, output escaping, rate limiting, payload limits, secure session cookies, secrets in `.env`
- Accessible: labels, ARIA pressed state, live regions, keyboard support, focus outlines, mobile-first layout

---

## Requirements

| Item | Needed |
|------|--------|
| PHP | 8.1+ (8.3+ recommended), extensions: `mbstring` (recommended), `json`, `session` |
| Web server | Apache with `mod_rewrite` (XAMPP / cPanel) – or PHP's built-in server for quick tests |
| Browser | Chrome, Edge, Firefox, Safari (recent versions) |
| HTTPS | **Required for microphone access** everywhere except `localhost` |

---

## Project structure

```text
walkie-talkie/
├── index.php                 Front controller (project root = web root, e.g. XAMPP)
├── .htaccess                 Rewrite rules + blocks access to private folders
├── .env / .env.example       Configuration (secret, limits)
├── config/
│   ├── app.php               Reads .env, defines ICE (STUN/TURN) servers
│   └── routes.php            URL → controller table
├── app/
│   ├── bootstrap.php         Autoloader, config, error handlers
│   ├── Core/                 Env, Config, Request, Response, Session, View, Router, Logger, Kernel
│   ├── Controllers/          Home (join), Channel, Signal (POST /signal), Pwa (sw.js + manifest)
│   ├── Middleware/           StartSession, CsrfMiddleware
│   ├── Models/               Guest (nickname rules), Channel (name rules)
│   ├── Services/             RoomService, SignalService, TokenService, RateLimiter
│   └── Helpers/helpers.php   e(), url(), asset(), csrf_field()
├── views/                    join.php, channel.php, layouts/main.php, errors/error.php
├── public/                   Everything the browser downloads directly
│   ├── index.php             Front controller when the document root is /public
│   ├── sw.js, manifest.webmanifest
│   └── assets/
│       ├── css/app.css
│       ├── js/               app.js, ptt.js, webrtc.js, signaling.js, audio-level.js, pwa.js, join.js
│       └── icons/
├── storage/                  rooms/ (JSON), logs/, ratelimit/   (must be writable, not public)
└── tests/run-tests.php       Command-line tests of the server logic
```

### How it works (short version)

1. **Join page** → `POST /join` stores nickname + channel in the PHP session.
2. **Channel page** → the server creates a *signed token* (peer id, nickname, channel, expiry) and hands it to JavaScript.
3. `signaling.js` calls `POST /signal` with `hello`, then keeps calling `poll` (about every 0.6 s). Messages: `hello`, `poll`, `ptt_request`, `ptt_release`, `signal`, `leave`.
4. The **new** user creates a WebRTC offer for every user already in the room; the others answer. The offer/answer/ICE data is relayed through `/signal`. After that, audio flows **directly** browser-to-browser.
5. **Floor control:** `ptt_request` runs inside an exclusive file lock on `storage/rooms/<channel>.json`. The first request wins, everybody else gets `busy`. The floor is released on button release, on timeout, or when the speaker disappears.
6. While not transmitting the microphone track is `enabled = false` (silence). It is only switched on after the server grants the floor.

---

## XAMPP installation (Windows)

1. Install XAMPP with PHP 8.3 or newer.
2. Copy the folder to `C:\xampp\htdocs\walkie-talkie`.
3. Copy `.env.example` to `.env` (the zip already contains a `.env` with a random secret). Set a long random `SIGNAL_SECRET`:
   ```text
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"
   ```
4. Open the XAMPP Control Panel → **Start Apache**. (`mod_rewrite` is enabled by default; `AllowOverride All` must be on for `htdocs`, which is also the default.)
5. Open <http://localhost/walkie-talkie/>.
6. Enter a nickname and channel, press **JOIN CHANNEL**.
7. Press and hold the big button and **Allow** the microphone when the browser asks.

**Test with two users on one computer:** open a normal window **and a private/incognito window** (they have separate sessions), join the same channel with different nicknames. Wear headphones, otherwise the microphone picks up the speakers.

**Quick test without Apache** (PHP built-in server, uses `public/` as web root):

```text
cd walkie-talkie
php -S localhost:8000 -t public public/index.php
```
Then open <http://localhost:8000/>.

### Run the automatic tests

```text
C:\xampp\php\php.exe tests\run-tests.php
```
It checks validation, tokens, floor control (busy / release / timeout), the channel limit, signaling relay and rate limiting using a temporary storage folder.

---

## Mobile testing

1. Put the phone and the computer on the **same Wi-Fi**.
2. Find the computer's LAN address (`ipconfig` → IPv4, e.g. `192.168.1.10`). Allow Apache through the Windows firewall (private network).
3. Open `http://192.168.1.10/walkie-talkie/` on the computer (so the QR code contains the LAN address), then scan the QR code with the phone – or type the address manually.
4. **HTTPS requirement:** browsers only allow microphone access on **HTTPS** or **localhost**. Over plain `http://192.168…` you can **listen** but the phone cannot **talk**. Options for testing:
   - **Chrome/Edge (desktop or Android):** open `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, add `http://192.168.1.10`, enable, relaunch. (Testing only!)
   - Enable HTTPS in XAMPP (a self-signed certificate is enough for tests; accept the warning on the phone).
   - Use a free tunnel such as Cloudflare Tunnel or ngrok to get a real `https://` address.
5. Tap **Allow** when the phone asks for microphone permission.
6. Keep the screen on (the app requests a screen wake-lock where supported). Mobile browsers pause web pages in the background, so a phone that is locked cannot receive audio.

---

## PWA installation

| Platform | Steps |
|----------|-------|
| Android Chrome | Open the site over HTTPS → tap **Install app** on the join page (or menu ⋮ → *Install app / Add to Home screen*) |
| iPhone Safari | Tap **Share** → **Add to Home Screen** |
| Desktop Chrome / Edge | Click the install icon at the right side of the address bar, or the **Install app** button on the join page |

The service worker only registers on **HTTPS or localhost**. It caches the app shell (CSS, JS, icons, manifest) and shows an offline page. It never caches `/signal`, the channel page or any voice data.

---

## cPanel deployment

1. **Upload** the project (zip upload + *Extract* in File Manager). Recommended layout:
   ```text
   /home/USER/walkie-talkie/          ← project (outside public_html)
   /home/USER/public_html/ → points to walkie-talkie/public   (see step 2)
   ```
2. **Document root:** in *Domains* (or *Subdomains*) set the domain/subdomain **document root to `walkie-talkie/public`**. This keeps `app/`, `config/`, `storage/` and `.env` unreachable from the web.
   *If you cannot change the document root*, upload everything into `public_html/walkie-talkie/` – the root `.htaccess` blocks `app/`, `config/`, `storage/`, `views/` and `.env`, and `index.php` in the root is used.
3. **Configure `.env`:** set `SIGNAL_SECRET` to a long random value, `APP_ENV=production`, `APP_DEBUG=false`, and optionally `APP_PUBLIC_URL=https://your-domain/` and the `TURN_*` values.
4. **Rewrite:** Apache `mod_rewrite` is enabled on practically all cPanel hosts; the `.htaccess` files are included. Select **PHP 8.3** in *MultiPHP Manager*.
5. **Permissions:** `storage/` and its sub-folders (`rooms`, `logs`, `ratelimit`) must be writable (`755`, or `775` if the web server runs under a different group).
6. **HTTPS:** enable **AutoSSL / Let's Encrypt** and (optionally) force HTTPS. Microphone access and the PWA need HTTPS.

> Voice never passes through your server, so shared hosting is fine. Each active user sends about one small PHP request per 0.6 s – a small channel works well; big deployments should use a WebSocket server.

---

## Configuration (`.env`)

| Key | Default | Meaning |
|-----|---------|---------|
| `SIGNAL_SECRET` | – | Long random secret used to sign tokens. **Required**, never commit it. (If left at the default, a random secret is generated into `storage/secret.key`.) |
| `SIGNAL_MAX_PEERS` | 8 | Max users per channel |
| `SIGNAL_FLOOR_TIMEOUT_MS` | 30000 | Max talk time per transmission |
| `SIGNAL_MAX_BODY` | 32768 | Max bytes per signaling request |
| `SIGNAL_MAX_DATA` | 12288 | Max bytes of one WebRTC offer/answer/ICE block |
| `SIGNAL_TOKEN_TTL` | 900 | Token lifetime (renewed automatically while connected) |
| `SIGNAL_PEER_TIMEOUT_MS` | 15000 | Silent users are removed after this time |
| `SIGNAL_RATE_LIMIT` / `SIGNAL_RATE_WINDOW` | 600 / 60 | Requests per user per window (seconds) |
| `APP_BASE_PATH` | auto | e.g. `/walkie-talkie` (normally auto-detected) |
| `APP_PUBLIC_URL` | auto | URL encoded in the QR code |
| `TURN_URL`, `TURN_USERNAME`, `TURN_CREDENTIAL` | empty | Optional TURN relay |

ICE servers (STUN/TURN) are defined **only** in `config/app.php`.

### About STUN and TURN

The project uses public **Google STUN servers**. STUN helps browsers find their public address, but it **does not guarantee a connection on every network**. Strict firewalls, some mobile carriers and some corporate/school networks need a **TURN relay** that forwards the audio. For production, run your own TURN server (for example *coturn*) or rent one, then set `TURN_URL=turn:turn.example.com:3478`, `TURN_USERNAME`, `TURN_CREDENTIAL` in `.env`.

> Note: TURN credentials are sent to the browser (that is how TURN works). Use a dedicated low-privilege account, ideally time-limited credentials.

### Mesh limit

Every user connects to every other user (a "mesh"). 2–8 users is comfortable; more users need an SFU media server, which is outside the scope of this student project.

---

## Test checklist (manual)

| # | Test | Expected |
|---|------|----------|
| 1 | One user joins | `CONNECTED`, `1 USER` |
| 2 | Second user joins | `2 USERS`, both see each other |
| 3 | A presses PTT | A: `TRANSMITTING`, B: `Alex is talking` / `LISTENING` |
| 4 | B presses PTT while A talks | `CHANNEL BUSY` |
| 5 | A releases | `Channel available`, speaker banner back to *Waiting for a speaker* |
| 6 | B presses PTT | B: `TRANSMITTING` |
| 7 | A leaves | A removed from B's list |
| 8 | Stop Apache briefly, start again | `DISCONNECTED` → `RECONNECTING` → `CONNECTED` |
| 9 | Deny microphone | "Microphone permission is required to talk." – page keeps working, you can still listen |
| 10 | 9th user joins (limit 8) | "This channel is currently full. Maximum 8 users are allowed." |

The server-side part of tests 1–7 and 10 plus the talk-time limit is automated in `tests/run-tests.php`.

---

## Troubleshooting

**Microphone unavailable / "needs HTTPS"** – Use `https://` or `localhost`. Check the lock icon → site settings → Microphone = Allow. Close other apps that use the microphone. On iPhone use Safari (or the installed PWA).

**Channel full** – Max users reached (`SIGNAL_MAX_PEERS`). Wait for someone to leave, or raise the limit. Users that closed the browser disappear automatically after ~15 s.

**WebRTC connection failed / I hear nothing** – (1) Tap **Tap to enable audio** if it appears (browser autoplay rule). (2) Check the small link icon next to a user in the list: orange = connecting, red = failed. (3) Strict networks need a TURN server (see above). (4) Make sure the speaker's `MICROPHONE: ON` shows while transmitting and the system volume is up.

**Signaling unavailable ("Unable to connect to the communication server")** – Apache is stopped, `mod_rewrite`/`.htaccess` is not active (`AllowOverride All`), or `storage/` is not writable. Look at `storage/logs/app-YYYY-MM-DD.log`. Set `APP_DEBUG=true` temporarily to see PHP errors on screen.

**"The communication session has expired"** – The token timed out (for example the computer slept). Return to the start page and join again.

**PWA not installing** – Needs HTTPS (or localhost), a valid manifest and the service worker. Check Chrome DevTools → *Application* → *Manifest* / *Service Workers*. After changing icons or `sw.js`, unregister the service worker and reload.

**Phone cannot connect** – Same Wi-Fi? Use the LAN IP, not `localhost`. Allow Apache in the Windows firewall. Guest/"client isolation" Wi-Fi networks block device-to-device traffic.

**Two tabs in the same browser** – They share one PHP session (same nickname/channel). Use a private window or another browser to simulate a second user.

**Everything shows a blank page / 500** – Check the PHP version (8.1+), file permissions and the log file in `storage/logs/`.

---

## Security notes

- Tokens: `base64url(payload).base64url(HMAC-SHA256(payload, SIGNAL_SECRET))`; signature, expiry, peer id, nickname and channel are validated on **every** request. The browser never learns the secret.
- CSRF token on `POST /join` and `POST /leave`; `POST /signal` is protected by the signed token plus an `Origin` check.
- All user text is escaped with `htmlspecialchars` in PHP and set with `textContent` in JavaScript.
- Rate limiting per IP and per user; request body limit 32 KB; WebRTC data block limit 12 KB.
- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS; strict session mode; session id regenerated on join.
- Content-Security-Policy limits scripts to this site, jsDelivr and cdnjs.
- `.htaccess` denies web access to `app/`, `config/`, `storage/`, `views/`, `tests/` and `.env`. **Never** upload a real `.env` to a public repository.

## License

Free to use for learning and school projects.
