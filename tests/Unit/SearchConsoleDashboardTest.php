<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\SearchConsole\Application\SearchConsoleDashboardService;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\TestCase;

final class SearchConsoleDashboardTest extends TestCase
{
    public function testMetricsFiltersBreakdownsAndWeightedPosition(): void
    {
        [$database, $dashboard, $website, $property, $sync] = $this->services();
        $date = gmdate('Y-m-d', time() - 86400);
        $this->row($database, $property, $sync, $date, 'alpha', '/a', 'desktop', 'usa', 'web', 10, 100, 3.0);
        $this->row($database, $property, $sync, $date, 'beta', '/b', 'mobile', 'gbr', 'web', 5, 50, 7.0);

        $model = $dashboard->dashboard(1, $website, ['start_date' => $date, 'end_date' => $date]);
        $this->assertSame('ready', $model['state']); $this->assertSame(15, $model['metrics']['clicks']);
        $this->assertSame(150, $model['metrics']['impressions']); $this->assertSame(0.1, $model['metrics']['ctr']);
        $this->assertSame(4.333333333333333, $model['metrics']['average_position']);
        $this->assertSame(2, $model['metrics']['queries']); $this->assertSame(2, $model['metrics']['pages']);
        $this->assertSame(['alpha', 'beta'], array_column($model['queries'], 'dimension'));
        $this->assertSame(['desktop', 'mobile'], array_column($model['devices'], 'dimension'));
        $filtered = $dashboard->dashboard(1, $website, ['start_date' => $date, 'end_date' => $date, 'query' => 'bet', 'device' => 'mobile', 'country' => 'gbr', 'search_type' => 'web', 'page' => 'https://example.com/b']);
        $this->assertSame(5, $filtered['metrics']['clicks']); $this->assertSame(['beta'], array_column($filtered['queries'], 'dimension'));
    }

    public function testTrendOrderingAndPreviousPeriodChanges(): void
    {
        [$database, $dashboard, $website, $property, $sync] = $this->services();
        $currentStart = gmdate('Y-m-d', time() - 2 * 86400); $currentEnd = gmdate('Y-m-d', time() - 86400);
        $previousStart = gmdate('Y-m-d', time() - 4 * 86400); $previousEnd = gmdate('Y-m-d', time() - 3 * 86400);
        $this->row($database, $property, $sync, $previousStart, 'alpha', '/a', 'desktop', 'usa', 'web', 1, 20, 8.0);
        $this->row($database, $property, $sync, $previousEnd, 'alpha', '/a', 'desktop', 'usa', 'web', 1, 20, 8.0);
        $this->row($database, $property, $sync, $currentEnd, 'alpha', '/a', 'desktop', 'usa', 'web', 5, 50, 4.0);
        $this->row($database, $property, $sync, $currentStart, 'alpha', '/a', 'desktop', 'usa', 'web', 5, 50, 4.0);
        $model = $dashboard->dashboard(1, $website, ['start_date' => $currentStart, 'end_date' => $currentEnd]);
        $this->assertSame([$currentStart, $currentEnd], array_column($model['trend'], 'data_date'));
        $this->assertSame(8.0, $model['metrics']['changes']['clicks']);
        $this->assertSame(-4.0, $model['metrics']['changes']['average_position']);
    }

