<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Rbac\AuthorizationException;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use App\Modules\SearchConsole\Application\OAuthStateStore;
use App\Modules\SearchConsole\Application\SearchConsoleConnectionService;
use App\Modules\SearchConsole\Application\SearchConsoleSyncManager;
use App\Modules\SearchConsole\Application\SearchConsoleSyncWorker;
use App\Modules\SearchConsole\Domain\SearchConsoleGateway;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;
use App\Modules\SearchConsole\Infrastructure\OpenSslTokenVault;
use App\Modules\Websites\Application\WebsiteManager;
use App\Modules\Websites\Domain\WebsiteInput;
use PDO;
use Tests\Support\ArraySessionStore;
use Tests\TestCase;

final class FakeSyncGateway implements SearchConsoleGateway
{
    public string $state = ''; public array $failures = []; public array $failAt = []; public array $pages = []; public int $refreshes = 0; public array $analyticsCalls = [];
    public array $token = ['access_token' => 'initial-access', 'refresh_token' => 'refresh-token', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly'];
    public array $rows = [];
    public function authorizationUrl(string $state, string $codeChallenge): string { $this->state = $state; return 'https://accounts.google.com/auth'; }
    public function exchange(string $authorizationCode, string $codeVerifier): array { return $this->token; }
    public function refresh(string $refreshToken): array { $this->refreshes++; if (($this->failures[0] ?? null) === 'authorization_revoked') { array_shift($this->failures); throw new SearchConsoleUnavailable('authorization_revoked'); } return ['access_token' => 'refreshed-access', 'expires_in' => 3600, 'scope' => $this->token['scope']]; }
    public function properties(string $accessToken): array { return [['uri' => 'sc-domain:example.com', 'type' => 'domain', 'permission' => 'siteOwner']]; }
    public function revoke(string $token): void {}
    public function searchAnalytics(string $accessToken, string $propertyUri, string $startDate, string $endDate, string $searchType, int $startRow, int $rowLimit): array
    {
        $this->analyticsCalls[] = compact('accessToken', 'propertyUri', 'startDate', 'endDate', 'searchType', 'startRow', 'rowLimit');
        if (isset($this->failAt[$startRow])) throw new SearchConsoleUnavailable((string) $this->failAt[$startRow]);
        if ($this->failures !== []) throw new SearchConsoleUnavailable((string) array_shift($this->failures));
        if ($this->pages !== []) return ['rows' => $this->pages[$startRow] ?? [], 'next_start_row' => array_key_exists($startRow + $rowLimit, $this->pages) ? $startRow + $rowLimit : null];
        return ['rows' => $this->rows, 'next_start_row' => null];
    }
}

final class SearchConsoleSyncTest extends TestCase
{
    public function testNormalSyncStoresDimensionsMetricsAndLifecycle(): void
    {
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(); $gateway->rows = $this->rows($start);
        $id = $manager->submit(1, $website, $start, $end, 'web'); $this->assertSame(1, $worker->work());
        $status = $manager->status(1, $id); $this->assertSame('completed', $status['status']); $this->assertSame(2, $status['rows_saved']);
        $this->assertSame(['started', 'fetching', 'processing', 'saving', 'completed'], array_column($status['logs'], 'state'));
        $data = $database->fetchAll('SELECT * FROM search_console_data ORDER BY query_text'); $this->assertSame(2, count($data));
        $this->assertSame('desktop', $data[0]['device']); $this->assertSame('usa', $data[0]['country']); $this->assertSame('web', $data[0]['search_type']);
        $this->assertSame(4, (int) $data[0]['clicks']); $this->assertSame(20, (int) $data[0]['impressions']); $this->assertSame(0.2, (float) $data[0]['ctr']); $this->assertSame(3.5, (float) $data[0]['average_position']);
    }

