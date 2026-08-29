<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Database\Database;

final class LoginRateLimiter
{
    public function __construct(
        private readonly Database $database,
        #[\SensitiveParameter] private readonly string $key,
        private readonly int $maximumAttempts,
        private readonly int $windowSeconds,
    ) {}

    public function blocked(string $email, string $network): bool
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->windowSeconds);
        $keys = $this->keys($email, $network);
        $account = $this->database->fetchOne('SELECT COUNT(*) AS attempts FROM auth_login_attempts WHERE attempted_at >= :cutoff AND account_key = :account', ['cutoff' => $cutoff, 'account' => $keys[0]]);
        $networkCount = $this->database->fetchOne('SELECT COUNT(*) AS attempts FROM auth_login_attempts WHERE attempted_at >= :cutoff AND network_key = :network', ['cutoff' => $cutoff, 'network' => $keys[1]]);
        return (int) ($account['attempts'] ?? 0) >= $this->maximumAttempts || (int) ($networkCount['attempts'] ?? 0) >= $this->maximumAttempts * 10;
    }

    public function failure(string $email, string $network): void
    {
        [$account, $networkKey] = $this->keys($email, $network);
        $this->database->execute('INSERT INTO auth_login_attempts (account_key, network_key, attempted_at) VALUES (:account, :network, :attempted)', ['account' => $account, 'network' => $networkKey, 'attempted' => gmdate('Y-m-d H:i:s')]);
        $this->database->execute('DELETE FROM auth_login_attempts WHERE attempted_at < :expired', ['expired' => gmdate('Y-m-d H:i:s', time() - ($this->windowSeconds * 2))]);
    }

    public function clear(string $email, string $network): void
    {
        [$account] = $this->keys($email, $network);
        $this->database->execute('DELETE FROM auth_login_attempts WHERE account_key = :account', ['account' => $account]);
    }

    private function keys(string $email, string $network): array
    {
        return [hash_hmac('sha256', strtolower(trim($email)), $this->key), hash_hmac('sha256', $network, $this->key)];
    }
}
