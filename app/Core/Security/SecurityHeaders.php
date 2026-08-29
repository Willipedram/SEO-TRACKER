<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\Response;

final class SecurityHeaders
{
    public static function apply(Response $response, string $requestId, bool $https = false): Response
    {
        $scriptHashes = self::inlineHashes($response->body, 'script');
        $styleHashes = self::inlineHashes($response->body, 'style');
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; script-src 'self' https://cdn.jsdelivr.net" . $scriptHashes . "; style-src 'self' https://cdn.jsdelivr.net" . $styleHashes . "; font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'; manifest-src 'self'",
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

    private static function inlineHashes(string $html, string $tag): string
    {
        if ($html === '' || preg_match_all('~<' . $tag . '(?:\\s[^>]*)?>(.*?)</' . $tag . '>~is', $html, $matches) < 1) {
            return '';
        }
        $hashes = [];
        foreach ($matches[1] as $content) {
            $hashes[] = " 'sha256-" . base64_encode(hash('sha256', $content, true)) . "'";
        }
        return implode('', array_unique($hashes));
    }
}
