<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_24_000000_search_console_sync', schemaVersion: 11, transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $decimal = $mysql ? 'DECIMAL(14,8)' : 'REAL';
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_syncs (id $id, public_id CHAR(32) NOT NULL UNIQUE, user_id $foreignId NOT NULL, website_id $foreignId NOT NULL, property_id $foreignId NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, search_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, phase VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, lease_expires_at DATETIME NULL, rows_fetched INTEGER NOT NULL DEFAULT 0, rows_saved INTEGER NOT NULL DEFAULT 0, error_code VARCHAR(100) NULL, error_detail VARCHAR(255) NULL, created_at DATETIME NOT NULL, started_at DATETIME NULL, completed_at DATETIME NULL, updated_at DATETIME NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE, FOREIGN KEY (property_id) REFERENCES search_console_properties(id) ON DELETE CASCADE)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_sync_logs (id $id, sync_id $foreignId NOT NULL, state VARCHAR(30) NOT NULL, error_code VARCHAR(100) NULL, message VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, FOREIGN KEY (sync_id) REFERENCES search_console_syncs(id) ON DELETE CASCADE)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_sync_stage (sync_id $foreignId NOT NULL, dimension_hash CHAR(64) NOT NULL, data_date DATE NOT NULL, query_text TEXT NOT NULL, page_url VARCHAR(2048) NOT NULL, device VARCHAR(30) NOT NULL, country VARCHAR(10) NOT NULL, search_type VARCHAR(30) NOT NULL, clicks BIGINT NOT NULL, impressions BIGINT NOT NULL, ctr $decimal NOT NULL, average_position $decimal NOT NULL, PRIMARY KEY (sync_id,dimension_hash), FOREIGN KEY (sync_id) REFERENCES search_console_syncs(id) ON DELETE CASCADE)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_data (id $id, dimension_hash CHAR(64) NOT NULL UNIQUE, website_id $foreignId NOT NULL, property_id $foreignId NOT NULL, last_sync_id $foreignId NOT NULL, data_date DATE NOT NULL, query_text TEXT NOT NULL, page_url VARCHAR(2048) NOT NULL, device VARCHAR(30) NOT NULL, country VARCHAR(10) NOT NULL, search_type VARCHAR(30) NOT NULL, clicks BIGINT NOT NULL, impressions BIGINT NOT NULL, ctr $decimal NOT NULL, average_position $decimal NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE, FOREIGN KEY (property_id) REFERENCES search_console_properties(id) ON DELETE CASCADE, FOREIGN KEY (last_sync_id) REFERENCES search_console_syncs(id) ON DELETE RESTRICT)$suffix");
        $indexes = ['search_console_syncs_queue' => 'search_console_syncs (status, available_at)', 'search_console_syncs_owner' => 'search_console_syncs (user_id, website_id, created_at)', 'search_console_logs_sync' => 'search_console_sync_logs (sync_id, occurred_at)', 'search_console_data_lookup' => 'search_console_data (website_id, data_date, search_type)'];
        foreach ($indexes as $name => $definition) {
            if (!$mysql) $database->execute("CREATE INDEX IF NOT EXISTS $name ON $definition");
            elseif ($database->fetchOne('SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = :name', ['name' => $name]) === null) $database->execute("CREATE INDEX $name ON $definition");
        }
        $database->execute('UPDATE modules SET version = :version WHERE module_key = :key', ['version' => '1.2.0', 'key' => 'search_console']);
    },
);
