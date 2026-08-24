<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\SearchConsole\Domain\SearchConsoleGateway;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;
use App\Modules\SearchConsole\Domain\TokenVault;
use InvalidArgumentException;
use Throwable;

final class SearchConsoleConnectionService
{
    public function __construct(
        private readonly Database $database, private readonly Authorization $authorization,
        private readonly AuditRecorder $audit, private readonly OAuthStateStore $states,
        private readonly SearchConsoleGateway $gateway, private readonly TokenVault $vault,
    ) {}

    public function begin(int $actorId, string $websitePublicId): string
    {
        $this->available($actorId);
        $this->website($actorId, $websitePublicId);
        $pending = $this->states->issue($actorId, $websitePublicId);
        return $this->gateway->authorizationUrl($pending['state'], $pending['challenge']);
    }

    public function complete(int $actorId, string $state, ?string $code, ?string $providerError = null): array
    {
        $this->available($actorId);
        $pending = $this->states->consume($state, $actorId);
        $website = $this->website($actorId, $pending['website']);
        if ($providerError !== null) throw new SearchConsoleUnavailable($providerError === 'access_denied' ? 'authorization_denied' : 'invalid_callback');
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9._~+\/-]{8,2048}$/', $code)) throw new SearchConsoleUnavailable('invalid_callback');
        $tokens = $this->normalizeTokens($this->gateway->exchange($code, $pending['verifier']));
        try { $properties = $this->gateway->properties($tokens['access_token']); }
        catch (Throwable $exception) { $this->bestEffortRevoke($tokens); throw $exception; }
        if ($properties === []) $this->bestEffortRevoke($tokens);
        $publicId = bin2hex(random_bytes(16)); $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($actorId, $website, $tokens, $properties, $publicId, $now): void {
            $database->execute('INSERT INTO search_console_connections (public_id,user_id,provider_subject,status,granted_scopes,credential_envelope,credential_key_version,token_expires_at,last_error_code,created_at,updated_at) VALUES (:public,:user,NULL,:status,:scopes,:envelope,:key_version,:expires,NULL,:created,:updated)', [
                'public' => $publicId, 'user' => $actorId, 'status' => $properties === [] ? 'no_properties' : 'property_selection',
                'scopes' => json_encode($tokens['scopes'], JSON_THROW_ON_ERROR), 'envelope' => $properties === [] ? null : $this->vault->seal($tokens), 'key_version' => $properties === [] ? null : $this->vault->keyVersion(),
                'expires' => $properties === [] ? null : gmdate('Y-m-d H:i:s', $tokens['expires_at']), 'created' => $now, 'updated' => $now,
            ]);
            $connectionId = (int) $database->fetchOne('SELECT id FROM search_console_connections WHERE public_id = :public', ['public' => $publicId])['id'];
            $database->execute('INSERT INTO search_console_connection_contexts (connection_id, website_id, created_at) VALUES (:connection,:website,:created)', ['connection' => $connectionId, 'website' => $website['id'], 'created' => $now]);
            foreach ($properties as $property) {
                $uri = $property['uri'] ?? null; $type = $property['type'] ?? null; $permission = $property['permission'] ?? null;
                if (!is_string($uri) || $uri === '' || strlen($uri) > 2048 || !in_array($type, ['domain', 'url_prefix'], true) || !is_string($permission) || !preg_match('/^[A-Za-z]+$/', $permission)) continue;
                $database->execute('INSERT INTO search_console_properties (public_id,connection_id,website_id,property_uri,property_type,permission_level,selected,created_at,updated_at) VALUES (:public,:connection,NULL,:uri,:type,:permission,0,:created,:updated)', ['public' => bin2hex(random_bytes(16)), 'connection' => $connectionId, 'uri' => $uri, 'type' => $type, 'permission' => $permission, 'created' => $now, 'updated' => $now]);
            }
            $this->audit->record($actorId, 'search_console.authorized', 'search_console_connection', $publicId, ['property_count' => count($properties), 'website' => $website['public_id']]);
        });
        return ['connection' => $publicId, 'website' => $website['public_id'], 'properties' => count($properties)];
    }

    public function properties(int $actorId, string $websitePublicId, string $connectionPublicId): array
    {
        $this->available($actorId); $this->website($actorId, $websitePublicId);
        $connection = $this->connectionForWebsite($actorId, $connectionPublicId, (int) $this->website($actorId, $websitePublicId)['id']);
        return $this->database->fetchAll('SELECT public_id, property_uri, property_type, permission_level, selected FROM search_console_properties WHERE connection_id = :connection ORDER BY property_type, property_uri', ['connection' => $connection['id']]);
    }

    public function select(int $actorId, string $websitePublicId, string $connectionPublicId, string $propertyPublicId): void
    {
        $this->available($actorId); $website = $this->website($actorId, $websitePublicId); $connection = $this->connectionForWebsite($actorId, $connectionPublicId, (int) $website['id']);
        if (!preg_match('/^[a-f0-9]{32}$/', $propertyPublicId)) throw new InvalidArgumentException('Search Console property not found.');
        $property = $this->database->fetchOne('SELECT id FROM search_console_properties WHERE public_id = :public AND connection_id = :connection', ['public' => $propertyPublicId, 'connection' => $connection['id']]);
        if ($property === null) throw new InvalidArgumentException('Search Console property not found.');
        $this->database->transaction(function (Database $database) use ($actorId, $website, $connection, $property, $propertyPublicId): void {
            $database->execute('UPDATE search_console_properties SET website_id = NULL, selected = 0, updated_at = :updated WHERE website_id = :website', ['updated' => gmdate('Y-m-d H:i:s'), 'website' => $website['id']]);
            $database->execute('UPDATE search_console_properties SET website_id = :website, selected = 1, updated_at = :updated WHERE id = :property AND connection_id = :connection', ['website' => $website['id'], 'updated' => gmdate('Y-m-d H:i:s'), 'property' => $property['id'], 'connection' => $connection['id']]);
            $database->execute("UPDATE search_console_connections SET status = 'connected', updated_at = :updated WHERE id = :connection", ['updated' => gmdate('Y-m-d H:i:s'), 'connection' => $connection['id']]);
            $this->audit->record($actorId, 'search_console.property_selected', 'search_console_property', $propertyPublicId, ['website' => $website['public_id']]);
        });
    }

    public function websiteStatus(int $actorId, string $websitePublicId): array
    {
        $this->available($actorId); $website = $this->website($actorId, $websitePublicId);
        $row = $this->database->fetchOne('SELECT c.public_id AS connection_public_id, c.status, p.public_id AS property_public_id, p.property_uri, p.property_type, p.permission_level FROM search_console_properties p JOIN search_console_connections c ON c.id = p.connection_id WHERE p.website_id = :website AND p.selected = 1 AND c.user_id = :user LIMIT 1', ['website' => $website['id'], 'user' => $actorId]);
        return $row ?? ['status' => 'not_connected'];
    }

    public function disconnect(int $actorId, string $websitePublicId): void
    {
        $this->available($actorId); $website = $this->website($actorId, $websitePublicId);
        $connection = $this->database->fetchOne('SELECT c.* FROM search_console_connections c JOIN search_console_properties p ON p.connection_id = c.id WHERE p.website_id = :website AND p.selected = 1 AND c.user_id = :user LIMIT 1', ['website' => $website['id'], 'user' => $actorId]);
        if ($connection === null) throw new InvalidArgumentException('Search Console is not connected.');
        $revocationFailed = false;
        try {
            $tokens = $this->vault->open((string) $connection['credential_envelope']);
            $token = $tokens['refresh_token'] ?? $tokens['access_token'] ?? null;
            if (is_string($token) && $token !== '') $this->gateway->revoke($token);
        } catch (Throwable) { $revocationFailed = true; }
        $this->database->transaction(function (Database $database) use ($actorId, $website, $connection, $revocationFailed): void {
            $database->execute('UPDATE search_console_properties SET website_id = NULL, selected = 0, updated_at = :updated WHERE website_id = :website', ['updated' => gmdate('Y-m-d H:i:s'), 'website' => $website['id']]);
            $database->execute("UPDATE search_console_connections SET status = 'revoked', credential_envelope = NULL, credential_key_version = NULL, token_expires_at = NULL, last_error_code = :error, updated_at = :updated WHERE id = :connection", ['error' => $revocationFailed ? 'revocation_failed' : null, 'updated' => gmdate('Y-m-d H:i:s'), 'connection' => $connection['id']]);
            $this->audit->record($actorId, 'search_console.disconnected', 'search_console_connection', $connection['public_id'], ['website' => $website['public_id'], 'provider_revocation' => !$revocationFailed]);
        });
    }

    public function accessToken(int $actorId, string $connectionPublicId): string
    {
        $this->available($actorId); return $this->token($actorId, $connectionPublicId);
    }

    public function accessTokenForSync(int $actorId, string $connectionPublicId): string
    {
        $this->authorization->require($actorId, 'search_console.sync');
        $module = $this->database->fetchOne('SELECT enabled FROM modules WHERE module_key = :key', ['key' => SearchConsoleManager::KEY]);
        if (!(bool) ($module['enabled'] ?? false)) throw new SearchConsoleUnavailable('module_disabled');
        return $this->token($actorId, $connectionPublicId);
    }

    public function markRevokedForSync(int $actorId, string $connectionPublicId): void
    {
        $this->authorization->require($actorId, 'search_console.sync'); $connection = $this->connection($actorId, $connectionPublicId); $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($connection, $now): void {
            $database->execute('UPDATE search_console_properties SET website_id = NULL, selected = 0, updated_at = :updated WHERE connection_id = :connection', ['updated' => $now, 'connection' => $connection['id']]);
            $database->execute("UPDATE search_console_connections SET status = 'revoked', credential_envelope = NULL, credential_key_version = NULL, token_expires_at = NULL, last_error_code = 'authorization_revoked', updated_at = :updated WHERE id = :connection", ['updated' => $now, 'connection' => $connection['id']]);
        });
    }

    private function token(int $actorId, string $connectionPublicId): string
    {
        $connection = $this->connection($actorId, $connectionPublicId);
        if ($connection['status'] !== 'connected' || !is_string($connection['credential_envelope']) || $connection['credential_envelope'] === '') throw new SearchConsoleUnavailable('authorization_revoked');
        $tokens = $this->vault->open($connection['credential_envelope']);
        if (($tokens['expires_at'] ?? 0) <= time() + 60) {
            if (!is_string($tokens['refresh_token'] ?? null)) throw new SearchConsoleUnavailable('authorization_revoked');
            try { $refreshed = $this->normalizeTokens($this->gateway->refresh($tokens['refresh_token']), $tokens['refresh_token']); }
            catch (SearchConsoleUnavailable $exception) {
                if ($exception->getMessage() === 'authorization_revoked') $this->database->execute("UPDATE search_console_connections SET status = 'revoked', credential_envelope = NULL, credential_key_version = NULL, token_expires_at = NULL, last_error_code = 'authorization_revoked', updated_at = :updated WHERE id = :id", ['updated' => gmdate('Y-m-d H:i:s'), 'id' => $connection['id']]);
                throw $exception;
            }
            $this->database->execute('UPDATE search_console_connections SET credential_envelope = :envelope, credential_key_version = :version, token_expires_at = :expires, updated_at = :updated WHERE id = :id', ['envelope' => $this->vault->seal($refreshed), 'version' => $this->vault->keyVersion(), 'expires' => gmdate('Y-m-d H:i:s', $refreshed['expires_at']), 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $connection['id']]);
            $tokens = $refreshed;
        }
        return (string) $tokens['access_token'];
    }

    private function normalizeTokens(array $response, ?string $existingRefresh = null): array
    {
        $access = $response['access_token'] ?? null; $refresh = $response['refresh_token'] ?? $existingRefresh; $expires = filter_var($response['expires_in'] ?? null, FILTER_VALIDATE_INT);
        if (isset($response['token_type']) && (!is_string($response['token_type']) || strcasecmp($response['token_type'], 'Bearer') !== 0)) throw new SearchConsoleUnavailable('invalid_token_response');
        if (!is_string($access) || $access === '' || strlen($access) > 8192 || !is_string($refresh) || $refresh === '' || strlen($refresh) > 8192 || $expires === false || $expires < 60 || $expires > 86400) throw new SearchConsoleUnavailable('invalid_token_response');
        $scope = $response['scope'] ?? '';
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer', 'expires_at' => time() + $expires, 'scopes' => is_string($scope) ? array_values(array_filter(explode(' ', $scope))) : []];
    }

    private function bestEffortRevoke(array $tokens): void
    {
        $token = $tokens['refresh_token'] ?? $tokens['access_token'] ?? null;
        try { if (is_string($token) && $token !== '') $this->gateway->revoke($token); } catch (Throwable) {}
    }

    private function available(int $actorId): void
    {
        $this->authorization->require($actorId, 'search_console.connect');
        $module = $this->database->fetchOne('SELECT enabled FROM modules WHERE module_key = :key', ['key' => SearchConsoleManager::KEY]);
        if (!(bool) ($module['enabled'] ?? false)) throw new SearchConsoleUnavailable('module_disabled');
    }

    private function website(int $actorId, string $publicId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Website not found.');
        $website = $this->database->fetchOne("SELECT id, public_id FROM websites WHERE public_id = :public AND owner_user_id = :owner AND status <> 'archived'", ['public' => $publicId, 'owner' => $actorId]);
        if ($website === null) throw new InvalidArgumentException('Website not found.');
        return $website;
    }

    private function connection(int $actorId, string $publicId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Search Console connection not found.');
        $connection = $this->database->fetchOne('SELECT * FROM search_console_connections WHERE public_id = :public AND user_id = :user', ['public' => $publicId, 'user' => $actorId]);
        if ($connection === null) throw new InvalidArgumentException('Search Console connection not found.');
        return $connection;
    }

    private function connectionForWebsite(int $actorId, string $publicId, int $websiteId): array
    {
        $connection = $this->connection($actorId, $publicId);
        if ($this->database->fetchOne('SELECT connection_id FROM search_console_connection_contexts WHERE connection_id = :connection AND website_id = :website', ['connection' => $connection['id'], 'website' => $websiteId]) === null) throw new InvalidArgumentException('Search Console connection not found.');
        return $connection;
    }
}
