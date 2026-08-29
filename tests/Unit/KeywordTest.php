<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth\PasswordHasher;
use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\UserManager;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\Keywords\Application\KeywordManager;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

final class KeywordTest extends TestCase
{
    private const ENGINES = ['google', 'bing'];
    private const DEVICES = ['desktop', 'mobile'];
    private const COUNTRIES = ['US', 'GB'];

    public function testCreateEditTargetDevicesStatusAndDelete(): void
    {
        [$database, $keywords, $websites] = $this->services();
        $website = $websites->create(1, WebsiteInput::from('Example', 'https://example.com', ''));
        $desktop = $keywords->create(1, $website, $this->input('SEO Tracker', 'desktop', ['target_url' => 'https://example.com/products?q=seo']));
        $mobile = $keywords->create(1, $website, $this->input('SEO Tracker', 'mobile'));
        $this->assertSame(2, count($keywords->list(1, $website)));
        $this->assertSame('https://example.com/products?q=seo', $keywords->find(1, $website, $desktop)['target_url']);
        $keywords->update(1, $website, $desktop, $this->input('SEO Platform', 'desktop', ['search_engine' => 'bing', 'country' => 'GB', 'language' => 'en-gb', 'active' => false]));
        $edited = $keywords->find(1, $website, $desktop);
        $this->assertSame('bing', $edited['search_engine']);
        $this->assertSame(0, (int) $edited['active']);
        $keywords->setActive(1, $website, $desktop, true);
        $this->assertSame(1, (int) $keywords->find(1, $website, $desktop)['active']);
        $keywords->delete(1, $website, $mobile);
        $this->assertSame(1, count($keywords->list(1, $website)));
        $this->assertTrue((int) $database->fetchOne("SELECT COUNT(*) AS total FROM audit_logs WHERE target_type = 'keyword'")['total'] >= 5);
    }

    public function testRejectsInvalidSearchConfigurationAndDuplicates(): void
    {
        [, $keywords, $websites] = $this->services();
        $website = $websites->create(1, WebsiteInput::from('Example', 'https://example.com', ''));
        foreach ([
            ['keyword' => '', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'desktop'],
            ['keyword' => 'term', 'search_engine' => 'ask', 'country' => 'US', 'language' => 'en', 'device' => 'desktop'],
            ['keyword' => 'term', 'search_engine' => 'google', 'country' => 'USA', 'language' => 'en', 'device' => 'desktop'],
            ['keyword' => 'term', 'search_engine' => 'google', 'country' => 'US', 'language' => '../en', 'device' => 'desktop'],
            ['keyword' => 'term', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'tablet'],
            ['keyword' => 'term', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => 'desktop', 'target_url' => 'javascript:alert(1)'],
        ] as $values) {
            $failed = false;
            try { KeywordInput::from($values + ['active' => true], self::ENGINES, self::DEVICES, self::COUNTRIES); } catch (InvalidArgumentException) { $failed = true; }
            $this->assertTrue($failed);
        }
        $keywords->create(1, $website, $this->input('  Local   SEO  ', 'desktop'));
        $failed = false;
        try { $keywords->create(1, $website, $this->input('local seo', 'desktop')); } catch (InvalidArgumentException) { $failed = true; }
        $this->assertTrue($failed);
    }

    public function testPermissionsAndWebsiteIsolationBlockIdor(): void
    {
        [$database, $keywords, $websites, $users, $roles] = $this->services();
        $website = $websites->create(1, WebsiteInput::from('Admin', 'https://admin.example.com', ''));
        $keyword = $keywords->create(1, $website, $this->input('private term', 'desktop'));
        $other = $users->create(1, 'Other', 'other@example.com', 'correct-horse-battery');
        $denied = false;
        try { $keywords->list($other, $website); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
        $role = $roles->create(1, 'keyword-manager', 'Keyword manager');
        $permissionIds = array_column($database->fetchAll("SELECT id FROM permissions WHERE permission_key LIKE 'keywords.%' OR permission_key LIKE 'websites.%'"), 'id');
        $roles->assignPermissions(1, $role, $permissionIds);
        $roles->assignRoles(1, $other, [$role]);
        $notFound = false;
        try { $keywords->find($other, $website, $keyword); } catch (InvalidArgumentException) { $notFound = true; }
        $this->assertTrue($notFound);
        $otherWebsite = $websites->create($other, WebsiteInput::from('Other', 'https://other.example.com', ''));
        $otherKeyword = $keywords->create($other, $otherWebsite, $this->input('other term', 'mobile'));
        $notFound = false;
        try { $keywords->delete($other, $otherWebsite, $keyword); } catch (InvalidArgumentException) { $notFound = true; }
        $this->assertTrue($notFound);
        $this->assertSame('other term', $keywords->find($other, $otherWebsite, $otherKeyword)['keyword_text']);
    }

    private function input(string $keyword, string $device, array $overrides = []): KeywordInput
    {
        return KeywordInput::from($overrides + ['keyword' => $keyword, 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => $device, 'active' => true], self::ENGINES, self::DEVICES, self::COUNTRIES);
    }

    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '0.8.0', 6, new Logger(sys_get_temp_dir() . '/seo-keywords-migration.log')))->run();
        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        return [$database, new KeywordManager($database, $authorization, $audit), new WebsiteManager($database, $authorization, $audit), new UserManager($database, $authorization, $audit, new PasswordHasher()), new RoleManager($database, $authorization, $audit)];
    }
}
