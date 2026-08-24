<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use InvalidArgumentException;

final class SearchConsoleManager
{
    public const KEY = 'search_console';
    public const VERSION = '1.4.0';

    public function __construct(
        private readonly Database $database,
        private readonly Authorization $authorization,
        private readonly AuditRecorder $audit,
        private readonly array $oauth,
    ) {}

    public function status(int $actorId): array
    {
        $this->authorization->require($actorId, 'settings.manage');
        $row = $this->database->fetchOne('SELECT version, enabled, installed_at FROM modules WHERE module_key = :key', ['key' => self::KEY]);
        $enabled = (bool) ($row['enabled'] ?? false);
        $issues = $this->configurationIssues();
        return [
            'enabled' => $enabled,
            'version' => (string) ($row['version'] ?? self::VERSION),
            'status' => !$enabled ? 'disabled' : ($issues === [] ? 'ready' : 'misconfigured'),
            'issues' => $issues,
            'client_id_configured' => trim((string) ($this->oauth['client_id'] ?? '')) !== '',
            'client_secret_configured' => trim((string) ($this->oauth['client_secret'] ?? '')) !== '',
            'redirect_uri' => $this->safeRedirectUri(),
            'scopes' => array_values(array_filter((array) ($this->oauth['scopes'] ?? []), 'is_string')),
            'websites' => $this->database->fetchAll("SELECT public_id, site_name FROM websites WHERE owner_user_id = :owner AND status <> 'archived' ORDER BY site_name", ['owner' => $actorId]),
        ];
    }

    public function setEnabled(int $actorId, bool $enabled): void
    {
        $this->authorization->require($actorId, 'settings.manage');
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($enabled, $now, $actorId): void {
            $database->execute('UPDATE modules SET enabled = :enabled, version = :version WHERE module_key = :key', ['enabled' => $enabled ? 1 : 0, 'version' => self::VERSION, 'key' => self::KEY]);
            $this->audit->record($actorId, 'module.status_changed', 'module', self::KEY, ['enabled' => $enabled, 'version' => self::VERSION]);
        });
    }

    public function configurationIssues(): array
    {
        $issues = [];
        if (trim((string) ($this->oauth['client_id'] ?? '')) === '') $issues[] = 'missing_client_id';
        if (trim((string) ($this->oauth['client_secret'] ?? '')) === '') $issues[] = 'missing_client_secret';
        $key = base64_decode((string) ($this->oauth['encryption_key'] ?? ''), true);
        if (!is_string($key) || strlen($key) !== 32 || !extension_loaded('openssl')) $issues[] = 'invalid_encryption_key';
        $redirect = (string) ($this->oauth['redirect_uri'] ?? '');
        if ($redirect === '' || filter_var($redirect, FILTER_VALIDATE_URL) === false || parse_url($redirect, PHP_URL_SCHEME) !== 'https' || parse_url($redirect, PHP_URL_USER) !== null || parse_url($redirect, PHP_URL_FRAGMENT) !== null) $issues[] = 'invalid_redirect_uri';
        foreach ((array) ($this->oauth['scopes'] ?? []) as $scope) if (!is_string($scope) || !str_starts_with($scope, 'https://www.googleapis.com/auth/')) throw new InvalidArgumentException('Invalid Search Console scope configuration.');
        return array_values(array_unique($issues));
    }

    private function safeRedirectUri(): ?string
    {
        $uri = (string) ($this->oauth['redirect_uri'] ?? '');
        return filter_var($uri, FILTER_VALIDATE_URL) !== false && parse_url($uri, PHP_URL_SCHEME) === 'https' ? $uri : null;
    }
}
