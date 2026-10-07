<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

if (!defined('APP_ROOT')) {
    http_response_code(500);
    exit('APP_ROOT is not defined.');
}
if (!defined('APP_PUBLIC_PREFIX')) {
    define('APP_PUBLIC_PREFIX', '');
}
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('PHP 8.1 or newer is required (PHP 8.3+ recommended).');
}

// Tiny PSR-4 style autoloader: App\Core\Router -> app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(APP_ROOT . '/.env');
Config::load(APP_ROOT . '/config/app.php');
Config::set('public_prefix', APP_PUBLIC_PREFIX);
Config::set('base_path', App\Core\Request::detectBasePath((string) Config::get('base_path', '')));

require APP_ROOT . '/app/Helpers/helpers.php';

date_default_timezone_set((string) Config::get('timezone', 'UTC'));

if (Config::get('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

// Convert PHP warnings to log entries; never show them to users.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    Logger::error("PHP error [$no] $str", ['file' => $file, 'line' => $line]);
    return true;
});

set_exception_handler(static function (\Throwable $e): void {
    Logger::error('Uncaught exception: ' . $e->getMessage(), [
        'class' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    $isJson = str_ends_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/signal');
    if ($isJson) {
        Response::json(['ok' => false, 'error' => 'server_error', 'message' => 'Unable to connect to the communication server.'], 500);
    }
    $msg = Config::get('debug') ? $e->getMessage() : 'Something went wrong on the server. Please try again later.';
    Response::error(500, 'Server error', $msg);
});
