<?php

declare(strict_types=1);

namespace App\Core\Update;

final class UpdatePlan
{
    /** @param list<Migration> $pending */
    public function __construct(
        public readonly string $installedApplicationVersion,
        public readonly string $sourceApplicationVersion,
        public readonly int $installedSchemaVersion,
        public readonly int $targetSchemaVersion,
        public readonly array $pending,
    ) {}

    public function required(): bool
    {
        return $this->pending !== [] || $this->installedApplicationVersion !== $this->sourceApplicationVersion;
    }
}
