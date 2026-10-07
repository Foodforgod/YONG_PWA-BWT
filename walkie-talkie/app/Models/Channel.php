<?php
declare(strict_types=1);

namespace App\Models;

/** Channel (room) name rules. */
final class Channel
{
    public const MAX = 64;

    public static function normalize(string $raw): string
    {
        $n = mb_strtolower(trim($raw), 'UTF-8');
        $n = preg_replace('/\s+/u', '-', $n) ?? '';
        $n = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $n) ?? '';
        $n = preg_replace('/-{2,}/', '-', $n) ?? '';
        $n = trim($n, '-');
        return trim(mb_substr($n, 0, self::MAX), '-');
    }

    /** @return array{0:string,1:?string} [normalizedName, errorMessage|null] */
    public static function validate(string $raw): array
    {
        if (mb_strlen(trim($raw)) > self::MAX) {
            return ['', 'Channel name must be ' . self::MAX . ' characters or fewer.'];
        }
        $n = self::normalize($raw);
        if ($n === '') {
            return ['', 'Please enter a channel name (letters, numbers, - or _).'];
        }
        return [$n, null];
    }

    public static function isValid(string $n): bool
    {
        return $n !== '' && mb_strlen($n) <= self::MAX && (bool) preg_match('/^[\p{L}\p{N}_-]+$/u', $n);
    }

    /** Safe file name for the JSON room file (ASCII slug + short hash). */
    public static function fileName(string $n): string
    {
        $slug = substr(preg_replace('/[^a-z0-9_-]/', '', $n) ?? '', 0, 40);
        return ($slug !== '' ? $slug . '_' : '') . substr(sha1($n), 0, 10);
    }
}
