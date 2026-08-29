<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\Keywords\Application\KeywordManager;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\RankTracking\Application\RankCheckManager;
use App\Modules\RankTracking\Application\RankWorker;
use App\Modules\RankTracking\Domain\RankAdapter;
use App\Modules\RankTracking\Domain\RankExecutionResult;
use App\Modules\RankTracking\Domain\RankJob;
use App\Modules\RankTracking\Infrastructure\RankAdapterRegistry;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\TestCase;

final class DeviceFixtureRankAdapter implements RankAdapter
{
    public function key(): string { return 'integration_fixture'; }
    public function version(): string { return '1.0.0'; }
    public function executionSource(): string { return 'provider_api'; }
    public function supportsExecutionDevice(string $requestedDevice, string $executionDevice): bool
    {
        return $executionDevice === 'fixture_' . $requestedDevice;
    }
    public function execute(RankJob $job): RankExecutionResult
    {
        $position = $job->requestedDevice === 'desktop' ? 4 : 9;
        return new RankExecutionResult('ranked', $position, 'https://example.test/' . $job->requestedDevice, 100, 'fixture_' . $job->requestedDevice, 'fixture:deterministic', gmdate('Y-m-d H:i:s'));
    }
}

final class RankWorkflowIntegrationTest extends TestCase
{
    public function testMigratedDatabasePreservesDeviceSpecificRankHistoryAndAuditTrail(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.test', 'test-password-only', 'Test');
        $database = new Database($pdo);
        $base = dirname(__DIR__, 2);
        (new MigrationRunner($database, new MigrationDiscovery($base . '/database/migrations'), '1.8.0', 13, new Logger(sys_get_temp_dir() . '/seo-integration-migrations.log')))->run();

        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        $websites = new WebsiteManager($database, $authorization, $audit);
        $keywords = new KeywordManager($database, $authorization, $audit);
        $website = $websites->create(1, WebsiteInput::from('Integration site', 'https://example.test', ''));
        $desktop = $keywords->create(1, $website, $this->keyword('integration query', 'desktop'));
        $mobile = $keywords->create(1, $website, $this->keyword('integration query', 'mobile'));

        $adapter = new DeviceFixtureRankAdapter();
        $registry = new RankAdapterRegistry([$adapter]);
        $manager = new RankCheckManager($database, $authorization, $audit, $registry, $adapter->key());
        $worker = new RankWorker($database, $registry, new Logger(sys_get_temp_dir() . '/seo-integration-rank.log'), 2, 30);
        $desktopRequest = $manager->submit(1, $website, $desktop);
        $mobileRequest = $manager->submit(1, $website, $mobile);
        $this->assertSame(2, $worker->work(2));

        $this->assertSame(4, (int) $manager->status(1, $desktopRequest)['result']['position']);
        $this->assertSame('fixture_desktop', $manager->status(1, $desktopRequest)['result']['execution_device']);
        $this->assertSame(9, (int) $manager->status(1, $mobileRequest)['result']['position']);
        $this->assertSame('fixture_mobile', $manager->status(1, $mobileRequest)['result']['execution_device']);

        $secondDesktop = $manager->submit(1, $website, $desktop);
        $this->assertSame(1, $worker->work(1));
        $this->assertTrue($secondDesktop !== $desktopRequest);
        $this->assertSame(2, count($manager->history(1, $website, $desktop)));
        $this->assertSame(3, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM rank_results')['total']);
        $this->assertTrue((int) $database->fetchOne("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'rank_check.requested'")['total'] >= 3);
    }

    private function keyword(string $text, string $device): KeywordInput
    {
        return KeywordInput::from([
            'keyword' => $text,
            'target_url' => 'https://example.test',
            'search_engine' => 'google',
            'country' => 'US',
            'language' => 'en',
            'device' => $device,
            'active' => true,
        ], ['google'], ['desktop', 'mobile'], ['US']);
    }
}