    public function testRepeatedRangeUpsertsAndActiveDuplicateReturnsSameJob(): void
    {
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(); $gateway->rows = $this->rows($start);
        $first = $manager->submit(1, $website, $start, $end, 'web'); $this->assertSame($first, $manager->submit(1, $website, $start, $end, 'web')); $worker->work();
        $gateway->rows[0]['clicks'] = 9; $gateway->rows[0]['ctr'] = 0.45; $second = $manager->submit(1, $website, $start, $end, 'web');
        $this->assertTrue($first !== $second); $worker->work(); $this->assertSame(2, count($database->fetchAll('SELECT id FROM search_console_data')));
        $this->assertSame(9, (int) $database->fetchOne("SELECT clicks FROM search_console_data WHERE query_text = 'alpha'")['clicks']);
    }

    public function testMultipleRangesRemainDistinct(): void
    {
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(); $gateway->rows = $this->rows($start); $manager->submit(1, $website, $start, $end, 'web'); $worker->work();
        $secondDate = gmdate('Y-m-d', strtotime($start . ' -1 day')); $gateway->rows = $this->rows($secondDate); $manager->submit(1, $website, $secondDate, $secondDate, 'web'); $worker->work();
        $this->assertSame(4, count($database->fetchAll('SELECT id FROM search_console_data')));
    }

    public function testRateLimitRetriesThenSucceedsWithoutStorm(): void
    {
        [, $manager, $worker, $gateway, $website, $start, $end] = $this->services(); $gateway->rows = $this->rows($start); $gateway->failures = ['rate_limited'];
        $id = $manager->submit(1, $website, $start, $end, 'web'); $now = time(); $worker->work(now: $now);
        $this->assertSame('retry_wait', $manager->status(1, $id)['status']); $this->assertSame(0, $worker->work(now: $now + 29));
        $this->assertSame(1, $worker->work(now: $now + 31)); $this->assertSame('completed', $manager->status(1, $id)['status']);
    }

