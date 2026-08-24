<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Domain;

final readonly class RankJob
{
    public function __construct(
        public string $requestId,
        public string $keyword,
        public ?string $targetUrl,
        public string $searchEngine,
        public string $country,
        public string $language,
        public string $requestedDevice,
    ) {}
}
