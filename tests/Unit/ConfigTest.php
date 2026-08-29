<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config\Config;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testNestedConfiguration(): void
    {
        $config = new Config(['app' => ['debug' => false]]);
        $this->assertSame(false, $config->get('app.debug'));
        $this->assertSame('fallback', $config->get('app.missing', 'fallback'));
    }

    public function testKeywordCountryAllowlistIsCompleteAndUnique(): void
    {
        $keywords = require dirname(__DIR__, 2) . '/config/keywords.php';
        $countries = $keywords['countries'];
        $this->assertSame(249, count($countries));
        $this->assertSame(249, count(array_unique($countries)));
        foreach (['US', 'IR', 'GB', 'DE', 'JP', 'ZA'] as $country) {
            $this->assertTrue(in_array($country, $countries, true));
        }
        foreach ($countries as $country) {
            $this->assertTrue(is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1);
        }
    }
}
