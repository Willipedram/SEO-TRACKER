<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth\PasswordHasher;
use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\UserManager;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\Keywords\Application\KeywordManager;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\RankTracking\Application\RankCheckManager;
use App\Modules\RankTracking\Application\RankWorker;
use App\Modules\RankTracking\Domain\RankAdapter;
use App\Modules\RankTracking\Domain\RankAdapterFailure;
use App\Modules\RankTracking\Domain\RankExecutionResult;
use App\Modules\RankTracking\Domain\RankJob;
use App\Modules\RankTracking\Infrastructure\RankAdapterRegistry;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

final class SequenceRankAdapter implements RankAdapter
{
    public array $devices = [];
    public function __construct(private array $outcomes) {}
    public function key(): string { return 'test_provider'; }
    public function version(): string { return '1.2.3'; }
    public function executionSource(): string { return 'provider_api'; }
    public function supportsExecutionDevice(string $requestedDevice, string $executionDevice): bool { return $executionDevice === 'provider_' . $requestedDevice; }
    public function execute(RankJob $job): RankExecutionResult
    {
        $this->devices[] = $job->requestedDevice;
        $outcome = array_shift($this->outcomes);
        if (is_callable($outcome)) $outcome = $outcome($job);
        if ($outcome instanceof RankAdapterFailure) throw $outcome;
        if (!$outcome instanceof RankExecutionResult) throw new \RuntimeException('Missing test outcome.');
        return $outcome;
    }
}

