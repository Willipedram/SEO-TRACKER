<?php

declare(strict_types=1);

namespace App\Core\Rbac;

use App\Core\Database\Database;
use InvalidArgumentException;

final class RoleManager
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit) {}

    public function roles(int $actorId): array
    {
        $this->authorization->require($actorId, 'roles.manage');
        return $this->database->fetchAll("SELECT roles.id, roles.role_key, roles.name, COALESCE(COUNT(DISTINCT role_permissions.permission_id), 0) AS permission_count, COALESCE(COUNT(DISTINCT user_roles.user_id), 0) AS user_count FROM roles LEFT JOIN role_permissions ON role_permissions.role_id = roles.id LEFT JOIN user_roles ON user_roles.role_id = roles.id GROUP BY roles.id, roles.role_key, roles.name ORDER BY roles.name");
    }

    public function permissions(int $actorId): array
    {
        $this->authorization->require($actorId, 'roles.manage');
        return $this->database->fetchAll('SELECT id, permission_key, description FROM permissions ORDER BY permission_key');
    }

    public function create(int $actorId, string $key, string $name): int
    {
        $this->authorization->require($actorId, 'roles.manage');
        $key = strtolower(trim($key));
        $name = trim($name);
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $key) || $name === '' || strlen($name) > 150) {
            throw new InvalidArgumentException('Enter a valid role key and name.');
        }
        $now = gmdate('Y-m-d H:i:s');
        return $this->database->transaction(function (Database $database) use ($actorId, $key, $name, $now): int {
            $database->execute('INSERT INTO roles (role_key, name, created_at, updated_at) VALUES (:key, :name, :created, :updated)', ['key' => $key, 'name' => $name, 'created' => $now, 'updated' => $now]);
            $id = (int) $database->fetchOne('SELECT id FROM roles WHERE role_key = :key', ['key' => $key])['id'];
            $this->audit->record($actorId, 'role.created', 'role', $id, ['role_key' => $key]);
            return $id;
        });
    }

    public function assignRoles(int $actorId, int $userId, array $roleIds): void
    {
        $this->authorization->require($actorId, 'roles.manage');
        if ($userId < 1 || $this->database->fetchOne('SELECT id FROM users WHERE id = :id', ['id' => $userId]) === null) {
            throw new InvalidArgumentException('User not found.');
        }
        $roleIds = $this->ids($roleIds);
        $roles = $roleIds === [] ? [] : $this->rolesByIds($roleIds);
        if (count($roles) !== count($roleIds)) {
            throw new InvalidArgumentException('One or more roles do not exist.');
        }
        $administratorAssigned = in_array('administrator', array_column($roles, 'role_key'), true);
        $currentlyAdministrator = $this->database->fetchOne('SELECT 1 AS found FROM user_roles JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = :user AND roles.role_key = :key', ['user' => $userId, 'key' => 'administrator']) !== null;
        if ($currentlyAdministrator && !$administratorAssigned && ($actorId === $userId || $this->activeAdministratorCount() <= 1)) {
            throw new InvalidArgumentException('The current or last active administrator role cannot be removed.');
        }
        $this->database->transaction(function (Database $database) use ($actorId, $userId, $roleIds): void {
            $database->execute('DELETE FROM user_roles WHERE user_id = :user', ['user' => $userId]);
            foreach ($roleIds as $roleId) {
                $database->execute('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (:user, :role, :assigned)', ['user' => $userId, 'role' => $roleId, 'assigned' => gmdate('Y-m-d H:i:s')]);
            }
            $this->audit->record($actorId, 'user.roles_changed', 'user', $userId, ['role_count' => count($roleIds)]);
        });
    }

    public function assignPermissions(int $actorId, int $roleId, array $permissionIds): void
    {
        $this->authorization->require($actorId, 'roles.manage');
        $role = $this->database->fetchOne('SELECT id, role_key FROM roles WHERE id = :id', ['id' => $roleId]);
        if ($role === null) {
            throw new InvalidArgumentException('Role not found.');
        }
        $permissionIds = $this->ids($permissionIds);
        $permissions = $permissionIds === [] ? [] : $this->permissionsByIds($permissionIds);
        if (count($permissions) !== count($permissionIds)) {
            throw new InvalidArgumentException('One or more permissions do not exist.');
        }
        if ($role['role_key'] === 'administrator' && !in_array('roles.manage', array_column($permissions, 'permission_key'), true)) {
            throw new InvalidArgumentException('The administrator role must retain roles.manage.');
        }
        $this->database->transaction(function (Database $database) use ($actorId, $roleId, $permissionIds): void {
            $database->execute('DELETE FROM role_permissions WHERE role_id = :role', ['role' => $roleId]);
            foreach ($permissionIds as $permissionId) {
                $database->execute('INSERT INTO role_permissions (role_id, permission_id, assigned_at) VALUES (:role, :permission, :assigned)', ['role' => $roleId, 'permission' => $permissionId, 'assigned' => gmdate('Y-m-d H:i:s')]);
            }
            $this->audit->record($actorId, 'role.permissions_changed', 'role', $roleId, ['permission_count' => count($permissionIds)]);
        });
    }

    public function userRoleIds(int $actorId, int $userId): array
    {
        $this->authorization->require($actorId, 'roles.manage');
        return array_map('intval', array_column($this->database->fetchAll('SELECT role_id FROM user_roles WHERE user_id = :user', ['user' => $userId]), 'role_id'));
    }

    public function rolePermissionIds(int $actorId, int $roleId): array
    {
        $this->authorization->require($actorId, 'roles.manage');
        return array_map('intval', array_column($this->database->fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = :role', ['role' => $roleId]), 'permission_id'));
    }

    private function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || count($ids) >= 100) {
                throw new InvalidArgumentException('Invalid assignment selection.');
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private function rolesByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->database->fetchAll('SELECT id, role_key FROM roles WHERE id IN (' . $placeholders . ')', $ids);
    }

    private function permissionsByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->database->fetchAll('SELECT id, permission_key FROM permissions WHERE id IN (' . $placeholders . ')', $ids);
    }

    private function activeAdministratorCount(): int
    {
        return (int) ($this->database->fetchOne('SELECT COUNT(DISTINCT users.id) AS total FROM users JOIN user_roles ON user_roles.user_id = users.id JOIN roles ON roles.id = user_roles.role_id WHERE roles.role_key = :key AND users.disabled_at IS NULL', ['key' => 'administrator'])['total'] ?? 0);
    }
}
