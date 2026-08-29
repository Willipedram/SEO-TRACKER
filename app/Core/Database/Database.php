<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use Throwable;

final class Database
{
    public function __construct(private readonly PDO $connection) {}

    public function driver(): string
    {
        return (string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function transaction(callable $operation): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $operation($this);
            $this->connection->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }
}
