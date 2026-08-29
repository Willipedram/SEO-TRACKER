<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Domain;

interface RankAdapter
{
    public function key(): string;
    public function version(): string;
    public function executionSource(): string;
    public function supportsExecutionDevice(string $requestedDevice, string $executionDevice): bool;
    public function execute(RankJob $job): RankExecutionResult;
}
