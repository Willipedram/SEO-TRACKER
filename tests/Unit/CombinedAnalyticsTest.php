<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Localization\Translator;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
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
use App\Modules\SearchConsole\Application\CombinedAnalyticsService;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\TestCase;

final class CombinedRankAdapter implements RankAdapter
{
    public function key(): string { return 'combined_test'; }
    public function version(): string { return '1.0.0'; }
    public function executionSource(): string { return 'provider_api'; }
    public function supportsExecutionDevice(string $requestedDevice, string $executionDevice): bool { return $executionDevice === $requestedDevice; }
    public function execute(RankJob $job): RankExecutionResult { return new RankExecutionResult('ranked', 4, 'https://example.com/page', 100, $job->requestedDevice, $job->requestedDevice . ':test', gmdate('Y-m-d H:i:s')); }
}

final class CombinedAnalyticsTest extends TestCase
{
    public function testMatchingDataKeepsMetricsDistinctAndAligned(): void
    {
        [$database, $service, $website, $keyword, $rankManager, $worker, $property, $sync] = $this->services(); $rankManager->submit(1, $website, $keyword); $worker->work(1);
        $this->searchRow($database, $property, $sync, gmdate('Y-m-d'), 'seo tracker', 'desktop', 8, 80, 6.5);
        $model = $service->compare(1, $website, $keyword, '7');
        $this->assertSame('matched', $model['state']); $this->assertSame(4, $model['rank_tracker_latest']['position']);
        $this->assertSame(6.5, $model['search_console_summary']['average_position']); $this->assertSame(8, $model['search_console_summary']['clicks']);
        $this->assertSame(4, $model['timeline'][0]['rank_tracker_position']); $this->assertSame(6.5, $model['timeline'][0]['search_console_average_position']);
    }

    public function testPartialNoRankAndDifferentDatesRemainExplicit(): void
    {
        [$database, $service, $website, $keyword, $rankManager, $worker, $property, $sync] = $this->services();
        $this->searchRow($database, $property, $sync, gmdate('Y-m-d', time() - 86400), 'seo tracker', 'desktop', 3, 30, 9.0);
        $searchOnly = $service->compare(1, $website, $keyword, '7'); $this->assertSame('search_console_only', $searchOnly['state']);
        $rankManager->submit(1, $website, $keyword); $worker->work(1); $partial = $service->compare(1, $website, $keyword, '7');
        $this->assertSame('matched', $partial['state']); $this->assertSame(2, count($partial['timeline']));
        $this->assertSame(null, $partial['timeline'][0]['rank_tracker_position']); $this->assertSame(null, $partial['timeline'][1]['search_console_average_position']);
    }

    public function testQueryAndDeviceMismatchesAreNotForced(): void
    {
        [$database, $service, $website, $keyword, $rankManager, $worker, $property, $sync] = $this->services(); $rankManager->submit(1, $website, $keyword); $worker->work(1);
        $this->searchRow($database, $property, $sync, gmdate('Y-m-d'), 'SEO Tracker', 'desktop', 1, 10, 2.0);
        $this->searchRow($database, $property, $sync, gmdate('Y-m-d'), 'seo tracker', 'mobile', 1, 10, 3.0);
        $model = $service->compare(1, $website, $keyword, '7'); $this->assertSame('rank_only', $model['state']); $this->assertSame(0, $model['search_console_summary']['impressions']);
    }

    public function testDisabledModuleAndAuthorizationDoNotAffectRankData(): void
    {
        [$database, $service, $website, $keyword, $rankManager, $worker] = $this->services(); $rankManager->submit(1, $website, $keyword); $worker->work(1);
        $database->execute("UPDATE modules SET enabled=0 WHERE module_key='search_console'"); $model = $service->compare(1, $website, $keyword, '7');
        $this->assertSame('rank_only', $model['state']); $this->assertSame('module_disabled', $model['search_console_state']); $this->assertSame(4, $model['rank_tracker_latest']['position']);
        $database->execute("INSERT INTO users (name,email,password_hash,created_at,updated_at) VALUES ('No access','none@example.test','x',:now,:now)", ['now' => gmdate('Y-m-d H:i:s')]);
        $denied = false; try { $service->compare(2, $website, $keyword, '7'); } catch (AuthorizationException) { $denied = true; } $this->assertTrue($denied);
    }

