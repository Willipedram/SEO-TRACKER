<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use RuntimeException;
use Tests\TestCase;

final class BootstrapTest extends TestCase
{
    public function testFrontControllerAndDirectAdminDirectoryPathsNormalizeToRoot(): void
    {
        $this->assertSame('/', Request::normalizePath('/', '/index.php'));
        $this->assertSame('/', Request::normalizePath('/index.php', '/index.php'));
        $this->assertSame('/install', Request::normalizePath('/index.php/install', '/index.php'));
        $this->assertSame('/', Request::normalizePath('/public/index.php', '/public/index.php'));
        $this->assertSame('/', Request::normalizePath('/seo-tracker/', '/seo-tracker/index.php'));
        $this->assertSame('/install', Request::normalizePath('/seo-tracker/install', '/seo-tracker/index.php'));
        $this->assertSame('/', Request::normalizePath('/seotrack', '/seotrack/public/index.php'));
        $this->assertSame('/install', Request::normalizePath('/seotrack/install', '/seotrack/public/index.php'));
        $this->assertSame('/seotrack', Request::baseUrl('/seotrack/public/index.php'));
        $this->assertSame('/seotrack', Request::baseUrl('/index.php', '/home/user/domains/example/public_html/seotrack/public/index.php', '/home/user/domains/example/public_html'));
        $this->assertSame('/install', Request::normalizePath('/seotrack/install', '/index.php', '/seotrack'));
    }

    public function testSubdirectoryMountPrefixesRedirectsAndHtmlLinks(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $home = $application->handle(new Request('GET', '/', headers: ['host' => 'localhost'], baseUrl: '/seotrack'));
        $this->assertSame('/seotrack/install', $home->headers['Location']);

        $installer = $application->handle(new Request('GET', '/install', headers: ['host' => 'localhost'], baseUrl: '/seotrack'));
        $this->assertTrue(str_contains($installer->body, 'href="/assets/installer.css"'));
        $this->assertTrue(str_contains($installer->body, 'src="/assets/tooltips.js"'));
        $this->assertTrue(str_contains($installer->body, 'href="data:,"'));
        $this->assertTrue(str_contains($installer->body, 'href="/seotrack/install?step=database"'));
    }

    public function testDomainRootFrontControllerCanInferVirtualMountFromRoute(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $installer = $application->handle(new Request('GET', '/seotrack/install', headers: ['host' => 'localhost']));

        $this->assertSame(200, $installer->status);
        $this->assertTrue(str_contains($installer->body, 'href="/assets/installer.css"'));
        $this->assertTrue(str_contains($installer->body, 'src="/assets/tooltips.js"'));
        $this->assertTrue(str_contains($installer->body, 'href="data:,"'));
        $this->assertTrue(str_contains($installer->body, 'href="/seotrack/install?step=database"'));
    }

    public function testPostKernelBootstrapFailuresUseNormalSafeErrorHandling(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $requestId = 'bootstrap-failure-test-20260829';
        $response = $application->renderBootstrapFailure(
            new RuntimeException('Session storage is not writable.'),
            new Request('GET', '/', headers: ['host' => 'localhost', 'x-request-id' => $requestId], scheme: 'https'),
        );

        $this->assertSame(500, $response->status);
        $payload = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame($requestId, $payload['request_id']);
        $this->assertSame('خطای پیش‌بینی‌نشده‌ای رخ داد. شناسه پیگیری را برای پشتیبانی نگه دارید.', $payload['error']);
        $this->assertSame($requestId, $response->headers['X-Request-ID']);
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers['Strict-Transport-Security']);
        $this->assertTrue(!isset($payload['exception'], $payload['message'], $payload['trace']));
        $this->assertTrue(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/storage/logs/application.log'), $requestId));
    }

    public function testApplicationBootsWithoutDatabaseOrBusinessData(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $response = $application->handle(new Request('GET', '/', headers: ['host' => 'localhost']));
        $this->assertSame(302, $response->status);
        $this->assertSame('/install', $response->headers['Location']);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $this->assertTrue(str_contains($response->headers['Content-Security-Policy'], "object-src 'none'"));
        $this->assertSame('same-origin', $response->headers['Cross-Origin-Resource-Policy']);
        $this->assertTrue(!isset($response->headers['Strict-Transport-Security']));
    }

    public function testHstsIsOnlySentForHttpsRequests(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $response = $application->handle(new Request('GET', '/missing', headers: ['host' => 'localhost'], scheme: 'https'));
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers['Strict-Transport-Security']);
    }

    public function testUnknownRoutesAreSafe(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $requestId = 'missing-route-test-20260829';
        $response = $application->handle(new Request('GET', '/missing', headers: ['host' => 'localhost', 'x-request-id' => $requestId]));
        $this->assertSame(404, $response->status);
        $this->assertSame('{"error":"یافت نشد."}', $response->body);
        $this->assertSame('/missing', $response->headers['X-Route-Path']);
        $this->assertSame('/', $response->headers['X-Mount-Path']);
        $log = (string) file_get_contents(dirname(__DIR__, 2) . '/storage/logs/application.log');
        $this->assertTrue(str_contains($log, $requestId));
        $this->assertTrue(str_contains($log, 'Route not found.'));
    }

    public function testUntrustedHostIsRejected(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $response = $application->handle(new Request('GET', '/', headers: ['host' => 'invalid host']));
        $this->assertSame(400, $response->status);
    }

    public function testProductionErrorsDoNotExposeDetails(): void
    {
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $application->router()->get('/explode', static function (): never {
            throw new RuntimeException('internal-only-detail');
        });
        $response = $application->handle(new Request('GET', '/explode', headers: ['host' => 'localhost']));
        $this->assertSame(500, $response->status);
        $this->assertTrue(!str_contains($response->body, 'internal-only-detail'));
        $this->assertTrue(str_contains($response->body, 'request_id'));
    }

    public function testUnsafeRequestsRequireCsrfToken(): void
    {
        $_SESSION = [];
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $application->router()->post('/change', static fn (): Response => Response::json(['changed' => true]));

        $rejected = $application->handle(new Request('POST', '/change', headers: ['host' => 'localhost']));
        $this->assertSame(419, $rejected->status);

        $accepted = $application->handle(new Request('POST', '/change', headers: ['host' => 'localhost', 'x-csrf-token' => Csrf::token()]));
        $this->assertSame(200, $accepted->status);
    }
}
