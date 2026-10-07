<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
    ) {
    }

    /** Folder the app lives in (e.g. "/walkie-talkie"), or "" when served from the web root. */
    public static function detectBasePath(string $configured): string
    {
        if (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli') {
            return '';
        }
        $base = trim($configured);
        if ($base === '') {
            $base = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        }
        $base = '/' . trim($base, '/');
        return $base === '/' ? '' : $base;
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $uri = rawurldecode($uri);

        $base = (string) Config::get('base_path', '');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php'));
        }
        $path = '/' . trim($uri, '/');

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** Reads the raw body but refuses anything bigger than $max bytes. Returns null if too large. */
    public function body(int $max): ?string
    {
        $length = (int) ($this->server['CONTENT_LENGTH'] ?? 0);
        if ($length > $max) {
            return null;
        }
        $data = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        return strlen($data) > $max ? null : $data;
    }

    /** Basic CSRF defence for JSON endpoints: the Origin header (if sent) must match our host. */
    public function isSameOrigin(): bool
    {
        $origin = $this->header('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return true;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $host = (string) ($this->server['HTTP_HOST'] ?? '');
        $originFull = strtolower((string) $originHost . ($originPort ? ':' . $originPort : ''));
        return $originFull === strtolower($host) || strtolower((string) $originHost) === strtolower(explode(':', $host)[0]);
    }
}
