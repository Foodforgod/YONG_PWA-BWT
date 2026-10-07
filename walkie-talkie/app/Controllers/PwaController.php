<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Serves sw.js and manifest.webmanifest from the app root URL
 * (needed when the project root - not /public - is the web root, e.g. XAMPP),
 * because a service worker can only control pages below its own URL path.
 */
final class PwaController
{
    public function serviceWorker(Request $request): void
    {
        $file = APP_ROOT . '/public/sw.js';
        if (!is_file($file)) {
            Response::error(404, 'Not found', 'Service worker not found.');
        }
        $base = (string) Config::get('base_path', '');
        Response::raw((string) file_get_contents($file), 'application/javascript; charset=utf-8', [
            'Cache-Control'          => 'no-cache',
            'Service-Worker-Allowed' => $base . '/',
        ]);
    }

    public function manifest(Request $request): void
    {
        $file = APP_ROOT . '/public/manifest.webmanifest';
        $manifest = json_decode((string) @file_get_contents($file), true);
        if (!is_array($manifest)) {
            Response::error(404, 'Not found', 'Manifest not found.');
        }
        // The static manifest uses paths relative to the web root. When the project root is the web root,
        // the assets live in /public/, so prefix the icon paths.
        $prefix = ltrim((string) Config::get('public_prefix', ''), '/');
        if ($prefix !== '' && isset($manifest['icons'])) {
            foreach ($manifest['icons'] as &$icon) {
                $icon['src'] = $prefix . '/' . ltrim((string) $icon['src'], '/');
            }
            unset($icon);
        }
        Response::raw((string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'application/manifest+json; charset=utf-8', [
            'Cache-Control' => 'no-cache',
        ]);
    }
}
