<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_080000_search_console_foundation', schemaVersion: 9, transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS search_console_connections (id $id, public_id CHAR(32) NOT NULL UNIQUE, user_id $foreignId NOT NULL, provider_subject VARCHAR(255) NULL, status VARCHAR(30) NOT NULL, granted_scopes TEXT NOT NULL, credential_envelope TEXT NULL, credential_key_version VARCHAR(50) NULL, token_expires_at DATETIME NULL, last_error_code VARCHAR(100) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        if (!$mysql) $database->execute('CREATE INDEX IF NOT EXISTS search_console_connections_user_status ON search_console_connections (user_id, status)');
        elseif ($database->fetchOne("SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'search_console_connections' AND index_name = 'search_console_connections_user_status'") === null) $database->execute('CREATE INDEX search_console_connections_user_status ON search_console_connections (user_id, status)');
        $now = gmdate('Y-m-d H:i:s');
        if ($database->fetchOne('SELECT id FROM modules WHERE module_key = :key', ['key' => 'search_console']) === null) $database->execute('INSERT INTO modules (module_key, version, enabled, installed_at) VALUES (:key, :version, 0, :installed)', ['key' => 'search_console', 'version' => '1.0.0', 'installed' => $now]);
    },
);
