<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth\PasswordResetTokens;
use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use PDO;
use Tests\TestCase;

final class PasswordResetTokensTest extends TestCase
{
    public function testTokenIsHashedTimeLimitedAndSingleUse(): void
    {
        $database = $this->database();
        $tokens = new PasswordResetTokens($database, 3600);
        $token = $tokens->issue(1);
        $this->assertSame(97, strlen($token));
        $stored = $database->fetchOne('SELECT token_hash FROM password_reset_tokens');
        $this->assertTrue(!str_contains($token, $stored['token_hash']));
        $this->assertSame(1, $tokens->consume($token));
        $this->assertSame(null, $tokens->consume($token));
    }

    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $database = $this->database();
        $tokens = new PasswordResetTokens($database, -1);
        $this->assertSame(null, $tokens->consume($tokens->issue(1)));
        $this->assertSame(null, $tokens->consume('not-a-token'));
    }

    private function database(): Database
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Admin', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '0.5.0', 3, new Logger(sys_get_temp_dir() . '/seo-reset-migration.log')))->run();
        return $database;
    }
}
