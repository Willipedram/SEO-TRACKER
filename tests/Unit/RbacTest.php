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
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

final class RbacTest extends TestCase
{
    public function testPermissionsAreDataDrivenAndAdministratorIsSeeded(): void
    {
        [$database, , , $authorization] = $this->services();
        $this->assertSame(19, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM permissions')['total']);
        $this->assertTrue($authorization->allows(1, 'users.create'));
        $this->assertTrue($authorization->allows(1, 'roles.manage'));
    }

    public function testPermissionAndMultipleRoleAssignmentsCompose(): void
    {
        [$database, $users, $roles, $authorization] = $this->services();
        $userId = $users->create(1, 'Limited User', 'limited@example.com', 'correct-horse-battery');
        $viewer = $roles->create(1, 'user-viewer', 'User viewer');
        $creator = $roles->create(1, 'user-creator', 'User creator');
        $viewPermission = (int) $database->fetchOne('SELECT id FROM permissions WHERE permission_key = :key', ['key' => 'users.view'])['id'];
        $createPermission = (int) $database->fetchOne('SELECT id FROM permissions WHERE permission_key = :key', ['key' => 'users.create'])['id'];
        $roles->assignPermissions(1, $viewer, [$viewPermission]);
        $roles->assignPermissions(1, $creator, [$createPermission]);
        $roles->assignRoles(1, $userId, [$viewer, $creator]);
        $this->assertTrue($authorization->allows($userId, 'users.view'));
        $this->assertTrue($authorization->allows($userId, 'users.create'));
        $this->assertTrue(!$authorization->allows($userId, 'users.delete'));
        $this->assertSame(2, count($roles->userRoleIds(1, $userId)));
    }

    public function testUnauthorizedUserCannotEscalateOrCallManagersDirectly(): void
    {
        [$database, $users, $roles] = $this->services();
        $userId = $users->create(1, 'No Access', 'noaccess@example.com', 'correct-horse-battery');
        $administratorRole = (int) $database->fetchOne('SELECT id FROM roles WHERE role_key = :key', ['key' => 'administrator'])['id'];
        $denied = false;
        try { $roles->assignRoles($userId, $userId, [$administratorRole]); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
        $denied = false;
        try { $users->all($userId); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
    }

    public function testDisabledUsersHaveNoEffectivePermissions(): void
    {
        [$database, $users, $roles, $authorization] = $this->services();
        $userId = $users->create(1, 'Viewer', 'viewer@example.com', 'correct-horse-battery');
        $viewer = $roles->create(1, 'viewer', 'Viewer');
        $permission = (int) $database->fetchOne('SELECT id FROM permissions WHERE permission_key = :key', ['key' => 'users.view'])['id'];
        $roles->assignPermissions(1, $viewer, [$permission]);
        $roles->assignRoles(1, $userId, [$viewer]);
        $this->assertTrue($authorization->allows($userId, 'users.view'));
        $users->setDisabled(1, $userId, true);
        $this->assertTrue(!$authorization->allows($userId, 'users.view'));
    }

    public function testSelfAndLastAdministratorProtectionsAndAuditRecords(): void
    {
        [$database, $users, $roles] = $this->services();
        $rejected = false;
        try { $users->setDisabled(1, 1, true); } catch (InvalidArgumentException) { $rejected = true; }
        $this->assertTrue($rejected);
        $rejected = false;
        try { $roles->assignRoles(1, 1, []); } catch (InvalidArgumentException) { $rejected = true; }
        $this->assertTrue($rejected);
        $created = $users->create(1, 'Audited', 'audited@example.com', 'correct-horse-battery');
        $users->update(1, $created, 'Audited User', 'audited@example.com');
        $this->assertTrue((int) $database->fetchOne('SELECT COUNT(*) AS total FROM audit_logs')['total'] >= 2);
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '0.6.0', 4, new Logger(sys_get_temp_dir() . '/seo-rbac-migration.log')))->run();
        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        return [$database, new UserManager($database, $authorization, $audit, new PasswordHasher()), new RoleManager($database, $authorization, $audit), $authorization];
    }
}
