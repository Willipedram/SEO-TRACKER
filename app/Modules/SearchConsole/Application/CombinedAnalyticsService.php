<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Database\Database;
use App\Core\Rbac\Authorization;
use InvalidArgumentException;

final class CombinedAnalyticsService
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization) {}

    public function keywords(int $actorId, string $websitePublicId): array
    {
        $this->authorization->require($actorId, 'rank_tracking.view');
        $this->authorization->require($actorId, 'search_console.sync');
        if (!preg_match('/^[a-f0-9]{32}$/', $websitePublicId)) throw new InvalidArgumentException('Website not found.');
        $website = $this->database->fetchOne('SELECT id FROM websites WHERE public_id=:website AND owner_user_id=:owner', ['website' => $websitePublicId, 'owner' => $actorId]);
        if ($website === null) throw new InvalidArgumentException('Website not found.');
        return $this->database->fetchAll('SELECT public_id,keyword_text,device,search_engine FROM keywords WHERE website_id=:website ORDER BY keyword_text,device,id', ['website' => $website['id']]);
    }

    public function compare(int $actorId, string $websitePublicId, string $keywordPublicId, string $range = '30'): array
    {
        $this->authorization->require($actorId, 'rank_tracking.view');
        $this->authorization->require($actorId, 'search_console.sync');
        if (!preg_match('/^[a-f0-9]{32}$/', $websitePublicId) || !preg_match('/^[a-f0-9]{32}$/', $keywordPublicId)) throw new InvalidArgumentException('Comparison not found.');
        if (!in_array($range, ['7', '30', '90'], true)) throw new InvalidArgumentException('Invalid comparison range.');
        $keyword = $this->database->fetchOne('SELECT k.id,k.public_id,k.keyword_text,k.search_engine,k.device,w.id AS website_id,w.public_id AS website_public_id,w.site_name FROM keywords k JOIN websites w ON w.id=k.website_id WHERE k.public_id=:keyword AND w.public_id=:website AND w.owner_user_id=:owner', ['keyword' => $keywordPublicId, 'website' => $websitePublicId, 'owner' => $actorId]);
        if ($keyword === null) throw new InvalidArgumentException('Comparison not found.');
        $end = gmdate('Y-m-d'); $start = gmdate('Y-m-d', time() - (((int) $range - 1) * 86400));
        $rank = $this->rankSeries((int) $keyword['id'], (string) $keyword['device'], $start, $end);
        $searchConsole = $this->searchConsoleSeries($actorId, $keyword, $start, $end);
        $dates = array_values(array_unique([...array_keys($rank), ...array_keys($searchConsole['series'])])); sort($dates, SORT_STRING);
        $timeline = []; foreach ($dates as $date) $timeline[] = ['date' => $date, 'rank_tracker_position' => $rank[$date]['position'] ?? null, 'rank_tracker_observed_at' => $rank[$date]['observed_at'] ?? null, 'search_console_average_position' => $searchConsole['series'][$date]['average_position'] ?? null, 'search_console_clicks' => $searchConsole['series'][$date]['clicks'] ?? null, 'search_console_impressions' => $searchConsole['series'][$date]['impressions'] ?? null];
        $rankLatest = $rank === [] ? null : $rank[array_key_last($rank)];
        $scSummary = $this->searchConsoleSummary($searchConsole['series']);
        $availability = $this->availability($rank !== [], $searchConsole['series'] !== [], $searchConsole['state']);
        return ['website' => ['public_id' => $keyword['website_public_id'], 'site_name' => $keyword['site_name']], 'keyword' => ['public_id' => $keyword['public_id'], 'keyword_text' => $keyword['keyword_text'], 'search_engine' => $keyword['search_engine'], 'device' => $keyword['device']], 'range' => $range, 'start_date' => $start, 'end_date' => $end, 'state' => $availability, 'search_console_state' => $searchConsole['state'], 'rank_tracker_latest' => $rankLatest, 'search_console_summary' => $scSummary, 'timeline' => $timeline, 'limitations' => ['exact_query', 'utc_day', 'same_device', 'search_console_aggregated', 'country_not_aligned', 'no_causality']];
    }

    private function rankSeries(int $keywordId, string $device, string $start, string $end): array
    {
        $rows = $this->database->fetchAll('SELECT position,result_type,observed_at FROM rank_results WHERE keyword_id=:keyword AND requested_device=:device AND observed_at>=:start AND observed_at<:after ORDER BY observed_at,id', ['keyword' => $keywordId, 'device' => $device, 'start' => $start . ' 00:00:00', 'after' => gmdate('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00']);
        $series = []; foreach ($rows as $row) { $date = substr((string) $row['observed_at'], 0, 10); $series[$date] = ['position' => $row['position'] === null ? null : (int) $row['position'], 'result_type' => $row['result_type'], 'observed_at' => $row['observed_at']]; }
        return $series;
    }

    private function searchConsoleSeries(int $actorId, array $keyword, string $start, string $end): array
    {
        $module = $this->database->fetchOne("SELECT enabled FROM modules WHERE module_key='search_console'");
        if (!(bool) ($module['enabled'] ?? false)) return ['state' => 'module_disabled', 'series' => []];
        if ($keyword['search_engine'] !== 'google') return ['state' => 'unsupported_engine', 'series' => []];
        $property = $this->database->fetchOne("SELECT p.id FROM search_console_properties p JOIN search_console_connections c ON c.id=p.connection_id WHERE p.website_id=:website AND p.selected=1 AND c.user_id=:user AND c.status='connected' LIMIT 1", ['website' => $keyword['website_id'], 'user' => $actorId]);
        if ($property === null) return ['state' => 'not_connected', 'series' => []];
        $queryEquality = $this->database->driver() === 'mysql' ? 'BINARY d.query_text=BINARY :query' : 'd.query_text=:query COLLATE BINARY';
        $rows = $this->database->fetchAll("SELECT data_date,SUM(clicks) AS clicks,SUM(impressions) AS impressions,CASE WHEN SUM(impressions)>0 THEN SUM(average_position*impressions)/SUM(impressions) ELSE NULL END AS average_position FROM search_console_data d WHERE d.website_id=:website AND d.property_id=:property AND d.data_date>=:start AND d.data_date<=:end AND d.device=:device AND d.search_type='web' AND $queryEquality GROUP BY data_date ORDER BY data_date", ['website' => $keyword['website_id'], 'property' => $property['id'], 'start' => $start, 'end' => $end, 'device' => $keyword['device'], 'query' => $keyword['keyword_text']]);
        $series = []; foreach ($rows as $row) $series[(string) $row['data_date']] = ['clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'], 'average_position' => $row['average_position'] === null ? null : (float) $row['average_position']];
        return ['state' => $series === [] ? 'no_search_console_match' : 'ready', 'series' => $series];
    }

    private function searchConsoleSummary(array $series): array
    {
        $clicks = 0; $impressions = 0; $weighted = 0.0;
        foreach ($series as $row) { $clicks += $row['clicks']; $impressions += $row['impressions']; if ($row['average_position'] !== null) $weighted += $row['average_position'] * $row['impressions']; }
        return ['clicks' => $clicks, 'impressions' => $impressions, 'average_position' => $impressions > 0 ? $weighted / $impressions : null];
    }

    private function availability(bool $rank, bool $searchConsole, string $searchConsoleState): string
    {
        if ($rank && $searchConsole) return 'matched'; if ($rank) return 'rank_only'; if ($searchConsole) return 'search_console_only';
        return in_array($searchConsoleState, ['module_disabled', 'not_connected', 'unsupported_engine'], true) ? $searchConsoleState : 'no_data';
    }
}
