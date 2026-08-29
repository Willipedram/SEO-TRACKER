<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Application;

use App\Core\Database\Database;
use App\Core\Rbac\Authorization;
use InvalidArgumentException;

final class RankDashboardService
{
    public const THRESHOLDS = [1, 3, 5, 10, 20, 50, 100];

    public function __construct(private readonly Database $database, private readonly Authorization $authorization) {}

    public function dashboard(int $actorId, string $websitePublicId, ?string $keywordPublicId = null, string $device = 'all', string $range = '30'): array
    {
        $this->authorization->require($actorId, 'rank_tracking.view');
        $website = $this->website($actorId, $websitePublicId);
        $device = $this->device($device);
        $since = $this->since($range);
        $keywords = $this->database->fetchAll('SELECT id, public_id, keyword_text, normalized_keyword, search_engine, country_code, language_code, device, active FROM keywords WHERE website_id = :website ORDER BY keyword_text, device, id', ['website' => $website['id']]);
        if ($keywordPublicId !== null && !preg_match('/^[a-f0-9]{32}$/', $keywordPublicId)) throw new InvalidArgumentException('Keyword not found.');
        if ($keywordPublicId !== null && !in_array($keywordPublicId, array_column($keywords, 'public_id'), true)) throw new InvalidArgumentException('Keyword not found.');
        $parameters = ['website' => $website['id']];
        $sql = 'SELECT id, keyword_id, result_type, position, ranking_url, requested_device, execution_device, observed_at FROM rank_results WHERE website_id = :website';
        if ($since !== null) { $sql .= ' AND observed_at >= :since'; $parameters['since'] = $since; }
        $results = $this->database->fetchAll($sql . ' ORDER BY observed_at, id', $parameters);
        $byKeyword = [];
        foreach ($results as $result) $byKeyword[(int) $result['keyword_id']][] = $result;
        $latestByGroupDevice = [];
        foreach ($keywords as $keyword) {
            $history = $byKeyword[(int) $keyword['id']] ?? [];
            if ($history !== []) $latestByGroupDevice[$this->group($keyword)][(string) $keyword['device']] = $history[array_key_last($history)];
        }
        $rows = [];
        foreach ($keywords as $keyword) {
            if ($keywordPublicId !== null && $keyword['public_id'] !== $keywordPublicId) continue;
            if ($device !== 'all' && $keyword['device'] !== $device) continue;
            $history = $byKeyword[(int) $keyword['id']] ?? [];
            $current = $history === [] ? null : $history[array_key_last($history)];
            $previous = count($history) < 2 ? null : $history[count($history) - 2];
            $ranked = array_values(array_filter(array_column($history, 'position'), static fn (mixed $position): bool => $position !== null));
            $currentPosition = $current['position'] ?? null;
            $previousPosition = $previous['position'] ?? null;
            $change = $currentPosition !== null && $previousPosition !== null ? (int) $previousPosition - (int) $currentPosition : null;
            $paired = $latestByGroupDevice[$this->group($keyword)] ?? [];
            $rows[] = $keyword + [
                'current_position' => $currentPosition === null ? null : (int) $currentPosition,
                'previous_position' => $previousPosition === null ? null : (int) $previousPosition,
                'change' => $change,
                'change_state' => $change === null ? 'unavailable' : ($change > 0 ? 'improved' : ($change < 0 ? 'dropped' : 'unchanged')),
                'best_position' => $ranked === [] ? null : (int) min($ranked),
                'worst_position' => $ranked === [] ? null : (int) max($ranked),
                'ranking_url' => $current['ranking_url'] ?? null,
                'last_checked' => $current['observed_at'] ?? null,
                'desktop_position' => isset($paired['desktop']['position']) ? (int) $paired['desktop']['position'] : null,
                'mobile_position' => isset($paired['mobile']['position']) ? (int) $paired['mobile']['position'] : null,
            ];
        }
        return ['website' => $website, 'keywords' => $keywords, 'rows' => $rows, 'device' => $device, 'range' => $range];
    }

    public function chart(int $actorId, string $websitePublicId, string $keywordPublicId, string $device = 'all', string $range = '30'): array
    {
        $this->authorization->require($actorId, 'rank_tracking.view');
        $website = $this->website($actorId, $websitePublicId);
        $device = $this->device($device);
        if (!preg_match('/^[a-f0-9]{32}$/', $keywordPublicId)) throw new InvalidArgumentException('Keyword not found.');
        $selected = $this->database->fetchOne('SELECT id, keyword_text, normalized_keyword, search_engine, country_code, language_code FROM keywords WHERE public_id = :keyword AND website_id = :website', ['keyword' => $keywordPublicId, 'website' => $website['id']]);
        if ($selected === null) throw new InvalidArgumentException('Keyword not found.');
        $siblings = $this->database->fetchAll('SELECT id, device FROM keywords WHERE website_id = :website AND normalized_keyword = :keyword AND search_engine = :engine AND country_code = :country AND language_code = :language', ['website' => $website['id'], 'keyword' => $selected['normalized_keyword'], 'engine' => $selected['search_engine'], 'country' => $selected['country_code'], 'language' => $selected['language_code']]);
        $series = ['desktop' => [], 'mobile' => []];
        $since = $this->since($range);
        $ids = [];
        foreach ($siblings as $sibling) if ($device === 'all' || $sibling['device'] === $device) $ids[(int) $sibling['id']] = (string) $sibling['device'];
        if ($ids !== []) {
            $parameters = array_keys($ids);
            $sql = 'SELECT keyword_id, result_type, position, ranking_url, observed_at FROM rank_results WHERE keyword_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            if ($since !== null) { $sql .= ' AND observed_at >= ?'; $parameters[] = $since; }
            foreach ($this->database->fetchAll($sql . ' ORDER BY observed_at, id', $parameters) as $point) {
                $series[$ids[(int) $point['keyword_id']]][] = $point;
            }
        }
        $positions = [];
        foreach ($series as $points) foreach ($points as $point) if ($point['position'] !== null) $positions[] = (int) $point['position'];
        return ['keyword' => $selected, 'series' => $series, 'thresholds' => self::THRESHOLDS, 'max_position' => max([100, ...$positions]), 'range' => $range, 'device' => $device];
    }

    public static function y(int $position, int $maxPosition, int $height): float
    {
        if ($position < 1 || $maxPosition < 1 || $height < 1) throw new InvalidArgumentException('Invalid chart coordinate.');
        return (($position - 1) / max(1, $maxPosition - 1)) * $height;
    }

    private function website(int $actorId, string $publicId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Website not found.');
        $website = $this->database->fetchOne('SELECT id, public_id, site_name FROM websites WHERE public_id = :public AND owner_user_id = :owner', ['public' => $publicId, 'owner' => $actorId]);
        if ($website === null) throw new InvalidArgumentException('Website not found.');
        return $website;
    }

    private function device(string $device): string
    {
        if (!in_array($device, ['all', 'desktop', 'mobile'], true)) throw new InvalidArgumentException('Invalid device filter.');
        return $device;
    }

    private function since(string $range): ?string
    {
        if ($range === 'all') return null;
        if (!in_array($range, ['7', '30', '90', '365'], true)) throw new InvalidArgumentException('Invalid date range.');
        return gmdate('Y-m-d H:i:s', time() - ((int) $range * 86400));
    }

    private function group(array $keyword): string
    {
        return implode('|', [$keyword['normalized_keyword'], $keyword['search_engine'], $keyword['country_code'], $keyword['language_code']]);
    }
}
