<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\Response;

final class SecurityHeaders
{
    public static function apply(Response $response, string $requestId, bool $https = false): Response
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Request-ID' => $requestId,
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return new Response($response->body, $response->status, $response->headers + $headers);
    }
}
