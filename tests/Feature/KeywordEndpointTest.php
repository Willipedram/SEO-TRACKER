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
use App\Modules\Keywords\Application\KeywordManager;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\Keywords\Infrastructure\KeywordFactory;
use App\Modules\Keywords\Presentation\KeywordController;
use App\Modules\RankTracking\Infrastructure\RankTrackingFactory;
use App\Modules\RankTracking\Presentation\RankTrackingController;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\TestCase;

final class KeywordEndpointTest extends TestCase
{
    public function testKeywordListAndDirectPermissionDenial(): void
    {
        $base = dirname(__DIR__, 2);
        $path = sys_get_temp_dir() . '/seo-keyword-endpoint-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery($base . '/database/migrations'), '2.4.0', 14, new Logger(sys_get_temp_dir() . '/seo-keyword-endpoint-migration.log')))->run();
        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        $website = (new WebsiteManager($database, $authorization, $audit))->create(1, WebsiteInput::from('Site', 'https://example.com', ''));
        $keyword = (new KeywordManager($database, $authorization, $audit))->create(1, $website, KeywordInput::from(['keyword' => '<script>safe</script>', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'desktop', 'active' => true], ['google', 'bing'], ['desktop', 'mobile'], ['US']));
        $_SESSION['auth'] = ['user_id' => 1, 'authenticated_at' => time(), 'last_activity' => time()];
        $config = new Config([
            'app' => ['key' => 'test-key', 'locale' => 'fa', 'rtl_locales' => ['fa']], 'auth' => [], 'keywords' => ['search_engines' => ['google', 'bing'], 'devices' => ['desktop', 'mobile'], 'countries' => ['US']],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]]],
            'logging' => ['path' => sys_get_temp_dir() . '/seo-keyword-endpoint.log', 'level' => 'error'],
        ]);
        $controller = new KeywordController(new KeywordFactory($base, $config));
        $selection = $controller->index(new Request('GET', '/keywords'));
        $this->assertSame(200, $selection->status);
        $this->assertTrue(str_contains($selection->body, 'Select a website'));
        $this->assertTrue(str_contains($selection->body, '/keywords?website=' . $website));
        $this->assertTrue(str_contains($selection->body, 'example.com'));
        $response = $controller->index(new Request('GET', '/keywords', ['website' => $website]));
        $this->assertSame(200, $response->status);
        $this->assertTrue(str_contains($response->body, '&lt;script&gt;safe&lt;/script&gt;'));
        $this->assertTrue(!str_contains($response->body, '<script>safe</script>'));
        $edit = $controller->editForm(new Request('GET', '/keywords/edit', ['website' => $website, 'id' => $keyword]));
        $this->assertTrue(str_contains($edit->body, '/rank-dashboard?website=' . $website . '&keyword=' . $keyword));
        $this->assertTrue(!str_contains($edit->body, 'action="/rank-checks"'));
        $rankController = new RankTrackingController(new RankTrackingFactory($base, $config));
        $unavailableSubmission = $rankController->submit(new Request('POST', '/rank-checks', body: ['website' => $website, 'keyword' => $keyword]));
        $this->assertSame(303, $unavailableSubmission->status);
        $this->assertSame('/rank-dashboard?website=' . $website . '&keyword=' . $keyword, $unavailableSubmission->headers['Location']);
        $rankSelection = $rankController->dashboard(new Request('GET', '/rank-dashboard'));
        $this->assertSame(200, $rankSelection->status);
        $this->assertTrue(str_contains($rankSelection->body, 'انتخاب وب‌سایت'));
        $this->assertTrue(str_contains($rankSelection->body, '/rank-dashboard?website=' . $website));
        $rankDashboard = $rankController->dashboard(new Request('GET', '/rank-dashboard', ['website'=>$website]));
        $this->assertSame(200, $rankDashboard->status);
        $this->assertTrue(str_contains($rankDashboard->body, 'رتبه‌یابی با IP کاربر فعال است.'));
        $this->assertTrue(str_contains($rankDashboard->body, 'class="btn btn-sm btn-primary manual-rank-start"'));
        $this->assertTrue(str_contains($rankDashboard->body, 'id="manual-rank-modal"'));
        $this->assertTrue(!str_contains($rankDashboard->body, 'action="/rank-checks"'));
        $manual = $rankController->recordManual(new Request('POST', '/rank-checks/manual', body: ['website'=>$website,'keyword'=>$keyword,'position'=>'8','ranking_url'=>'https://example.com/manual-result']));
        $this->assertSame(303, $manual->status);
        $this->assertSame('/rank-dashboard?website='.$website.'&keyword='.$keyword, $manual->headers['Location']);
        $database->execute("INSERT INTO users (name, email, password_hash, email_verified_at, disabled_at, created_at, updated_at) VALUES ('No Access', 'none@example.com', 'unused', NULL, NULL, '2026-01-01', '2026-01-01')");
        $limited = (int) $database->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => 'none@example.com'])['id'];
        $_SESSION['auth'] = ['user_id' => $limited, 'authenticated_at' => time(), 'last_activity' => time()];
        $this->assertSame(403, $controller->index(new Request('GET', '/keywords', ['website' => $website]))->status);
        $this->assertSame(403, $controller->create(new Request('POST', '/keywords/create', body: ['website' => $website, 'keyword' => 'blocked', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'desktop', 'active' => '1']))->status);
        $_SESSION = [];
        unlink($path);
    }
}
