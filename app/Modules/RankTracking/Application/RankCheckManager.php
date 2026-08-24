<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Application;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\RankTracking\Infrastructure\RankAdapterRegistry;
use InvalidArgumentException;

final class RankCheckManager
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit, private readonly RankAdapterRegistry $adapters, private readonly string $adapterKey, private readonly int $rateLimit = 10, private readonly int $rateWindow = 900) {}

    public function submit(int $actorId, string $websitePublicId, string $keywordPublicId): string
    {
        $this->authorization->require($actorId, 'rank_tracking.run');
        $adapter = $this->adapters->get($this->adapterKey);
        if ($adapter === null) throw new InvalidArgumentException('No approved Rank Tracking adapter is configured.');
        $keyword = $this->keyword($actorId, $websitePublicId, $keywordPublicId);
        if ((int) $keyword['active'] !== 1) throw new InvalidArgumentException('Only active keywords can be checked.');
        if ($keyword['website_status'] === 'archived') throw new InvalidArgumentException('Archived websites cannot run rank checks.');
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->rateWindow);
        $count = (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM rank_check_requests WHERE user_id = :user AND created_at >= :cutoff', ['user' => $actorId, 'cutoff' => $cutoff])['total'] ?? 0);
        if ($count >= $this->rateLimit) throw new InvalidArgumentException('Rank check rate limit reached. Wait before trying again.');
        $publicId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($actorId, $keyword, $adapter, $publicId, $now): void {
            $database->execute('INSERT INTO rank_check_requests (public_id, user_id, website_id, keyword_id, keyword_text, target_url, search_engine, country_code, language_code, requested_device, execution_source, adapter_key, status, attempt_count, available_at, created_at, started_at, completed_at, error_code, error_detail) VALUES (:public, :user, :website, :keyword, :text, :target, :engine, :country, :language, :device, :source, :adapter, :status, 0, :available, :created, NULL, NULL, NULL, NULL)', ['public' => $publicId, 'user' => $actorId, 'website' => $keyword['website_id'], 'keyword' => $keyword['id'], 'text' => $keyword['keyword_text'], 'target' => $keyword['target_url'], 'engine' => $keyword['search_engine'], 'country' => $keyword['country_code'], 'language' => $keyword['language_code'], 'device' => $keyword['device'], 'source' => $adapter->executionSource(), 'adapter' => $adapter->key(), 'status' => 'pending', 'available' => $now, 'created' => $now]);
            $this->audit->record($actorId, 'rank_check.requested', 'rank_check', $publicId, ['source' => $adapter->executionSource(), 'device' => $keyword['device']]);
        });
        return $publicId;
    }

    public function status(int $actorId, string $requestId): array
    {
        $this->authorization->require($actorId, 'rank_tracking.view');
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) throw new InvalidArgumentException('Rank check not found.');
        $request = $this->database->fetchOne('SELECT public_id, status, requested_device, execution_source, attempt_count, created_at, started_at, completed_at, error_code, error_detail FROM rank_check_requests WHERE public_id = :public AND user_id = :user', ['public' => $requestId, 'user' => $actorId]);
        if ($request === null) throw new InvalidArgumentException('Rank check not found.');
        $request['result'] = $this->database->fetchOne('SELECT rank_results.result_type, rank_results.position, rank_results.ranking_url, rank_results.checked_depth, rank_results.execution_device, rank_results.adapter_key, rank_results.adapter_version, rank_results.observed_at FROM rank_results JOIN rank_check_requests ON rank_check_requests.id = rank_results.request_id WHERE rank_check_requests.public_id = :public AND rank_check_requests.user_id = :user', ['public' => $requestId, 'user' => $actorId]);
        return $request;
    }

    public function history(int $actorId, string $websitePublicId, string $keywordPublicId): array
    {
        $this->authorization->require($actorId, 'rank_tracking.view');
        $keyword = $this->keyword($actorId, $websitePublicId, $keywordPublicId);
        return $this->database->fetchAll('SELECT rank_results.public_id, rank_results.result_type, rank_results.position, rank_results.ranking_url, rank_results.checked_depth, rank_results.requested_device, rank_results.execution_device, rank_results.execution_source, rank_results.adapter_key, rank_results.adapter_version, rank_results.observed_at FROM rank_results WHERE keyword_id = :keyword ORDER BY observed_at DESC, id DESC', ['keyword' => $keyword['id']]);
    }

    private function keyword(int $actorId, string $websitePublicId, string $keywordPublicId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $websitePublicId) || !preg_match('/^[a-f0-9]{32}$/', $keywordPublicId)) throw new InvalidArgumentException('Keyword not found.');
        $row = $this->database->fetchOne('SELECT keywords.*, websites.id AS website_id, websites.status AS website_status FROM keywords JOIN websites ON websites.id = keywords.website_id WHERE keywords.public_id = :keyword AND websites.public_id = :website AND websites.owner_user_id = :owner', ['keyword' => $keywordPublicId, 'website' => $websitePublicId, 'owner' => $actorId]);
        if ($row === null) throw new InvalidArgumentException('Keyword not found.');
        return $row;
    }
}
