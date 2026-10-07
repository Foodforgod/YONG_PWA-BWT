<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/** Very small fixed-window rate limiter that stores counters in storage/ratelimit/. */
final class RateLimiter
{
    /** Returns true while the caller is still inside the allowed limit. */
    public static function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $dir = rtrim((string) Config::get('storage_path'), '/\\') . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($dir . '/' . sha1($key) . '.json', 'c+');
        if (!$fh) {
            return true; // never block users because of a storage problem
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true);
        $now = time();
        if (!is_array($data) || ($now - (int) ($data['start'] ?? 0)) >= $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }
        $data['count']++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        if (random_int(1, 300) === 1) {
            self::cleanup($dir);
        }
        return $data['count'] <= $limit;
    }

    private static function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 600) {
                @unlink($file);
            }
        }
    }
}
