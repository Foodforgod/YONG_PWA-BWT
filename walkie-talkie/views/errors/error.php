<main class="join-wrap">
    <section class="radio-card text-center">
        <div class="error-code"><?= (int) $code ?></div>
        <h1 class="h4"><?= e($title) ?></h1>
        <p class="text-secondary"><?= e($message) ?></p>
        <a class="btn btn-ptt-join mt-2" href="<?= e(url('')) ?>"><i class="fa-solid fa-house" aria-hidden="true"></i> Back to start</a>
    </section>
</main>
