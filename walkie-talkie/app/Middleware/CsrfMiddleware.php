<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Rejects state-changing requests that do not carry the session's CSRF token. */
final class CsrfMiddleware
{
    public function handle(Request $request): void
    {
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return;
        }
        if (!Session::verifyCsrf((string) $request->input('_csrf', ''))) {
            Response::error(419, 'Session expired', 'Your page expired. Please go back to the start page and try again.');
        }
    }
}
