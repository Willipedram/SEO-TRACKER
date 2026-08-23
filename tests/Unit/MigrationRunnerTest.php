<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Core\Update\UpdateException;
use PDO;
use Tests\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testOnePendingMigrationIsTrackedAndPreservesData(): void
    {
        [$pdo, $database] = $this->versionOneDatabase();
        $runner = $this->runner($database, dirname(__DIR__, 2) . '/database/migrations', 2);
        $plan = $runner->plan();
        $this->assertSame(1, count($plan->pending));
        $this->assertSame('2026_08_23_010000_update_tracking', $plan->pending[0]->id);

        $completed = $runner->run();
        $this->assertTrue(!$completed->required());
        $this->assertSame(2, (int) $pdo->query('SELECT schema_version FROM app_installations')->fetchColumn());
        $this->assertSame('Admin', $pdo->query('SELECT name FROM users')->fetchColumn());
        $this->assertSame('succeeded', $pdo->query("SELECT status FROM migrations WHERE migration = '2026_08_23_010000_update_tracking'")->fetchColumn());
    }

    public function testNoMigrationsNeeded(): void
    {
        [, $database] = $this->versionOneDatabase();
        $runner = $this->runner($database, dirname(__DIR__, 2) . '/database/migrations', 2);
        $runner->run();
        $this->assertSame([], $runner->plan()->pending);
        $this->assertTrue(!$runner->plan()->required());
    }

    public function testMultipleMigrationsRunInSchemaOrder(): void
    {
        [$pdo, $database] = $this->versionOneDatabase();
        $path = $this->migrationDirectory([
            3 => "\$database->execute(\"INSERT INTO settings (setting_key, setting_value, value_type, created_at, updated_at) VALUES ('order.three',(SELECT setting_value FROM settings WHERE setting_key='order.two'),'string','2026-01-01','2026-01-01')\");",
        ]);
        $database->execute("INSERT INTO settings (setting_key, setting_value, value_type, created_at, updated_at) VALUES ('order.two','2','string','2026-01-01','2026-01-01')");
        $this->runner($database, $path, 3)->run();
        $this->assertSame('2', $pdo->query("SELECT setting_value FROM settings WHERE setting_key='order.three'")->fetchColumn());
        $this->assertSame(['2026_08_23_010000_update_tracking', '2026_08_23_030000_test_migration_3'], $pdo->query("SELECT migration FROM migrations WHERE migration != '2026_08_23_000000_phase03_baseline' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $this->removeDirectory($path);
    }

    public function testFailedMigrationIsRecordedStopsLaterWorkAndCanRetry(): void
    {
        [$pdo, $database] = $this->versionOneDatabase();
        $path = $this->migrationDirectory([
            3 => "throw new \\RuntimeException('token=must-not-persist');",
            4 => "\$database->execute(\"INSERT INTO settings (setting_key, setting_value, value_type, created_at, updated_at) VALUES ('must.not.run','x','string','2026-01-01','2026-01-01')\");",
        ]);
        $failed = false;
        try {
            $this->runner($database, $path, 4)->run();
        } catch (UpdateException) {
            $failed = true;
        }
        $this->assertTrue($failed);
        $this->assertSame(1, (int) $pdo->query('SELECT schema_version FROM app_installations')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key='must.not.run'")->fetchColumn());
        $failure = $pdo->query('SELECT migration, error_message FROM migration_failures')->fetch();
        $this->assertSame('2026_08_23_030000_test_migration_3', $failure['migration']);
        $this->assertTrue(!str_contains($failure['error_message'], 'must-not-persist'));

        $this->writeMigration($path, 3, "\$database->execute(\"INSERT INTO settings (setting_key, setting_value, value_type, created_at, updated_at) VALUES ('retry.worked','yes','string','2026-01-01','2026-01-01')\");");
        $this->runner($database, $path, 4)->run();
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM migration_failures')->fetchColumn());
        $this->assertSame(4, (int) $pdo->query('SELECT schema_version FROM app_installations')->fetchColumn());
        $this->removeDirectory($path);
    }

    private function versionOneDatabase(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Admin', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        return [$pdo, new Database($pdo)];
    }

    private function runner(Database $database, string $path, int $target): MigrationRunner
    {
        return new MigrationRunner($database, new MigrationDiscovery($path), '0.4.0', $target, new Logger(sys_get_temp_dir() . '/seo-update-test.log'));
    }

    private function migrationDirectory(array $operations): string
    {
        $path = sys_get_temp_dir() . '/seo-migrations-' . bin2hex(random_bytes(4));
        mkdir($path);
        copy(dirname(__DIR__, 2) . '/database/migrations/2026_08_23_000000_phase03_baseline.php', $path . '/2026_08_23_000000_phase03_baseline.php');
        copy(dirname(__DIR__, 2) . '/database/migrations/2026_08_23_010000_update_tracking.php', $path . '/2026_08_23_010000_update_tracking.php');
        foreach ($operations as $version => $operation) {
            $this->writeMigration($path, $version, $operation);
        }
        return $path;
    }

    private function writeMigration(string $path, int $version, string $operation): void
    {
        $id = '2026_08_23_0' . $version . '0000_test_migration_' . $version;
        $php = "<?php\ndeclare(strict_types=1);\nuse App\\Core\\Database\\Database;\nuse App\\Core\\Update\\Migration;\nreturn new Migration('$id', $version, true, static function (Database \$database): void { $operation });\n";
        file_put_contents($path . '/' . $id . '.php', $php);
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($path);
    }
}
