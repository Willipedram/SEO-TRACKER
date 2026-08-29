<?php

declare(strict_types=1);

namespace App\Core\Security;

final class SafeRedirect
{
    public static function isGoogleAuthorizationUrl(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'accounts.google.com'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443)
            && str_starts_with((string) ($parts['path'] ?? ''), '/o/oauth2/');
    }
}
