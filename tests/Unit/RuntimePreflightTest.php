<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

require_once dirname(__DIR__, 2) . '/public/runtime-preflight.php';

final class RuntimePreflightTest extends TestCase
{
    public function testPhp81IsRejectedBeforePhp82ClassesAreLoaded(): void
    {
        $result = seo_tracker_runtime_preflight(80129, '8.1.29');

        $this->assertSame(false, $result['supported']);
        $this->assertSame('8.1.29', $result['current']);
        $this->assertSame('8.2.0', $result['required']);
    }

    public function testPhp82AndNewerAreAccepted(): void
    {
        $this->assertSame(true, seo_tracker_runtime_preflight(80200, '8.2.0')['supported']);
        $this->assertSame(true, seo_tracker_runtime_preflight(80507, '8.5.7')['supported']);
    }
}
