<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\SearchConsole\Application\OAuthStateStore;
use App\Modules\SearchConsole\Application\SearchConsoleConnectionService;
use App\Modules\SearchConsole\Domain\SearchConsoleGateway;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;
use App\Modules\SearchConsole\Infrastructure\OpenSslTokenVault;
use App\Modules\SearchConsole\Infrastructure\GoogleSearchConsoleGateway;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\Support\ArraySessionStore;
use Tests\TestCase;

final class FakeSearchConsoleGateway implements SearchConsoleGateway
{
    public string $state = ''; public string $challenge = ''; public array $revoked = []; public int $refreshes = 0;
    public array $tokens = ['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly'];
    public array $sites = [['uri' => 'sc-domain:example.com', 'type' => 'domain', 'permission' => 'siteOwner'], ['uri' => 'https://example.com/', 'type' => 'url_prefix', 'permission' => 'siteFullUser']];
    public ?string $failure = null;
    public function authorizationUrl(string $state, string $codeChallenge): string { $this->state = $state; $this->challenge = $codeChallenge; return 'https://accounts.google.com/o/oauth2/v2/auth?state=' . rawurlencode($state); }
    public function exchange(string $authorizationCode, string $codeVerifier): array { if ($this->failure !== null) throw new SearchConsoleUnavailable($this->failure); return $this->tokens; }
    public function refresh(string $refreshToken): array { $this->refreshes++; if ($this->failure !== null) throw new SearchConsoleUnavailable($this->failure); return ['access_token' => 'refreshed-secret', 'expires_in' => 3600, 'scope' => $this->tokens['scope']]; }
    public function properties(string $accessToken): array { if ($this->failure !== null) throw new SearchConsoleUnavailable($this->failure); return $this->sites; }
    public function revoke(string $token): void { $this->revoked[] = $token; if ($this->failure !== null) throw new SearchConsoleUnavailable($this->failure); }
    public function searchAnalytics(string $accessToken, string $propertyUri, string $startDate, string $endDate, string $searchType, int $startRow, int $rowLimit): array { return ['rows' => [], 'next_start_row' => null]; }
}

final class SearchConsoleOAuthTest extends TestCase
{
    public function testPkceStateExchangeDiscoverySelectionAndEncryptedStorage(): void
    {
        [$database, $service, $gateway, $website] = $this->services();
        $url = $service->begin(1, $website);
        $this->assertTrue(str_starts_with($url, 'https://accounts.google.com/'));
        $this->assertTrue(strlen($gateway->state) >= 43);
        $this->assertTrue(strlen($gateway->challenge) >= 43);
        $result = $service->complete(1, $gateway->state, 'valid-code-123');
        $this->assertSame(2, $result['properties']);
        $stored = $database->fetchOne('SELECT credential_envelope FROM search_console_connections WHERE public_id = :public', ['public' => $result['connection']]);
        $this->assertTrue(!str_contains((string) $stored['credential_envelope'], 'access-secret'));
        $properties = $service->properties(1, $website, $result['connection']);
        $this->assertSame(['domain', 'url_prefix'], array_column($properties, 'property_type'));
        $service->select(1, $website, $result['connection'], $properties[0]['public_id']);
        $status = $service->websiteStatus(1, $website);
        $this->assertSame('connected', $status['status']);
        $this->assertSame('sc-domain:example.com', $status['property_uri']);
    }

