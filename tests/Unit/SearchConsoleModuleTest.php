<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\SearchConsole\Application\SearchConsoleManager;
use PDO;
use Tests\TestCase;

final class SearchConsoleModuleTest extends TestCase
{
    public function testDisabledAndMisconfiguredStatesAreExplicit(): void
    {
        [$database, $manager] = $this->services([]);
        $status = $manager->status(1);
        $this->assertSame(false, $status['enabled']);
        $this->assertSame('disabled', $status['status']);
        $this->assertTrue(in_array('missing_client_secret', $status['issues'], true));
        $manager->setEnabled(1, true);
        $this->assertSame('misconfigured', $manager->status(1)['status']);
    }

    public function testReadyConfigurationNeverReturnsSecret(): void
    {
        [, $manager] = $this->services(['client_id' => 'client-id.apps.googleusercontent.com', 'client_secret' => 'super-secret', 'redirect_uri' => 'https://example.test/oauth/search-console/callback', 'encryption_key' => base64_encode(random_bytes(32)), 'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly']]);
        $manager->setEnabled(1, true);
        $status = $manager->status(1);
        $this->assertSame('ready', $status['status']);
        $this->assertSame(true, $status['client_secret_configured']);
        $this->assertTrue(!str_contains(json_encode($status, JSON_THROW_ON_ERROR), 'super-secret'));
    }

    public function testDisablePreservesConnectionRows(): void
    {
        [$database, $manager] = $this->services([]);
        $database->execute("INSERT INTO search_console_connections (public_id,user_id,provider_subject,status,granted_scopes,credential_envelope,credential_key_version,token_expires_at,last_error_code,created_at,updated_at) VALUES (:public,1,'subject','pending','[]',NULL,NULL,NULL,NULL,:now,:now)", ['public' => str_repeat('a', 32), 'now' => '2026-08-23 00:00:00']);
        $manager->setEnabled(1, true);
        $manager->setEnabled(1, false);
        $this->assertSame(1, count($database->fetchAll('SELECT id FROM search_console_connections')));
    }

    public function testSettingsManagementPermissionIsRequired(): void
    {
        [$database, $manager] = $this->services([]);
        $database->execute("INSERT INTO users (name,email,password_hash,email_verified_at,disabled_at,created_at,updated_at) VALUES ('Viewer','viewer@example.test','x',NULL,NULL,:now,:now)", ['now' => '2026-08-23 00:00:00']);
        $user = (int) $database->fetchOne("SELECT id FROM users WHERE email = 'viewer@example.test'")['id'];
        $denied = false;
        try { $manager->status($user); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
    }

    public function testInvalidScopeConfigurationIsRejected(): void
    {
        [, $manager] = $this->services(['scopes' => ['https://evil.example/scope']]);
        $rejected = false;
        try { $manager->status(1); } catch (\InvalidArgumentException) { $rejected = true; }
        $this->assertTrue($rejected);
    }

    private function services(array $oauth): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.test', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '1.2.0', 10, new Logger(sys_get_temp_dir() . '/search-console-migration.log')))->run();
        return [$database, new SearchConsoleManager($database, new Authorization($database), new AuditRecorder($database), $oauth)];
    }
}
