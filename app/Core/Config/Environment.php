<?php

declare(strict_types=1);

namespace App\Core\Config;

use RuntimeException;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new RuntimeException(sprintf('Invalid environment entry on line %d.', $number + 1));
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                throw new RuntimeException(sprintf('Invalid environment name on line %d.', $number + 1));
            }
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $decoded = json_decode($value, true);
                if (!is_string($decoded)) {
                    throw new RuntimeException(sprintf('Invalid quoted environment value on line %d.', $number + 1));
                }
                $value = $decoded;
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }
            if (getenv($name) === false && !array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv($name . '=' . $value);
            }
        }
    }
}
