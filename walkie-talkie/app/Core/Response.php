<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: microphone=(self), camera=(), geolocation=()');
    }

    public static function json(array $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $path, int $status = 302): never
    {
        header('Location: ' . url($path), true, $status);
        exit;
    }

    public static function raw(string $body, string $contentType, array $headers = [], int $status = 200): never
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: ' . $contentType);
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $body;
        exit;
    }

    public static function error(int $status, string $title, string $message): never
    {
        View::render('errors/error', ['code' => $status, 'title' => $title, 'message' => $message, 'pageTitle' => $title], 'layouts/main', $status);
    }
}
