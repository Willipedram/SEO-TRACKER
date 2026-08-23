<?php

declare(strict_types=1);

namespace App\Core\Installer;

use PDO;
use Throwable;
use App\Core\Database\Database;
use App\Core\Update\Migration;
use App\Core\Auth\PasswordHasher;

final class SchemaInstaller
{
    public const APPLICATION_ID = 'seo-tracker-platform';
    private const MIGRATION = '2026_08_23_000000_phase03_baseline';

    public function install(PDO $pdo, string $name, string $email, #[\SensitiveParameter] string $password, string $siteName): void
    {
        $this->validateAdmin($name, $email, $password, $siteName);
        if ((new DatabaseInspector())->inspect($pdo) !== DatabaseInspector::EMPTY) {
            throw new InstallerException('Fresh installation requires an empty database. No changes were made.');
        }

        $created = ['roles', 'users', 'user_roles', 'settings', 'modules', 'migrations', 'app_installations', 'migration_failures'];
        try {
            $migration = require dirname(__DIR__, 3) . '/database/migrations/' . self::MIGRATION . '.php';
            if (!$migration instanceof Migration) {
                throw new InstallerException('Baseline migration is invalid.');
            }
            $migration->up(new Database($pdo));
            $pdo->beginTransaction();
            $now = gmdate('Y-m-d H:i:s');
            $this->insert($pdo, 'INSERT INTO roles (role_key, name, created_at, updated_at) VALUES (:key, :name, :created, :updated)', ['key' => 'administrator', 'name' => 'Administrator', 'created' => $now, 'updated' => $now]);
            $roleId = (int) $pdo->lastInsertId();
            $this->insert($pdo, 'INSERT INTO users (name, email, password_hash, email_verified_at, created_at, updated_at) VALUES (:name, :email, :password, :verified, :created, :updated)', ['name' => $name, 'email' => strtolower($email), 'password' => (new PasswordHasher())->hash($password), 'verified' => $now, 'created' => $now, 'updated' => $now]);
            $userId = (int) $pdo->lastInsertId();
            $this->insert($pdo, 'INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (:user, :role, :assigned)', ['user' => $userId, 'role' => $roleId, 'assigned' => $now]);
            $this->insert($pdo, 'INSERT INTO settings (setting_key, setting_value, value_type, created_at, updated_at) VALUES (:key, :value, :type, :created, :updated)', ['key' => 'application.name', 'value' => $siteName, 'type' => 'string', 'created' => $now, 'updated' => $now]);
            $this->insert($pdo, 'INSERT INTO modules (module_key, version, enabled, installed_at) VALUES (:key, :version, :enabled, :installed)', ['key' => 'Foundation', 'version' => '1.0.0', 'enabled' => 1, 'installed' => $now]);
            $this->insert($pdo, 'INSERT INTO migrations (migration, batch, applied_at) VALUES (:migration, :batch, :applied)', ['migration' => self::MIGRATION, 'batch' => 1, 'applied' => $now]);
            $this->insert($pdo, 'INSERT INTO app_installations (application_id, schema_version, installed_at) VALUES (:id, :version, :installed)', ['id' => self::APPLICATION_ID, 'version' => 1, 'installed' => $now]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach (array_reverse($created) as $table) {
                try {
                    $pdo->exec('DROP TABLE IF EXISTS ' . $table);
                } catch (Throwable) {
                }
            }
            throw new InstallerException('Installation could not complete. Newly created installer tables were rolled back where supported.', 0, $exception);
        }
    }

    private function validateAdmin(string $name, string $email, string $password, string $siteName): void
    {
        if (trim($name) === '' || strlen($name) > 100) {
            throw new InstallerException('Administrator name is required and must be 100 characters or fewer.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new InstallerException('Enter a valid administrator email address.');
        }
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new InstallerException('Administrator password must be between 12 and 1024 characters.');
        }
        if (trim($siteName) === '' || strlen($siteName) > 120) {
            throw new InstallerException('Application name is required and must be 120 characters or fewer.');
        }
    }

    private function insert(PDO $pdo, string $sql, array $parameters): void
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
    }
}
