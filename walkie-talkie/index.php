<?php
declare(strict_types=1);

/**
 * Front controller (used when the project root is the web root,
 * e.g. XAMPP: http://localhost/walkie-talkie/).
 * When the document root points to /public (cPanel recommended), public/index.php is used instead.
 */
define('APP_ROOT', __DIR__);
define('APP_PUBLIC_PREFIX', '/public');

require APP_ROOT . '/app/bootstrap.php';
\App\Core\Kernel::run();
