<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Rbac\PermissionCatalog;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_030000_rbac_foundation',
    schemaVersion: 4,
    transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $auditIndex = $mysql ? ', INDEX audit_logs_actor_time (actor_user_id, occurred_at)' : '';
        $permissionIndex = $mysql ? ', INDEX role_permissions_permission (permission_id)' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS permissions (id $id, permission_key VARCHAR(190) NOT NULL UNIQUE, description VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS role_permissions (role_id $foreignId NOT NULL, permission_id $foreignId NOT NULL, assigned_at DATETIME NOT NULL, PRIMARY KEY (role_id, permission_id), FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE, FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE$permissionIndex)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS audit_logs (id $id, actor_user_id $foreignId NULL, action VARCHAR(100) NOT NULL, target_type VARCHAR(100) NOT NULL, target_id VARCHAR(190) NULL, metadata TEXT NOT NULL, occurred_at DATETIME NOT NULL$auditIndex)$suffix");
        $now = gmdate('Y-m-d H:i:s');
        foreach (PermissionCatalog::DEFINITIONS as $key => $description) {
            if ($database->fetchOne('SELECT id FROM permissions WHERE permission_key = :key', ['key' => $key]) === null) {
                $database->execute('INSERT INTO permissions (permission_key, description, created_at, updated_at) VALUES (:key, :description, :created, :updated)', ['key' => $key, 'description' => $description, 'created' => $now, 'updated' => $now]);
            }
        }
        $administrator = $database->fetchOne('SELECT id FROM roles WHERE role_key = :key', ['key' => 'administrator']);
        if ($administrator !== null) {
            foreach ($database->fetchAll('SELECT id FROM permissions') as $permission) {
                if ($database->fetchOne('SELECT role_id FROM role_permissions WHERE role_id = :role AND permission_id = :permission', ['role' => $administrator['id'], 'permission' => $permission['id']]) === null) {
                    $database->execute('INSERT INTO role_permissions (role_id, permission_id, assigned_at) VALUES (:role, :permission, :assigned)', ['role' => $administrator['id'], 'permission' => $permission['id'], 'assigned' => $now]);
                }
            }
        }
        if (!$mysql) {
            $database->execute('CREATE INDEX IF NOT EXISTS audit_logs_actor_time ON audit_logs (actor_user_id, occurred_at)');
            $database->execute('CREATE INDEX IF NOT EXISTS role_permissions_permission ON role_permissions (permission_id)');
        }
    },
);
