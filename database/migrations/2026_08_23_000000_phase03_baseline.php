<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_000000_phase03_baseline',
    schemaVersion: 1,
    transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $boolean = $mysql ? 'TINYINT(1)' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $statements = [
            "CREATE TABLE roles (id $id, role_key VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$suffix",
            "CREATE TABLE users (id $id, name VARCHAR(100) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, email_verified_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$suffix",
            "CREATE TABLE user_roles (user_id $foreignId NOT NULL, role_id $foreignId NOT NULL, assigned_at DATETIME NOT NULL, PRIMARY KEY (user_id, role_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE settings (id $id, setting_key VARCHAR(190) NOT NULL UNIQUE, setting_value TEXT NOT NULL, value_type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$suffix",
            "CREATE TABLE modules (id $id, module_key VARCHAR(100) NOT NULL UNIQUE, version VARCHAR(30) NOT NULL, enabled $boolean NOT NULL, installed_at DATETIME NOT NULL)$suffix",
            "CREATE TABLE migrations (id $id, migration VARCHAR(190) NOT NULL UNIQUE, batch INTEGER NOT NULL, applied_at DATETIME NOT NULL)$suffix",
            "CREATE TABLE app_installations (application_id VARCHAR(100) PRIMARY KEY, schema_version INTEGER NOT NULL, installed_at DATETIME NOT NULL)$suffix",
            "CREATE TABLE migration_failures (migration VARCHAR(190) PRIMARY KEY, schema_version INTEGER NOT NULL, error_class VARCHAR(190) NOT NULL, error_message TEXT NOT NULL, failed_at DATETIME NOT NULL)$suffix",
        ];
        foreach ($statements as $sql) {
            $database->execute($sql);
        }
    },
);
