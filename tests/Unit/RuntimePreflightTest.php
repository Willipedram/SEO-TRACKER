<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

require_once dirname(__DIR__, 2) . '/public/runtime-preflight.php';

final class RuntimePreflightTest extends TestCase
{
    public function testPhp80IsRejectedBeforePhp81ClassesAreLoaded(): void
    {
        $result = seo_tracker_runtime_preflight(80030, '8.0.30');

        $this->assertSame(false, $result['supported']);
        $this->assertSame('8.0.30', $result['current']);
        $this->assertSame('8.1.0', $result['required']);
    }

    public function testPhp81AndNewerAreAccepted(): void
    {
        $this->assertSame(true, seo_tracker_runtime_preflight(80100, '8.1.0')['supported']);
        $this->assertSame(true, seo_tracker_runtime_preflight(80200, '8.2.0')['supported']);
        $this->assertSame(true, seo_tracker_runtime_preflight(80507, '8.5.7')['supported']);
    }

    public function testApplicationSourceDoesNotUsePhp82ReadonlyClasses(): void
    {
        $basePath = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($basePath . '/composer.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('^8.1', $composer['require']['php']);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath . '/app'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $source = (string) file_get_contents($file->getPathname());
            $this->assertTrue(!preg_match('/\breadonly\s+class\b/', $source), 'PHP 8.2 readonly class found: ' . $file->getPathname());
        }
    }
}
