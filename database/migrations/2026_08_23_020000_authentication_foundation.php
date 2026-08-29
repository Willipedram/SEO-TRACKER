<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_020000_authentication_foundation',
    schemaVersion: 3,
    transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $columns = $mysql
            ? array_column($database->fetchAll('SHOW COLUMNS FROM users'), 'Field')
            : array_column($database->fetchAll('PRAGMA table_info(users)'), 'name');
        if (!in_array('disabled_at', $columns, true)) {
            $database->execute('ALTER TABLE users ADD COLUMN disabled_at DATETIME NULL');
        }
        $attemptIndexes = $mysql ? ', INDEX auth_login_attempts_account_time (account_key, attempted_at), INDEX auth_login_attempts_network_time (network_key, attempted_at)' : '';
        $resetIndex = $mysql ? ', INDEX password_reset_tokens_expiry (expires_at)' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS auth_login_attempts (id $id, account_key CHAR(64) NOT NULL, network_key CHAR(64) NOT NULL, attempted_at DATETIME NOT NULL$attemptIndexes)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS password_reset_tokens (selector CHAR(32) PRIMARY KEY, user_id $foreignId NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE$resetIndex)$suffix");
        if (!$mysql) {
            $database->execute('CREATE INDEX IF NOT EXISTS auth_login_attempts_account_time ON auth_login_attempts (account_key, attempted_at)');
            $database->execute('CREATE INDEX IF NOT EXISTS auth_login_attempts_network_time ON auth_login_attempts (network_key, attempted_at)');
            $database->execute('CREATE INDEX IF NOT EXISTS password_reset_tokens_expiry ON password_reset_tokens (expires_at)');
        }
    },
);
