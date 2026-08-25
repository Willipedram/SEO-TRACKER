<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Core\Database\Database;
use App\Core\Rbac\Authorization;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportService
{
    public const TYPES = ['website', 'keyword', 'ranking', 'search_console', 'improved', 'dropped', 'top10', 'top3', 'number1'];

    public function __construct(private readonly Database $database, private readonly Authorization $authorization) {}

    public function filterOptions(int $actorId): array
    {
        $this->authorization->require($actorId, 'reports.view');
        return ['websites' => $this->database->fetchAll('SELECT public_id,site_name FROM websites WHERE owner_user_id=:owner ORDER BY site_name,id', ['owner' => $actorId]), 'keywords' => $this->database->fetchAll('SELECT k.public_id,k.keyword_text,k.device,w.public_id AS website_public_id FROM keywords k JOIN websites w ON w.id=k.website_id WHERE w.owner_user_id=:owner ORDER BY k.keyword_text,k.device,k.id', ['owner' => $actorId])];
    }

    public function report(int $actorId, string $type, array $input = []): array
    {
        $this->authorization->require($actorId, 'reports.view');
        $module = $this->database->fetchOne("SELECT enabled FROM modules WHERE module_key='reports'"); if ($module !== null && !(bool) $module['enabled']) throw new InvalidArgumentException('Reports module is disabled.');
        if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Unknown report type.');
        $filters = $this->filters($actorId, $input); $page = $this->positiveInt($input['page'] ?? 1, 1, 100000); $perPage = $this->positiveInt($input['per_page'] ?? 50, 1, 100); $offset = ($page - 1) * $perPage;
        if ($type === 'website') [$rows, $total] = $this->websites($actorId, $filters, $perPage, $offset);
        elseif ($type === 'search_console') [$rows, $total, $state] = $this->searchConsole($actorId, $filters, $perPage, $offset);
        elseif ($type === 'keyword') [$rows, $total] = $this->keywords($actorId, $filters, $perPage, $offset);
        elseif ($type === 'ranking') [$rows, $total] = $this->rankings($actorId, $filters, $perPage, $offset);
        else [$rows, $total] = $this->movement($actorId, $type, $filters, $perPage, $offset);
        return ['type' => $type, 'source' => $type === 'search_console' ? 'search_console' : 'rank_tracker', 'state' => $state ?? ($rows === [] ? 'empty' : 'ready'), 'filters' => $filters, 'rows' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function csv(int $actorId, string $type, array $input = [], int $maximum = 10000): string
    {
        $input['page'] = 1; $input['per_page'] = 100; $written = 0; $stream = fopen('php://temp/maxmemory:2097152', 'w+'); if ($stream === false) throw new InvalidArgumentException('CSV export unavailable.');
        fwrite($stream, "\xEF\xBB\xBF"); $headersWritten = false;
        do {
            $report = $this->report($actorId, $type, $input);
            foreach ($report['rows'] as $row) {
                if (!$headersWritten) { fputcsv($stream, array_keys($row), ',', '"', ''); $headersWritten = true; }
                fputcsv($stream, array_map([$this, 'csvCell'], array_values($row)), ',', '"', ''); $written++; if ($written >= $maximum) break 2;
            }
            $input['page']++;
        } while ($input['page'] <= $report['pages']);
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return is_string($csv) ? $csv : '';
    }

    private function websites(int $actorId, array $f, int $limit, int $offset): array
    {
        $where = 'w.owner_user_id=:owner'; $p = ['owner' => $actorId]; if ($f['website_id'] !== null) { $where .= ' AND w.id=:website'; $p['website'] = $f['website_id']; }
        $total = (int) ($this->database->fetchOne("SELECT COUNT(*) AS total FROM websites w WHERE $where", $p)['total'] ?? 0);
        $rows = $this->database->fetchAll("SELECT w.public_id AS website_id,w.site_name,w.canonical_url,w.status,COUNT(DISTINCT k.id) AS keywords,COUNT(DISTINCT rr.id) AS rank_observations FROM websites w LEFT JOIN keywords k ON k.website_id=w.id LEFT JOIN rank_results rr ON rr.website_id=w.id AND rr.observed_at>=:start AND rr.observed_at<:after WHERE $where GROUP BY w.id,w.public_id,w.site_name,w.canonical_url,w.status ORDER BY w.site_name,w.id LIMIT $limit OFFSET $offset", $p + ['start' => $f['start_date'] . ' 00:00:00', 'after' => $f['after_date'] . ' 00:00:00']);
        return [$rows, $total];
    }

    private function keywords(int $actorId, array $f, int $limit, int $offset): array
    {
        [$where, $p] = $this->keywordWhere($actorId, $f); $total = (int) ($this->database->fetchOne("SELECT COUNT(*) AS total FROM keywords k JOIN websites w ON w.id=k.website_id WHERE $where", $p)['total'] ?? 0);
        $rows = $this->database->fetchAll("SELECT k.id,k.public_id AS keyword_id,w.site_name,k.keyword_text,k.search_engine,k.country_code,k.language_code,k.device,k.active FROM keywords k JOIN websites w ON w.id=k.website_id WHERE $where ORDER BY w.site_name,k.keyword_text,k.id LIMIT $limit OFFSET $offset", $p);
        return [$this->attachCurrentPositions($rows, $f), $total];
    }

    private function rankings(int $actorId, array $f, int $limit, int $offset): array
    {
        [$where, $p] = $this->rankWhere($actorId, $f); $total = (int) ($this->database->fetchOne("SELECT COUNT(*) AS total FROM rank_results rr JOIN keywords k ON k.id=rr.keyword_id JOIN websites w ON w.id=rr.website_id WHERE $where", $p)['total'] ?? 0);
        $rows = $this->database->fetchAll("SELECT w.site_name,k.keyword_text,rr.requested_device AS device,rr.result_type,rr.position AS rank_tracker_position,rr.ranking_url,rr.observed_at FROM rank_results rr JOIN keywords k ON k.id=rr.keyword_id JOIN websites w ON w.id=rr.website_id WHERE $where ORDER BY rr.observed_at DESC,rr.id DESC LIMIT $limit OFFSET $offset", $p);
        return [$rows, $total];
    }

    private function movement(int $actorId, string $type, array $f, int $limit, int $offset): array
    {
        [$keywordWhere, $p] = $this->keywordWhere($actorId, $f); $p += ['start' => $f['start_date'] . ' 00:00:00', 'after' => $f['after_date'] . ' 00:00:00'];
        $condition = match ($type) { 'improved' => 'previous_position IS NOT NULL AND current_position IS NOT NULL AND previous_position-current_position>0', 'dropped' => 'previous_position IS NOT NULL AND current_position IS NOT NULL AND previous_position-current_position<0', 'top10' => 'current_position BETWEEN 1 AND 10', 'top3' => 'current_position BETWEEN 1 AND 3', 'number1' => 'current_position=1', default => '1=0' };
        $cte = "WITH ordered AS (SELECT rr.keyword_id,rr.position,ROW_NUMBER() OVER (PARTITION BY rr.keyword_id ORDER BY rr.observed_at DESC,rr.id DESC) AS sequence FROM rank_results rr WHERE rr.observed_at>=:start AND rr.observed_at<:after), latest AS (SELECT keyword_id,MAX(CASE WHEN sequence=1 THEN position END) AS current_position,MAX(CASE WHEN sequence=2 THEN position END) AS previous_position FROM ordered WHERE sequence<=2 GROUP BY keyword_id), classified AS (SELECT k.public_id AS keyword_id,w.site_name,k.keyword_text,k.device,l.current_position,l.previous_position,l.previous_position-l.current_position AS change FROM keywords k JOIN websites w ON w.id=k.website_id JOIN latest l ON l.keyword_id=k.id WHERE $keywordWhere)";
        $total = (int) ($this->database->fetchOne("$cte SELECT COUNT(*) AS total FROM classified WHERE $condition", $p)['total'] ?? 0); $rows = $this->database->fetchAll("$cte SELECT * FROM classified WHERE $condition ORDER BY site_name,keyword_text,device LIMIT $limit OFFSET $offset", $p); return [$rows, $total];
    }

    private function searchConsole(int $actorId, array $f, int $limit, int $offset): array
    {
        $module = $this->database->fetchOne("SELECT enabled FROM modules WHERE module_key='search_console'"); if (!(bool) ($module['enabled'] ?? false)) return [[], 0, 'module_disabled'];
        $where = 'w.owner_user_id=:owner AND d.data_date>=:start AND d.data_date<=:end'; $p = ['owner' => $actorId, 'start' => $f['start_date'], 'end' => $f['end_date']];
        if ($f['website_id'] !== null) { $where .= ' AND d.website_id=:website'; $p['website'] = $f['website_id']; } if ($f['device'] !== 'all') { $where .= ' AND d.device=:device'; $p['device'] = $f['device']; } if ($f['country'] !== 'all') { $where .= ' AND d.country=:country'; $p['country'] = $f['country']; } if ($f['search_type'] !== 'all') { $where .= ' AND d.search_type=:search_type'; $p['search_type'] = $f['search_type']; } if ($f['query'] !== '') { $where .= ' AND INSTR(d.query_text,:query)>0'; $p['query'] = $f['query']; } if ($f['page_url'] !== '') { $where .= ' AND d.page_url=:page_url'; $p['page_url'] = $f['page_url']; }
        $group = 'd.website_id,d.query_text,d.page_url,d.device,d.country,d.search_type'; $total = (int) ($this->database->fetchOne("SELECT COUNT(*) AS total FROM (SELECT 1 FROM search_console_data d JOIN websites w ON w.id=d.website_id WHERE $where GROUP BY $group) grouped", $p)['total'] ?? 0);
        $rows = $this->database->fetchAll("SELECT w.site_name,d.query_text,d.page_url,d.device,d.country,d.search_type,SUM(d.clicks) AS clicks,SUM(d.impressions) AS impressions,CASE WHEN SUM(d.impressions)>0 THEN SUM(d.clicks)*1.0/SUM(d.impressions) ELSE 0 END AS ctr,CASE WHEN SUM(d.impressions)>0 THEN SUM(d.average_position*d.impressions)/SUM(d.impressions) ELSE NULL END AS search_console_average_position FROM search_console_data d JOIN websites w ON w.id=d.website_id WHERE $where GROUP BY $group,w.site_name ORDER BY clicks DESC,impressions DESC,d.query_text LIMIT $limit OFFSET $offset", $p);
        return [$rows, $total, $rows === [] ? 'no_search_console_data' : 'ready'];
    }

    private function attachCurrentPositions(array $rows, array $f, bool $withPrevious = false): array
    {
        if ($rows === []) return []; $ids = array_column($rows, 'id'); $p = $ids; $p[] = $f['start_date'] . ' 00:00:00'; $p[] = $f['after_date'] . ' 00:00:00';
        $history = $this->database->fetchAll('SELECT keyword_id,position,observed_at FROM rank_results WHERE keyword_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND observed_at>=? AND observed_at<? ORDER BY observed_at,id', $p); $by = []; foreach ($history as $point) $by[(int) $point['keyword_id']][] = $point;
        foreach ($rows as &$row) { $points = $by[(int) $row['id']] ?? []; $current = $points === [] ? null : $points[array_key_last($points)]; $previous = count($points) < 2 ? null : $points[count($points) - 2]; $row['current_position'] = ($current['position'] ?? null) === null ? null : (int) $current['position']; if ($withPrevious) { $row['previous_position'] = ($previous['position'] ?? null) === null ? null : (int) $previous['position']; $row['change'] = $row['current_position'] !== null && $row['previous_position'] !== null ? $row['previous_position'] - $row['current_position'] : null; } unset($row['id']); } unset($row); return $rows;
    }

    private function filters(int $actorId, array $input): array
    {
        $zone = new DateTimeZone('UTC'); $today = new DateTimeImmutable('today', $zone); $start = is_string($input['start_date'] ?? null) ? $input['start_date'] : $today->modify('-29 days')->format('Y-m-d'); $end = is_string($input['end_date'] ?? null) ? $input['end_date'] : $today->format('Y-m-d'); $s = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $zone); $e = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $zone); if ($s === false || $e === false || $s->format('Y-m-d') !== $start || $e->format('Y-m-d') !== $end || $s > $e || $s->diff($e)->days > 366) throw new InvalidArgumentException('Invalid report date range.');
        $website = null; if (is_string($input['website'] ?? null) && $input['website'] !== '') { if (!preg_match('/^[a-f0-9]{32}$/', $input['website'])) throw new InvalidArgumentException('Website not found.'); $owned = $this->database->fetchOne('SELECT id FROM websites WHERE public_id=:public AND owner_user_id=:owner', ['public' => $input['website'], 'owner' => $actorId]); if ($owned === null) throw new InvalidArgumentException('Website not found.'); $website = (int) $owned['id']; }
        $keyword = null; if (is_string($input['keyword'] ?? null) && $input['keyword'] !== '') { if (!preg_match('/^[a-f0-9]{32}$/', $input['keyword'])) throw new InvalidArgumentException('Keyword not found.'); $owned = $this->database->fetchOne('SELECT k.id FROM keywords k JOIN websites w ON w.id=k.website_id WHERE k.public_id=:public AND w.owner_user_id=:owner' . ($website === null ? '' : ' AND w.id=:website'), ['public' => $input['keyword'], 'owner' => $actorId] + ($website === null ? [] : ['website' => $website])); if ($owned === null) throw new InvalidArgumentException('Keyword not found.'); $keyword = (int) $owned['id']; }
        $device = is_string($input['device'] ?? null) ? $input['device'] : 'all'; $country = is_string($input['country'] ?? null) ? strtolower($input['country']) : 'all'; $searchType = is_string($input['search_type'] ?? null) ? $input['search_type'] : 'all'; if (!in_array($device, ['all','desktop','mobile','tablet'], true) || ($country !== 'all' && !preg_match('/^[a-z]{3}$/', $country)) || !in_array($searchType, ['all','web','image','video','news','discover','googleNews'], true)) throw new InvalidArgumentException('Invalid report filters.');
        $query = is_string($input['query'] ?? null) ? trim($input['query']) : ''; $page = is_string($input['page_url'] ?? null) ? trim($input['page_url']) : ''; if (mb_strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $query) || ($page !== '' && (strlen($page) > 2048 || filter_var($page, FILTER_VALIDATE_URL) === false))) throw new InvalidArgumentException('Invalid report filters.');
        return ['start_date' => $start, 'end_date' => $end, 'after_date' => $e->modify('+1 day')->format('Y-m-d'), 'website' => is_string($input['website'] ?? null) ? $input['website'] : '', 'keyword' => is_string($input['keyword'] ?? null) ? $input['keyword'] : '', 'website_id' => $website, 'keyword_id' => $keyword, 'device' => $device, 'country' => $country, 'search_type' => $searchType, 'query' => $query, 'page_url' => $page];
    }

    private function keywordWhere(int $actorId, array $f): array { $where = 'w.owner_user_id=:owner'; $p = ['owner' => $actorId]; if ($f['website_id'] !== null) { $where .= ' AND w.id=:website'; $p['website'] = $f['website_id']; } if ($f['keyword_id'] !== null) { $where .= ' AND k.id=:keyword'; $p['keyword'] = $f['keyword_id']; } if ($f['device'] !== 'all') { $where .= ' AND k.device=:device'; $p['device'] = $f['device']; } return [$where, $p]; }
    private function rankWhere(int $actorId, array $f): array { [$where, $p] = $this->keywordWhere($actorId, $f); $where .= ' AND rr.observed_at>=:start AND rr.observed_at<:after'; return [$where, $p + ['start' => $f['start_date'] . ' 00:00:00', 'after' => $f['after_date'] . ' 00:00:00']]; }
    private function positiveInt(mixed $value, int $min, int $max): int { $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]); if ($filtered === false) throw new InvalidArgumentException('Invalid pagination.'); return (int) $filtered; }
    private function csvCell(mixed $value): string { $cell = $value === null ? '' : (string) $value; if (is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/', $cell)) $cell = "'" . $cell; return $cell; }
}