    public function testApiErrorFailsAndCoreRemainsAvailable(): void
    {
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(); $gateway->failures = ['api_error'];
        $id = $manager->submit(1, $website, $start, $end, 'web'); $worker->work(); $status = $manager->status(1, $id);
        $this->assertSame('failed', $status['status']); $this->assertSame('api_error', $status['error_code']);
        $this->assertSame(1, count($database->fetchAll('SELECT id FROM websites'))); $this->assertSame(0, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM rank_check_requests')['total']);
    }

    public function testLaterPageFailurePublishesNoPartialData(): void
    {
        [$database, $manager, , $gateway, $website, $start, $end, $connections] = $this->services();
        $gateway->pages = [0 => $this->rows($start), 2 => $this->rows($start)]; $gateway->failAt = [2 => 'api_error'];
        $worker = new SearchConsoleSyncWorker($database, $connections, $gateway, new Logger(sys_get_temp_dir() . '/search-console-sync-stage.log'), 3, 300, 2, 10);
        $id = $manager->submit(1, $website, $start, $end, 'web'); $worker->work();
        $this->assertSame('failed', $manager->status(1, $id)['status']); $this->assertSame(0, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM search_console_data')['total']); $this->assertSame(0, (int) $database->fetchOne('SELECT COUNT(*) AS total FROM search_console_sync_stage')['total']);
    }

    public function testTokenRefreshAndRevocationPaths(): void
    {
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(expiresIn: 60); $gateway->rows = $this->rows($start);
        $id = $manager->submit(1, $website, $start, $end, 'web'); $worker->work(); $this->assertSame('completed', $manager->status(1, $id)['status']); $this->assertSame(1, $gateway->refreshes); $this->assertSame('refreshed-access', $gateway->analyticsCalls[0]['accessToken']);
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(expiresIn: 60); $gateway->failures = ['authorization_revoked'];
        $id = $manager->submit(1, $website, $start, $end, 'web'); $worker->work(); $this->assertSame('failed', $manager->status(1, $id)['status']);
        $connection = $database->fetchOne('SELECT status,credential_envelope FROM search_console_connections ORDER BY id DESC LIMIT 1'); $this->assertSame('revoked', $connection['status']); $this->assertSame(null, $connection['credential_envelope']);
    }

    public function testValidationAuthorizationAndResponseRejection(): void
    {
        [$database, $manager, $worker, $gateway, $website, $start, $end] = $this->services(); $invalid = false;
        try { $manager->submit(1, $website, $end, $start, 'web'); } catch (\InvalidArgumentException) { $invalid = true; } $this->assertTrue($invalid);
        $invalidType = false; try { $manager->submit(1, $website, $start, $end, 'everything'); } catch (\InvalidArgumentException) { $invalidType = true; } $this->assertTrue($invalidType);
        $database->execute("INSERT INTO users (name,email,password_hash,email_verified_at,disabled_at,created_at,updated_at) VALUES ('Viewer','sync-viewer@example.test','x',NULL,NULL,:now,:now)", ['now' => gmdate('Y-m-d H:i:s')]); $viewer = (int) $database->fetchOne("SELECT id FROM users WHERE email='sync-viewer@example.test'")['id'];
        $denied = false; try { $manager->submit($viewer, $website, $start, $end, 'web'); } catch (AuthorizationException) { $denied = true; } $this->assertTrue($denied);
        $gateway->rows = [["keys" => [$start, 'query', 'file:///etc/passwd', 'DESKTOP', 'usa'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1]];
        $id = $manager->submit(1, $website, $start, $end, 'web'); $worker->work(); $this->assertSame('response_invalid', $manager->status(1, $id)['error_code']);
    }

    private function services(int $expiresIn = 3600): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        (new SchemaInstaller())->install($pdo, 'Administrator', 'admin@example.test', 'correct-horse-battery', 'Tracker'); $database = new Database($pdo);
        (new MigrationRunner($database, new MigrationDiscovery(dirname(__DIR__, 2) . '/database/migrations'), '1.3.0', 11, new Logger(sys_get_temp_dir() . '/search-console-sync-migration.log')))->run(); $database->execute("UPDATE modules SET enabled=1 WHERE module_key='search_console'");
        $authorization = new Authorization($database); $audit = new AuditRecorder($database); $websites = new WebsiteManager($database, $authorization, $audit); $website = $websites->create(1, WebsiteInput::from('Example', 'https://example.com', ''));
        $gateway = new FakeSyncGateway(); $gateway->token['expires_in'] = $expiresIn; $session = new ArraySessionStore(); $vault = new OpenSslTokenVault(base64_encode(str_repeat('s', 32)), 'test');
        $connections = new SearchConsoleConnectionService($database, $authorization, $audit, new OAuthStateStore($session), $gateway, $vault); $connections->begin(1, $website); $result = $connections->complete(1, $gateway->state, 'valid-code-123'); $property = $connections->properties(1, $website, $result['connection'])[0]; $connections->select(1, $website, $result['connection'], $property['public_id']);
        $manager = new SearchConsoleSyncManager($database, $authorization, $audit, 31); $worker = new SearchConsoleSyncWorker($database, $connections, $gateway, new Logger(sys_get_temp_dir() . '/search-console-sync.log'), 3, 300, 25000, 250000);
        $end = gmdate('Y-m-d', time() - 86400); $start = gmdate('Y-m-d', time() - 2 * 86400); return [$database, $manager, $worker, $gateway, $website, $start, $end, $connections];
    }

    private function rows(string $date): array
    {
        return [
            ['keys' => [$date, 'alpha', 'https://example.com/a', 'DESKTOP', 'usa'], 'clicks' => 4, 'impressions' => 20, 'ctr' => 0.2, 'position' => 3.5],
            ['keys' => [$date, 'beta', 'https://example.com/b', 'MOBILE', 'gbr'], 'clicks' => 2, 'impressions' => 10, 'ctr' => 0.2, 'position' => 8.25],
        ];
    }
}
