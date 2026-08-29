<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Domain;

final class RankJob
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $keyword,
        public readonly ?string $targetUrl,
        public readonly string $searchEngine,
        public readonly string $country,
        public readonly string $language,
        public readonly string $requestedDevice,
    ) {}
}
