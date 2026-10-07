<?php
declare(strict_types=1);

namespace App\Core;

/** Writes technical errors to storage/logs/ (never shown to users). */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $dir = rtrim((string) Config::get('storage_path', APP_ROOT . '/storage'), '/\\') . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $line = sprintf(
                "[%s] %s %s %s\n",
                date('Y-m-d H:i:s'),
                $level,
                $message,
                $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
            );
            @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging must never break the application.
        }
    }
}
