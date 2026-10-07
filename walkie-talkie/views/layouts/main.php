<?php
/** @var string $content */
$appName = (string) \App\Core\Config::get('name', 'Walkie Talkie');
$title = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' · ' . $appName : $appName;
$scope = url('');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title) ?></title>
    <meta name="description" content="Push-to-talk voice communication in your browser.">
    <meta name="theme-color" content="#0b1118">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="PulseLink">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('icons/logo.svg')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>"
      data-base="<?= e($scope === '/' ? '' : rtrim($scope, '/')) ?>"
      data-assets="<?= e(asset('')) ?>">
    <?= $content ?>

    <script type="module" src="<?= e(asset('js/pwa.js')) ?>"></script>
    <?php if (!empty($scripts)): foreach ($scripts as $src): ?>
        <?= $src ?>

    <?php endforeach; endif; ?>
</body>
</html>
