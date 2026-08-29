<?php

declare(strict_types=1);

namespace App\Core\Config;

use InvalidArgumentException;

final class Config
{
    public function __construct(private readonly array $items) {}

    public static function load(string $directory): self
    {
        require_once $directory . '/support.php';
        $items = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (basename($file) === 'support.php') {
                continue;
            }
            $value = require $file;
            if (!is_array($value)) {
                throw new InvalidArgumentException('Configuration files must return arrays.');
            }
            $items[pathinfo($file, PATHINFO_FILENAME)] = $value;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function requireString(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Configuration "%s" must be a non-empty string.', $key));
        }
        return $value;
    }
}
