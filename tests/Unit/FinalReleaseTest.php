<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class FinalReleaseTest extends TestCase
{
    public function testAuthoritativeVersionChangelogAndReleaseNotesAgree(): void
    {
        $base = dirname(__DIR__, 2); $version = require $base . '/config/version.php';
        $this->assertSame('2.4.0', $version['application']);
        $this->assertSame(14, $version['schema']);
        $this->assertTrue(str_contains((string) file_get_contents($base . '/CHANGELOG.md'), '## [2.4.0] - 2026-08-29'));
        $this->assertTrue(str_contains((string) file_get_contents($base . '/RELEASE.md'), '# SEO Tracker 2.4.0 final release'));
    }

    public function testResponsiveAndRtlStylesArePresent(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/installer.css');
        foreach (['@media (max-width: 760px)', '[dir="rtl"] th', 'grid-template-columns: 1fr', 'overflow-x: auto', 'width: 100%'] as $contract) {
            $this->assertTrue(str_contains($css, $contract), 'Missing presentation contract: ' . $contract);
        }
    }

    public function testEnglishAndPersianCatalogKeysRemainInParity(): void
    {
        $base = dirname(__DIR__, 2) . '/lang';
        foreach (['rank_dashboard.php', 'reports.php', 'search_console.php', 'settings.php'] as $catalog) {
            $english = require $base . '/en/' . $catalog; $persian = require $base . '/fa/' . $catalog;
            $englishKeys = array_keys($english); $persianKeys = array_keys($persian); sort($englishKeys); sort($persianKeys);
            $this->assertSame($englishKeys, $persianKeys, 'Translation keys differ for ' . $catalog);
            $this->assertTrue(mb_check_encoding(implode('', array_map('strval', $persian)), 'UTF-8'));
        }
    }

    public function testAllModuleManifestsAreValidAndVersioned(): void
    {
        $manifests = glob(dirname(__DIR__, 2) . '/app/Modules/*/module.json') ?: [];
        $this->assertTrue(count($manifests) >= 7);
        foreach ($manifests as $path) {
            $manifest = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            $this->assertTrue(is_string($manifest['name'] ?? null) && $manifest['name'] !== '');
            $this->assertTrue(is_string($manifest['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $manifest['version']) === 1);
            $this->assertTrue(is_string($manifest['provider'] ?? null) && str_starts_with($manifest['provider'], 'App\\'));
        }
    }
}
