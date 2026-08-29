<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Config\Config;
use App\Core\Installer\SchemaInstaller;
use App\Core\Localization\Translator;
use App\Core\Logging\Logger;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\Keywords\Application\KeywordManager;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\RankTracking\Application\RankDashboardService;
use App\Modules\RankTracking\Presentation\RankChartRenderer;
use App\Modules\RankTracking\Infrastructure\RankTrackingFactory;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use App\Core\Rbac\AuditRecorder;
use PDO;
use Tests\TestCase;

final class RankDashboardTest extends TestCase
{
    public function testCurrentPreviousImprovementBestWorstAndUrl(): void
    {
        [$database, $service, $website, $desktop] = $this->services();
        $this->result($database, $website, $desktop, 10, '2026-08-20 10:00:00', 'https://example.com/old');
        $this->result($database, $website, $desktop, 5, '2026-08-21 10:00:00', 'https://example.com/new');
        $row = $service->dashboard(1, $website['public_id'], range: 'all')['rows'][0];
        $this->assertSame(5, $row['current_position']);
        $this->assertSame(10, $row['previous_position']);
        $this->assertSame(5, $row['change']);
        $this->assertSame('improved', $row['change_state']);
        $this->assertSame(5, $row['best_position']);
        $this->assertSame(10, $row['worst_position']);
        $this->assertSame('https://example.com/new', $row['ranking_url']);
        $this->assertSame('2026-08-21 10:00:00', $row['last_checked']);
    }

    public function testDropUnchangedAndMissingObservations(): void
    {
        [$database, $service, $website, $desktop] = $this->services();
        $this->result($database, $website, $desktop, 5, '2026-08-20 10:00:00');
        $this->result($database, $website, $desktop, 9, '2026-08-21 10:00:00');
        $row = $service->dashboard(1, $website['public_id'], range: 'all')['rows'][0];
        $this->assertSame(-4, $row['change']);
        $this->assertSame('dropped', $row['change_state']);
        $this->result($database, $website, $desktop, 9, '2026-08-22 10:00:00');
        $row = $service->dashboard(1, $website['public_id'], range: 'all')['rows'][0];
        $this->assertSame(0, $row['change']);
        $this->assertSame('unchanged', $row['change_state']);
        $this->result($database, $website, $desktop, null, '2026-08-23 10:00:00');
        $row = $service->dashboard(1, $website['public_id'], range: 'all')['rows'][0];
        $this->assertSame(null, $row['current_position']);
        $this->assertSame('unavailable', $row['change_state']);
        $this->assertSame(5, $row['best_position']);
        $this->assertSame(9, $row['worst_position']);
    }

    public function testDesktopMobileRemainDistinct(): void
    {
        [$database, $service, $website, $desktop, $keywords] = $this->services();
        $mobileId = $keywords->create(1, $website['public_id'], $this->keyword('mobile'));
        $mobile = $database->fetchOne('SELECT * FROM keywords WHERE public_id = :public', ['public' => $mobileId]);
        $this->result($database, $website, $desktop, 3, '2026-08-20 10:00:00', device: 'desktop');
        $this->result($database, $website, $mobile, 18, '2026-08-20 10:05:00', device: 'mobile');
        $rows = $service->dashboard(1, $website['public_id'], range: 'all')['rows'];
        $this->assertSame(2, count($rows));
        foreach ($rows as $row) {
            $this->assertSame(3, $row['desktop_position']);
            $this->assertSame(18, $row['mobile_position']);
        }
    }

    public function testChartIsChronologicalAndRankAxisIsInverted(): void
    {
        [$database, $service, $website, $desktop] = $this->services();
        $this->result($database, $website, $desktop, 20, '2026-08-22 10:00:00');
        $this->result($database, $website, $desktop, 2, '2026-08-20 10:00:00');
        $this->result($database, $website, $desktop, null, '2026-08-21 10:00:00');
        $model = $service->chart(1, $website['public_id'], $desktop['public_id'], range: 'all');
        $this->assertSame(['2026-08-20 10:00:00', '2026-08-21 10:00:00', '2026-08-22 10:00:00'], array_column($model['series']['desktop'], 'observed_at'));
        $this->assertSame(0.0, RankDashboardService::y(1, 100, 300));
        $this->assertSame(300.0, RankDashboardService::y(100, 100, 300));
        $svg = (new RankChartRenderer(new Translator('en', dirname(__DIR__, 2))))->render($model);
        $this->assertTrue(str_contains($svg, 'data-axis-direction="inverted"'));
        $this->assertTrue(str_contains($svg, '#1'));
        $this->assertTrue(str_contains($svg, '#100'));
        $this->assertTrue(str_contains($svg, '2026-08-20 10:00'));
        $this->assertTrue(str_contains($svg, '2026-08-22 10:00'));
        $this->assertSame(2, substr_count($svg, '<polyline'));
    }

