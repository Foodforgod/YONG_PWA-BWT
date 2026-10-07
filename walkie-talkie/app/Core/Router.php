<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @param array<int,array> $routes [method, path, [Controller::class, 'action'], [Middleware::class, ...]] */
    public function __construct(private array $routes)
    {
    }

    public function dispatch(Request $request): void
    {
        $pathKnown = false;
        foreach ($this->routes as $route) {
            [$method, $path, $handler] = $route;
            if ($path !== $request->path) {
                continue;
            }
            $pathKnown = true;
            $allowed = $method === $request->method || ($method === 'GET' && $request->method === 'HEAD');
            if (!$allowed) {
                continue;
            }
            foreach (($route[3] ?? []) as $middleware) {
                (new $middleware())->handle($request);
            }
            [$class, $action] = $handler;
            (new $class())->$action($request);
            return;
        }

        if ($request->path === '/signal') {
            Response::json(['ok' => false, 'error' => 'bad_request', 'message' => 'Method not allowed.'], 405);
        }
        if ($pathKnown) {
            Response::error(405, 'Method not allowed', 'This page cannot be opened this way.');
        }
        Response::error(404, 'Page not found', 'The page you are looking for does not exist.');
    }
}
