<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Auth\SessionStore;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;

final class OAuthStateStore
{
    private const SESSION_KEY = 'search_console_oauth';

    public function __construct(private readonly SessionStore $session, private readonly int $lifetime = 600) {}

    public function issue(int $userId, string $websitePublicId): array
    {
        $state = $this->base64url(random_bytes(32));
        $verifier = $this->base64url(random_bytes(64));
        $this->session->set(self::SESSION_KEY, [
            'state_hash' => hash('sha256', $state), 'verifier' => $verifier,
            'user_id' => $userId, 'website' => $websitePublicId, 'expires_at' => time() + $this->lifetime,
        ]);
        return ['state' => $state, 'challenge' => $this->base64url(hash('sha256', $verifier, true))];
    }

    public function consume(string $state, int $userId): array
    {
        $pending = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['state_hash'] ?? null) || !hash_equals($pending['state_hash'], hash('sha256', $state)) || ($pending['user_id'] ?? null) !== $userId) throw new SearchConsoleUnavailable('invalid_oauth_state');
        if (!is_int($pending['expires_at'] ?? null) || $pending['expires_at'] < time()) throw new SearchConsoleUnavailable('expired_oauth_state');
        if (!is_string($pending['verifier'] ?? null) || !is_string($pending['website'] ?? null)) throw new SearchConsoleUnavailable('invalid_oauth_state');
        return ['verifier' => $pending['verifier'], 'website' => $pending['website']];
    }

    private function base64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
