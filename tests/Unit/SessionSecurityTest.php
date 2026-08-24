<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class SessionSecurityTest extends TestCase
{
    public function testSecureCookieConfiguration(): void
    {
        $_ENV['SESSION_SECURE'] = 'true';
        $_ENV['SESSION_SAME_SITE'] = 'Strict';
        putenv('SESSION_SECURE=true');
        putenv('SESSION_SAME_SITE=Strict');
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $parameters = $application->sessionCookieParameters();
        $this->assertSame(true, $parameters['secure']);
        $this->assertSame(true, $parameters['httponly']);
        $this->assertSame('Strict', $parameters['samesite']);
        unset($_ENV['SESSION_SECURE'], $_ENV['SESSION_SAME_SITE']);
        putenv('SESSION_SECURE');
        putenv('SESSION_SAME_SITE');
    }
}
