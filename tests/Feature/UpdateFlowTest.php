<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Installer\SchemaInstaller;
use App\Core\Update\UpdaterController;
use PDO;
use Tests\TestCase;

final class UpdateFlowTest extends TestCase
{
    public function testSourceReplacementRequiresAdminAndPreservesExistingUser(): void
    {
        $base = dirname(__DIR__, 2);
        $path = sys_get_temp_dir() . '/seo-source-update-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        (new SchemaInstaller())->install($pdo, 'Existing Admin', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $config = new Config([
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]]],
            'version' => ['application' => '0.4.0', 'schema' => 2],
            'logging' => ['path' => sys_get_temp_dir() . '/seo-update-feature.log', 'level' => 'error'],
        ]);
        $controller = new UpdaterController($base, $config);
        $home = $controller->home();
        $this->assertSame(302, $home->status);
        $this->assertSame('/update', $home->headers['Location']);
        $this->assertSame(200, $controller->show()->status);

        $denied = $controller->run(new Request('POST', '/update', body: ['email' => 'admin@example.com', 'password' => 'wrong-password']));
        $this->assertSame(403, $denied->status);
        $completed = $controller->run(new Request('POST', '/update', body: ['email' => 'admin@example.com', 'password' => 'correct-horse-battery']));
        $this->assertSame(200, $completed->status);
        $this->assertSame('Existing Admin', $pdo->query('SELECT name FROM users')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT schema_version FROM app_installations')->fetchColumn());
        $this->assertSame(200, $controller->home()->status);
        unlink($path);
    }
}
