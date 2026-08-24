<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self((string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
