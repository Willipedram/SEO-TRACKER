<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logging\Logger;
use Tests\TestCase;

final class LoggerTest extends TestCase
{
    public function testSecretsAreRedacted(): void
    {
        $path = sys_get_temp_dir() . '/seo-tracker-' . bin2hex(random_bytes(4)) . '.log';
        (new Logger($path, 'debug'))->error('failure', ['password' => 'do-not-log', 'nested' => ['token' => 'hidden']]);
        $contents = (string) file_get_contents($path);
        @unlink($path);
        $this->assertTrue(!str_contains($contents, 'do-not-log'));
        $this->assertTrue(!str_contains($contents, 'hidden'));
        $this->assertTrue(str_contains($contents, '[REDACTED]'));
    }

    public function testSecretsEmbeddedInExceptionMessagesAreRedacted(): void
    {
        $path = sys_get_temp_dir() . '/seo-tracker-' . bin2hex(random_bytes(4)) . '.log';
        (new Logger($path))->error('failure', ['message' => 'token=do-not-log']);
        $contents = (string) file_get_contents($path);
        @unlink($path);
        $this->assertTrue(!str_contains($contents, 'do-not-log'));
    }

    public function testSecretsEmbeddedInLogMessagesAreRedacted(): void
    {
        $path = sys_get_temp_dir() . '/seo-tracker-' . bin2hex(random_bytes(4)) . '.log';
        (new Logger($path))->error('authorization: Bearer-secret-value');
        $contents = (string) file_get_contents($path);
        @unlink($path);
        $this->assertTrue(!str_contains($contents, 'Bearer-secret-value'));
    }
}
