<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth\PasswordHasher;
use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\UserManager;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

final class WebsiteTest extends TestCase
{
    public function testCreatesNormalizesEditsSettingsAndArchivesWebsite(): void
    {
        [$database, $manager] = $this->services();
        $id = $manager->create(1, WebsiteInput::from('Example', 'HTTPS://Example.COM/', 'A site'));
        $website = $manager->find(1, $id);
        $this->assertSame('example.com', $website['normalized_domain']);
        $this->assertSame('https://example.com', $website['canonical_url']);
        $manager->update(1, $id, WebsiteInput::from('Updated', 'http://example.com', 'Updated description'));
        $manager->settings(1, $id, 'Europe/Amsterdam', 'paused');
        $website = $manager->find(1, $id);
        $this->assertSame('Updated', $website['site_name']);
        $this->assertSame('paused', $website['status']);
        $this->assertSame('Europe/Amsterdam', $website['timezone']);
        $manager->archive(1, $id);
        $this->assertSame('archived', $manager->find(1, $id)['status']);
        $this->assertSame(0, count($manager->list(1)));
        $this->assertTrue((int) $database->fetchOne("SELECT COUNT(*) AS total FROM audit_logs WHERE target_type = 'website'")['total'] >= 4);
    }

    public function testRejectsUnsafeInvalidAndDuplicateOrigins(): void
    {
        [, $manager] = $this->services();
        foreach (['javascript://example.com', 'https://user:secret@example.com', 'https://example.com/path', 'https://example.com?x=1', 'https://localhost'] as $url) {
            $failed = false;
            try { WebsiteInput::from('Invalid', $url, ''); } catch (InvalidArgumentException) { $failed = true; }
            $this->assertTrue($failed);
        }
        $manager->create(1, WebsiteInput::from('One', 'https://EXAMPLE.com/', ''));
        $failed = false;
        try { $manager->create(1, WebsiteInput::from('Duplicate', 'http://example.com', '')); } catch (InvalidArgumentException) { $failed = true; }
        $this->assertTrue($failed);
    }

    public function testPermissionsAndOwnershipPreventDirectIdorAccess(): void
    {
        [$database, $manager, $users, $roles] = $this->services();
        $websiteId = $manager->create(1, WebsiteInput::from('Private', 'https://private.example.com', ''));
        $other = $users->create(1, 'Other User', 'other@example.com', 'correct-horse-battery');
        $denied = false;
        try { $manager->find($other, $websiteId); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
        $role = $roles->create(1, 'website-manager', 'Website manager');
        $permissionIds = array_column($database->fetchAll("SELECT id FROM permissions WHERE permission_key LIKE 'websites.%'"), 'id');
        $roles->assignPermissions(1, $role, $permissionIds);
        $roles->assignRoles(1, $other, [$role]);
        $notFound = false;
        try { $manager->find($other, $websiteId); } catch (InvalidArgumentException) { $notFound = true; }
        $this->assertTrue($notFound);
        $otherWebsite = $manager->create($other, WebsiteInput::from('Other', 'https://private.example.com', ''));
        $this->assertTrue($otherWebsite !== $websiteId);
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '0.7.0', 5, new Logger(sys_get_temp_dir() . '/seo-websites-migration.log')))->run();
        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        return [$database, new WebsiteManager($database, $authorization, $audit), new UserManager($database, $authorization, $audit, new PasswordHasher()), new RoleManager($database, $authorization, $audit)];
    }
}
