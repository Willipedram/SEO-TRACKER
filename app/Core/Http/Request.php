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
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), '/' . ltrim($path, '/'), $_GET, $_POST, array_change_key_case($headers, CASE_LOWER), $_COOKIE, $scheme, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
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
