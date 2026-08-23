<?php

declare(strict_types=1);

namespace App\Core\Rbac;

use App\Core\Database\Database;

final class Authorization
{
    public function __construct(private readonly Database $database) {}

    public function allows(int $userId, string $permission): bool
    {
        $row = $this->database->fetchOne('SELECT 1 AS allowed FROM users JOIN user_roles ON user_roles.user_id = users.id JOIN role_permissions ON role_permissions.role_id = user_roles.role_id JOIN permissions ON permissions.id = role_permissions.permission_id WHERE users.id = :user AND users.disabled_at IS NULL AND permissions.permission_key = :permission LIMIT 1', ['user' => $userId, 'permission' => $permission]);
        return $row !== null;
    }

    public function require(int $userId, string $permission): void
    {
        if (!$this->allows($userId, $permission)) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    public function permissions(int $userId): array
    {
        return array_column($this->database->fetchAll('SELECT DISTINCT permissions.permission_key FROM permissions JOIN role_permissions ON role_permissions.permission_id = permissions.id JOIN user_roles ON user_roles.role_id = role_permissions.role_id JOIN users ON users.id = user_roles.user_id WHERE users.id = :user AND users.disabled_at IS NULL ORDER BY permissions.permission_key', ['user' => $userId]), 'permission_key');
    }
}
