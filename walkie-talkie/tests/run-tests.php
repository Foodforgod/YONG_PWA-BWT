<?php
declare(strict_types=1);

/**
 * Simple command-line tests for the server-side logic (no PHPUnit needed).
 * Run:   php tests/run-tests.php        (XAMPP: C:\xampp\php\php.exe tests\run-tests.php)
 * Uses a temporary storage folder - your real rooms are not touched.
 */

use App\Core\Config;
use App\Models\Channel;
use App\Models\Guest;
use App\Services\RateLimiter;
use App\Services\SignalException;
use App\Services\SignalService;
use App\Services\TokenService;

define('APP_ROOT', dirname(__DIR__));
$tmp = sys_get_temp_dir() . '/wt-tests-' . bin2hex(random_bytes(4));
mkdir($tmp . '/rooms', 0775, true);
mkdir($tmp . '/logs', 0775, true);
mkdir($tmp . '/ratelimit', 0775, true);
require APP_ROOT . '/app/bootstrap.php';

// Override settings for the tests (the real .env is not modified).
Config::set('storage_path', $tmp);
Config::set('secret', 'unit-test-secret-unit-test-secret');
Config::set('max_peers', 3);
Config::set('floor_timeout_ms', 3000);

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}
function expectError(callable $fn, string $code): bool
{
    try {
        $fn();
    } catch (SignalException $e) {
        return $e->errorCode === $code;
    }
    return false;
}

echo "Input validation\n";
check('nickname trimmed', Guest::validateNickname("  Alex   Lee ")[0] === 'Alex Lee');
check('empty nickname rejected', Guest::validateNickname('   ')[1] !== null);
check('25 char nickname rejected', Guest::validateNickname(str_repeat('a', 25))[1] !== null);
check('html in nickname rejected', Guest::validateNickname('<script>')[1] !== null);
check('channel normalized', Channel::normalize('  Team A!! ') === 'team-a');
check('empty channel rejected', Channel::validate('???')[1] !== null);
check('65 char channel rejected', Channel::validate(str_repeat('a', 65))[1] !== null);

echo "Tokens (HMAC-SHA256)\n";
$t = TokenService::issue('guest_aaaa1111-0001', 'Alex', 'room-1');
check('valid token verifies', TokenService::verify($t)['pid'] === 'guest_aaaa1111-0001');
[$p, $s] = explode('.', $t);
check('tampered payload rejected', expectError(fn () => TokenService::verify(strrev($p) . '.' . $s), 'invalid_token'));
check('tampered signature rejected', expectError(fn () => TokenService::verify($p . '.' . strrev($s)), 'invalid_token'));
Config::set('token_ttl', -10);
$expired = TokenService::issue('guest_aaaa1111-0002', 'Alex', 'room-1');
check('expired token rejected', expectError(fn () => TokenService::verify($expired), 'token_expired'));
Config::set('token_ttl', 900);

echo "Rooms and floor control\n";
$svc = new SignalService();
$mk = fn (string $id, string $nick) => ['pid' => $id, 'nick' => $nick, 'ch' => 'room-1'];
$A = $mk('guest_a0000001-0001', 'Alex');
$B = $mk('guest_b0000002-0001', 'Jason');
$C = $mk('guest_c0000003-0001', 'Mei');
$D = $mk('guest_d0000004-0001', 'Zed');

$r = $svc->handle('hello', $A, []);
check('Test 1: one user joins -> 1 USER', count($r['state']['peers']) === 1 && $r['rejoined'] === false);
$r = $svc->handle('hello', $B, []);
check('Test 2: second user joins -> 2 USERS', count($r['state']['peers']) === 2);

$r = $svc->handle('ptt_request', $A, []);
check('Test 3: A gets the floor', $r['granted'] === true);
$r = $svc->handle('poll', $B, ['since' => 0]);
check('Test 3: B sees A speaking', $r['state']['speaker']['nick'] === 'Alex');

$r = $svc->handle('ptt_request', $B, []);
check('Test 4: B is told CHANNEL BUSY', $r['granted'] === false && $r['reason'] === 'busy' && $r['speaker']['nick'] === 'Alex');

$svc->handle('ptt_release', $A, []);
$r = $svc->handle('poll', $B, ['since' => 0]);
check('Test 5: channel available after release', $r['state']['speaker'] === null);

$r = $svc->handle('ptt_request', $B, []);
check('Test 6: B now gets the floor', $r['granted'] === true);
$svc->handle('ptt_release', $B, []);

echo "Signaling relay\n";
$svc->handle('signal', $A, ['to' => $B['pid'], 'data' => ['kind' => 'offer', 'sdp' => 'v=0']]);
$r = $svc->handle('poll', $B, ['since' => 0]);
$sig = array_values(array_filter($r['events'], fn ($e) => $e['type'] === 'signal'));
check('offer delivered only to target', count($sig) === 1 && $sig[0]['from'] === $A['pid'] && $sig[0]['data']['kind'] === 'offer');
$r = $svc->handle('poll', $A, ['since' => 0]);
check('offer NOT delivered to sender', count(array_filter($r['events'], fn ($e) => $e['type'] === 'signal')) === 0);
check('bad signal kind rejected', expectError(fn () => $svc->handle('signal', $A, ['to' => $B['pid'], 'data' => ['kind' => 'evil']]), 'bad_request'));
Config::set('max_data', 1024);
check('oversized signal rejected', expectError(fn () => $svc->handle('signal', $A, ['to' => $B['pid'], 'data' => ['kind' => 'offer', 'sdp' => str_repeat('x', 2000)]]), 'payload_too_large'));
Config::set('max_data', 12288);

echo "Room limit\n";
$svc->handle('hello', $C, []);
check('Test 10: 4th user -> channel full', expectError(fn () => $svc->handle('hello', $D, []), 'room_full'));

echo "Talk time limit\n";
$svc->handle('ptt_request', $C, []);
sleep(4);
$r = $svc->handle('poll', $A, ['since' => 0]);
check('floor released after timeout', $r['state']['speaker'] === null);
$r = $svc->handle('poll', $C, ['since' => 0]);
check('speaker receives ptt_timeout event', count(array_filter($r['events'], fn ($e) => $e['type'] === 'ptt_timeout')) === 1);

echo "Leaving\n";
$svc->handle('leave', $B, []);
$r = $svc->handle('poll', $A, ['since' => 0]);
check('Test 7: user removed from participant list', count($r['state']['peers']) === 2);
check('left user gets not_joined', expectError(fn () => $svc->handle('poll', $B, ['since' => 0]), 'not_joined'));

echo "Rate limiting\n";
$ok = 0;
for ($i = 0; $i < 12; $i++) {
    if (RateLimiter::allow('test-key', 10, 60)) {
        $ok++;
    }
}
check('12 requests with limit 10 -> only 10 allowed', $ok === 10);

echo "\n$pass passed, $fail failed\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail > 0 ? 1 : 0);
