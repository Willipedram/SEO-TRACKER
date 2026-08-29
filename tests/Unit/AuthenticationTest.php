<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth\Authenticator;
use App\Core\Auth\LoginRateLimiter;
use App\Core\Auth\PasswordHasher;
use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use PDO;
use Tests\Support\ArraySessionStore;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    public function testPasswordHashingVerificationAndRehashSupport(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('a-secure-test-password');
        $this->assertTrue($hasher->verify('a-secure-test-password', $hash));
        $this->assertTrue(!$hasher->verify('wrong-password', $hash));
        $this->assertTrue(!str_contains($hash, 'a-secure-test-password'));
    }

    public function testSuccessfulLoginRegeneratesSessionAndLogoutInvalidatesIt(): void
    {
        [$auth, $session] = $this->auth();
        $result = $auth->login('ADMIN@example.com', 'correct-horse-battery', '192.0.2.10');
        $this->assertTrue($result->success);
        $this->assertSame(1, $session->regenerations);
        $this->assertSame(1, $auth->user()['id']);
        $auth->logout();
        $this->assertTrue($session->invalidated);
        $this->assertSame(null, $session->get('auth'));
    }

    public function testFailedAndDisabledAccountsUseGenericFailure(): void
    {
        [$auth, , $database] = $this->auth();
        $missing = $auth->login('missing@example.com', 'incorrect-password', '192.0.2.11');
        $wrong = $auth->login('admin@example.com', 'incorrect-password', '192.0.2.12');
        $database->execute('UPDATE users SET disabled_at = :disabled WHERE id = 1', ['disabled' => gmdate('Y-m-d H:i:s')]);
        $disabled = $auth->login('admin@example.com', 'correct-horse-battery', '192.0.2.13');
        $this->assertSame($missing->message, $wrong->message);
        $this->assertSame($wrong->message, $disabled->message);
        $this->assertTrue(!$disabled->success);
    }

    public function testBruteForceLimitBlocksCredentialsTemporarily(): void
    {
        [$auth] = $this->auth(maximumAttempts: 3);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertTrue(!$auth->login('admin@example.com', 'incorrect-password', '192.0.2.14')->success);
        }
        $blocked = $auth->login('admin@example.com', 'correct-horse-battery', '192.0.2.14');
        $this->assertTrue(!$blocked->success);
        $this->assertTrue(str_contains($blocked->message, 'Wait'));
    }

    public function testMalformedAndExpiredSessionsAreRejected(): void
    {
        [$auth, $session] = $this->auth();
        $session->set('auth', ['user_id' => '1']);
        $this->assertSame(null, $auth->user());
        $this->assertTrue($session->invalidated);
        $session->set('auth', ['user_id' => 1, 'authenticated_at' => time() - 1000, 'last_activity' => time() - 1000]);
        $this->assertSame(null, $auth->user());
    }

    private function auth(int $maximumAttempts = 5): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Admin', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '0.5.0', 3, new Logger(sys_get_temp_dir() . '/seo-auth-migration.log')))->run();
        $session = new ArraySessionStore();
        $auth = new Authenticator($database, new PasswordHasher(), $session, new LoginRateLimiter($database, 'test-application-key', $maximumAttempts, 900), new Logger(sys_get_temp_dir() . '/seo-auth.log'), 300, 600, 'test-audit-key');
        return [$auth, $session, $database];
    }
}
