<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Installer\DatabaseConfiguration;
use App\Core\Installer\DatabaseInspector;
use App\Core\Installer\InstallerController;
use App\Core\Installer\InstallerException;
use App\Core\Installer\SchemaInstaller;
use PDO;
use Tests\TestCase;

final class InstallerDatabaseTest extends TestCase
{
    public function testConnectionFailureDoesNotExposePassword(): void
    {
        $password = 'never-expose-this';
        try {
            (new DatabaseConfiguration('127.0.0.1', 1, 'missing', 'missing', $password))->connect();
            $this->assertTrue(false, 'Connection unexpectedly succeeded.');
        } catch (InstallerException $exception) {
            $this->assertTrue(!str_contains($exception->getMessage(), $password));
            $this->assertTrue(!str_contains($exception->getMessage(), 'mysql:'));
        }
    }

    public function testEmptyUnknownAndExistingDatabasesAreDistinguished(): void
    {
        $inspector = new DatabaseInspector();
        $empty = new PDO('sqlite::memory:');
        $this->assertSame(DatabaseInspector::EMPTY, $inspector->inspect($empty));

        $unknown = new PDO('sqlite::memory:');
        $unknown->exec('CREATE TABLE unrelated (id INTEGER)');
        $this->assertSame(DatabaseInspector::UNKNOWN, $inspector->inspect($unknown));

        $existing = new PDO('sqlite::memory:');
        (new SchemaInstaller())->install($existing, 'Admin', 'admin@example.com', 'correct-horse-battery', 'SEO Tracker');
        $this->assertSame(DatabaseInspector::APPLICATION, $inspector->inspect($existing));
    }

    public function testAdminAndInstallationStateArePersisted(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'First Admin', 'ADMIN@example.com', 'correct-horse-battery', 'My Tracker');
        $admin = $pdo->query('SELECT name, email, password_hash FROM users')->fetch();
        $this->assertSame('First Admin', $admin['name']);
        $this->assertSame('admin@example.com', $admin['email']);
        $this->assertTrue(password_verify('correct-horse-battery', $admin['password_hash']));
        $this->assertSame('administrator', $pdo->query('SELECT role_key FROM roles')->fetchColumn());
        $this->assertSame(SchemaInstaller::APPLICATION_ID, $pdo->query('SELECT application_id FROM app_installations')->fetchColumn());
    }

    public function testRepeatedInstallerAccessIsHidden(): void
    {
        $path = sys_get_temp_dir() . '/seo-installed-' . bin2hex(random_bytes(4)) . '.sqlite';
        $base = sys_get_temp_dir() . '/seo-install-lock-' . bin2hex(random_bytes(4));
        mkdir($base . '/storage', 0750, true);
        $pdo = new PDO('sqlite:' . $path);
        (new SchemaInstaller())->install($pdo, 'Admin', 'admin@example.com', 'correct-horse-battery', 'SEO Tracker');
        $config = new Config(['database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]]]]);
        $controller = new InstallerController($base, $config);
        $this->assertTrue($controller->show(new Request('GET', '/install'))->status !== 404, 'A replaced source tree without its lock must reopen database detection.');
        file_put_contents($base . '/storage/installed.lock', SchemaInstaller::APPLICATION_ID . PHP_EOL);
        $response = $controller->show(new Request('GET', '/install'));
        unlink($path);
        unlink($base . '/storage/installed.lock'); rmdir($base . '/storage'); rmdir($base);
        $this->assertSame(404, $response->status);
    }

    public function testValidationFailureLeavesDatabaseEmptyForRetry(): void
    {
        $pdo = new PDO('sqlite::memory:');
        try {
            (new SchemaInstaller())->install($pdo, 'Admin', 'not-an-email', 'correct-horse-battery', 'SEO Tracker');
        } catch (InstallerException) {
        }
        $this->assertSame(DatabaseInspector::EMPTY, (new DatabaseInspector())->inspect($pdo));
    }
}