    public function testLocalizedLabelsStateMetricMeanings(): void
    {
        $english = new Translator('en', dirname(__DIR__, 2), 'search_console'); $persian = new Translator('fa', dirname(__DIR__, 2), 'search_console');
        $this->assertTrue(str_contains($english->get('rank_tracker_position'), 'Rank Tracker'));
        $this->assertTrue(str_contains($english->get('search_console_average_position'), 'Search Console'));
        $this->assertTrue($english->get('metrics_not_equivalent') !== 'metrics_not_equivalent'); $this->assertTrue($persian->get('metrics_not_equivalent') !== 'metrics_not_equivalent');
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.test', 'correct-horse-battery', 'Tracker'); $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '1.5.0', 12, new Logger(sys_get_temp_dir() . '/combined-migration.log')))->run(); $database->execute("UPDATE modules SET enabled=1 WHERE module_key='search_console'");
        $authorization = new Authorization($database); $audit = new AuditRecorder($database); $website = (new WebsiteManager($database, $authorization, $audit))->create(1, WebsiteInput::from('Example', 'https://example.com', ''));
        $keyword = (new KeywordManager($database, $authorization, $audit))->create(1, $website, KeywordInput::from(['keyword' => 'seo tracker', 'target_url' => '', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'desktop', 'active' => true], ['google'], ['desktop', 'mobile'], ['US']));
        $adapter = new CombinedRankAdapter(); $registry = new RankAdapterRegistry([$adapter]); $rankManager = new RankCheckManager($database, $authorization, $audit, $registry, $adapter->key(), 10, 900); $worker = new RankWorker($database, $registry, new Logger(sys_get_temp_dir() . '/combined-worker.log'), 3, 120);
        $now = gmdate('Y-m-d H:i:s'); $database->execute("INSERT INTO search_console_connections (public_id,user_id,status,granted_scopes,created_at,updated_at) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'connected','scope',:now,:now)", ['now' => $now]); $connection = (int) $database->fetchOne("SELECT id FROM search_console_connections WHERE public_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")['id'];
        $database->execute("INSERT INTO search_console_properties (public_id,connection_id,website_id,property_uri,property_type,permission_level,selected,created_at,updated_at) SELECT 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',:connection,id,'sc-domain:example.com','domain','siteOwner',1,:now,:now FROM websites WHERE public_id=:website", ['connection' => $connection, 'now' => $now, 'website' => $website]); $property = (int) $database->fetchOne("SELECT id FROM search_console_properties WHERE public_id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'")['id'];
        $database->execute("INSERT INTO search_console_syncs (public_id,user_id,website_id,property_id,start_date,end_date,search_type,status,phase,available_at,created_at,updated_at) SELECT 'cccccccccccccccccccccccccccccccc',1,id,:property,:date,:date,'web','completed','completed',:now,:now,:now FROM websites WHERE public_id=:website", ['property' => $property, 'date' => gmdate('Y-m-d'), 'now' => $now, 'website' => $website]); $sync = (int) $database->fetchOne("SELECT id FROM search_console_syncs WHERE public_id='cccccccccccccccccccccccccccccccc'")['id'];
        return [$database, new CombinedAnalyticsService($database, $authorization), $website, $keyword, $rankManager, $worker, $property, $sync];
    }

    private function searchRow(Database $database, int $property, int $sync, string $date, string $query, string $device, int $clicks, int $impressions, float $position): void
    {
        $hash = hash('sha256', implode('|', [$date, $query, $device])); $now = gmdate('Y-m-d H:i:s');
        $database->execute("INSERT INTO search_console_data (dimension_hash,website_id,property_id,last_sync_id,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,created_at,updated_at) SELECT :hash,id,:property,:sync,:date,:query,'https://example.com/page',:device,'usa','web',:clicks,:impressions,:ctr,:position,:now,:now FROM websites LIMIT 1", ['hash' => $hash, 'property' => $property, 'sync' => $sync, 'date' => $date, 'query' => $query, 'device' => $device, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $clicks / $impressions, 'position' => $position, 'now' => $now]);
    }
}
