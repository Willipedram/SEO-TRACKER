<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Database\Database;
use App\Core\Logging\Logger;

final class Authenticator
{
    private readonly string $dummyHash;

    public function __construct(
        private readonly Database $database,
        private readonly PasswordHasher $hasher,
        private readonly SessionStore $session,
        private readonly LoginRateLimiter $limiter,
        private readonly Logger $logger,
        private readonly int $idleTimeout,
        private readonly int $absoluteTimeout,
        #[\SensitiveParameter] private readonly string $auditKey,
    ) {
        $this->dummyHash = $hasher->hash('not-a-real-user-password');
    }

    public function login(string $email, #[\SensitiveParameter] string $password, string $network): AuthenticationResult
    {
        $email = strtolower(trim($email));
        if ($this->limiter->blocked($email, $network)) {
            $this->logger->warning('Authentication throttled.', ['account_key' => hash_hmac('sha256', $email, $this->auditKey), 'network_key' => hash_hmac('sha256', $network, $this->auditKey)]);
            return new AuthenticationResult(false, 'Unable to sign in with those credentials. Wait and try again.');
        }
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $this->database->fetchOne('SELECT id, email, password_hash, disabled_at FROM users WHERE email = :email LIMIT 1', ['email' => $email])
            : null;
        $hash = is_string($user['password_hash'] ?? null) ? $user['password_hash'] : $this->dummyHash;
        $verified = $this->hasher->verify($password, $hash);
        if (!$verified || $user === null || $user['disabled_at'] !== null) {
            $this->limiter->failure($email, $network);
            $this->logger->warning('Authentication failed.', ['account_key' => hash_hmac('sha256', $email, $this->auditKey), 'network_key' => hash_hmac('sha256', $network, $this->auditKey), 'reason' => $user !== null && $user['disabled_at'] !== null ? 'disabled' : 'invalid']);
            return new AuthenticationResult(false, 'Unable to sign in with those credentials.');
        }
        if ($this->hasher->needsRehash($hash)) {
            $this->database->execute('UPDATE users SET password_hash = :hash, updated_at = :updated WHERE id = :id', ['hash' => $this->hasher->hash($password), 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $user['id']]);
        }
        $this->limiter->clear($email, $network);
        $this->session->regenerate();
        $now = time();
        $this->session->set('auth', ['user_id' => (int) $user['id'], 'authenticated_at' => $now, 'last_activity' => $now]);
        $this->logger->info('Authentication succeeded.', ['user_id' => (int) $user['id'], 'network_key' => hash_hmac('sha256', $network, $this->auditKey)]);
        return new AuthenticationResult(true, 'Signed in.');
    }

    public function user(): ?array
    {
        $auth = $this->session->get('auth');
        if (!is_array($auth) || !is_int($auth['user_id'] ?? null) || !is_int($auth['authenticated_at'] ?? null) || !is_int($auth['last_activity'] ?? null)) {
            $this->session->remove('auth');
            return null;
        }
        $now = time();
        if ($auth['last_activity'] < $now - $this->idleTimeout || $auth['authenticated_at'] < $now - $this->absoluteTimeout) {
            $this->session->remove('auth');
            return null;
        }
        $user = $this->database->fetchOne('SELECT id, name, email, disabled_at FROM users WHERE id = :id LIMIT 1', ['id' => $auth['user_id']]);
        if ($user === null || $user['disabled_at'] !== null) {
            $this->session->remove('auth');
            return null;
        }
        $auth['last_activity'] = $now;
        $this->session->set('auth', $auth);
        return $user;
    }

    public function logout(): void
    {
        $auth = $this->session->get('auth');
        $this->logger->info('Authentication session ended.', ['user_id' => is_array($auth) ? ($auth['user_id'] ?? null) : null]);
        $this->session->invalidate();
    }
}
