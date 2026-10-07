<?php
declare(strict_types=1);

/**
 * Front controller when the document root is the /public folder
 * (recommended for production / cPanel, and for `php -S localhost:8000 -t public public/index.php`).
 */
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file) && basename($file) !== 'index.php') {
        return false; // let the built-in server serve static files
    }
}

define('APP_ROOT', dirname(__DIR__));
define('APP_PUBLIC_PREFIX', '');

require APP_ROOT . '/app/bootstrap.php';
\App\Core\Kernel::run();
