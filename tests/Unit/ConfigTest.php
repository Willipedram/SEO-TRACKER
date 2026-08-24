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
}
