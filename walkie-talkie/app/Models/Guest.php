<?php
declare(strict_types=1);

namespace App\Models;

/** Guest identity: nickname rules and ID generation (no accounts needed). */
final class Guest
{
    public const NICK_MAX = 24;

    public static function sanitizeNickname(string $raw): string
    {
        $n = preg_replace('/[\x00-\x1F\x7F]+/u', '', $raw) ?? '';
        $n = preg_replace('/\s+/u', ' ', $n) ?? '';
        return trim($n);
    }

    /** @return array{0:string,1:?string} [cleanNickname, errorMessage|null] */
    public static function validateNickname(string $raw): array
    {
        $n = self::sanitizeNickname($raw);
        if ($n === '') {
            return ['', 'Please enter a nickname.'];
        }
        if (mb_strlen($n) > self::NICK_MAX) {
            return [$n, 'Nickname must be ' . self::NICK_MAX . ' characters or fewer.'];
        }
        if (!preg_match('/^[\p{L}\p{N} _.\-\']+$/u', $n)) {
            return [$n, "Nickname may only contain letters, numbers, spaces and . _ - '"];
        }
        return [$n, null];
    }

    public static function isValidNickname(string $n): bool
    {
        return self::validateNickname($n)[1] === null;
    }

    public static function newGuestId(): string
    {
        return 'guest_' . bin2hex(random_bytes(4));
    }

    /** A new peer ID is created every time the channel page loads (so two tabs never clash). */
    public static function newPeerId(string $guestId): string
    {
        return $guestId . '-' . bin2hex(random_bytes(2));
    }

    public static function isValidPeerId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{6,40}$/', $id);
    }
}
