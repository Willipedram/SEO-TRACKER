<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Localization\UiLocalizer;
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
        foreach (['lang="fa"', 'dir="rtl"', 'admin-lte@4.0.0-rc4', 'bootstrap@5.3.3', 'bootstrap-icons@1.11.3', 'seo-auth-card', 'ورود به حساب'] as $required) {
            $this->assertTrue(str_contains($decoded, $required), 'Missing login UI contract: '.$required);
        }
        foreach (['@latest', 'admin@example.com', 'Demo', 'Messages', 'Notifications'] as $forbidden) $this->assertTrue(!str_contains($html, $forbidden));
    }

    public function testAuthenticatedSidebarIsPermissionAndModuleAware(): void
    {
        $context = ['authenticated'=>true,'user'=>['name'=>'کاربر آزمون'],'version'=>'2.4.0','modules'=>['websites','keywords','settings'],'permissions'=>['websites.view','keywords.view']];
        $html = (new UiLocalizer('fa', dirname(__DIR__, 2)))->html('<!doctype html><html><head><title>Websites</title></head><body><main><h1>Websites</h1><p class="empty-state">No websites</p></main></body></html>', '/websites', $context);
        foreach (['app-wrapper','app-sidebar','data-lte-toggle="sidebar"','href="/websites"','href="/keywords"','nav-link active','empty-state'] as $required) $this->assertTrue(str_contains($html,$required), 'Missing shell contract: '.$required);
        foreach (['href="/admin/users"','href="/admin/roles"','search-console/dashboard','href="#"'] as $forbidden) $this->assertTrue(!str_contains($html,$forbidden), 'Unauthorized/dead menu leaked: '.$forbidden);
    }

    public function testApplicationStylesCoverResponsiveRtlAndReducedMotion(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/phase27.css');
        foreach (['html[dir=rtl]','@media(max-width:991.98px)','@media(max-width:575.98px)','prefers-reduced-motion','.rank-chart','.table-scroll'] as $required) $this->assertTrue(str_contains($css,$required));
    }
}
