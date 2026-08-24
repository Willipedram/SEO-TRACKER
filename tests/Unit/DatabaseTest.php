<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class DatabaseTest extends TestCase
{
    public function testQueriesAreParameterisedAndTransactionsRollBack(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $database = new Database($pdo);
        $database->execute('CREATE TABLE checks (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $database->execute('INSERT INTO checks (label) VALUES (:label)', ['label' => "safe'); DROP TABLE checks; --"]);
        $this->assertSame("safe'); DROP TABLE checks; --", $database->fetchOne('SELECT label FROM checks WHERE id = :id', ['id' => 1])['label']);

        try {
            $database->transaction(static function (Database $database): void {
                $database->execute('INSERT INTO checks (label) VALUES (:label)', ['label' => 'rolled back']);
                throw new RuntimeException('stop');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(1, count($database->fetchAll('SELECT id FROM checks')));
    }
}