    public function testEmptyFailedDisconnectedDisabledAndAuthorizationStates(): void
    {
        [$database, $dashboard, $website, $property, $sync] = $this->services(); $date = gmdate('Y-m-d', time() - 86400);
        $database->execute("UPDATE search_console_syncs SET status='failed',phase='failed',error_code='api_error',completed_at=:now WHERE id=:id", ['now' => gmdate('Y-m-d H:i:s'), 'id' => $sync]);
        $failed = $dashboard->dashboard(1, $website, ['start_date' => $date, 'end_date' => $date]);
        $this->assertSame('no_data', $failed['state']); $this->assertSame('failed', $failed['latest_sync']['status']);
        $database->execute('DELETE FROM search_console_syncs');
        $this->assertSame('never_synced', $dashboard->dashboard(1, $website, ['start_date' => $date, 'end_date' => $date])['state']);
        $database->execute("UPDATE search_console_connections SET status='revoked'");
        $this->assertSame('authorization_expired', $dashboard->dashboard(1, $website, ['start_date' => $date, 'end_date' => $date])['state']);
        $database->execute("UPDATE modules SET enabled=0 WHERE module_key='search_console'");
        $this->assertSame('module_disabled', $dashboard->dashboard(1, $website, ['start_date' => $date, 'end_date' => $date])['state']);
        $database->execute("INSERT INTO users (name,email,password_hash,created_at,updated_at) VALUES ('Viewer','viewer@example.test','x',:now,:now)", ['now' => gmdate('Y-m-d H:i:s')]);
        $denied = false; try { $dashboard->dashboard(2, $website, ['start_date' => $date, 'end_date' => $date]); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.test', 'correct-horse-battery', 'Tracker'); $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '1.4.0', 12, new Logger(sys_get_temp_dir() . '/search-console-dashboard-migration.log')))->run();
        $database->execute("UPDATE modules SET enabled=1 WHERE module_key='search_console'");
        $website = (new WebsiteManager($database, new Authorization($database), new AuditRecorder($database)))->create(1, WebsiteInput::from('Example', 'https://example.com', ''));
        $now = gmdate('Y-m-d H:i:s');
        $database->execute("INSERT INTO search_console_connections (public_id,user_id,status,granted_scopes,created_at,updated_at) VALUES ('11111111111111111111111111111111',1,'connected','scope',:now,:now)", ['now' => $now]); $connection = (int) $database->fetchOne("SELECT id FROM search_console_connections WHERE public_id='11111111111111111111111111111111'")['id'];
        $database->execute('INSERT INTO search_console_connection_contexts (connection_id,website_id,created_at) SELECT :connection,id,:now FROM websites WHERE public_id=:website', ['connection' => $connection, 'now' => $now, 'website' => $website]);
        $database->execute("INSERT INTO search_console_properties (public_id,connection_id,website_id,property_uri,property_type,permission_level,selected,created_at,updated_at) SELECT '22222222222222222222222222222222',:connection,id,'sc-domain:example.com','domain','siteOwner',1,:now,:now FROM websites WHERE public_id=:website", ['connection' => $connection, 'now' => $now, 'website' => $website]); $property = (int) $database->fetchOne("SELECT id FROM search_console_properties WHERE public_id='22222222222222222222222222222222'")['id'];
        $date = gmdate('Y-m-d', time() - 86400);
        $database->execute("INSERT INTO search_console_syncs (public_id,user_id,website_id,property_id,start_date,end_date,search_type,status,phase,available_at,created_at,updated_at) SELECT '33333333333333333333333333333333',1,id,:property,:date,:date,'web','completed','completed',:now,:now,:now FROM websites WHERE public_id=:website", ['property' => $property, 'date' => $date, 'now' => $now, 'website' => $website]);
        $sync = (int) $database->fetchOne("SELECT id FROM search_console_syncs WHERE public_id='33333333333333333333333333333333'")['id'];
        return [$database, new SearchConsoleDashboardService($database, new Authorization($database)), $website, $property, $sync];
    }

    private function row(Database $database, int $property, int $sync, string $date, string $query, string $path, string $device, string $country, string $type, int $clicks, int $impressions, float $position): void
    {
        $hash = hash('sha256', implode('|', [$date, $query, $path, $device, $country, $type]));
        $database->execute('INSERT INTO search_console_data (dimension_hash,website_id,property_id,last_sync_id,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,created_at,updated_at) SELECT :hash,id,:property,:sync,:date,:query,:page,:device,:country,:type,:clicks,:impressions,:ctr,:position,:now,:now FROM websites LIMIT 1', ['hash' => $hash, 'property' => $property, 'sync' => $sync, 'date' => $date, 'query' => $query, 'page' => 'https://example.com' . $path, 'device' => $device, 'country' => $country, 'type' => $type, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $clicks / $impressions, 'position' => $position, 'now' => gmdate('Y-m-d H:i:s')]);
    }
}
