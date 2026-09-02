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
        foreach (['app-wrapper','app-sidebar','data-lte-toggle="sidebar"','href="/websites"','href="/keywords"','nav-link active','empty-state','id="seo-logbox"','data-logbox-copy','data-logbox-level="INFO"','data-logbox-level="WARNING"','data-logbox-level="ERROR"','data-logbox-status'] as $required) $this->assertTrue(str_contains($html,$required), 'Missing shell contract: '.$required);
        foreach (['href="/admin/users"','href="/admin/roles"','search-console/dashboard','href="#"'] as $forbidden) $this->assertTrue(!str_contains($html,$forbidden), 'Unauthorized/dead menu leaked: '.$forbidden);

        $context['base_url']='/seotrack';
        $mounted=(new UiLocalizer('fa',dirname(__DIR__,2)))->html('<!doctype html><html><head><title>Keywords</title></head><body><main><h1>Keywords</h1></main></body></html>','/keywords',$context);
        foreach(['href="/seotrack/account"','href="/seotrack/websites"','href="/seotrack/keywords"','action="/seotrack/logout"'] as $required)$this->assertTrue(str_contains($mounted,$required),'Mount prefix missing: '.$required);
        $this->assertTrue(!str_contains($mounted,'/seotrack/seotrack/'));

        $actions=(new UiLocalizer('fa',dirname(__DIR__,2)))->html('<!doctype html><html><head><title>Websites</title></head><body><main><h1>Websites</h1><p><a class="button" href="/websites/create">Add website</a> <a href="/websites?archived=1">Include archived</a></p></main></body></html>','/websites',$context);
        $this->assertTrue(str_contains($actions,'class="button btn btn-primary"'), 'Legacy action links must receive button styling.');
    }

    public function testApplicationStylesCoverResponsiveRtlAndReducedMotion(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/phase27.css');
        foreach (['font-family:IRANSans','local("IRANSansX")','color-scheme:light','html[dir=rtl]','html,body{max-width:100%;overflow-x:hidden;color','width:min(calc(100% - 2rem),560px)','.app-main.card{width:auto;max-width:none!important','.app-header,.app-footer{width:100%;max-width:none;margin:0!important','.app-header .container-fluid{display:flex;width:100%','.btn,.button{display:inline-flex','.footer-inner{display:flex;width:100%','body>.progress,body>#nprogress,body>.pace{display:none!important}','transform:translateX(-100%)','html[dir=rtl] .app-sidebar{transform:translateX(100%)','.logbox-filters','.logbox-filter.is-active','.level-warning','.logbox-filter-status','.logbox-output','.seo-table-responsive','--bs-table-bg:#fff','.rank-job-wait','@media(max-width:991.98px)','@media(max-width:575.98px)','prefers-reduced-motion','.rank-chart','.table-scroll','.installer-choice-grid','.term-tooltip{max-width:min(210px','.seo-loading::before','.persian-date-hint'] as $required) $this->assertTrue(str_contains($css,$required));
        foreach (['margin-left:var(--lte-sidebar-width,250px)','margin-right:var(--lte-sidebar-width,250px)'] as $forbidden) $this->assertTrue(!str_contains($css,$forbidden),'Header/footer must rely on the AdminLTE grid instead of adding a second sidebar offset.');
        foreach (['body.layout-fixed .app-wrapper>.app-footer{width:auto!important','margin-inline-start:0!important','margin-inline-end:0!important','.footer-content{width:100%;margin:0!important','.footer-inner{display:flex;width:100%'] as $required) $this->assertTrue(str_contains($css,$required),'Footer must not retain a logical or physical sidebar margin.');
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

    public function testUserIpRankRunnerHasModalAndIncognitoExtensionContracts(): void
    {
        $base=dirname(__DIR__,2);
        $javascript=(string)file_get_contents($base.'/public/assets/manual-rank.js');
        foreach(['SEO_RANK_START','SEO_RANK_ACK','SEO_RANK_PROGRESS','SEO_RANK_DONE','/rank-checks/manual','crypto.randomUUID()','PAGE_START','SAVE_RESPONSE','data-manual-rank-debug','lastProgress','progress = null','requiredProtocol = 7','EXTENSION_VERSION','chrome://extensions','manual-rank-start-all','BATCH_START','startNext'] as $required)$this->assertTrue(str_contains($javascript,$required));
        $this->assertTrue(preg_match('/[^\x00-\x7F]/',$javascript)!==1,'Inline rank runner must remain ASCII so DOMDocument cannot turn Persian script strings into visible numeric entities.');
        $localized=(new UiLocalizer('fa',$base))->html('<!doctype html><html><head><title>Rank</title></head><body><main><h1>Rank</h1></main></body></html>','/login');
        $this->assertTrue(!str_contains($localized,'&amp;#1575;'));
        $this->assertTrue(str_contains($localized,'\\u062f\\u0631'));
        $manifest=json_decode((string)file_get_contents($base.'/browser-extension/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame(3,$manifest['manifest_version']);
        $this->assertSame('1.6.0',$manifest['version']);
        $this->assertTrue(in_array('debugger',$manifest['permissions'],true));
        $this->assertSame('spanning',$manifest['incognito']);
        $this->assertTrue(in_array('https://*/*',$manifest['optional_host_permissions'],true));
        $this->assertTrue(!str_contains(json_encode($manifest,JSON_THROW_ON_ERROR),'pnakhostin.com'));
        $worker=(string)file_get_contents($base.'/browser-extension/worker.js');
        foreach(['incognito:true','pws=0','page<10','chrome.scripting.executeScript','SEO_RANK_DONE','sender.tab?.id','searchParams.set','waitForGoogle','document.readyState','DOCUMENT_TIMEOUT','INSPECT_FAILED','attempt<3','WINDOW_CREATED','PAGE_LOADED','INSPECTED','RUN_FAILED','googleHost','searchParams.get(\'q\')','displayed-cite-addresses','displayedDestination','querySelector?.(\'cite\')','displayed=','anchors=','preferred=','SEARCH_COMPLETE','protocol:7','getManifest().version','TARGET_DOMAIN','SEEN_URLS','RAW_HREFS','normalizeHost','waitForCaptchaResolution','CAPTCHA_WAITING','CAPTCHA_STILL_WAITING','CAPTCHA_SOLVED','CAPTCHA_TIMEOUT','CAPTCHA_WINDOW_CLOSED','Network.setUserAgentOverride','Emulation.setDeviceMetricsOverride','USER_AGENT_APPLIED','mobile-android','desktop-windows'] as $required)$this->assertTrue(str_contains($worker,$required));
        foreach(['resolveRedirect','resolveCandidates','RESOLVE_START','chrome.tabs.create','chrome.tabs.remove'] as $forbidden)$this->assertTrue(!str_contains($worker,$forbidden));
        foreach(['chrome.tabs.onUpdated','Google timeout','PAGE_TIMEOUT'] as $forbidden)$this->assertTrue(!str_contains($worker,$forbidden));
        $this->assertTrue(!str_contains($worker,'pnakhostin.com'));
        $popup=(string)file_get_contents($base.'/browser-extension/popup.js');
        foreach(['chrome.permissions.request','registerContentScripts','chrome.tabs.reload'] as $required)$this->assertTrue(str_contains($popup,$required));
        $this->assertTrue(str_contains((string)file_get_contents($base.'/browser-extension/popup.html'),'logo.svg'));
        $bridge=(string)file_get_contents($base.'/browser-extension/bridge.js');
        foreach(['protocol:response.protocol','version:response.version'] as $required)$this->assertTrue(str_contains($bridge,$required));
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/browser-extension',\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file)$this->assertTrue(!in_array(strtolower($file->getExtension()),['png','jpg','jpeg','gif','webp','ico'],true),'Binary extension asset is forbidden: '.$file->getFilename());
    }

    public function testLogboxFiltersStructuredLevelsAndCopiesVisibleOutput(): void
    {
        $javascript=(string)file_get_contents(dirname(__DIR__,2).'/public/assets/logbox.js');
        foreach(['JSON.parse(line)','data-logbox-level','INFO','WARNING','ERROR','CRITICAL','data-logbox-status','states = new WeakMap','output.textContent = visible.join','navigator.clipboard.writeText(output.textContent'] as $required)$this->assertTrue(str_contains($javascript,$required));
        $this->assertTrue(preg_match('/[^\x00-\x7F]/',$javascript)!==1,'Inline logbox JavaScript must remain ASCII-safe after DOM serialization.');
    }

    public function testAjaxAdminUxAndPersianDateAssetsAreSafeAndIntegrated(): void
    {
        $base=dirname(__DIR__,2);
        $ajax=(string)file_get_contents($base.'/public/assets/admin-ui.js');
        foreach(['X-Requested-With','DOMParser','seo:content-updated','history.pushState','popstate','window.confirm','toast-container','search-console/connect','removeInjectedSkipLinks','MutationObserver','data-keyword-modal-open','data-keyword-modal-close','response.ok','payload.redirect'] as $required)$this->assertTrue(str_contains($ajax,$required));
        $this->assertTrue(str_contains((string)file_get_contents($base.'/public/assets/phase27.css'),'.skip-links{display:none!important}'));
        $dates=(string)file_get_contents($base.'/public/assets/persian-dates.js');
        foreach(['fa-IR-u-ca-persian','Asia/Tehran','Intl.DateTimeFormat','input[type="date"]','persian-date-hint','seo:content-updated'] as $required)$this->assertTrue(str_contains($dates,$required));
        $localized=(new UiLocalizer('fa',$base))->html('<!doctype html><html><head></head><body><main><p>2026-09-02 12:30:00</p></main></body></html>','/login');
        $this->assertTrue(str_contains($localized,'fa-IR-u-ca-persian'));
        $this->assertTrue(str_contains($localized,'X-Requested-With'));
    }
}
