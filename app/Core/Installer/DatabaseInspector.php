<?php

declare(strict_types=1);

namespace App\Core\Installer;

use PDO;
use Throwable;

final class DatabaseInspector
{
    public const EMPTY = 'empty';
    public const APPLICATION = 'application';
    public const UNKNOWN = 'unknown';

    public function inspect(PDO $pdo): string
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === []) {
            return self::EMPTY;
        }
        if (!in_array('app_installations', $tables, true)) {
            return self::UNKNOWN;
        }
        try {
            $statement = $pdo->prepare('SELECT application_id FROM app_installations WHERE application_id = :id LIMIT 1');
            $statement->execute(['id' => SchemaInstaller::APPLICATION_ID]);
            return $statement->fetchColumn() === SchemaInstaller::APPLICATION_ID ? self::APPLICATION : self::UNKNOWN;
        } catch (Throwable) {
            return self::UNKNOWN;
        }
    }
}