final class RankTrackingTest extends TestCase
{
    public function testDesktopSuccessPositionUrlAndImmutableHistory(): void
    {
        $adapter = new SequenceRankAdapter([
            $this->ranked(3, 'https://example.com/landing', 'provider_desktop'),
            $this->ranked(5, 'https://example.com/other', 'provider_desktop'),
        ]);
        [$database, $manager, $worker, $website, $keyword] = $this->services($adapter, 'desktop');
        $first = $manager->submit(1, $website, $keyword);
        $this->assertSame('pending', $manager->status(1, $first)['status']);
        $this->assertSame(1, $worker->work(1));
        $status = $manager->status(1, $first);
        $this->assertSame('completed', $status['status']);
        $this->assertSame(3, (int) $status['result']['position']);
        $this->assertSame('https://example.com/landing', $status['result']['ranking_url']);
        $second = $manager->submit(1, $website, $keyword);
        $worker->work(1);
        $history = $manager->history(1, $website, $keyword);
        $this->assertSame(2, count($history));
        $this->assertSame(2, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM rank_results')['total']);
        $this->assertTrue($first !== $second);
        $this->assertSame(['desktop', 'desktop'], $adapter->devices);
    }

    public function testMobileNotFoundIsSuccessfulWithoutFabricatedPosition(): void
    {
        $adapter = new SequenceRankAdapter([$this->notFound('provider_mobile')]);
        [, $manager, $worker, $website, $keyword] = $this->services($adapter, 'mobile');
        $request = $manager->submit(1, $website, $keyword);
        $worker->work(1);
        $result = $manager->status(1, $request)['result'];
        $this->assertSame('not_found', $result['result_type']);
        $this->assertSame(null, $result['position']);
        $this->assertSame(null, $result['ranking_url']);
        $this->assertSame('provider_mobile', $result['execution_device']);
        $this->assertSame(['mobile'], $adapter->devices);
    }

    public function testJobIsRunningWhileAdapterExecutes(): void
    {
        $database = null;
        $adapter = new SequenceRankAdapter([function () use (&$database): RankExecutionResult {
            $this->assertSame('running', $database->fetchOne('SELECT status FROM rank_check_requests LIMIT 1')['status']);
            $this->assertSame('running', $database->fetchOne('SELECT status FROM rank_execution_attempts LIMIT 1')['status']);
            return $this->notFound('provider_desktop');
        }]);
        [$database, $manager, $worker, $website, $keyword] = $this->services($adapter);
        $request = $manager->submit(1, $website, $keyword);
        $worker->work(1);
        $this->assertSame('completed', $manager->status(1, $request)['status']);
    }

    public function testFailedJobStoresNoResult(): void
    {
        $adapter = new SequenceRankAdapter([new RankAdapterFailure('challenge_presented', false, 'Provider challenge prevented execution.')]);
        [$database, $manager, $worker, $website, $keyword] = $this->services($adapter);
        $request = $manager->submit(1, $website, $keyword);
        $worker->work(1);
        $status = $manager->status(1, $request);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('challenge_presented', $status['error_code']);
        $this->assertSame(null, $status['result']);
        $this->assertSame(0, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM rank_results')['total']);
    }

    public function testTransientFailureRetriesOnceThenCompletes(): void
    {
        $adapter = new SequenceRankAdapter([new RankAdapterFailure('network_timeout', true, 'Provider timed out.'), $this->ranked(8, 'https://example.com', 'provider_desktop')]);
        [$database, $manager, $worker, $website, $keyword] = $this->services($adapter);
        $request = $manager->submit(1, $website, $keyword);
        $now = time();
        $worker->work(1, $now);
        $this->assertSame('retry_wait', $manager->status(1, $request)['status']);
        $worker->work(1, $now + 11);
        $this->assertSame('completed', $manager->status(1, $request)['status']);
        $this->assertSame(2, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM rank_execution_attempts')['total']);
    }

    public function testRateLimitInvalidInactiveAndAuthorization(): void
    {
        $adapter = new SequenceRankAdapter([$this->notFound('provider_desktop')]);
        [$database, $manager, , $website, $keyword, $keywords, $users, $roles] = $this->services($adapter, managerRateLimit: 1);
        $request = $manager->submit(1, $website, $keyword);
        $limited = false;
        try { $manager->submit(1, $website, $keyword); } catch (InvalidArgumentException) { $limited = true; }
        $this->assertTrue($limited);
        $inactive = $keywords->create(1, $website, $this->keywordInput('inactive term', false));
        $rejected = false;
        try { $manager->submit(1, $website, $inactive); } catch (InvalidArgumentException) { $rejected = true; }
        $this->assertTrue($rejected);
        $other = $users->create(1, 'Other', 'other@example.com', 'correct-horse-battery');
        $denied = false;
        try { $manager->status($other, str_repeat('a', 32)); } catch (AuthorizationException) { $denied = true; }
        $this->assertTrue($denied);
        $role = $roles->create(1, 'rank-viewer', 'Rank viewer');
        $permissions = array_column($database->fetchAll("SELECT id FROM permissions WHERE permission_key IN ('rank_tracking.run','rank_tracking.view')"), 'id');
        $roles->assignPermissions(1, $role, $permissions);
        $roles->assignRoles(1, $other, [$role]);
        $owned = false;
        try { $manager->status($other, $request); } catch (InvalidArgumentException) { $owned = true; }
        $this->assertTrue($owned);
        $invalid = false;
        try { $manager->submit(1, $website, str_repeat('f', 32)); } catch (InvalidArgumentException) { $invalid = true; }
        $this->assertTrue($invalid);
        $this->assertSame(1, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM rank_check_requests')['total']);
    }

    public function testNoUnapprovedProductionAdapterCannotSubmit(): void
    {
        [$database, , , $website, $keyword] = $this->services(new SequenceRankAdapter([]));
        $authorization = new Authorization($database);
        $manager = new RankCheckManager($database, $authorization, new AuditRecorder($database), new RankAdapterRegistry(), '', 10, 900);
        $blocked = false;
        try { $manager->submit(1, $website, $keyword); } catch (InvalidArgumentException) { $blocked = true; }
        $this->assertTrue($blocked);
    }

    public function testManualObservationIsRecordedWithoutProviderAdapter(): void
    {
        [$database, $manager, , $website, $keyword] = $this->services(new SequenceRankAdapter([]));
        $request = $manager->recordManual(1, $website, $keyword, 12, 'https://www.example.com/ranking-page');
        $status = $manager->status(1, $request);
        $this->assertSame('completed', $status['status']);
        $this->assertSame('client', $status['execution_source']);
        $this->assertSame(12, (int) $status['result']['position']);
        $this->assertSame('manual', $status['result']['adapter_key']);
        $this->assertSame('https://www.example.com/ranking-page', $status['result']['ranking_url']);
        $this->assertSame(1, (int) $database->fetchOne("SELECT COUNT(*) AS total FROM audit_logs WHERE action='rank_check.manual_recorded'")['total']);

        $rejected = false;
        try { $manager->recordManual(1, $website, $keyword, 3, 'https://attacker.example/rank'); } catch (InvalidArgumentException) { $rejected = true; }
        $this->assertTrue($rejected);
    }

    private function services(RankAdapter $adapter, string $device = 'desktop', int $managerRateLimit = 10): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.com', 'correct-horse-battery', 'Tracker');
        $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '0.9.0', 7, new Logger(sys_get_temp_dir() . '/seo-rank-migration.log')))->run();
        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        $websites = new WebsiteManager($database, $authorization, $audit);
        $keywords = new KeywordManager($database, $authorization, $audit);
        $website = $websites->create(1, WebsiteInput::from('Example', 'https://example.com', ''));
        $keyword = $keywords->create(1, $website, $this->keywordInput('seo tracker', true, $device));
        $registry = new RankAdapterRegistry([$adapter]);
        return [$database, new RankCheckManager($database, $authorization, $audit, $registry, $adapter->key(), $managerRateLimit, 900), new RankWorker($database, $registry, new Logger(sys_get_temp_dir() . '/seo-rank-worker.log'), 3, 120), $website, $keyword, $keywords, new UserManager($database, $authorization, $audit, new PasswordHasher()), new RoleManager($database, $authorization, $audit)];
    }

    private function keywordInput(string $text, bool $active, string $device = 'desktop'): KeywordInput
    {
        return KeywordInput::from(['keyword' => $text, 'target_url' => 'https://example.com', 'search_engine' => 'google', 'country' => 'US', 'language' => 'en', 'device' => $device, 'active' => $active], ['google'], ['desktop', 'mobile'], ['US']);
    }

    private function ranked(int $position, string $url, string $device): RankExecutionResult
    {
        return new RankExecutionResult('ranked', $position, $url, 100, $device, $device . ':1', gmdate('Y-m-d H:i:s'));
    }

    private function notFound(string $device): RankExecutionResult
    {
        return new RankExecutionResult('not_found', null, null, 100, $device, $device . ':1', gmdate('Y-m-d H:i:s'));
    }
}
