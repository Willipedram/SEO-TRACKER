<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Localization\PersianNormalizer;
use App\Core\Localization\Terminology;
use App\Core\Localization\Translator;
use App\Core\Localization\UiLocalizer;
use DOMDocument;
use Tests\TestCase;

final class PersianLocalizationTest extends TestCase
{
    public function testPersianIsDefaultAndFreshInstallerAndLoginAreRtlPersian(): void
    {
        $base = dirname(__DIR__, 2); $application = require $base . '/bootstrap/app.php';
        $this->assertSame('fa', $application->config()->get('app.locale'));
        $response = $application->handle(new Request('GET', '/install', headers: ['host'=>'localhost']));
        $this->assertTrue(in_array($response->status,[200,503],true));
        $decoded = html_entity_decode($response->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertTrue(str_contains($decoded, 'lang="fa"'));
        $this->assertTrue(str_contains($decoded, 'dir="rtl"'));
        $this->assertTrue(str_contains($decoded, 'بررسی محیط میزبانی'));
        $login=html_entity_decode((new UiLocalizer('fa',$base))->html('<html><head></head><body><h1>Sign in</h1></body></html>'),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $this->assertTrue(str_contains($login,'ورود به حساب'));
    }

    public function testMajorScreenCatalogCoverageIsPersianAndStructured(): void
    {
        $base = dirname(__DIR__, 2); $en = require $base.'/lang/en/ui.php'; $fa = require $base.'/lang/fa/ui.php';
        foreach (['auth.sign_in','installer.environment','update.required','admin.users','admin.roles','admin.permissions','websites.title','keywords.title','rank.tracking','errors.database_connection'] as $key) {
            $this->assertTrue(isset($en[$key], $fa[$key]), 'Missing major-screen key: '.$key);
            $this->assertTrue($en[$key] !== $fa[$key]);
            $this->assertTrue(!preg_match('/^[\x00-\x7F]+$/', $fa[$key]));
        }
        foreach (['rank_dashboard','search_console','reports','settings'] as $catalog) {
            $localized = require $base.'/lang/fa/'.$catalog.'.php';
            $this->assertTrue($localized !== [] && mb_check_encoding(implode('', array_map('strval',$localized)), 'UTF-8'));
        }
    }

    public function testAccessibleCanonicalEnglishTooltipWorksForHoverFocusAndTap(): void
    {
        $base = dirname(__DIR__, 2); $html = (new UiLocalizer('fa',$base))->html('<!doctype html><html><head></head><body><table><tr><th>Position</th></tr></table></body></html>');
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (['رتبه ثبت‌شده توسط ردیاب','Rank Tracker Position','class="term-trigger"','aria-describedby=','aria-expanded="false"','role="tooltip"','dir="ltr"','/assets/tooltips.js'] as $required) $this->assertTrue(str_contains($decoded,$required));
        $css=(string)file_get_contents($base.'/public/assets/installer.css'); $js=(string)file_get_contents($base.'/public/assets/tooltips.js');
        foreach ([':hover',':focus-within','aria-expanded="true"','max-width: min(280px, 80vw)'] as $required) $this->assertTrue(str_contains($css,$required));
        foreach (['click','aria-expanded','Escape'] as $required) $this->assertTrue(str_contains($js,$required));
    }

    public function testRankTrackerAndSearchConsolePositionRemainDistinct(): void
    {
        $terms=(new Terminology('fa',dirname(__DIR__,2)))->all();
        $rank=$terms['rank_tracker_position']; $average=$terms['average_position'];
        $this->assertSame('رتبه ثبت‌شده توسط ردیاب',$rank['label']); $this->assertSame('Rank Tracker Position',$rank['term']);
        $this->assertSame('میانگین جایگاه سرچ کنسول',$average['label']); $this->assertSame('Search Console Average Position',$average['term']);
        $this->assertTrue($rank['label']!==$average['label'] && $rank['term']!==$average['term']);
    }

    public function testSearchConsoleCanonicalTermsAreAccurate(): void
    {
        $terms=(new Terminology('fa',dirname(__DIR__,2)))->all();
        foreach (['property'=>'Search Console Property','clicks'=>'Clicks','impressions'=>'Impressions','ctr'=>'CTR — Click-Through Rate','average_position'=>'Search Console Average Position','search_type'=>'Search Type','sync'=>'Sync'] as $key=>$canonical) $this->assertSame($canonical,$terms[$key]['term']);
    }

    public function testTechnicalValuesIdentifiersAndKeywordTextAreNeverNormalized(): void
    {
        $source='<html><head></head><body><code>rank_tracking.run</code><input type="hidden" value="pending"><p>https://example.com/a?x=1</p><p>user@example.com</p><p>192.0.2.10</p><p>v2.4.0</p><p>كلمه ي آزمایشی</p></body></html>';
        $localized=(new UiLocalizer('fa',dirname(__DIR__,2)))->html($source);
        $decoded=html_entity_decode($localized,ENT_QUOTES|ENT_HTML5,'UTF-8');
        foreach (['rank_tracking.run','value="pending"','https://example.com/a?x=1','user@example.com','192.0.2.10','v2.4.0','كلمه ي آزمایشی'] as $value) $this->assertTrue(str_contains($decoded,$value),'Technical value changed: '.$value);
        $this->assertTrue(!str_contains($decoded,'term-tooltip">URL'));
        $this->assertSame('متن فارسی',PersianNormalizer::ui('متن فارسي'));
    }

    public function testInternalStatusValuesStayStableWhilePresentationIsLocalized(): void
    {
        $states=['pending','running','completed','failed']; $copy=$states;
        $html='<html><head></head><body>'.implode('',array_map(static fn(string $s):string=>'<span>'.$s.'</span><input type="hidden" value="'.$s.'">',$states)).'</body></html>';
        $decoded=html_entity_decode((new UiLocalizer('fa',dirname(__DIR__,2)))->html($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        foreach (['در انتظار','در حال اجرا','تکمیل‌شده','ناموفق'] as $label) $this->assertTrue(str_contains($decoded,$label));
        foreach ($states as $state) $this->assertTrue(str_contains($decoded,'value="'.$state.'"'));
        $this->assertSame($copy,$states);
    }

    public function testMissingTranslationAndEnglishFallbackAreDetectable(): void
    {
        $translator=new Translator('fa',dirname(__DIR__,2),'ui');
        $this->assertTrue($translator->has('auth.sign_in'));
        $this->assertTrue(!$translator->has('missing.phase26.key'));
        $this->assertSame('missing.phase26.key',$translator->get('missing.phase26.key'));
        $fallback=new Translator('de',dirname(__DIR__,2),'ui');
        $this->assertSame('Sign in',$fallback->get('auth.sign_in'));
    }

    public function testCanonicalDatesAndNumbersRemainPresentationOnly(): void
    {
        $source='<html><head></head><body><time>2026-08-29 12:34:56</time><code>14</code><p>#1</p></body></html>';
        $decoded=html_entity_decode((new UiLocalizer('fa',dirname(__DIR__,2)))->html($source),ENT_QUOTES|ENT_HTML5,'UTF-8');
        foreach (['2026-08-29 12:34:56','14','#1'] as $value) $this->assertTrue(str_contains($decoded,$value));
    }
}
