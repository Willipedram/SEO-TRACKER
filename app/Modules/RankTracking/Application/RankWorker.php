<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Application;

use App\Core\Database\Database;
use App\Core\Logging\Logger;
use App\Modules\RankTracking\Domain\RankAdapterFailure;
use App\Modules\RankTracking\Domain\RankExecutionResult;
use App\Modules\RankTracking\Domain\RankJob;
use App\Modules\RankTracking\Infrastructure\RankAdapterRegistry;
use Throwable;

final class RankWorker
{
    private const RETRYABLE = ['rate_limited', 'provider_unavailable', 'network_timeout', 'lease_expired'];

    public function __construct(private readonly Database $database, private readonly RankAdapterRegistry $adapters, private readonly Logger $logger, private readonly int $maxAttempts = 3, private readonly int $leaseSeconds = 120) {}

    public function work(int $limit = 10, ?int $now = null, string $workerId = 'cron'): int
    {
        $now ??= time();
        $limit = max(1, min(100, $limit));
        $this->reapExpired($now);
        $processed = 0;
        while ($processed < $limit) {
            $claim = $this->claim($now, $workerId);
            if ($claim === null) break;
            $this->execute($claim, $now);
            $processed++;
        }
        return $processed;
    }

    private function claim(int $now, string $workerId): ?array
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        return $this->database->transaction(function (Database $database) use ($now, $workerId, $at): ?array {
            $request = $database->fetchOne("SELECT * FROM rank_check_requests WHERE status IN ('pending','retry_wait') AND available_at <= :available ORDER BY available_at, id LIMIT 1", ['available' => $at]);
            if ($request === null) return null;
            $updated = $database->execute("UPDATE rank_check_requests SET status = 'running', attempt_count = attempt_count + 1, started_at = COALESCE(started_at, :started), error_code = NULL, error_detail = NULL WHERE id = :id AND status IN ('pending','retry_wait') AND available_at <= :available", ['started' => $at, 'id' => $request['id'], 'available' => $at]);
            if ($updated !== 1) return null;
            $attempt = (int) $request['attempt_count'] + 1;
            $adapter = $this->adapters->get((string) $request['adapter_key']);
            $token = random_bytes(32);
            $attemptPublic = bin2hex(random_bytes(16));
            $database->execute('INSERT INTO rank_execution_attempts (public_id, request_id, attempt_number, execution_source, adapter_key, adapter_version, requested_device, execution_device, user_agent_profile, network_context, status, leased_by, lease_token_hash, lease_expires_at, started_at, completed_at, error_code, error_detail, retryable) VALUES (:public, :request, :number, :source, :adapter, :version, :device, NULL, NULL, :network, :status, :worker, :token, :expires, :started, NULL, NULL, NULL, 0)', ['public' => $attemptPublic, 'request' => $request['id'], 'number' => $attempt, 'source' => $request['execution_source'], 'adapter' => $request['adapter_key'], 'version' => $adapter?->version() ?? 'unavailable', 'device' => $request['requested_device'], 'network' => $request['execution_source'] === 'local_agent' ? 'agent_observed' : 'provider_egress', 'status' => 'running', 'worker' => substr($workerId, 0, 100), 'token' => hash('sha256', $token), 'expires' => gmdate('Y-m-d H:i:s', $now + $this->leaseSeconds), 'started' => $at]);
            $request['attempt_id'] = (int) $database->fetchOne('SELECT id FROM rank_execution_attempts WHERE public_id = :public', ['public' => $attemptPublic])['id'];
            $request['attempt_public_id'] = $attemptPublic;
            $request['attempt_number'] = $attempt;
            return $request;
        });
    }

    private function execute(array $request, int $now): void
    {
        $adapter = $this->adapters->get((string) $request['adapter_key']);
        if ($adapter === null) {
            $this->fail($request, new RankAdapterFailure('adapter_unsupported', false, 'Configured rank adapter is unavailable.'), $now);
            return;
        }
        try {
            $result = $adapter->execute(new RankJob((string) $request['public_id'], (string) $request['keyword_text'], $request['target_url'] === null ? null : (string) $request['target_url'], (string) $request['search_engine'], (string) $request['country_code'], (string) $request['language_code'], (string) $request['requested_device']));
            if (!$adapter->supportsExecutionDevice((string) $request['requested_device'], $result->executionDevice)) {
                throw new RankAdapterFailure('result_rejected', false, 'Adapter returned incompatible device semantics.');
            }
            $this->complete($request, $adapter->version(), $result, $now);
        } catch (RankAdapterFailure $failure) {
            $this->fail($request, $failure, $now);
        } catch (Throwable $exception) {
            $this->logger->error('Rank adapter raised an unexpected exception.', ['request_id' => $request['public_id'], 'attempt' => $request['attempt_number'], 'exception' => $exception]);
            $this->fail($request, new RankAdapterFailure('internal_error', false, 'Rank execution failed.'), $now);
        }
    }

    private function complete(array $request, string $adapterVersion, RankExecutionResult $result, int $now): void
    {
        $finished = gmdate('Y-m-d H:i:s', $now);
        $this->database->transaction(function (Database $database) use ($request, $adapterVersion, $result, $finished): void {
            $accepted = $database->execute("UPDATE rank_execution_attempts SET status = 'succeeded', execution_device = :execution_device, user_agent_profile = :profile, completed_at = :completed WHERE id = :id AND status = 'running' AND lease_expires_at >= :completed", ['execution_device' => $result->executionDevice, 'profile' => $result->userAgentProfile, 'completed' => $finished, 'id' => $request['attempt_id']]);
            if ($accepted !== 1) throw new RankAdapterFailure('result_rejected', false, 'Late or duplicate rank result was rejected.');
            $database->execute('INSERT INTO rank_results (public_id, request_id, attempt_id, website_id, keyword_id, result_type, position, ranking_url, checked_depth, search_engine, country_code, language_code, requested_device, execution_device, execution_source, adapter_key, adapter_version, observed_at, created_at) VALUES (:public, :request, :attempt, :website, :keyword, :type, :position, :url, :depth, :engine, :country, :language, :requested_device, :execution_device, :source, :adapter, :version, :observed, :created)', ['public' => bin2hex(random_bytes(16)), 'request' => $request['id'], 'attempt' => $request['attempt_id'], 'website' => $request['website_id'], 'keyword' => $request['keyword_id'], 'type' => $result->type, 'position' => $result->position, 'url' => $result->rankingUrl, 'depth' => $result->checkedDepth, 'engine' => $request['search_engine'], 'country' => $request['country_code'], 'language' => $request['language_code'], 'requested_device' => $request['requested_device'], 'execution_device' => $result->executionDevice, 'source' => $request['execution_source'], 'adapter' => $request['adapter_key'], 'version' => $adapterVersion, 'observed' => $result->observedAt, 'created' => $finished]);
            $database->execute("UPDATE rank_check_requests SET status = 'completed', completed_at = :completed, error_code = NULL, error_detail = NULL WHERE id = :id AND status = 'running'", ['completed' => $finished, 'id' => $request['id']]);
        });
    }

    private function fail(array $request, RankAdapterFailure $failure, int $now): void
    {
        $code = in_array($failure->errorCode, ['configuration_invalid', 'adapter_unsupported', 'authentication_failed', 'quota_exceeded', 'rate_limited', 'provider_unavailable', 'network_timeout', 'challenge_presented', 'consent_required', 'response_invalid', 'parse_failed', 'lease_expired', 'result_rejected', 'internal_error'], true) ? $failure->errorCode : 'internal_error';
        $detail = $this->safeDetail($code);
        $retryable = $failure->retryable && in_array($code, self::RETRYABLE, true) && (int) $request['attempt_number'] < $this->maxAttempts;
        $finished = gmdate('Y-m-d H:i:s', $now);
        $this->database->transaction(function (Database $database) use ($request, $code, $detail, $retryable, $finished, $now): void {
            $database->execute("UPDATE rank_execution_attempts SET status = 'failed', completed_at = :completed, error_code = :code, error_detail = :detail, retryable = :retryable WHERE id = :id AND status = 'running'", ['completed' => $finished, 'code' => $code, 'detail' => $detail, 'retryable' => $retryable ? 1 : 0, 'id' => $request['attempt_id']]);
            if ($retryable) {
                $delay = min(300, (2 ** (int) $request['attempt_number']) * 5);
                $database->execute("UPDATE rank_check_requests SET status = 'retry_wait', available_at = :available, error_code = :code, error_detail = :detail WHERE id = :id AND status = 'running'", ['available' => gmdate('Y-m-d H:i:s', $now + $delay), 'code' => $code, 'detail' => $detail, 'id' => $request['id']]);
            } else {
                $database->execute("UPDATE rank_check_requests SET status = 'failed', completed_at = :completed, error_code = :code, error_detail = :detail WHERE id = :id AND status = 'running'", ['completed' => $finished, 'code' => $code, 'detail' => $detail, 'id' => $request['id']]);
            }
        });
    }

    private function reapExpired(int $now): void
    {
        $expired = $this->database->fetchAll("SELECT rank_check_requests.*, rank_execution_attempts.id AS attempt_id, rank_execution_attempts.attempt_number FROM rank_execution_attempts JOIN rank_check_requests ON rank_check_requests.id = rank_execution_attempts.request_id WHERE rank_execution_attempts.status = 'running' AND rank_execution_attempts.lease_expires_at < :now", ['now' => gmdate('Y-m-d H:i:s', $now)]);
        foreach ($expired as $request) $this->fail($request, new RankAdapterFailure('lease_expired', true, 'Execution lease expired.'), $now);
    }

    private function safeDetail(string $code): string
    {
        return match ($code) {
            'rate_limited' => 'The execution source rate limited this check.',
            'provider_unavailable', 'network_timeout' => 'The execution source is temporarily unavailable.',
            'challenge_presented', 'consent_required' => 'The execution source could not produce a conclusive result.',
            'configuration_invalid', 'adapter_unsupported' => 'Rank Tracking is not configured for this request.',
            'authentication_failed' => 'The execution source credentials were rejected.',
            'quota_exceeded' => 'The execution source quota is exhausted.',
            'response_invalid', 'parse_failed', 'result_rejected' => 'The execution result could not be safely accepted.',
            'lease_expired' => 'The execution lease expired.',
            default => 'Rank execution failed.',
        };
    }
}
