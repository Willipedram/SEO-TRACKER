<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Localization\UiLocalizer;
use App\Core\Localization\Terminology;
use App\Core\Http\Response;
use App\Core\Security\SecurityHeaders;
use Tests\TestCase;

final class AdminLteUiTest extends TestCase
{
    public function testStandaloneLoginUsesPinnedAdminLtePersianRtlLayout(): void
    {
        $html = (new UiLocalizer('fa', dirname(__DIR__, 2)))->html(
            '<!doctype html><html lang="en"><head><title>Sign in — SEO Tracker</title></head><body><main><h1>Sign in</h1><form><label>Email<input type="email"></label><button>Sign in</button></form></main></body></html>',
            '/login',
        );
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (['lang="fa"', 'dir="rtl"', 'https://cdn.jsdelivr.net/npm/admin-lte@4.9.1/', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/', 'seo-auth-card', 'ورود به حساب'] as $required) {
            $this->assertTrue(str_contains($decoded, $required), 'Missing login UI contract: '.$required);
        }
        foreach (['@latest', 'admin@example.com', 'Demo', 'Messages', 'Notifications'] as $forbidden) $this->assertTrue(!str_contains($html, $forbidden));
        $this->assertTrue(preg_match('/admin-lte@(?:[^"\/]*)?(?:rc|alpha|beta)/i', $html) !== 1, 'A prerelease AdminLTE build was loaded.');
        $this->assertTrue(substr_count($html, 'bootstrap@5.3.8/') === 1, 'Bootstrap must be loaded exactly once.');
    }

    public function testAuthenticatedSidebarIsPermissionAndModuleAware(): void
    {
        $context = ['authenticated'=>true,'user'=>['name'=>'کاربر آزمون'],'version'=>'2.4.0','modules'=>['websites','keywords','settings'],'permissions'=>['websites.view','keywords.view']];
        $html = (new UiLocalizer('fa', dirname(__DIR__, 2)))->html('<!doctype html><html><head><title>Websites</title></head><body><main><h1>Websites</h1><p class="empty-state">No websites</p></main></body></html>', '/websites', $context);
        foreach (['app-wrapper','app-sidebar','data-lte-toggle="sidebar"','href="/websites"','href="/keywords"','nav-link active','empty-state'] as $required) $this->assertTrue(str_contains($html,$required), 'Missing shell contract: '.$required);
        foreach (['href="/admin/users"','href="/admin/roles"','search-console/dashboard','href="#"'] as $forbidden) $this->assertTrue(!str_contains($html,$forbidden), 'Unauthorized/dead menu leaked: '.$forbidden);

        $context['base_url']='/seotrack';
        $mounted=(new UiLocalizer('fa',dirname(__DIR__,2)))->html('<!doctype html><html><head><title>Keywords</title></head><body><main><h1>Keywords</h1></main></body></html>','/keywords',$context);
        foreach(['href="/seotrack/account"','href="/seotrack/websites"','href="/seotrack/keywords"','action="/seotrack/logout"'] as $required)$this->assertTrue(str_contains($mounted,$required),'Mount prefix missing: '.$required);
        $this->assertTrue(!str_contains($mounted,'/seotrack/seotrack/'));
    }

    public function testApplicationStylesCoverResponsiveRtlAndReducedMotion(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/phase27.css');
        foreach (['font-family:IRANSans','local("IRANSansX")','html[dir=rtl]','html,body{max-width:100%;overflow-x:hidden}','width:min(calc(100% - 2rem),560px)','.seo-table-responsive','@media(max-width:991.98px)','@media(max-width:575.98px)','prefers-reduced-motion','.rank-chart','.table-scroll','.installer-choice-grid'] as $required) $this->assertTrue(str_contains($css,$required));
    }

    public function testCdnAndInlineAssetsHaveStrictCspCoverage(): void
    {
        $html = (new UiLocalizer('fa', dirname(__DIR__, 2)))->html('<!doctype html><html><head><title>Sign in</title></head><body><main><h1>Sign in</h1></main></body></html>', '/login');
        $secured = SecurityHeaders::apply(new Response($html, 200, ['Content-Type'=>'text/html; charset=utf-8','Content-Security-Policy'=>"default-src 'self'; connect-src 'self'"]), 'phase27-csp-test');
        $policy = $secured->headers['Content-Security-Policy'];
        foreach (["script-src 'self' https://cdn.jsdelivr.net", "style-src 'self' https://cdn.jsdelivr.net", "font-src 'self' https://cdn.jsdelivr.net", "connect-src 'self' https://cdn.jsdelivr.net", "'sha256-"] as $required) $this->assertTrue(str_contains($policy,$required));
        $this->assertSame('phase27-jsdelivr-connect-v2', $secured->headers['X-SEO-CSP-Version']);
        $this->assertTrue($policy !== "default-src 'self'; connect-src 'self'");
        foreach (["'unsafe-inline'", "'unsafe-eval'"] as $forbidden) $this->assertTrue(!str_contains($policy,$forbidden));
    }

    public function testPhase26TechnicalTermsRemainEnglishLtrTooltips(): void
    {
        $terms = (new Terminology('fa', dirname(__DIR__, 2)))->all();
        foreach (['rank_tracker_position','average_position','property','ctr','impressions','sync','permission','migration','job_status'] as $key) {
            $this->assertTrue(isset($terms[$key]), 'Missing technical term: '.$key);
            $html=(new UiLocalizer('fa',dirname(__DIR__,2)))->html('<!doctype html><html><head><title>Terms</title></head><body><main><h1>'.htmlspecialchars($terms[$key]['label'],ENT_QUOTES,'UTF-8').'</h1></main></body></html>','/login');
            $decoded=html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8');
            $this->assertTrue(str_contains($decoded,(string)$terms[$key]['term']));
            $this->assertTrue(str_contains($decoded,'role="tooltip"')&&str_contains($decoded,'dir="ltr"'));
        }
    }

    public function testUiSourcesContainNoDeadOrDemoNavigation(): void
    {
        $paths=[dirname(__DIR__,2).'/app',dirname(__DIR__,2).'/routes',dirname(__DIR__,2).'/public'];
        $source=''; foreach($paths as $path){$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS));foreach($iterator as $file)if($file->isFile())$source.=(string)file_get_contents($file->getPathname());}
        foreach (['href="#"','javascript:void(0)','Alexander Pierce','demo@example.com','admin-lte@latest','admin-lte@4.0.0-rc4'] as $forbidden) $this->assertTrue(!str_contains($source,$forbidden),'Forbidden UI source found: '.$forbidden);
    }
}
