<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Domain;

use RuntimeException;

final class RankAdapterFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable, string $safeMessage)
    {
        parent::__construct(substr($safeMessage, 0, 255));
    }
}
