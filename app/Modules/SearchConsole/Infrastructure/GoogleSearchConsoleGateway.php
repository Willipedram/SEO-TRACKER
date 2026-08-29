<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Infrastructure;

use App\Modules\SearchConsole\Domain\SearchConsoleGateway;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;

final class GoogleSearchConsoleGateway implements SearchConsoleGateway
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const SITES = 'https://www.googleapis.com/webmasters/v3/sites';
    private const REVOKE = 'https://oauth2.googleapis.com/revoke';
    private const ANALYTICS = 'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';

    public function __construct(
        #[\SensitiveParameter] private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly array $scopes,
        private readonly int $timeout = 15,
    ) {}

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTH . '?' . http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri,
            'response_type' => 'code', 'scope' => implode(' ', $this->scopes),
            'state' => $state, 'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256',
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $authorizationCode, string $codeVerifier): array
    {
        return $this->post(self::TOKEN, ['code' => $authorizationCode, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'redirect_uri' => $this->redirectUri, 'grant_type' => 'authorization_code', 'code_verifier' => $codeVerifier], 'token_exchange_failed');
    }

    public function refresh(string $refreshToken): array
    {
        $response = $this->request(self::TOKEN, 'POST', ['refresh_token' => $refreshToken, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'grant_type' => 'refresh_token']);
        if ($response['status'] !== 200) {
            $body = $this->json($response['body'], 'token_refresh_failed');
            throw new SearchConsoleUnavailable(($body['error'] ?? null) === 'invalid_grant' ? 'authorization_revoked' : 'token_refresh_failed');
        }
        return $this->json($response['body'], 'token_refresh_failed');
    }

    public function properties(string $accessToken): array
    {
        $response = $this->request(self::SITES, 'GET', [], ['Authorization: Bearer ' . $accessToken]);
        if ($response['status'] !== 200) throw new SearchConsoleUnavailable($response['status'] === 401 ? 'authorization_revoked' : 'property_discovery_failed');
        $decoded = $this->json($response['body'], 'property_discovery_failed');
        $properties = [];
        foreach (($decoded['siteEntry'] ?? []) as $site) {
            $url = $site['siteUrl'] ?? null; $permission = $site['permissionLevel'] ?? null;
            if (is_string($url) && $url !== '' && is_string($permission)) $properties[] = ['uri' => $url, 'type' => str_starts_with($url, 'sc-domain:') ? 'domain' : 'url_prefix', 'permission' => $permission];
        }
        return $properties;
    }

    public function revoke(string $token): void
    {
        $response = $this->request(self::REVOKE, 'POST', ['token' => $token]);
        if (!in_array($response['status'], [200, 400], true)) throw new SearchConsoleUnavailable('revocation_failed');
    }

    public function searchAnalytics(string $accessToken, string $propertyUri, string $startDate, string $endDate, string $searchType, int $startRow, int $rowLimit): array
    {
        $url = sprintf(self::ANALYTICS, rawurlencode($propertyUri));
        $payload = json_encode(['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => ['date', 'query', 'page', 'device', 'country'], 'type' => $searchType, 'dataState' => 'final', 'aggregationType' => 'auto', 'startRow' => $startRow, 'rowLimit' => $rowLimit], JSON_THROW_ON_ERROR);
        $response = $this->request($url, 'POST', [], ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'], $payload);
        if ($response['status'] !== 200) {
            $code = match ($response['status']) { 401 => 'authorization_revoked', 429 => 'rate_limited', 500, 502, 503, 504 => 'api_unavailable', default => 'api_error' };
            throw new SearchConsoleUnavailable($code);
        }
        $decoded = $this->json($response['body'], 'response_invalid');
        $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];
        return ['rows' => $rows, 'next_start_row' => count($rows) === $rowLimit ? $startRow + $rowLimit : null];
    }

    private function post(string $url, array $fields, string $error): array
    {
        $response = $this->request($url, 'POST', $fields);
        if ($response['status'] !== 200) throw new SearchConsoleUnavailable($error);
        return $this->json($response['body'], $error);
    }

    private function request(string $url, string $method, array $fields = [], array $headers = [], ?string $rawContent = null): array
    {
        $headers[] = 'Accept: application/json';
        $content = $rawContent ?? ($fields === [] ? '' : http_build_query($fields, '', '&', PHP_QUERY_RFC3986));
        if ($rawContent === null && $content !== '') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'timeout' => $this->timeout, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0]]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) throw new SearchConsoleUnavailable('google_unavailable');
        $status = 0;
        foreach ($http_response_header ?? [] as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) $status = (int) $matches[1];
        return ['status' => $status, 'body' => $body];
    }

    private function json(string $body, string $error): array
    {
        try { $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new SearchConsoleUnavailable($error); }
        if (!is_array($decoded)) throw new SearchConsoleUnavailable($error);
        return $decoded;
    }
}
