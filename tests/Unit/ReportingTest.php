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
use App\Modules\Keywords\Application\KeywordManager;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\RankTracking\Application\RankCheckManager;
use App\Modules\RankTracking\Application\RankWorker;
use App\Modules\RankTracking\Domain\RankAdapter;
use App\Modules\RankTracking\Domain\RankExecutionResult;
use App\Modules\RankTracking\Domain\RankJob;
use App\Modules\RankTracking\Infrastructure\RankAdapterRegistry;
use App\Modules\Reports\Application\ReportService;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\TestCase;

final class ReportingAdapter implements RankAdapter
{
    public function __construct(private array $positions) {}
    public function key(): string { return 'report_test'; } public function version(): string { return '1.0.0'; } public function executionSource(): string { return 'provider_api'; }
    public function supportsExecutionDevice(string $requestedDevice, string $executionDevice): bool { return $requestedDevice === $executionDevice; }
    public function execute(RankJob $job): RankExecutionResult { $position = (int) array_shift($this->positions); return new RankExecutionResult('ranked', $position, 'https://example.com/' . $position, 100, $job->requestedDevice, 'report:test', gmdate('Y-m-d H:i:s')); }
}

final class ReportingTest extends TestCase
{
    public function testWebsiteKeywordRankingAndClassificationReports(): void
    {
        [$database, $reports, $website, $keyword, $manager, $worker] = $this->services([8, 3]); $manager->submit(1, $website, $keyword); $worker->work(1); $manager->submit(1, $website, $keyword); $worker->work(1);
        foreach (['website','keyword','ranking','improved','top10','top3'] as $type) $this->assertTrue($reports->report(1, $type)['rows'] !== []);
        $this->assertSame([], $reports->report(1, 'dropped')['rows']); $this->assertSame([], $reports->report(1, 'number1')['rows']);
        $database->execute('UPDATE rank_results SET position=1 WHERE id=(SELECT MAX(id) FROM rank_results)'); $this->assertSame(1, $reports->report(1, 'number1')['total']);
    }

    public function testDroppedDateDeviceAndWebsiteFilters(): void
    {
        [, $reports, $website, $keyword, $manager, $worker] = $this->services([2, 9]); $manager->submit(1, $website, $keyword); $worker->work(1); $manager->submit(1, $website, $keyword); $worker->work(1);
        $this->assertSame(1, $reports->report(1, 'dropped', ['website' => $website, 'keyword' => $keyword, 'device' => 'desktop'])['total']);
        $future = gmdate('Y-m-d', time() + 86400); $this->assertSame(0, $reports->report(1, 'ranking', ['start_date' => $future, 'end_date' => $future])['total']);
        $this->assertSame(0, $reports->report(1, 'keyword', ['device' => 'mobile'])['total']);
    }

    public function testSearchConsoleReportAndDisabledStateKeepSemantics(): void
    {
        [$database, $reports, $website, , , , $property, $sync] = $this->services([]); $this->searchRow($database, $property, $sync, '=query', 'mobile');
        $report = $reports->report(1, 'search_console', ['website' => $website, 'device' => 'mobile', 'country' => 'usa', 'search_type' => 'web']);
        $this->assertSame('search_console', $report['source']); $this->assertSame(5.5, (float) $report['rows'][0]['search_console_average_position']);
        $database->execute("UPDATE modules SET enabled=0 WHERE module_key='search_console'"); $disabled = $reports->report(1, 'search_console'); $this->assertSame('module_disabled', $disabled['state']);
        $this->assertSame('ready', $reports->report(1, 'website')['state']);
    }

    public function testCsvIsUtf8PaginatedAndFormulaSafe(): void
    {
        [$database, $reports] = $this->services([]); $database->execute("UPDATE keywords SET keyword_text='=HYPERLINK(\"https://evil.test\")'"); $csv = $reports->csv(1, 'keyword');
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF")); $this->assertTrue(str_contains($csv, "'=HYPERLINK"));
        for ($i=0; $i<105; $i++) $database->execute('INSERT INTO websites (public_id,owner_user_id,site_name,normalized_domain,canonical_url,protocol,description,timezone,status,created_at,updated_at) VALUES (:public,1,:name,:domain,:url,\'https\',\'\',\'UTC\',\'active\',:now,:now)', ['public' => bin2hex(random_bytes(16)), 'name' => 'Site ' . $i, 'domain' => 'site' . $i . '.test', 'url' => 'https://site' . $i . '.test', 'now' => gmdate('Y-m-d H:i:s')]);
        $page = $reports->report(1, 'website', ['per_page' => 25, 'page' => 2]); $this->assertSame(25, count($page['rows'])); $this->assertTrue($page['pages'] >= 5);
    }

