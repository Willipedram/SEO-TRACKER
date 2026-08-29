<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Database\Database;
use App\Core\Logging\Logger;
use App\Modules\SearchConsole\Domain\SearchConsoleGateway;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;
use Throwable;

final class SearchConsoleSyncWorker
{
    private const RETRYABLE = ['rate_limited', 'api_unavailable', 'google_unavailable', 'token_refresh_failed', 'lease_expired'];

    public function __construct(
        private readonly Database $database, private readonly SearchConsoleConnectionService $connections,
        private readonly SearchConsoleGateway $gateway, private readonly Logger $logger,
        private readonly int $maxAttempts = 3, private readonly int $leaseSeconds = 300,
        private readonly int $pageSize = 25000, private readonly int $maxRows = 250000,
    ) {}

    public function work(int $limit = 3, ?int $now = null): int
    {
        $now ??= time(); $limit = max(1, min(20, $limit)); $this->reap($now); $processed = 0;
        while ($processed < $limit) { $sync = $this->claim($now); if ($sync === null) break; $this->execute($sync, $now); $processed++; }
        return $processed;
    }

    private function claim(int $now): ?array
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        return $this->database->transaction(function (Database $database) use ($at, $now): ?array {
            $sync = $database->fetchOne("SELECT s.*, p.property_uri, c.public_id AS connection_public_id FROM search_console_syncs s JOIN search_console_properties p ON p.id = s.property_id JOIN search_console_connections c ON c.id = p.connection_id WHERE s.status IN ('pending','retry_wait') AND s.available_at <= :available ORDER BY s.available_at,s.id LIMIT 1", ['available' => $at]);
            if ($sync === null) return null;
            $updated = $database->execute("UPDATE search_console_syncs SET status = 'running', phase = 'fetching', attempt_count = attempt_count + 1, lease_expires_at = :lease, started_at = COALESCE(started_at,:started), error_code = NULL, error_detail = NULL, updated_at = :updated WHERE id = :id AND status IN ('pending','retry_wait') AND available_at <= :available", ['lease' => gmdate('Y-m-d H:i:s', $now + $this->leaseSeconds), 'started' => $at, 'updated' => $at, 'id' => $sync['id'], 'available' => $at]);
            if ($updated !== 1) return null;
            $sync['attempt_count'] = (int) $sync['attempt_count'] + 1; $this->log($database, (int) $sync['id'], 'fetching', null, 'Fetching Search Console data.', $at);
            return $sync;
        });
    }

    private function execute(array $sync, int $now): void
    {
        try {
            $accessToken = $this->connections->accessTokenForSync((int) $sync['user_id'], (string) $sync['connection_public_id']);
            $startRow = 0; $fetched = 0; $saved = 0;
            do {
                $response = $this->gateway->searchAnalytics($accessToken, (string) $sync['property_uri'], (string) $sync['start_date'], (string) $sync['end_date'], (string) $sync['search_type'], $startRow, $this->pageSize);
                $rows = $response['rows'] ?? null; $next = $response['next_start_row'] ?? null;
                if (!is_array($rows) || ($next !== null && (!is_int($next) || $next <= $startRow))) throw new SearchConsoleUnavailable('response_invalid');
                $fetched += count($rows);
                if ($fetched > $this->maxRows) throw new SearchConsoleUnavailable('result_too_large');
                $this->phase((int) $sync['id'], 'processing', 'Processing Search Console data.', $now, $fetched, $saved);
                $normalized = []; foreach ($rows as $row) $normalized[] = $this->normalize($row, $sync);
                $this->phase((int) $sync['id'], 'saving', 'Saving Search Console data.', $now, $fetched, $saved);
                $saved += $this->stage($sync, $normalized);
                $startRow = $next ?? 0;
            } while ($next !== null);
            $this->promote($sync, $fetched, $now);
        } catch (SearchConsoleUnavailable $exception) { $this->fail($sync, $exception->getMessage(), $now); }
        catch (Throwable $exception) {
            $this->logger->error('Search Console sync raised an unexpected exception.', ['sync_id' => $sync['public_id'], 'exception_class' => $exception::class]);
            $this->fail($sync, 'internal_error', $now);
        }
    }

    private function normalize(mixed $row, array $sync): array
    {
        if (!is_array($row) || !is_array($row['keys'] ?? null) || count($row['keys']) !== 5) throw new SearchConsoleUnavailable('response_invalid');
        [$date, $query, $page, $device, $country] = $row['keys'];
        $parsedDate = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC')) : false;
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date || $date < $sync['start_date'] || $date > $sync['end_date']) throw new SearchConsoleUnavailable('response_invalid');
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $query)) throw new SearchConsoleUnavailable('response_invalid');
        if (!is_string($page) || strlen($page) > 2048 || preg_match('/[\x00-\x20\x7F]/', $page) || filter_var($page, FILTER_VALIDATE_URL) === false || !in_array(parse_url($page, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($page, PHP_URL_USER) !== null || parse_url($page, PHP_URL_PASS) !== null || parse_url($page, PHP_URL_FRAGMENT) !== null) throw new SearchConsoleUnavailable('response_invalid');
        $device = strtolower((string) $device); $country = strtolower((string) $country);
        if (!in_array($device, ['desktop', 'mobile', 'tablet'], true) || !preg_match('/^[a-z]{3}$/', $country)) throw new SearchConsoleUnavailable('response_invalid');
        $clicks = filter_var($row['clicks'] ?? null, FILTER_VALIDATE_INT); $impressions = filter_var($row['impressions'] ?? null, FILTER_VALIDATE_INT);
        $ctr = is_numeric($row['ctr'] ?? null) ? (float) $row['ctr'] : -1; $position = is_numeric($row['position'] ?? null) ? (float) $row['position'] : -1;
        if ($clicks === false || $clicks < 0 || $impressions === false || $impressions < 0 || $ctr < 0 || $ctr > 1 || !is_finite($ctr) || $position < 0 || !is_finite($position)) throw new SearchConsoleUnavailable('response_invalid');
        $dimensions = ['property' => (int) $sync['property_id'], 'date' => $date, 'query' => $query, 'page' => $page, 'device' => $device, 'country' => $country, 'type' => $sync['search_type']];
        return $dimensions + ['hash' => hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position];
    }

    private function stage(array $sync, array $rows): int
    {
        return $this->database->transaction(function (Database $database) use ($sync, $rows): int {
            foreach ($rows as $row) {
                $parameters = ['sync' => $sync['id'], 'hash' => $row['hash'], 'date' => $row['date'], 'query' => $row['query'], 'page' => $row['page'], 'device' => $row['device'], 'country' => $row['country'], 'type' => $row['type'], 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ctr' => $row['ctr'], 'position' => $row['position']];
                if ($database->driver() === 'mysql') $database->execute('INSERT INTO search_console_sync_stage (sync_id,dimension_hash,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position) VALUES (:sync,:hash,:date,:query,:page,:device,:country,:type,:clicks,:impressions,:ctr,:position) ON DUPLICATE KEY UPDATE clicks=VALUES(clicks),impressions=VALUES(impressions),ctr=VALUES(ctr),average_position=VALUES(average_position)', $parameters);
                else $database->execute('INSERT INTO search_console_sync_stage (sync_id,dimension_hash,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position) VALUES (:sync,:hash,:date,:query,:page,:device,:country,:type,:clicks,:impressions,:ctr,:position) ON CONFLICT(sync_id,dimension_hash) DO UPDATE SET clicks=excluded.clicks,impressions=excluded.impressions,ctr=excluded.ctr,average_position=excluded.average_position', $parameters);
            }
            return count($rows);
        });
    }

    private function promote(array $sync, int $fetched, int $now): void
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        $this->database->transaction(function (Database $database) use ($sync, $fetched, $at): void {
            $saved = (int) ($database->fetchOne('SELECT COUNT(*) AS total FROM search_console_sync_stage WHERE sync_id = :sync', ['sync' => $sync['id']])['total'] ?? 0);
            $parameters = ['website' => $sync['website_id'], 'property' => $sync['property_id'], 'last_sync' => $sync['id'], 'stage_sync' => $sync['id'], 'created' => $at, 'updated' => $at];
            if ($database->driver() === 'mysql') $database->execute('INSERT INTO search_console_data (dimension_hash,website_id,property_id,last_sync_id,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,created_at,updated_at) SELECT dimension_hash,:website,:property,:last_sync,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,:created,:updated FROM search_console_sync_stage WHERE sync_id=:stage_sync ON DUPLICATE KEY UPDATE last_sync_id=VALUES(last_sync_id),clicks=VALUES(clicks),impressions=VALUES(impressions),ctr=VALUES(ctr),average_position=VALUES(average_position),updated_at=VALUES(updated_at)', $parameters);
            else $database->execute('INSERT INTO search_console_data (dimension_hash,website_id,property_id,last_sync_id,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,created_at,updated_at) SELECT dimension_hash,:website,:property,:last_sync,data_date,query_text,page_url,device,country,search_type,clicks,impressions,ctr,average_position,:created,:updated FROM search_console_sync_stage WHERE sync_id=:stage_sync AND 1=1 ON CONFLICT(dimension_hash) DO UPDATE SET last_sync_id=excluded.last_sync_id,clicks=excluded.clicks,impressions=excluded.impressions,ctr=excluded.ctr,average_position=excluded.average_position,updated_at=excluded.updated_at', $parameters);
            $database->execute('DELETE FROM search_console_sync_stage WHERE sync_id = :sync', ['sync' => $sync['id']]);
            $database->execute("UPDATE search_console_syncs SET status = 'completed', phase = 'completed', rows_fetched = :fetched, rows_saved = :saved, lease_expires_at = NULL, completed_at = :completed, updated_at = :updated WHERE id = :id AND status = 'running'", ['fetched' => $fetched, 'saved' => $saved, 'completed' => $at, 'updated' => $at, 'id' => $sync['id']]);
            $this->log($database, (int) $sync['id'], 'completed', null, 'Synchronization completed.', $at);
        });
    }

    private function phase(int $syncId, string $phase, string $message, int $now, int $fetched, int $saved): void
    {
        $at = gmdate('Y-m-d H:i:s', $now); $this->database->transaction(function (Database $database) use ($syncId, $phase, $message, $at, $fetched, $saved): void {
            $database->execute('UPDATE search_console_syncs SET phase = :phase, rows_fetched = :fetched, rows_saved = :saved, lease_expires_at = :lease, updated_at = :updated WHERE id = :id AND status = :status', ['phase' => $phase, 'fetched' => $fetched, 'saved' => $saved, 'lease' => gmdate('Y-m-d H:i:s', time() + $this->leaseSeconds), 'updated' => $at, 'id' => $syncId, 'status' => 'running']);
            $this->log($database, $syncId, $phase, null, $message, $at);
        });
    }

    private function fail(array $sync, string $code, int $now): void
    {
        $allowed = ['rate_limited', 'api_unavailable', 'google_unavailable', 'token_refresh_failed', 'authorization_revoked', 'api_error', 'response_invalid', 'result_too_large', 'lease_expired', 'module_disabled', 'internal_error'];
        if (!in_array($code, $allowed, true)) $code = 'internal_error';
        if ($code === 'authorization_revoked') { try { $this->connections->markRevokedForSync((int) $sync['user_id'], (string) $sync['connection_public_id']); } catch (Throwable) {} }
        $retry = in_array($code, self::RETRYABLE, true) && (int) $sync['attempt_count'] < $this->maxAttempts;
        $at = gmdate('Y-m-d H:i:s', $now); $detail = $this->detail($code);
        $this->database->transaction(function (Database $database) use ($sync, $code, $retry, $at, $now, $detail): void {
            $database->execute('DELETE FROM search_console_sync_stage WHERE sync_id = :sync', ['sync' => $sync['id']]);
            if ($retry) $database->execute("UPDATE search_console_syncs SET status = 'retry_wait', phase = 'failed', available_at = :available, lease_expires_at = NULL, error_code = :code, error_detail = :detail, updated_at = :updated WHERE id = :id AND status = 'running'", ['available' => gmdate('Y-m-d H:i:s', $now + min(900, 30 * (2 ** ((int) $sync['attempt_count'] - 1)))), 'code' => $code, 'detail' => $detail, 'updated' => $at, 'id' => $sync['id']]);
            else $database->execute("UPDATE search_console_syncs SET status = 'failed', phase = 'failed', lease_expires_at = NULL, error_code = :code, error_detail = :detail, completed_at = :completed, updated_at = :updated WHERE id = :id AND status = 'running'", ['code' => $code, 'detail' => $detail, 'completed' => $at, 'updated' => $at, 'id' => $sync['id']]);
            $this->log($database, (int) $sync['id'], 'failed', $code, $detail, $at);
        });
        $this->logger->warning('Search Console sync failed.', ['sync_id' => $sync['public_id'], 'error_code' => $code, 'retrying' => $retry]);
    }

    private function reap(int $now): void
    {
        $expired = $this->database->fetchAll("SELECT s.*, c.public_id AS connection_public_id FROM search_console_syncs s JOIN search_console_properties p ON p.id=s.property_id JOIN search_console_connections c ON c.id=p.connection_id WHERE s.status='running' AND s.lease_expires_at < :now", ['now' => gmdate('Y-m-d H:i:s', $now)]);
        foreach ($expired as $sync) $this->fail($sync, 'lease_expired', $now);
    }

    private function detail(string $code): string
    {
        return match ($code) { 'rate_limited' => 'Google rate limited the synchronization.', 'api_unavailable', 'google_unavailable', 'token_refresh_failed' => 'Google is temporarily unavailable.', 'authorization_revoked' => 'Google authorization was revoked or expired.', 'response_invalid' => 'Google returned data that could not be safely accepted.', 'result_too_large' => 'The result exceeded the configured synchronization limit.', 'lease_expired' => 'The synchronization lease expired.', 'module_disabled' => 'Search Console is disabled.', 'api_error' => 'Google rejected the synchronization request.', default => 'Synchronization failed.' };
    }

    private function log(Database $database, int $syncId, string $state, ?string $error, string $message, string $at): void
    {
        $database->execute('INSERT INTO search_console_sync_logs (sync_id,state,error_code,message,occurred_at) VALUES (:sync,:state,:error,:message,:at)', ['sync' => $syncId, 'state' => $state, 'error' => $error, 'message' => $message, 'at' => $at]);
    }
}
