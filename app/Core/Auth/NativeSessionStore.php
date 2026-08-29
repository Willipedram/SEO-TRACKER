<?php

declare(strict_types=1);

namespace App\Core\Auth;

use RuntimeException;

final class NativeSessionStore implements SessionStore
{
    public function get(string $key, mixed $default = null): mixed { return $_SESSION[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $_SESSION[$key] = $value; }
    public function remove(string $key): void { unset($_SESSION[$key]); }

    public function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session.');
        }
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $parameters['path'], 'domain' => $parameters['domain'], 'secure' => $parameters['secure'], 'httponly' => true, 'samesite' => $parameters['samesite'] ?? 'Lax']);
            session_destroy();
        }
    }
}
