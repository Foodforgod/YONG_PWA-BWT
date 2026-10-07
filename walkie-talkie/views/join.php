<?php
/** @var string $error @var string $nickname @var string $channel @var string $csrf @var string $publicUrl @var int $maxPeers */
$isLocal = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])#i', $publicUrl);
?>
<main class="join-wrap">
    <section class="radio-card" aria-labelledby="app-title">
        <div class="text-center mb-3">
            <img class="join-logo" src="<?= e(asset('icons/logo.svg')) ?>" width="84" height="84" alt="">
            <h1 id="app-title" class="app-title">PULSELINK</h1>
            <p class="tagline">Push to Talk<br><span>Instant Voice Communication</span></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= nl2br(e((string) $error)) ?></div>
        <?php endif; ?>

        <form id="join-form" method="post" action="<?= e(url('join')) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="mb-3">
                <label for="nickname" class="form-label">Nickname</label>
                <input type="text" class="form-control form-control-lg radio-input" id="nickname" name="nickname"
                       maxlength="24" required autocomplete="nickname" autocapitalize="words"
                       placeholder="Enter your nickname" value="<?= e($nickname) ?>">
                <div class="form-text">Up to 24 characters. Example: Alex, Student01, Security Team</div>
            </div>

            <div class="mb-3">
                <label for="channel" class="form-label">Channel</label>
                <input type="text" class="form-control form-control-lg radio-input" id="channel" name="channel"
                       maxlength="64" required autocomplete="off" autocapitalize="none" spellcheck="false"
                       placeholder="Enter channel name" value="<?= e($channel) ?>">
                <div class="form-text">Example: security, team-a, room-101, 1234</div>
            </div>

            <div id="form-error" class="text-danger small mb-2" role="alert" aria-live="assertive"></div>

            <button type="submit" class="btn btn-ptt-join w-100">
                <i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i> JOIN CHANNEL
            </button>
        </form>

        <p class="hint mt-3 mb-0">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Enter the same channel name as your team members to communicate together.
            (Maximum <?= (int) $maxPeers ?> users per channel.)
        </p>

        <hr class="radio-hr">

        <div class="qr-block text-center">
            <h2 class="h6 text-uppercase">Open on your phone</h2>
            <div id="qr-box" class="qr-box" data-url="<?= e($publicUrl) ?>" aria-label="QR code for the application address"></div>
            <p class="small text-secondary mb-1">Scan this code to join</p>
            <p class="small text-break mb-0"><code><?= e($publicUrl) ?></code></p>
            <?php if ($isLocal): ?>
                <p class="small text-warning mt-2 mb-0">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    You opened the site as <strong>localhost</strong>. A phone cannot reach that address.
                    Open the site using your computer's LAN IP (for example <code>http://192.168.1.10<?= e(url('')) ?></code>) to get a working QR code.
                </p>
            <?php endif; ?>
        </div>

        <div class="text-center mt-3">
            <button type="button" id="install-btn" class="btn btn-outline-light btn-sm d-none">
                <i class="fa-solid fa-download" aria-hidden="true"></i> Install app
            </button>
            <p id="ios-hint" class="small text-secondary d-none mb-0">
                iPhone: tap <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Share, then “Add to Home Screen”.
            </p>
        </div>
    </section>
</main>
