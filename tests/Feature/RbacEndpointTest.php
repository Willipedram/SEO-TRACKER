<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AdminController;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\RbacFactory;
use App\Core\Rbac\RoleManager;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use PDO;
use Tests\TestCase;

final class RbacEndpointTest extends TestCase
{
    public function testDirectManagementEndpointBypassIsDenied(): void
    {
        $base = dirname(__DIR__, 2);
        $path = sys_get_temp_dir() . '/seo-rbac-endpoint-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery($base . '/database/migrations'), '0.6.0', 4, new Logger(sys_get_temp_dir() . '/seo-rbac-endpoint-migration.log')))->run();
        $database->execute("INSERT INTO users (name, email, password_hash, email_verified_at, disabled_at, created_at, updated_at) VALUES ('Limited','limited@example.com','unused',NULL,NULL,'2026-01-01','2026-01-01')");
        $limitedId = (int) $database->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => 'limited@example.com'])['id'];
        $authorization = new Authorization($database);
        $roles = new RoleManager($database, $authorization, new AuditRecorder($database));
        $viewer = $roles->create(1, 'viewer', 'Viewer');
        $permission = (int) $database->fetchOne('SELECT id FROM permissions WHERE permission_key = :key', ['key' => 'users.view'])['id'];
        $roles->assignPermissions(1, $viewer, [$permission]);
        $roles->assignRoles(1, $limitedId, [$viewer]);
        $_SESSION['auth'] = ['user_id' => $limitedId, 'authenticated_at' => time(), 'last_activity' => time()];
        $config = new Config([
            'app' => ['key' => 'test-key'], 'auth' => [],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]]],
            'logging' => ['path' => sys_get_temp_dir() . '/seo-rbac-endpoint.log', 'level' => 'error'],
        ]);
        $controller = new AdminController(new RbacFactory($base, $config));
        $this->assertSame(200, $controller->users()->status);
        $this->assertSame(403, $controller->roles()->status);
        $this->assertSame(403, $controller->createUserForm()->status);
        $this->assertSame(200, $controller->capabilities()->status);
        $_SESSION = [];
        unlink($path);
    }
}
