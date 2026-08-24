<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use App\Modules\Websites\Infrastructure\WebsiteFactory;
use App\Modules\Websites\Presentation\WebsiteController;
use PDO;
use Tests\TestCase;

final class WebsiteEndpointTest extends TestCase
{
    public function testDashboardLoadsAndUnknownOpaqueIdDoesNotLeakData(): void
    {
        $base = dirname(__DIR__, 2);
        $path = sys_get_temp_dir() . '/seo-website-endpoint-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery($base . '/database/migrations'), '0.7.0', 5, new Logger(sys_get_temp_dir() . '/seo-website-endpoint-migration.log')))->run();
        $manager = new WebsiteManager($database, new Authorization($database), new AuditRecorder($database));
        $id = $manager->create(1, WebsiteInput::from('Dashboard Site', 'https://dashboard.example.com', 'Safe description'));
        $_SESSION['auth'] = ['user_id' => 1, 'authenticated_at' => time(), 'last_activity' => time()];
        $config = new Config([
            'app' => ['key' => 'test-key'], 'auth' => [],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]]],
            'logging' => ['path' => sys_get_temp_dir() . '/seo-website-endpoint.log', 'level' => 'error'],
        ]);
        $controller = new WebsiteController(new WebsiteFactory($base, $config));
        $dashboard = $controller->dashboard(new Request('GET', '/websites/dashboard', ['id' => $id]));
        $this->assertSame(200, $dashboard->status);
        $this->assertTrue(str_contains($dashboard->body, 'Dashboard Site'));
        $this->assertTrue(str_contains($dashboard->body, 'Tracking overview'));
        $missing = $controller->dashboard(new Request('GET', '/websites/dashboard', ['id' => str_repeat('f', 32)]));
        $this->assertSame(404, $missing->status);
        $database->execute("INSERT INTO users (name, email, password_hash, email_verified_at, disabled_at, created_at, updated_at) VALUES ('No Access', 'none@example.com', 'unused', NULL, NULL, '2026-01-01', '2026-01-01')");
        $limitedId = (int) $database->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => 'none@example.com'])['id'];
        $_SESSION['auth'] = ['user_id' => $limitedId, 'authenticated_at' => time(), 'last_activity' => time()];
        $this->assertSame(403, $controller->index(new Request('GET', '/websites'))->status);
        $this->assertSame(403, $controller->create(new Request('POST', '/websites/create', body: ['name' => 'Blocked', 'url' => 'https://blocked.example.com']))->status);
        $_SESSION = [];
        unlink($path);
    }
}
