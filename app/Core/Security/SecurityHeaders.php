<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\Response;

final class SecurityHeaders
{
    public static function apply(Response $response, string $requestId): Response
    {
        return new Response($response->body, $response->status, $response->headers + [
            'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Request-ID' => $requestId,
        ]);
    }
}
