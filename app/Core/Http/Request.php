<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $scheme = 'http',
        public readonly string $remoteAddress = '0.0.0.0',
    ) {}

    public static function capture(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = self::normalizePath($path, (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), '/' . ltrim($path, '/'), $_GET, $_POST, array_change_key_case($headers, CASE_LOWER), $_COOKIE, $scheme, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    public static function normalizePath(string $path, string $scriptName = ''): string
    {
        $path = '/' . ltrim($path, '/');
        $script = '/' . ltrim(str_replace('\\', '/', $scriptName), '/');
        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/.');

        // DirectAdmin may expose an extracted release below the domain root.
        // Strip only the directory of the executing front controller.
        if ($directory !== '' && $directory !== '/' && ($path === $directory || str_starts_with($path, $directory . '/'))) {
            $path = substr($path, strlen($directory)) ?: '/';
        }

        // Apache DirectoryIndex/FastCGI combinations can retain index.php in
        // REQUEST_URI. It is an implementation detail, not an application route.
        foreach (['/public/index.php', '/index.php'] as $frontController) {
            if ($path === $frontController) return '/';
            if (str_starts_with($path, $frontController . '/')) {
                return '/' . ltrim(substr($path, strlen($frontController)), '/');
            }
        }

        return $path;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        return is_string($value) ? $value : null;
    }

    public function host(): string
    {
        return strtolower(explode(':', $this->header('host') ?? 'localhost', 2)[0]);
    }
}
