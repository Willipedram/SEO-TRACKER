<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_090000_search_console_oauth', schemaVersion: 10, transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $boolean = $mysql ? 'TINYINT(1)' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_properties (id $id, public_id CHAR(32) NOT NULL UNIQUE, connection_id $foreignId NOT NULL, website_id $foreignId NULL UNIQUE, property_uri VARCHAR(2048) NOT NULL, property_type VARCHAR(30) NOT NULL, permission_level VARCHAR(50) NOT NULL, selected $boolean NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY (connection_id) REFERENCES search_console_connections(id) ON DELETE CASCADE, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE SET NULL)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_connection_contexts (connection_id $foreignId PRIMARY KEY, website_id $foreignId NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY (connection_id) REFERENCES search_console_connections(id) ON DELETE CASCADE, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE)$suffix");
        if (!$mysql) $database->execute('CREATE INDEX IF NOT EXISTS search_console_properties_connection ON search_console_properties (connection_id, selected)');
        elseif ($database->fetchOne("SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'search_console_properties' AND index_name = 'search_console_properties_connection'") === null) $database->execute('CREATE INDEX search_console_properties_connection ON search_console_properties (connection_id, selected)');
        if (!$mysql) $database->execute('CREATE INDEX IF NOT EXISTS search_console_contexts_website ON search_console_connection_contexts (website_id)');
        elseif ($database->fetchOne("SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'search_console_connection_contexts' AND index_name = 'search_console_contexts_website'") === null) $database->execute('CREATE INDEX search_console_contexts_website ON search_console_connection_contexts (website_id)');
        $database->execute('UPDATE modules SET version = :version WHERE module_key = :key', ['version' => '1.1.0', 'key' => 'search_console']);
    },
);
