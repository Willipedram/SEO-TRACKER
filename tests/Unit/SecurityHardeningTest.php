<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Application;
use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\SafeRedirect;
use InvalidArgumentException;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    public function testOAuthRedirectRequiresTheExactGoogleAuthorizationOrigin(): void
    {
        $this->assertTrue(SafeRedirect::isGoogleAuthorizationUrl('https://accounts.google.com/o/oauth2/v2/auth?state=opaque'));
        foreach ([
            'http://accounts.google.com/o/oauth2/v2/auth',
            'https://accounts.google.com.evil.example/o/oauth2/v2/auth',
            'https://accounts.google.com@evil.example/o/oauth2/v2/auth',
            'https://user@accounts.google.com/o/oauth2/v2/auth',
            'https://accounts.google.com/not-oauth',
        ] as $url) {
            $this->assertTrue(!SafeRedirect::isGoogleAuthorizationUrl($url));
        }
    }

    public function testResponseRejectsHeaderControlCharactersInRedirects(): void
    {
        $thrown = false;
        try {
            Response::redirect("/account\r\nX-Injection: yes");
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testProductionModeOverridesAnAccidentalDebugFlag(): void
    {
        $application = $this->application(['env' => 'production', 'debug' => true]);
        $application->router()->get('/phase19-error', static function (): never {
            throw new \RuntimeException('private-production-detail');
        });
        $response = $application->handle(new Request('GET', '/phase19-error', headers: ['host' => 'localhost']));
        $this->assertSame(500, $response->status);
        $this->assertTrue(!str_contains($response->body, 'private-production-detail'));
    }

    public function testUnsafeSessionConfigurationFailsClosed(): void
    {
        $application = $this->application(session: ['secure' => false, 'same_site' => 'None', 'lifetime' => 43200]);
        $thrown = false;
        try {
            $application->sessionCookieParameters();
        } catch (\RuntimeException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    private function application(array $app = [], array $session = []): Application
    {
        $base = dirname(__DIR__, 2);
        return Application::build($base, new Config([
            'app' => $app + ['env' => 'testing', 'debug' => false, 'timezone' => 'UTC', 'trusted_hosts' => []],
            'logging' => ['path' => sys_get_temp_dir() . '/seo-phase19.log', 'level' => 'error'],
            'modules' => ['paths' => [], 'enabled' => [], 'optional' => []],
            'session' => $session + ['name' => 'phase19', 'path' => sys_get_temp_dir(), 'secure' => true, 'same_site' => 'Lax', 'lifetime' => 43200],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'version' => ['application' => '1.8.0', 'schema' => 13],
        ]));
    }
}
