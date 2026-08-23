<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Installer\EnvironmentChecker;
use Tests\TestCase;

final class EnvironmentCheckerTest extends TestCase
{
    public function testCompatibleEnvironmentPasses(): void
    {
        $this->assertTrue((new EnvironmentChecker(dirname(__DIR__, 2)))->passes());
    }

    public function testMissingWritableDirectoriesFailWithHelp(): void
    {
        $path = sys_get_temp_dir() . '/seo-environment-' . bin2hex(random_bytes(4));
        mkdir($path);
        $checks = (new EnvironmentChecker($path))->check();
        rmdir($path);
        $failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['pass']));
        $this->assertTrue(count($failed) >= 3);
        $this->assertTrue((string) $failed[0]['help'] !== '');
    }
}
