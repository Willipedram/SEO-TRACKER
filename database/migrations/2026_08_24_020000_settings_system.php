<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_24_020000_settings_system', schemaVersion: 13, transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql'; $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT'; $foreign = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER'; $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $unique = $mysql ? ', UNIQUE INDEX managed_settings_scope_key (scope_type,scope_id,setting_key), INDEX managed_settings_updated_by (updated_by)' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS managed_settings (id $id, setting_key VARCHAR(190) NOT NULL, scope_type VARCHAR(20) NOT NULL, scope_id VARCHAR(100) NOT NULL, setting_value TEXT NOT NULL, value_type VARCHAR(30) NOT NULL, updated_by $foreign NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT$unique)$suffix");
        if (!$mysql) { $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS managed_settings_scope_key ON managed_settings (scope_type,scope_id,setting_key)'); $database->execute('CREATE INDEX IF NOT EXISTS managed_settings_updated_by ON managed_settings (updated_by)'); }
        $now = gmdate('Y-m-d H:i:s'); foreach (['core'=>'1.0.0','authentication'=>'1.0.0','websites'=>'1.0.0','keywords'=>'1.0.0','rank_tracking'=>'1.0.0','reports'=>'1.0.0','settings'=>'1.0.0'] as $key => $version) if ($database->fetchOne('SELECT id FROM modules WHERE module_key=:key', ['key'=>$key]) === null) $database->execute('INSERT INTO modules (module_key,version,enabled,installed_at) VALUES (:key,:version,1,:now)', ['key'=>$key,'version'=>$version,'now'=>$now]);
    },
);
