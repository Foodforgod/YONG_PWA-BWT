<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'layouts/main', int $status = 200): never
    {
        $content = self::capture($view, $data);
        $html = $layout === '' ? $content : self::capture($layout, $data + ['content' => $content]);

        http_response_code($status);
        Response::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "img-src 'self' data: blob:; media-src 'self' blob: mediastream:; connect-src 'self'; "
            . "worker-src 'self'; manifest-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'"
        );
        echo $html;
        exit;
    }

    private static function capture(string $view, array $data): string
    {
        $file = APP_ROOT . '/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $view");
        }
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        })($file, $data);
    }
}
