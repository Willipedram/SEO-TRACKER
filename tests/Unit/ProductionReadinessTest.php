<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Deployment\ReleaseBuilder;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use PDO;
use Tests\TestCase;
use ZipArchive;

final class ProductionReadinessTest extends TestCase
{
    public function testProductionQueryIndexesMatchAuditedPredicates(): void
    {
        [$path, $pdo] = $this->installedDatabase();
        try {
            foreach ([
                'rank_results' => ['rank_results_website_time', 'rank_results_observed_keyword'],
                'search_console_syncs' => ['search_console_syncs_user_created'],
            ] as $table => $expected) {
                $indexes = array_column($pdo->query("PRAGMA index_list($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
                foreach ($expected as $name) $this->assertTrue(in_array($name, $indexes, true), "Missing production index $name.");
            }
            foreach ([
                ['SELECT id FROM rank_results WHERE website_id=1 AND observed_at>=\'2026-01-01\' ORDER BY observed_at,id', 'rank_results_website_time'],
                ['SELECT keyword_id FROM rank_results WHERE observed_at>=\'2026-01-01\' AND observed_at<\'2027-01-01\'', 'rank_results_observed_keyword'],
                ['SELECT COUNT(*) FROM search_console_syncs WHERE user_id=1 AND created_at>=\'2026-01-01\'', 'search_console_syncs_user_created'],
            ] as [$query, $index]) {
                $plan = implode(' ', array_column($pdo->query('EXPLAIN QUERY PLAN ' . $query)->fetchAll(PDO::FETCH_ASSOC), 'detail'));
                $this->assertTrue(str_contains($plan, $index), "Audited query does not use $index: $plan");
            }
            $this->assertSame(14, (int) $pdo->query('SELECT schema_version FROM app_installations')->fetchColumn());
        } finally {
            unset($pdo); @unlink($path);
        }
    }

    public function testDatabaseBackupCanActuallyRestorePersistentState(): void
    {
        [$path, $pdo] = $this->installedDatabase();
        $backup = $path . '.verified-backup';
        try {
            $pdo->exec("INSERT INTO settings(setting_key,setting_value,value_type,created_at,updated_at) VALUES('restore.marker','preserve-me','string','2026-08-27 00:00:00','2026-08-27 00:00:00')");
            unset($pdo);
            $this->assertTrue(copy($path, $backup));
            $this->assertSame(hash_file('sha256', $path), hash_file('sha256', $backup));
            unlink($path);
            $this->assertTrue(copy($backup, $path));
            $restored = new PDO('sqlite:' . $path);
            $this->assertSame('preserve-me', $restored->query("SELECT setting_value FROM settings WHERE setting_key='restore.marker'")->fetchColumn());
            $this->assertSame(14, (int) $restored->query('SELECT schema_version FROM app_installations')->fetchColumn());
        } finally {
            unset($pdo, $restored); @unlink($path); @unlink($backup);
        }
    }

    public function testReleaseArchiveOmitsDevelopmentAndRuntimeArtifacts(): void
    {
        $archive = sys_get_temp_dir() . '/seo-production-release-' . bin2hex(random_bytes(5)) . '.zip';
        try {
            (new ReleaseBuilder(dirname(__DIR__, 2)))->build($archive);
            $zip = new ZipArchive(); $this->assertSame(true, $zip->open($archive));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string) $zip->getNameIndex($i);
            $zip->close();
            foreach ($names as $name) {
                $this->assertTrue(!str_starts_with($name, 'tests/'), 'Tests leaked into production release.');
                $this->assertTrue($name === '.gitignore' || str_ends_with($name, '/.gitignore') || $name === '.gitkeep' || !str_starts_with($name, '.git'), 'VCS metadata leaked into production release.');
                $this->assertTrue(!preg_match('/\.(?:log|sqlite|bak|zip)$/i', $name), 'Runtime artifact leaked: ' . $name);
            }
            foreach (['app/Core/Application.php', 'public/index.php', 'public/assets/installer.css', 'public/assets/tooltips.js', 'database/migrations/2026_08_27_000000_production_query_indexes.php'] as $required) {
                $this->assertTrue(in_array($required, $names, true), 'Required runtime file missing: ' . $required);
            }
        } finally {
            @unlink($archive); @unlink($archive . '.sha256');
        }
    }

    /** @return array{string, PDO} */
    private function installedDatabase(): array
    {
        $base = dirname(__DIR__, 2); $path = sys_get_temp_dir() . '/seo-production-' . bin2hex(random_bytes(5)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new SchemaInstaller())->install($pdo, 'Production Admin', 'production@example.test', 'production-test-password', 'Production Test');
        $version = require $base . '/config/version.php';
        (new MigrationRunner(new Database($pdo), new MigrationDiscovery($base . '/database/migrations'), $version['application'], $version['schema'], new Logger(sys_get_temp_dir() . '/seo-production-test.log', 'error')))->run();
        return [$path, $pdo];
    }
}
