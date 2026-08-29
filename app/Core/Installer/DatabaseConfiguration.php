<?php

declare(strict_types=1);

namespace App\Core\Installer;

use InvalidArgumentException;
use PDO;
use PDOException;

final class DatabaseConfiguration
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        #[\SensitiveParameter] public readonly string $password,
    ) {
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new InvalidArgumentException('Enter a valid database host name or IP address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Database port must be between 1 and 65535.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database)) {
            throw new InvalidArgumentException('Database name may contain letters, numbers, underscores, and hyphens.');
        }
        if ($username === '' || strlen($username) > 128 || str_contains($username, "\0")) {
            throw new InvalidArgumentException('Enter a valid database username.');
        }
    }

    public function connect(): PDO
    {
        try {
            return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database), $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new InstallerException('Could not connect to the database. Check the host, port, database name, username, password, and user permissions.', 0, $exception);
        }
    }

    public function sessionValue(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'username' => $this->username, 'password' => $this->password];
    }

    public static function fromArray(array $input): self
    {
        return new self(trim((string) ($input['host'] ?? '')), filter_var($input['port'] ?? null, FILTER_VALIDATE_INT) ?: 0, trim((string) ($input['database'] ?? '')), trim((string) ($input['username'] ?? '')), (string) ($input['password'] ?? ''));
    }
}
