<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Central configuration. Values come from .env (see .env.example).
 * ICE (STUN/TURN) servers are defined here ONLY, so they are easy to change.
 */

$iceServers = [
    // Public STUN servers help browsers discover their public address (NAT traversal).
    ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']],
];

// Optional TURN relay (recommended for production, see README).
$turn = trim((string) Env::get('TURN_URL', ''));
if ($turn !== '') {
    $iceServers[] = [
        'urls' => array_values(array_filter(array_map('trim', explode(',', $turn)))),
        'username' => (string) Env::get('TURN_USERNAME', ''),
        'credential' => (string) Env::get('TURN_CREDENTIAL', ''),
    ];
}

return [
    'name'              => (string) Env::get('APP_NAME', 'Walkie Talkie'),
    'env'               => (string) Env::get('APP_ENV', 'local'),
    'debug'             => Env::bool('APP_DEBUG', false),
    'timezone'          => (string) Env::get('APP_TIMEZONE', 'UTC'),
    'base_path'         => (string) Env::get('APP_BASE_PATH', ''),
    'public_url'        => (string) Env::get('APP_PUBLIC_URL', ''),
    'storage_path'      => rtrim((string) Env::get('STORAGE_PATH', APP_ROOT . '/storage'), '/\\'),

    'secret'            => (string) Env::get('SIGNAL_SECRET', ''),
    'max_peers'         => max(2, min(16, Env::int('SIGNAL_MAX_PEERS', 8))),
    'floor_timeout_ms'  => max(3000, Env::int('SIGNAL_FLOOR_TIMEOUT_MS', 30000)),
    'max_body'          => max(2048, Env::int('SIGNAL_MAX_BODY', 32768)),
    'max_data'          => max(1024, Env::int('SIGNAL_MAX_DATA', 12288)),
    'token_ttl'         => max(60, Env::int('SIGNAL_TOKEN_TTL', 900)),
    'peer_timeout_ms'   => max(5000, Env::int('SIGNAL_PEER_TIMEOUT_MS', 15000)),
    'rate_limit'        => max(30, Env::int('SIGNAL_RATE_LIMIT', 600)),
    'rate_window'       => max(5, Env::int('SIGNAL_RATE_WINDOW', 60)),

    'ice_servers'       => $iceServers,
];
