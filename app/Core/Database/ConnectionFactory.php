<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    public function __construct(private readonly Config $config) {}

    public function connect(): Database
    {
        $name = $this->config->requireString('database.default');
        $connection = $this->config->get('database.connections.' . $name);
        if (!is_array($connection) || !in_array($connection['driver'] ?? null, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database connection.');
        }
        if ($connection['driver'] === 'sqlite') {
            try {
                return new Database(new PDO('sqlite:' . (string) ($connection['database'] ?? ''), options: [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]));
            } catch (PDOException $exception) {
                throw new RuntimeException('Database connection failed.', 0, $exception);
            }
        }
        foreach (['host', 'database', 'charset'] as $field) {
            if (!is_string($connection[$field] ?? null) || !preg_match('/^[A-Za-z0-9_.:-]+$/', $connection[$field])) {
                throw new RuntimeException(sprintf('Invalid database %s configuration.', $field));
            }
        }
        if (!is_int($connection['port'] ?? null) || $connection['port'] < 1 || $connection['port'] > 65535) {
            throw new RuntimeException('Invalid database port configuration.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $connection['host'], $connection['port'], $connection['database'], $connection['charset']);
        try {
            $pdo = new PDO($dsn, (string) $connection['username'], (string) $connection['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return new Database($pdo);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed.', 0, $exception);
        }
    }
}
