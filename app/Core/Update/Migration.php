<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Database\Database;

final class Migration
{
    public function __construct(
        public readonly string $id,
        public readonly int $schemaVersion,
        public readonly bool $transactional,
        private readonly \Closure $operation,
    ) {}

    public function up(Database $database): void
    {
        ($this->operation)($database);
    }
}
