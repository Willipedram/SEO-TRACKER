<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_010000_update_tracking',
    schemaVersion: 2,
    transactional: false,
    operation: static function (Database $database): void {
        $suffix = $database->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS migration_failures (migration VARCHAR(190) PRIMARY KEY, schema_version INTEGER NOT NULL, error_class VARCHAR(190) NOT NULL, error_message TEXT NOT NULL, failed_at DATETIME NOT NULL)$suffix");
        $columns = static function (string $table) use ($database): array {
            if ($database->driver() === 'sqlite') {
                return array_column($database->fetchAll('PRAGMA table_info(' . $table . ')'), 'name');
            }
            return array_column($database->fetchAll('SHOW COLUMNS FROM ' . $table), 'Field');
        };
        foreach (['status' => 'VARCHAR(20) NULL', 'started_at' => 'DATETIME NULL', 'completed_at' => 'DATETIME NULL'] as $name => $definition) {
            if (!in_array($name, $columns('migrations'), true)) {
                $database->execute('ALTER TABLE migrations ADD COLUMN ' . $name . ' ' . $definition);
            }
        }
        if (!in_array('source_version', $columns('app_installations'), true)) {
            $database->execute('ALTER TABLE app_installations ADD COLUMN source_version VARCHAR(30) NULL');
        }
    },
);
