<?php
declare(strict_types=1);

use App\Controllers\ChannelController;
use App\Controllers\HomeController;
use App\Controllers\PwaController;
use App\Controllers\SignalController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\StartSession;

return [
    ['GET',  '/',                      [HomeController::class,    'index'],         [StartSession::class]],
    ['POST', '/join',                  [HomeController::class,    'join'],          [StartSession::class, CsrfMiddleware::class]],
    ['GET',  '/channel',               [ChannelController::class, 'show'],          [StartSession::class]],
    ['POST', '/leave',                 [ChannelController::class, 'leave'],         [StartSession::class, CsrfMiddleware::class]],
    // Signaling is called by JavaScript many times per second: NO session (avoids session file locking).
    ['POST', '/signal',                [SignalController::class,  'handle'],        []],
    ['GET',  '/sw.js',                 [PwaController::class,     'serviceWorker'], []],
    ['GET',  '/manifest.webmanifest',  [PwaController::class,     'manifest'],      []],
];
