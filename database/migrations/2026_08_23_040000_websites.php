<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_040000_websites',
    schemaVersion: 5,
    transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $indexes = $mysql ? ', UNIQUE INDEX websites_owner_domain (owner_user_id, normalized_domain), UNIQUE INDEX websites_public_id (public_id), INDEX websites_owner_status (owner_user_id, status)' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS websites (id $id, public_id CHAR(32) NOT NULL, owner_user_id $foreignId NOT NULL, site_name VARCHAR(150) NOT NULL, normalized_domain VARCHAR(253) NOT NULL, canonical_url VARCHAR(2048) NOT NULL, protocol VARCHAR(5) NOT NULL, description TEXT NOT NULL, timezone VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, archived_at DATETIME NULL, FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT$indexes)$suffix");
        if (!$mysql) {
            $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS websites_owner_domain ON websites (owner_user_id, normalized_domain)');
            $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS websites_public_id ON websites (public_id)');
            $database->execute('CREATE INDEX IF NOT EXISTS websites_owner_status ON websites (owner_user_id, status)');
        }
    },
);
