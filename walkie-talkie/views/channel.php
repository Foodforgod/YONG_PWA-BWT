<?php
/** @var string $nickname @var string $channel @var string $config */
?>
<main id="walkie-app" class="radio-app" data-config="<?= e($config) ?>">

    <header class="radio-header">
        <div class="brand">
            <img src="<?= e(asset('icons/logo.svg')) ?>" width="36" height="36" alt="">
            <span class="brand-name">PULSELINK</span>
        </div>
        <div id="conn-status" class="status-pill status-connecting" role="status" aria-live="polite">
            <i class="fa-solid fa-wifi" aria-hidden="true"></i>
            <span id="conn-text">CONNECTING</span>
        </div>
    </header>

    <section class="channel-bar" aria-label="Channel information">
        <div class="channel-name" title="Channel"><i class="fa-solid fa-hashtag" aria-hidden="true"></i> <span><?= e(mb_strtoupper($channel)) ?></span></div>
        <div class="channel-meta">
            <span class="user-count"><i class="fa-solid fa-users" aria-hidden="true"></i> <span id="user-count-text">0 USERS</span></span>
            <span class="you"><i class="fa-solid fa-user" aria-hidden="true"></i> <?= e($nickname) ?></span>
        </div>
    </section>

    <section id="speaker-panel" class="speaker-panel is-idle" aria-live="polite" aria-atomic="true">
        <div id="speaker-live" class="live-badge d-none"><span class="live-dot"></span> LIVE</div>
        <div id="speaker-text" class="speaker-text">Waiting for a speaker</div>
    </section>

    <section class="ptt-section" aria-label="Push to talk">
        <button id="ptt-button" class="ptt-button" type="button" data-state="disabled"
                aria-pressed="false" aria-label="Push to talk. Hold to talk.">
            <span class="ptt-ring" aria-hidden="true"></span>
            <i id="ptt-icon" class="fa-solid fa-microphone ptt-icon" aria-hidden="true"></i>
            <span id="ptt-label" class="ptt-label">HOLD TO TALK</span>
        </button>
        <p class="ptt-hint">Press and hold the button, or hold the <kbd>Space</kbd> key.</p>
        <div id="talk-timer" class="talk-timer" aria-live="off"></div>
        <div id="ptt-message" class="ptt-message" role="status" aria-live="polite"></div>
    </section>

    <section class="meter-panel" aria-label="Audio level">
        <canvas id="audio-canvas" class="audio-canvas" height="72" aria-hidden="true"></canvas>
        <div id="vu-meter" class="vu-meter" aria-hidden="true">
            <?php for ($i = 0; $i < 16; $i++): ?><span></span><?php endfor; ?>
        </div>
        <div class="meter-foot">
            <span id="mic-status" class="mic-status" data-mic="off"><i class="fa-solid fa-microphone-slash" aria-hidden="true"></i> <span>MICROPHONE: OFF</span></span>
            <span class="vol"><i class="fa-solid fa-volume-high" aria-hidden="true"></i> <span id="rx-status">SILENT</span></span>
        </div>
    </section>

    <section class="participants" aria-labelledby="participants-title">
        <h2 id="participants-title" class="section-title">ON THIS CHANNEL</h2>
        <ul id="participant-list" class="participant-list"></ul>
        <div id="notice" class="notice" role="status" aria-live="polite"></div>
    </section>

    <footer class="radio-footer">
        <form id="leave-form" method="post" action="<?= e(url('leave')) ?>">
            <?= csrf_field() ?>
            <button id="leave-btn" type="submit" class="btn-leave">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Leave Channel
            </button>
        </form>
    </footer>

    <div id="remote-audio" hidden></div>

    <div id="audio-unlock" class="audio-unlock d-none">
        <button id="audio-unlock-btn" type="button" class="btn btn-warning btn-sm">
            <i class="fa-solid fa-volume-high" aria-hidden="true"></i> Tap to enable audio
        </button>
    </div>

    <div id="fatal-overlay" class="fatal-overlay d-none" role="alertdialog" aria-modal="true" aria-labelledby="fatal-title">
        <div class="radio-card text-center">
            <h2 id="fatal-title" class="h5">Connection problem</h2>
            <p id="fatal-text" class="text-secondary"></p>
            <a class="btn btn-ptt-join" href="<?= e(url('')) ?>">Back to start</a>
        </div>
    </div>
</main>
