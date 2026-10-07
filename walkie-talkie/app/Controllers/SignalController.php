<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\SignalException;
use App\Services\SignalService;
use App\Services\TokenService;

/** POST /signal  -  the only endpoint used by the JavaScript signaling client. */
final class SignalController
{
    public function handle(Request $request): void
    {
        if (!$request->isSameOrigin()) {
            $this->fail('forbidden', 'Request not allowed.', 403);
        }

        // Payload limit (default 32 KB).
        $raw = $request->body((int) Config::get('max_body', 32768));
        if ($raw === null) {
            $this->fail('payload_too_large', 'The request is too large.', 413);
        }
        $msg = json_decode($raw, true);
        if (!is_array($msg)) {
            $this->fail('bad_request', 'Invalid request.', 400);
        }

        // Coarse limit per IP address (protects against floods before we even check tokens).
        if (!RateLimiter::allow('ip:' . $request->ip(), (int) Config::get('rate_limit', 600) * 5, (int) Config::get('rate_window', 60))) {
            $this->fail('rate_limited', 'Too many requests. Please slow down.', 429, ['Retry-After' => '5']);
        }

        try {
            $claims = TokenService::verify((string) ($msg['token'] ?? ''));

            // Limit per user session.
            if (!RateLimiter::allow('peer:' . $claims['pid'], (int) Config::get('rate_limit', 600), (int) Config::get('rate_window', 60))) {
                $this->fail('rate_limited', 'Too many requests. Please slow down.', 429, ['Retry-After' => '5']);
            }

            $result = (new SignalService())->handle((string) ($msg['type'] ?? ''), $claims, $msg);

            // Renew the token while the user stays connected.
            if (($msg['type'] ?? '') !== 'leave' && TokenService::remaining($claims) < 300) {
                $result['token'] = TokenService::issue($claims['pid'], $claims['nick'], $claims['ch']);
            }
            Response::json($result);
        } catch (SignalException $e) {
            $this->fail($e->errorCode, $e->getMessage(), $e->status);
        } catch (\Throwable $e) {
            Logger::error('Signal error: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            $this->fail('server_error', 'Unable to connect to the communication server.', 500);
        }
    }

    private function fail(string $code, string $message, int $status, array $headers = []): never
    {
        Response::json(['ok' => false, 'error' => $code, 'message' => $message], $status, $headers);
    }
}
