<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Database\Database;

final class PasswordResetTokens
{
    public function __construct(private readonly Database $database, private readonly int $lifetimeSeconds) {}

    public function issue(int $userId): string
    {
        $selector = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $now = time();
        $this->database->execute('DELETE FROM password_reset_tokens WHERE user_id = :user OR expires_at < :now', ['user' => $userId, 'now' => gmdate('Y-m-d H:i:s', $now)]);
        $this->database->execute('INSERT INTO password_reset_tokens (selector, user_id, token_hash, expires_at, used_at, created_at) VALUES (:selector, :user, :hash, :expires, NULL, :created)', [
            'selector' => $selector, 'user' => $userId, 'hash' => hash('sha256', $secret), 'expires' => gmdate('Y-m-d H:i:s', $now + $this->lifetimeSeconds), 'created' => gmdate('Y-m-d H:i:s', $now),
        ]);
        return $selector . '.' . $secret;
    }

    public function consume(#[\SensitiveParameter] string $token): ?int
    {
        [$selector, $secret] = array_pad(explode('.', $token, 2), 2, '');
        if (!preg_match('/^[a-f0-9]{32}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return null;
        }
        return $this->database->transaction(function (Database $database) use ($selector, $secret): ?int {
            $record = $database->fetchOne('SELECT user_id, token_hash, expires_at, used_at FROM password_reset_tokens WHERE selector = :selector', ['selector' => $selector]);
            if ($record === null || $record['used_at'] !== null || strtotime((string) $record['expires_at']) < time() || !hash_equals((string) $record['token_hash'], hash('sha256', $secret))) {
                return null;
            }
            $updated = $database->execute('UPDATE password_reset_tokens SET used_at = :used WHERE selector = :selector AND used_at IS NULL', ['used' => gmdate('Y-m-d H:i:s'), 'selector' => $selector]);
            return $updated === 1 ? (int) $record['user_id'] : null;
        });
    }
}
