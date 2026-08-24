<?php

declare(strict_types=1);

function env(string $name, mixed $default = null): mixed
{
    $value = $_ENV[$name] ?? getenv($name);
    return $value === false ? $default : $value;
}

function env_bool(string $name, bool $default): bool
{
    $value = env($name);
    if ($value === null) {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function env_int(string $name, int $default): int
{
    $value = filter_var(env($name), FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function env_list(string $name, array $default = []): array
{
    $value = env($name);
    return is_string($value) && trim($value) !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $value))))
        : $default;
}