    public function testEmptyHistoryAndPermissions(): void
    {
        [$database, $service, $website, $desktop] = $this->services();
        $row = $service->dashboard(1, $website['public_id'], range: 'all')['rows'][0];
        $this->assertSame(null, $row['current_position']);
        $this->assertSame(null, $row['previous_position']);
        $this->assertSame(null, $row['best_position']);
        $this->assertSame([], $service->chart(1, $website['public_id'], $desktop['public_id'], range: 'all')['series']['desktop']);
        $database->execute("INSERT INTO users (name, email, password_hash, email_verified_at, disabled_at, created_at, updated_at) VALUES ('No Access','none@example.com','unused',NULL,NULL,'2026-01-01','2026-01-01')");
        $other = (int) $database->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => 'none@example.com'])['id'];
        $denied = false;
        try { $service->dashboard($other, $website['public_id']); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
        $database->execute("INSERT INTO roles (role_key,name,created_at,updated_at) VALUES ('rank-dashboard-viewer','Rank dashboard viewer','2026-01-01','2026-01-01')");
        $role = $database->fetchOne("SELECT id FROM roles WHERE role_key = 'rank-dashboard-viewer'")['id'];
        $permission = $database->fetchOne("SELECT id FROM permissions WHERE permission_key = 'rank_tracking.view'")['id'];
        $database->execute("INSERT INTO role_permissions (role_id,permission_id,assigned_at) VALUES (:role,:permission,'2026-01-01')", ['role' => $role, 'permission' => $permission]);
        $database->execute("INSERT INTO user_roles (user_id,role_id,assigned_at) VALUES (:user,:role,'2026-01-01')", ['user' => $other, 'role' => $role]);
        $isolated = false;
        try { $service->dashboard($other, $website['public_id']); } catch (\InvalidArgumentException) { $isolated = true; }
        $this->assertTrue($isolated);
    }

    public function testPersianCatalogIsAvailable(): void
    {
        $translator = new Translator('fa', dirname(__DIR__, 2));
        $this->assertSame('fa', $translator->locale());
        $this->assertTrue($translator->get('dashboard') !== 'dashboard');
        $factory = new RankTrackingFactory(dirname(__DIR__, 2), new Config(['app' => ['locale' => 'fa', 'rtl_locales' => ['fa']]]));
        $this->assertTrue($factory->isRtl());
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '1.0.0', 8, new Logger(sys_get_temp_dir() . '/seo-dashboard-migration.log')))->run();
        $authorization = new Authorization($database); $audit = new AuditRecorder($database);
        $websites = new WebsiteManager($database, $authorization, $audit); $keywords = new KeywordManager($database, $authorization, $audit);
        $websiteId = $websites->create(1, WebsiteInput::from('Dashboard', 'https://example.com', ''));
        $desktopId = $keywords->create(1, $websiteId, $this->keyword('desktop'));
        return [$database, new RankDashboardService($database, $authorization), $database->fetchOne('SELECT * FROM websites WHERE public_id = :public', ['public' => $websiteId]), $database->fetchOne('SELECT * FROM keywords WHERE public_id = :public', ['public' => $desktopId]), $keywords];
    }

    private function keyword(string $device): KeywordInput
    {
        return KeywordInput::from(['keyword' => 'seo tracker', 'target_url' => 'https://example.com', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => $device, 'active' => true], ['google'], ['desktop', 'mobile'], ['US']);
    }

    private function result(Database $database, array $website, array $keyword, ?int $position, string $observed, ?string $url = 'https://example.com', string $device = 'desktop'): void
    {
        $public = bin2hex(random_bytes(16)); $attempt = bin2hex(random_bytes(16)); $result = bin2hex(random_bytes(16));
        $database->execute("INSERT INTO rank_check_requests (public_id,user_id,website_id,keyword_id,keyword_text,target_url,search_engine,country_code,language_code,requested_device,execution_source,adapter_key,status,attempt_count,available_at,created_at,started_at,completed_at,error_code,error_detail) VALUES (:public,1,:website,:keyword,'seo tracker','https://example.com','google','US','en',:device,'provider_api','fixture','completed',1,:at,:at,:at,:at,NULL,NULL)", ['public' => $public, 'website' => $website['id'], 'keyword' => $keyword['id'], 'device' => $device, 'at' => $observed]);
        $requestId = $database->fetchOne('SELECT id FROM rank_check_requests WHERE public_id = :public', ['public' => $public])['id'];
        $database->execute("INSERT INTO rank_execution_attempts (public_id,request_id,attempt_number,execution_source,adapter_key,adapter_version,requested_device,execution_device,user_agent_profile,network_context,status,leased_by,lease_token_hash,lease_expires_at,started_at,completed_at,error_code,error_detail,retryable) VALUES (:public,:request,1,'provider_api','fixture','1.0.0',:device,:execution,'fixture:1','provider_egress','succeeded','test',:hash,:at,:at,:at,NULL,NULL,0)", ['public' => $attempt, 'request' => $requestId, 'device' => $device, 'execution' => 'provider_' . $device, 'hash' => str_repeat('a', 64), 'at' => $observed]);
        $attemptId = $database->fetchOne('SELECT id FROM rank_execution_attempts WHERE public_id = :public', ['public' => $attempt])['id'];
        $type = $position === null ? 'not_found' : 'ranked'; if ($position === null) $url = null;
        $database->execute("INSERT INTO rank_results (public_id,request_id,attempt_id,website_id,keyword_id,result_type,position,ranking_url,checked_depth,search_engine,country_code,language_code,requested_device,execution_device,execution_source,adapter_key,adapter_version,observed_at,created_at) VALUES (:public,:request,:attempt,:website,:keyword,:type,:position,:url,100,'google','US','en',:device,:execution,'provider_api','fixture','1.0.0',:at,:at)", ['public' => $result, 'request' => $requestId, 'attempt' => $attemptId, 'website' => $website['id'], 'keyword' => $keyword['id'], 'type' => $type, 'position' => $position, 'url' => $url, 'device' => $device, 'execution' => 'provider_' . $device, 'at' => $observed]);
    }
}
