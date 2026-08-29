<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Domain;

/** Phase 13/14 adapters implement this port; Core never references it. */
interface SearchConsoleGateway
{
    public function authorizationUrl(string $state, string $codeChallenge): string;

    public function exchange(string $authorizationCode, string $codeVerifier): array;

    public function refresh(string $refreshToken): array;

    public function properties(string $accessToken): array;

    public function revoke(string $token): void;

    /** @return array{rows: array, next_start_row: ?int} */
    public function searchAnalytics(string $accessToken, string $propertyUri, string $startDate, string $endDate, string $searchType, int $startRow, int $rowLimit): array;
}
