<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;

/** Escape text for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL of a page of the app, e.g. url('channel'). */
function url(string $path = ''): string
{
    return (string) Config::get('base_path', '') . '/' . ltrim($path, '/');
}

/** URL of a file inside public/assets, e.g. asset('css/app.css'). */
function asset(string $path): string
{
    return (string) Config::get('base_path', '') . (string) Config::get('public_prefix', '') . '/assets/' . ltrim($path, '/');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Session::csrfToken()) . '">';
}

/** Absolute public URL of the app (used for the QR code). */
function public_app_url(): string
{
    $configured = trim((string) Config::get('public_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/';
    }
    $scheme = Request::isHttps() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . url('');
}

/*
 * Fallbacks when the PHP "mbstring" extension is not enabled (it is enabled by default on XAMPP and most hosts).
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        return (int) preg_match_all('/./us', $s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $encoding = null): string
    {
        return strtolower($s);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s, ?string $encoding = null): string
    {
        return strtoupper($s);
    }
}
