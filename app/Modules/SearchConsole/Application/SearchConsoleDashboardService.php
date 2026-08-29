<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Database\Database;
use App\Core\Rbac\Authorization;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SearchConsoleDashboardService
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization) {}

    public function dashboard(int $actorId, string $websitePublicId, array $input = []): array
    {
        $this->authorization->require($actorId, 'search_console.sync');
        $website = $this->website($actorId, $websitePublicId);
        $module = $this->database->fetchOne('SELECT enabled FROM modules WHERE module_key = :key', ['key' => SearchConsoleManager::KEY]);
        if (!(bool) ($module['enabled'] ?? false)) return $this->emptyModel('module_disabled', $website, $this->filters($input));
        $property = $this->database->fetchOne("SELECT p.id,p.public_id,p.property_uri,p.property_type,p.permission_level,c.public_id AS connection_public_id,c.status AS connection_status FROM search_console_properties p JOIN search_console_connections c ON c.id=p.connection_id WHERE p.website_id=:website AND p.selected=1 AND c.user_id=:user LIMIT 1", ['website' => $website['id'], 'user' => $actorId]);
        if ($property === null) {
            $connection = $this->database->fetchOne('SELECT c.status FROM search_console_connection_contexts x JOIN search_console_connections c ON c.id=x.connection_id WHERE x.website_id=:website AND c.user_id=:user ORDER BY c.id DESC LIMIT 1', ['website' => $website['id'], 'user' => $actorId]);
            return $this->emptyModel(($connection['status'] ?? null) === 'revoked' ? 'authorization_expired' : 'not_connected', $website, $this->filters($input));
        }
        if ($property['connection_status'] !== 'connected') return $this->emptyModel('authorization_expired', $website, $this->filters($input), $property);
        $filters = $this->filters($input); $latest = $this->database->fetchOne('SELECT public_id,status,phase,start_date,end_date,search_type,rows_saved,error_code,error_detail,created_at,completed_at FROM search_console_syncs WHERE user_id=:user AND website_id=:website AND property_id=:property ORDER BY id DESC LIMIT 1', ['user' => $actorId, 'website' => $website['id'], 'property' => $property['id']]);
        [$where, $parameters] = $this->where((int) $website['id'], (int) $property['id'], $filters);
        $summary = $this->summary($where, $parameters);
        $previous = $this->previousSummary((int) $website['id'], (int) $property['id'], $filters);
        $summary['changes'] = $this->changes($summary, $previous);
        $trend = $this->database->fetchAll("SELECT data_date,SUM(clicks) AS clicks,SUM(impressions) AS impressions,CASE WHEN SUM(impressions)>0 THEN SUM(clicks)*1.0/SUM(impressions) ELSE 0 END AS ctr,CASE WHEN SUM(impressions)>0 THEN SUM(average_position*impressions)/SUM(impressions) ELSE NULL END AS average_position FROM search_console_data d WHERE $where GROUP BY data_date ORDER BY data_date", $parameters);
        return ['state' => (int) $summary['row_count'] === 0 ? ($latest === null ? 'never_synced' : 'no_data') : 'ready', 'website' => $website, 'property' => $property, 'latest_sync' => $latest, 'filters' => $filters, 'metrics' => $summary, 'trend' => $trend, 'queries' => $this->breakdown('query_text', $where, $parameters, 50), 'pages' => $this->breakdown('page_url', $where, $parameters, 50), 'devices' => $this->breakdown('device', $where, $parameters, 10)];
    }

    private function summary(string $where, array $parameters): array
    {
        $row = $this->database->fetchOne("SELECT COUNT(*) AS row_count,COALESCE(SUM(clicks),0) AS clicks,COALESCE(SUM(impressions),0) AS impressions,COUNT(DISTINCT query_text) AS queries,COUNT(DISTINCT page_url) AS pages,CASE WHEN SUM(impressions)>0 THEN SUM(clicks)*1.0/SUM(impressions) ELSE 0 END AS ctr,CASE WHEN SUM(impressions)>0 THEN SUM(average_position*impressions)/SUM(impressions) ELSE NULL END AS average_position FROM search_console_data d WHERE $where", $parameters) ?? [];
        return ['row_count' => (int) ($row['row_count'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0), 'queries' => (int) ($row['queries'] ?? 0), 'pages' => (int) ($row['pages'] ?? 0), 'ctr' => (float) ($row['ctr'] ?? 0), 'average_position' => $row['average_position'] === null ? null : (float) $row['average_position']];
    }

    private function previousSummary(int $websiteId, int $propertyId, array $filters): array
    {
        $start = new DateTimeImmutable($filters['start_date'], new DateTimeZone('UTC')); $end = new DateTimeImmutable($filters['end_date'], new DateTimeZone('UTC')); $days = (int) $start->diff($end)->days + 1;
        $previous = $filters; $previous['end_date'] = $start->modify('-1 day')->format('Y-m-d'); $previous['start_date'] = $start->modify("-$days days")->format('Y-m-d');
        [$where, $parameters] = $this->where($websiteId, $propertyId, $previous); return $this->summary($where, $parameters);
    }

    private function changes(array $current, array $previous): array
    {
        $changes = [];
        foreach (['clicks', 'impressions', 'ctr', 'average_position'] as $metric) {
            $now = $current[$metric]; $before = $previous[$metric];
            $changes[$metric] = $now === null || $before === null ? null : (float) $now - (float) $before;
        }
        return $changes;
    }

    private function breakdown(string $column, string $where, array $parameters, int $limit): array
    {
        if (!in_array($column, ['query_text', 'page_url', 'device'], true)) throw new InvalidArgumentException('Invalid dashboard dimension.');
        return $this->database->fetchAll("SELECT $column AS dimension,SUM(clicks) AS clicks,SUM(impressions) AS impressions,CASE WHEN SUM(impressions)>0 THEN SUM(clicks)*1.0/SUM(impressions) ELSE 0 END AS ctr,CASE WHEN SUM(impressions)>0 THEN SUM(average_position*impressions)/SUM(impressions) ELSE NULL END AS average_position FROM search_console_data d WHERE $where GROUP BY $column ORDER BY clicks DESC,impressions DESC,$column LIMIT $limit", $parameters);
    }

    private function where(int $websiteId, int $propertyId, array $filters): array
    {
        $where = 'd.website_id=:website AND d.property_id=:property AND d.data_date>=:start_date AND d.data_date<=:end_date';
        $parameters = ['website' => $websiteId, 'property' => $propertyId, 'start_date' => $filters['start_date'], 'end_date' => $filters['end_date']];
        foreach (['device', 'country', 'search_type'] as $field) if ($filters[$field] !== 'all') { $where .= " AND d.$field=:$field"; $parameters[$field] = $filters[$field]; }
        if ($filters['query'] !== '') { $where .= ' AND INSTR(d.query_text,:query)>0'; $parameters['query'] = $filters['query']; }
        if ($filters['page'] !== '') { $where .= ' AND d.page_url=:page'; $parameters['page'] = $filters['page']; }
        return [$where, $parameters];
    }

    private function filters(array $input): array
    {
        $zone = new DateTimeZone('UTC'); $yesterday = new DateTimeImmutable('yesterday', $zone);
        $start = is_string($input['start_date'] ?? null) ? $input['start_date'] : $yesterday->modify('-27 days')->format('Y-m-d'); $end = is_string($input['end_date'] ?? null) ? $input['end_date'] : $yesterday->format('Y-m-d');
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $zone); $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $zone);
        if ($startDate === false || $endDate === false || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end || $startDate > $endDate || $endDate > $yesterday || $startDate < $yesterday->modify('-16 months')) throw new InvalidArgumentException('Select a valid Search Console dashboard date range.');
        $device = is_string($input['device'] ?? null) ? $input['device'] : 'all'; $country = is_string($input['country'] ?? null) ? strtolower($input['country']) : 'all'; $searchType = is_string($input['search_type'] ?? null) ? $input['search_type'] : 'all';
        if (!in_array($device, ['all', 'desktop', 'mobile', 'tablet'], true) || ($country !== 'all' && !preg_match('/^[a-z]{3}$/', $country)) || !in_array($searchType, ['all', ...SearchConsoleSyncManager::SEARCH_TYPES], true)) throw new InvalidArgumentException('Select valid Search Console dashboard filters.');
        $query = is_string($input['query'] ?? null) ? trim($input['query']) : ''; if (!mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/', $query)) throw new InvalidArgumentException('Invalid query filter.');
        $page = is_string($input['page'] ?? null) ? trim($input['page']) : ''; if ($page !== '' && (strlen($page) > 2048 || filter_var($page, FILTER_VALIDATE_URL) === false || !in_array(parse_url($page, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($page, PHP_URL_USER) !== null || parse_url($page, PHP_URL_FRAGMENT) !== null)) throw new InvalidArgumentException('Invalid page filter.');
        return ['start_date' => $start, 'end_date' => $end, 'query' => $query, 'page' => $page, 'device' => $device, 'country' => $country, 'search_type' => $searchType];
    }

    private function website(int $actorId, string $publicId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Website not found.');
        $website = $this->database->fetchOne('SELECT id,public_id,site_name FROM websites WHERE public_id=:public AND owner_user_id=:owner', ['public' => $publicId, 'owner' => $actorId]);
        if ($website === null) throw new InvalidArgumentException('Website not found.'); return $website;
    }

    private function emptyModel(string $state, array $website, array $filters, ?array $property = null): array
    {
        return ['state' => $state, 'website' => $website, 'property' => $property, 'latest_sync' => null, 'filters' => $filters, 'metrics' => ['row_count' => 0, 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'average_position' => null, 'queries' => 0, 'pages' => 0, 'changes' => []], 'trend' => [], 'queries' => [], 'pages' => [], 'devices' => []];
    }
}
