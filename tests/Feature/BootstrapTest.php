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
        $response = $application->handle(new Request('GET', '/missing', headers: ['host' => 'localhost']));
        $this->assertSame(404, $response->status);
        $this->assertSame('{"error":"Not found."}', $response->body);
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