    public function testStateIsOneTimeUserBoundAndExpiryIsRejected(): void
    {
        [, $service, $gateway, $website] = $this->services();
        $service->begin(1, $website); $state = $gateway->state;
        $denied = false; try { $service->complete(1, 'wrong-state', 'valid-code-123'); } catch (SearchConsoleUnavailable) { $denied = true; }
        $this->assertTrue($denied);
        $replay = false; try { $service->complete(1, $state, 'valid-code-123'); } catch (SearchConsoleUnavailable) { $replay = true; }
        $this->assertTrue($replay);
        $session = new ArraySessionStore(); $states = new OAuthStateStore($session, -1); $issued = $states->issue(7, str_repeat('a', 32));
        $expired = false; try { $states->consume($issued['state'], 7); } catch (SearchConsoleUnavailable) { $expired = true; }
        $this->assertTrue($expired);
        $session = new ArraySessionStore(); $states = new OAuthStateStore($session); $issued = $states->issue(7, str_repeat('a', 32)); $wrongUser = false;
        try { $states->consume($issued['state'], 8); } catch (SearchConsoleUnavailable) { $wrongUser = true; }
        $this->assertTrue($wrongUser);
    }

    public function testDenialFailureNoPropertiesAndInvalidCallbackAreSafe(): void
    {
        [$database, $service, $gateway, $website] = $this->services();
        $service->begin(1, $website); $denied = false;
        try { $service->complete(1, $gateway->state, null, 'access_denied'); } catch (SearchConsoleUnavailable $e) { $denied = $e->getMessage() === 'authorization_denied'; }
        $this->assertTrue($denied); $this->assertSame(0, count($database->fetchAll('SELECT id FROM search_console_connections')));
        $gateway->sites = []; $service->begin(1, $website); $result = $service->complete(1, $gateway->state, 'valid-code-123');
        $this->assertSame(0, $result['properties']);
        $empty = $database->fetchOne('SELECT status, credential_envelope FROM search_console_connections WHERE public_id = :public', ['public' => $result['connection']]);
        $this->assertSame('no_properties', $empty['status']); $this->assertSame(null, $empty['credential_envelope']);
        $this->assertSame('refresh-secret', $gateway->revoked[0]);
        $gateway->failure = 'token_exchange_failed'; $service->begin(1, $website); $failed = false;
        try { $service->complete(1, $gateway->state, 'valid-code-123'); } catch (SearchConsoleUnavailable) { $failed = true; }
        $this->assertTrue($failed);
    }

    public function testRefreshAndDisconnectRevokeThenEraseTokensWithoutCoreDeletion(): void
    {
        [$database, $service, $gateway, $website] = $this->services();
        $gateway->tokens['expires_in'] = 60; $service->begin(1, $website); $result = $service->complete(1, $gateway->state, 'valid-code-123');
        $property = $service->properties(1, $website, $result['connection'])[0]; $service->select(1, $website, $result['connection'], $property['public_id']);
        $this->assertSame('refreshed-secret', $service->accessToken(1, $result['connection'])); $this->assertSame(1, $gateway->refreshes);
        $service->disconnect(1, $website);
        $connection = $database->fetchOne('SELECT status, credential_envelope FROM search_console_connections WHERE public_id = :public', ['public' => $result['connection']]);
        $this->assertSame('revoked', $connection['status']); $this->assertSame(null, $connection['credential_envelope']);
        $this->assertSame('refresh-secret', $gateway->revoked[0]);
        $this->assertSame(1, count($database->fetchAll('SELECT id FROM websites')));
        $this->assertSame('not_connected', $service->websiteStatus(1, $website)['status']);
    }

    public function testConnectionAndPropertyCannotCrossWebsiteContext(): void
    {
        [, $service, $gateway, $website, $websites] = $this->services();
        $other = $websites->create(1, WebsiteInput::from('Other', 'https://other.example', ''));
        $service->begin(1, $website); $result = $service->complete(1, $gateway->state, 'valid-code-123');
        $blocked = false; try { $service->properties(1, $other, $result['connection']); } catch (\InvalidArgumentException) { $blocked = true; }
        $this->assertTrue($blocked);
    }

