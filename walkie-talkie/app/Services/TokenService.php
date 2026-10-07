<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Channel;
use App\Models\Guest;

/**
 * Short-lived signed tokens (HMAC-SHA256).
 * token = base64url(payloadJSON) . "." . base64url(HMAC(payload))
 * The payload holds: peer id, nickname, channel and expiry time.
 * The secret never leaves the server.
 */
final class TokenService
{
    public static function issue(string $peerId, string $nickname, string $channel): string
    {
        $now = time();
        $payload = self::b64(json_encode([
            'v'    => 1,
            'pid'  => $peerId,
            'nick' => $nickname,
            'ch'   => $channel,
            'iat'  => $now,
            'exp'  => $now + (int) Config::get('token_ttl', 900),
        ], JSON_UNESCAPED_UNICODE));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, self::secret(), true));
    }

    /** @return array{pid:string,nick:string,ch:string,iat:int,exp:int} */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($token) > 2048) {
            throw new SignalException('invalid_token', 'The communication session is not valid.', 401);
        }
        [$payload, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $payload, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            throw new SignalException('invalid_token', 'The communication session is not valid.', 401);
        }
        $claims = json_decode((string) self::unb64($payload), true);
        if (!is_array($claims)
            || !isset($claims['pid'], $claims['nick'], $claims['ch'], $claims['exp'])
            || !is_string($claims['pid']) || !is_string($claims['nick']) || !is_string($claims['ch'])
        ) {
            throw new SignalException('invalid_token', 'The communication session is not valid.', 401);
        }
        if ((int) $claims['exp'] < time()) {
            throw new SignalException('token_expired', 'The communication session has expired.', 401);
        }
        // Do not trust the contents blindly: validate every field again.
        if (!Guest::isValidPeerId($claims['pid']) || !Channel::isValid($claims['ch']) || !Guest::isValidNickname($claims['nick'])) {
            throw new SignalException('invalid_token', 'The communication session is not valid.', 401);
        }
        return $claims;
    }

    public static function remaining(array $claims): int
    {
        return (int) $claims['exp'] - time();
    }

    private static function secret(): string
    {
        $secret = (string) Config::get('secret', '');
        if ($secret !== '' && $secret !== 'CHANGE_THIS_SECRET' && strlen($secret) >= 16) {
            return $secret;
        }
        // Fallback so the project still works if .env was not edited:
        // generate a random secret once and keep it in storage/secret.key (never in the source code).
        $file = rtrim((string) Config::get('storage_path'), '/\\') . '/secret.key';
        if (!is_file($file)) {
            @file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($file, 0600);
        }
        $value = trim((string) @file_get_contents($file));
        if (strlen($value) < 16) {
            throw new \RuntimeException('SIGNAL_SECRET is not configured and storage/ is not writable.');
        }
        return $value;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