    public function testPermissionsAndWebsiteIsolation(): void
    {
        [$database, $reports, $website] = $this->services([]); $database->execute("INSERT INTO users (name,email,password_hash,created_at,updated_at) VALUES ('Other','other@example.test','x',:now,:now)", ['now' => gmdate('Y-m-d H:i:s')]);
        $denied = false; try { $reports->report(2, 'website'); } catch (AuthorizationException) { $denied = true; } $this->assertTrue($denied);
        $invalid = false; try { $reports->report(1, 'website', ['website' => str_repeat('f', 32)]); } catch (\InvalidArgumentException) { $invalid = true; } $this->assertTrue($invalid);
        $this->assertSame(1, $reports->report(1, 'website', ['website' => $website])['total']);
    }

    private function services(array $positions): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); (new SchemaInstaller())->install($pdo,'Administrator','admin@example.test','correct-horse-battery','Tracker'); $database = new Database($pdo); (new MigrationRunner($database,new MigrationDiscovery(dirname(__DIR__,2).'/database/migrations'),'1.7.0',13,new Logger(sys_get_temp_dir().'/report-migration.log')))->run(); $database->execute("UPDATE modules SET enabled=1 WHERE module_key='search_console'"); $auth = new Authorization($database); $audit = new AuditRecorder($database); $website=(new WebsiteManager($database,$auth,$audit))->create(1,WebsiteInput::from('Example','https://example.com','')); $keyword=(new KeywordManager($database,$auth,$audit))->create(1,$website,KeywordInput::from(['keyword'=>'seo report','target_url'=>'','search_engine'=>'google','country'=>'US','language'=>'en','device'=>'desktop','active'=>true],['google'],['desktop','mobile'],['US'])); $adapter=new ReportingAdapter($positions); $registry=new RankAdapterRegistry([$adapter]); $manager=new RankCheckManager($database,$auth,$audit,$registry,$adapter->key(),20,900); $worker=new RankWorker($database,$registry,new Logger(sys_get_temp_dir().'/report-worker.log'),3,120);
        $now=gmdate('Y-m-d H:i:s'); $database->execute("INSERT INTO search_console_connections (public_id,user_id,status,granted_scopes,created_at,updated_at) VALUES ('dddddddddddddddddddddddddddddddd',1,'connected','scope',:now,:now)",['now'=>$now]); $connection=(int)$database->fetchOne("SELECT id FROM search_console_connections WHERE public_id='dddddddddddddddddddddddddddddddd'")['id']; $database->execute("INSERT INTO search_console_properties (public_id,connection_id,website_id,property_uri,property_type,permission_level,selected,created_at,updated_at) SELECT 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',:connection,id,'sc-domain:example.com','domain','siteOwner',1,:now,:now FROM websites WHERE public_id=:website",['connection'=>$connection,'now'=>$now,'website'=>$website]); $property=(int)$database->fetchOne("SELECT id FROM search_console_properties WHERE public_id='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'")['id']; $database->execute("INSERT INTO search_console_syncs (public_id,user_id,website_id,property_id,start_date,end_date,search_type,status,phase,available_at,created_at,updated_at) SELECT 'ffffffffffffffffffffffffffffffff',1,id,:property,:date,:date,'web','completed','completed',:now,:now,:now FROM websites WHERE public_id=:website",['property'=>$property,'date'=>gmdate('Y-m-d'),'now'=>$now,'website'=>$website]); $sync=(int)$database->fetchOne("SELECT id FROM search_console_syncs WHERE public_id='ffffffffffffffffffffffffffffffff'")['id']; return [$database,new ReportService($database,$auth),$website,$keyword,$manager,$worker,$property,$sync];
    }
    private function searchRow(Database $db,int $property,int $sync,string $query,string $device): void { $now=gmdate('Y-m-d H:i:s'); $db->execute("INSERT INTO search_console_data (dimension_hash,website_id,property_id,last_sync_id,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,created_at,updated_at) SELECT :hash,id,:property,:sync,:date,:query,'https://example.com/page',:device,'usa','web',5,20,.25,5.5,:now,:now FROM websites LIMIT 1",['hash'=>hash('sha256',$query.$device),'property'=>$property,'sync'=>$sync,'date'=>gmdate('Y-m-d'),'query'=>$query,'device'=>$device,'now'=>$now]); }
}
