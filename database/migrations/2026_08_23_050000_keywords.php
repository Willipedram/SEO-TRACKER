<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_050000_keywords',
    schemaVersion: 6,
    transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $boolean = $mysql ? 'TINYINT(1)' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $indexes = $mysql ? ', UNIQUE INDEX keywords_public_id (public_id), UNIQUE INDEX keywords_tracking_config (website_id, normalized_keyword, search_engine, country_code, language_code, device), INDEX keywords_website_active (website_id, active), INDEX keywords_tracking_lookup (search_engine, country_code, language_code, device, active)' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS keywords (id $id, public_id CHAR(32) NOT NULL, website_id $foreignId NOT NULL, keyword_text VARCHAR(255) NOT NULL, normalized_keyword VARCHAR(255) NOT NULL, target_url VARCHAR(2048) NULL, search_engine VARCHAR(50) NOT NULL, country_code CHAR(2) NOT NULL, language_code VARCHAR(35) NOT NULL, device VARCHAR(30) NOT NULL, active $boolean NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE$indexes)$suffix");
        if (!$mysql) {
            $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS keywords_public_id ON keywords (public_id)');
            $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS keywords_tracking_config ON keywords (website_id, normalized_keyword, search_engine, country_code, language_code, device)');
            $database->execute('CREATE INDEX IF NOT EXISTS keywords_website_active ON keywords (website_id, active)');
            $database->execute('CREATE INDEX IF NOT EXISTS keywords_tracking_lookup ON keywords (search_engine, country_code, language_code, device, active)');
        }
    },
);
