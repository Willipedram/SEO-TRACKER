<?php

declare(strict_types=1);

namespace App\Core\Rbac;

use App\Core\Auth\PasswordHasher;
use App\Core\Database\Database;
use InvalidArgumentException;

final class UserManager
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit, private readonly PasswordHasher $hasher) {}

    public function all(int $actorId): array
    {
        $this->authorization->require($actorId, 'users.view');
        $roles = $this->database->driver() === 'mysql' ? "GROUP_CONCAT(roles.name SEPARATOR ', ')" : "GROUP_CONCAT(roles.name, ', ')";
        return $this->database->fetchAll("SELECT users.id, users.name, users.email, users.disabled_at, COALESCE($roles, '') AS roles FROM users LEFT JOIN user_roles ON user_roles.user_id = users.id LEFT JOIN roles ON roles.id = user_roles.role_id GROUP BY users.id, users.name, users.email, users.disabled_at ORDER BY users.id");
    }

    public function create(int $actorId, string $name, string $email, #[\SensitiveParameter] string $password): int
    {
        $this->authorization->require($actorId, 'users.create');
        [$name, $email] = $this->validate($name, $email);
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new InvalidArgumentException('Password must be between 12 and 1024 characters.');
        }
        $now = gmdate('Y-m-d H:i:s');
        return $this->database->transaction(function (Database $database) use ($actorId, $name, $email, $password, $now): int {
            $database->execute('INSERT INTO users (name, email, password_hash, email_verified_at, disabled_at, created_at, updated_at) VALUES (:name, :email, :password, NULL, NULL, :created, :updated)', ['name' => $name, 'email' => $email, 'password' => $this->hasher->hash($password), 'created' => $now, 'updated' => $now]);
            $id = (int) $database->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => $email])['id'];
            $this->audit->record($actorId, 'user.created', 'user', $id);
            return $id;
        });
    }

    public function update(int $actorId, int $userId, string $name, string $email): void
    {
        $this->authorization->require($actorId, 'users.edit');
        $this->requireUser($userId);
        [$name, $email] = $this->validate($name, $email);
        $this->database->transaction(function (Database $database) use ($actorId, $userId, $name, $email): void {
            $database->execute('UPDATE users SET name = :name, email = :email, updated_at = :updated WHERE id = :id', ['name' => $name, 'email' => $email, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
            $this->audit->record($actorId, 'user.updated', 'user', $userId);
        });
    }

    public function setDisabled(int $actorId, int $userId, bool $disabled): void
    {
        $this->authorization->require($actorId, 'users.edit');
        $this->requireUser($userId);
        if ($actorId === $userId && $disabled) {
            throw new InvalidArgumentException('You cannot disable your own account.');
        }
        if ($disabled && $this->isLastActiveAdministrator($userId)) {
            throw new InvalidArgumentException('The last active administrator cannot be disabled.');
        }
        $this->database->transaction(function (Database $database) use ($actorId, $userId, $disabled): void {
            $database->execute('UPDATE users SET disabled_at = :disabled, updated_at = :updated WHERE id = :id', ['disabled' => $disabled ? gmdate('Y-m-d H:i:s') : null, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
            $this->audit->record($actorId, $disabled ? 'user.disabled' : 'user.enabled', 'user', $userId);
        });
    }

    public function delete(int $actorId, int $userId): void
    {
        $this->authorization->require($actorId, 'users.delete');
        $this->requireUser($userId);
        if ($actorId === $userId) {
            throw new InvalidArgumentException('You cannot delete your own account.');
        }
        if ($this->isLastActiveAdministrator($userId)) {
            throw new InvalidArgumentException('The last active administrator cannot be deleted.');
        }
        $this->database->transaction(function (Database $database) use ($actorId, $userId): void {
            $this->audit->record($actorId, 'user.deleted', 'user', $userId);
            $database->execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);
        });
    }

    private function validate(string $name, string $email): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Name is required and must be 100 characters or fewer.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        return [$name, $email];
    }

    private function requireUser(int $userId): void
    {
        if ($userId < 1 || $this->database->fetchOne('SELECT id FROM users WHERE id = :id', ['id' => $userId]) === null) {
            throw new InvalidArgumentException('User not found.');
        }
    }

    private function isLastActiveAdministrator(int $userId): bool
    {
        $target = $this->database->fetchOne('SELECT 1 AS administrator FROM user_roles JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = :user AND roles.role_key = :key', ['user' => $userId, 'key' => 'administrator']);
        if ($target === null) {
            return false;
        }
        $count = $this->database->fetchOne('SELECT COUNT(DISTINCT users.id) AS total FROM users JOIN user_roles ON user_roles.user_id = users.id JOIN roles ON roles.id = user_roles.role_id WHERE roles.role_key = :key AND users.disabled_at IS NULL', ['key' => 'administrator']);
        return (int) ($count['total'] ?? 0) <= 1;
    }
}
