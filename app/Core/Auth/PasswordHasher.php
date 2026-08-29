<?php

declare(strict_types=1);

namespace App\Core\Auth;

use RuntimeException;

final class PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algorithm);
    }
}
