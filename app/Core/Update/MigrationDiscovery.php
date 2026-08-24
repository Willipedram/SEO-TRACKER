<?php

declare(strict_types=1);

namespace App\Core\Update;

use RuntimeException;

final class MigrationDiscovery
{
    public function __construct(private readonly string $path) {}

    /** @return list<Migration> */
    public function discover(): array
    {
        $migrations = [];
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $id)) {
                throw new RuntimeException('Invalid migration filename: ' . basename($file));
            }
            $migration = require $file;
            if (!$migration instanceof Migration || $migration->id !== $id) {
                throw new RuntimeException('Migration must return a matching Migration object: ' . basename($file));
            }
            if (isset($migrations[$migration->schemaVersion])) {
                throw new RuntimeException('Duplicate schema version: ' . $migration->schemaVersion);
            }
            $migrations[$migration->schemaVersion] = $migration;
        }
        ksort($migrations, SORT_NUMERIC);
        return array_values($migrations);
    }
}