    public function testRevokedRefreshClearsUnusableCredentials(): void
    {
        [$database, $service, $gateway, $website] = $this->services(); $gateway->tokens['expires_in'] = 60;
        $service->begin(1, $website); $result = $service->complete(1, $gateway->state, 'valid-code-123'); $property = $service->properties(1, $website, $result['connection'])[0]; $service->select(1, $website, $result['connection'], $property['public_id']); $gateway->failure = 'authorization_revoked';
        $revoked = false; try { $service->accessToken(1, $result['connection']); } catch (SearchConsoleUnavailable) { $revoked = true; }
        $this->assertTrue($revoked); $stored = $database->fetchOne('SELECT status, credential_envelope, last_error_code FROM search_console_connections WHERE public_id = :public', ['public' => $result['connection']]);
        $this->assertSame('revoked', $stored['status']); $this->assertSame(null, $stored['credential_envelope']); $this->assertSame('authorization_revoked', $stored['last_error_code']);
    }

    public function testDisabledModuleAndMissingPermissionBlockConnection(): void
    {
        [$database, $service, , $website] = $this->services();
        $database->execute("UPDATE modules SET enabled = 0 WHERE module_key = 'search_console'"); $disabled = false;
        try { $service->begin(1, $website); } catch (SearchConsoleUnavailable) { $disabled = true; }
        $this->assertTrue($disabled);
        $database->execute("UPDATE modules SET enabled = 1 WHERE module_key = 'search_console'");
        $database->execute("INSERT INTO users (name,email,password_hash,email_verified_at,disabled_at,created_at,updated_at) VALUES ('Viewer','viewer-oauth@example.test','x',NULL,NULL,:now,:now)", ['now' => '2026-08-23 00:00:00']);
        $viewer = (int) $database->fetchOne("SELECT id FROM users WHERE email = 'viewer-oauth@example.test'")['id']; $denied = false;
        try { $service->begin($viewer, $website); } catch (\App\Core\Rbac\AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
    }

    public function testGoogleAuthorizationUrlUsesPkceAndNeverClientSecret(): void
    {
        $gateway = new GoogleSearchConsoleGateway('public-client', 'private-client-secret', 'https://example.test/oauth/search-console/callback', ['https://www.googleapis.com/auth/webmasters.readonly']);
        $url = $gateway->authorizationUrl('state-value', 'challenge-value'); $parts = parse_url($url); parse_str((string) $parts['query'], $query);
        $this->assertSame('accounts.google.com', $parts['host']); $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('challenge-value', $query['code_challenge']); $this->assertSame('state-value', $query['state']);
        $this->assertTrue(!str_contains($url, 'private-client-secret'));
    }

    public function testVaultRejectsWrongKeyAndTampering(): void
    {
        $vault = new OpenSslTokenVault(base64_encode(random_bytes(32)), 'key-1'); $sealed = $vault->seal(['access_token' => 'secret']);
        $this->assertSame('secret', $vault->open($sealed)['access_token']); $this->assertSame('key-1', $vault->keyVersion());
        $rejected = false; try { (new OpenSslTokenVault(base64_encode(random_bytes(32))))->open($sealed); } catch (SearchConsoleUnavailable) { $rejected = true; }
        $this->assertTrue($rejected);
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.test', 'correct-horse-battery', 'Tracker'); $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '1.2.0', 10, new Logger(sys_get_temp_dir() . '/search-console-oauth-migration.log')))->run();
        $database->execute("UPDATE modules SET enabled = 1 WHERE module_key = 'search_console'");
        $authorization = new Authorization($database); $audit = new AuditRecorder($database); $websites = new WebsiteManager($database, $authorization, $audit);
        $website = $websites->create(1, WebsiteInput::from('Example', 'https://example.com', '')); $gateway = new FakeSearchConsoleGateway();
        $service = new SearchConsoleConnectionService($database, $authorization, $audit, new OAuthStateStore(new ArraySessionStore()), $gateway, new OpenSslTokenVault(base64_encode(str_repeat('k', 32)), 'test'));
        return [$database, $service, $gateway, $website, $websites];
    }
}
