<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Presentation;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Rbac\AuthorizationException;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use App\Modules\SearchConsole\Infrastructure\SearchConsoleFactory;
use Throwable;
use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;
use InvalidArgumentException;

final class SearchConsoleController
{
    public function __construct(private readonly SearchConsoleFactory $factory) {}

    public function status(): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $actor = $auth->user();
            if ($actor === null) return Response::redirect('/login', 302);
            $model = $manager->status((int) $actor['id']);
            $t = $this->factory->translator();
            $issues = $model['issues'] === [] ? '' : '<ul class="error">' . implode('', array_map(static fn (string $issue): string => '<li>' . Html::escape($t->get($issue)) . '</li>', $model['issues'])) . '</ul>';
            $content = '<dl><dt>' . Html::escape($t->get('enabled')) . '</dt><dd>' . Html::escape($t->get($model['enabled'] ? 'yes' : 'no')) . '</dd><dt>' . Html::escape($t->get('version')) . '</dt><dd>' . Html::escape($model['version']) . '</dd><dt>' . Html::escape($t->get('status')) . '</dt><dd>' . Html::escape($t->get($model['status'])) . '</dd><dt>' . Html::escape($t->get('client_id')) . '</dt><dd>' . Html::escape($t->get($model['client_id_configured'] ? 'configured' : 'not_configured')) . '</dd><dt>' . Html::escape($t->get('client_secret')) . '</dt><dd>' . Html::escape($t->get($model['client_secret_configured'] ? 'configured' : 'not_configured')) . '</dd><dt>' . Html::escape($t->get('redirect_uri')) . '</dt><dd>' . Html::escape($model['redirect_uri'] ?? $t->get('not_configured')) . '</dd></dl>' . $issues;
            $content .= '<form method="post" action="/admin/modules/search-console/status"><input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '"><input type="hidden" name="enabled" value="' . ($model['enabled'] ? '0' : '1') . '"><button>' . Html::escape($t->get($model['enabled'] ? 'disable' : 'enable')) . '</button></form><p>' . Html::escape($t->get('oauth_later')) . '</p>';
            if ($model['enabled']) { $content .= '<ul>'; foreach ($model['websites'] as $website) $content .= '<li><a href="/websites/search-console?website=' . rawurlencode((string) $website['public_id']) . '">' . Html::escape((string) $website['site_name']) . ' — ' . Html::escape($t->get('website_title')) . '</a></li>'; $content .= '</ul>'; }
            return $this->page($t->get('title'), $content);
        } catch (AuthorizationException $exception) { return $this->page('Access denied', '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
        catch (Throwable) { return Response::json(['error' => 'Search Console module status is unavailable.'], 503); }
    }

    public function setStatus(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $actor = $auth->user();
            if ($actor === null) return Response::redirect('/login', 302);
            $enabled = filter_var($request->body['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) return Response::json(['error' => 'Invalid module status.'], 422);
            $manager->setEnabled((int) $actor['id'], $enabled);
            return Response::redirect('/admin/modules/search-console');
        } catch (AuthorizationException $exception) { return Response::json(['error' => $exception->getMessage()], 403); }
        catch (Throwable) { return Response::json(['error' => 'Search Console module status could not be changed.'], 503); }
    }

    public function website(Request $request): Response
    {
        $t = $this->factory->translator();
        try {
            [$connections, $auth] = $this->factory->connectionServices(); $actor = $this->actor($auth);
            $website = $this->id($request->query['website'] ?? null, 'Website');
            $connection = $request->query['connection'] ?? null;
            if (is_string($connection) && $connection !== '') {
                $properties = $connections->properties($actor, $website, $this->id($connection, 'Connection'));
                if ($properties === []) return $this->page($t->get('website_title'), '<p class="error">' . Html::escape($t->get('no_properties')) . '</p>');
                $options = '';
                foreach ($properties as $property) $options .= '<option value="' . Html::escape((string) $property['public_id']) . '">' . Html::escape((string) $property['property_uri']) . ' — ' . Html::escape((string) $property['permission_level']) . '</option>';
                return $this->page($t->get('select_property'), '<form method="post" action="/websites/search-console/property">' . $this->csrf() . '<input type="hidden" name="website" value="' . Html::escape($website) . '"><input type="hidden" name="connection" value="' . Html::escape($connection) . '"><label>' . Html::escape($t->get('property')) . '<select name="property" required>' . $options . '</select></label><button>' . Html::escape($t->get('select_property')) . '</button></form>');
            }
            $status = $connections->websiteStatus($actor, $website);
            if ($status['status'] === 'not_connected') return $this->page($t->get('website_title'), '<p>' . Html::escape($t->get('not_connected')) . '</p><form method="post" action="/websites/search-console/connect">' . $this->csrf() . '<input type="hidden" name="website" value="' . Html::escape($website) . '"><button>' . Html::escape($t->get('connect')) . '</button></form>');
            $content = '<p>' . Html::escape($t->get('connected')) . '</p><dl><dt>' . Html::escape($t->get('property')) . '</dt><dd>' . Html::escape((string) $status['property_uri']) . '</dd><dt>' . Html::escape($t->get('property_type')) . '</dt><dd>' . Html::escape((string) $status['property_type']) . '</dd></dl>';
            $content .= '<p><a class="button" href="/websites/search-console/dashboard?website=' . rawurlencode($website) . '">' . Html::escape($t->get('open_dashboard')) . '</a></p>';
            $comparisons = ''; foreach ($this->factory->combinedAnalytics()->keywords($actor, $website) as $keyword) $comparisons .= '<li><a href="/websites/search-console/combined?website=' . rawurlencode($website) . '&keyword=' . rawurlencode((string) $keyword['public_id']) . '">' . Html::escape((string) $keyword['keyword_text']) . ' — ' . Html::escape($t->get('filter_' . $keyword['device'])) . '</a></li>';
            if ($comparisons !== '') $content .= '<h2>' . Html::escape($t->get('combined_analysis')) . '</h2><ul>' . $comparisons . '</ul>';
            $yesterday = gmdate('Y-m-d', time() - 86400); $week = gmdate('Y-m-d', time() - 7 * 86400);
            $content .= '<h2>' . Html::escape($t->get('manual_sync')) . '</h2><form method="post" action="/websites/search-console/sync">' . $this->csrf() . '<input type="hidden" name="website" value="' . Html::escape($website) . '"><label>' . Html::escape($t->get('start_date')) . '<input type="date" name="start_date" required value="' . $week . '"></label><label>' . Html::escape($t->get('end_date')) . '<input type="date" name="end_date" required value="' . $yesterday . '"></label><label>' . Html::escape($t->get('search_type')) . '<select name="search_type"><option value="web">Web</option><option value="image">Image</option><option value="video">Video</option><option value="news">News</option><option value="discover">Discover</option><option value="googleNews">Google News</option></select></label><button>' . Html::escape($t->get('start_sync')) . '</button></form>';
            [$syncs] = $this->factory->syncServices(); $rows = '';
            foreach ($syncs->recent($actor, $website) as $sync) $rows .= '<tr><td><a href="/websites/search-console/sync-status?id=' . Html::escape((string) $sync['public_id']) . '">' . Html::escape((string) $sync['start_date']) . ' – ' . Html::escape((string) $sync['end_date']) . '</a></td><td>' . Html::escape((string) $sync['search_type']) . '</td><td>' . Html::escape($t->get((string) $sync['status'])) . '</td><td>' . (int) $sync['rows_saved'] . '</td></tr>';
            $content .= '<h2>' . Html::escape($t->get('sync_history')) . '</h2><table><thead><tr><th>' . Html::escape($t->get('date_range')) . '</th><th>' . Html::escape($t->get('search_type')) . '</th><th>' . Html::escape($t->get('status')) . '</th><th>' . Html::escape($t->get('rows')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table><form method="post" action="/websites/search-console/disconnect">' . $this->csrf() . '<input type="hidden" name="website" value="' . Html::escape($website) . '"><button class="danger">' . Html::escape($t->get('disconnect')) . '</button></form>';
            return $this->page($t->get('website_title'), $content);
        } catch (AuthorizationException $exception) { return $this->page($t->get('access_denied'), '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
        catch (InvalidArgumentException $exception) { return $this->page($t->get('website_title'), '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 404); }
        catch (Throwable) { return $this->page($t->get('website_title'), '<p class="error">' . Html::escape($t->get('unavailable')) . '</p>', 503); }
    }

    public function connect(Request $request): Response
    {
        try {
            [$connections, $auth] = $this->factory->connectionServices();
            $url = $connections->begin($this->actor($auth), $this->id($request->body['website'] ?? null, 'Website'));
            if (!str_starts_with($url, 'https://accounts.google.com/')) throw new SearchConsoleUnavailable('invalid_authorization_redirect');
            return Response::redirect($url);
        } catch (AuthorizationException $exception) { return Response::json(['error' => $exception->getMessage()], 403); }
        catch (Throwable) { return Response::json(['error' => 'Search Console authorization could not be started.'], 422); }
    }

    public function callback(Request $request): Response
    {
        $t = $this->factory->translator();
        try {
            [$connections, $auth] = $this->factory->connectionServices();
            $state = $request->query['state'] ?? null;
            if (!is_string($state) || strlen($state) > 128) throw new SearchConsoleUnavailable('invalid_oauth_state');
            $result = $connections->complete($this->actor($auth), $state, is_string($request->query['code'] ?? null) ? $request->query['code'] : null, is_string($request->query['error'] ?? null) ? $request->query['error'] : null);
            if ($result['properties'] < 1) return $this->page($t->get('website_title'), '<p class="error">' . Html::escape($t->get('no_properties')) . '</p>', 422);
            return Response::redirect('/websites/search-console?website=' . rawurlencode($result['website']) . '&connection=' . rawurlencode($result['connection']));
        } catch (SearchConsoleUnavailable $exception) { return $this->page($t->get('website_title'), '<p class="error">' . Html::escape($t->get($exception->getMessage())) . '</p>', 422); }
        catch (Throwable) { return $this->page($t->get('website_title'), '<p class="error">' . Html::escape($t->get('oauth_failed')) . '</p>', 503); }
    }

    public function selectProperty(Request $request): Response
    {
        try {
            [$connections, $auth] = $this->factory->connectionServices(); $actor = $this->actor($auth);
            $website = $this->id($request->body['website'] ?? null, 'Website');
            $connections->select($actor, $website, $this->id($request->body['connection'] ?? null, 'Connection'), $this->id($request->body['property'] ?? null, 'Property'));
            return Response::redirect('/websites/search-console?website=' . rawurlencode($website));
        } catch (AuthorizationException $exception) { return Response::json(['error' => $exception->getMessage()], 403); }
        catch (Throwable) { return Response::json(['error' => 'Search Console property could not be selected.'], 422); }
    }

    public function disconnect(Request $request): Response
    {
        try {
            [$connections, $auth] = $this->factory->connectionServices(); $website = $this->id($request->body['website'] ?? null, 'Website');
            $connections->disconnect($this->actor($auth), $website);
            return Response::redirect('/websites/search-console?website=' . rawurlencode($website));
        } catch (AuthorizationException $exception) { return Response::json(['error' => $exception->getMessage()], 403); }
        catch (Throwable) { return Response::json(['error' => 'Search Console could not be disconnected.'], 422); }
    }

    public function sync(Request $request): Response
    {
        try {
            [$syncs, $auth] = $this->factory->syncServices(); $website = $this->id($request->body['website'] ?? null, 'Website');
            $id = $syncs->submit($this->actor($auth), $website, (string) ($request->body['start_date'] ?? ''), (string) ($request->body['end_date'] ?? ''), (string) ($request->body['search_type'] ?? ''));
            return Response::redirect('/websites/search-console/sync-status?id=' . rawurlencode($id));
        } catch (AuthorizationException $exception) { return Response::json(['error' => $exception->getMessage()], 403); }
        catch (Throwable) { return Response::json(['error' => 'Search Console synchronization could not be queued.'], 422); }
    }

    public function syncStatus(Request $request): Response
    {
        $t = $this->factory->translator();
        try {
            [$syncs, $auth] = $this->factory->syncServices(); $sync = $syncs->status($this->actor($auth), $this->id($request->query['id'] ?? null, 'Sync'));
            $logs = ''; foreach ($sync['logs'] as $log) $logs .= '<tr><td>' . Html::escape((string) $log['occurred_at']) . '</td><td>' . Html::escape($t->get((string) $log['state'])) . '</td><td>' . Html::escape((string) $log['message']) . '</td></tr>';
            $content = '<dl><dt>' . Html::escape($t->get('status')) . '</dt><dd>' . Html::escape($t->get((string) $sync['status'])) . '</dd><dt>' . Html::escape($t->get('phase')) . '</dt><dd>' . Html::escape($t->get((string) $sync['phase'])) . '</dd><dt>' . Html::escape($t->get('date_range')) . '</dt><dd>' . Html::escape((string) $sync['start_date']) . ' – ' . Html::escape((string) $sync['end_date']) . '</dd><dt>' . Html::escape($t->get('rows')) . '</dt><dd>' . (int) $sync['rows_saved'] . '</dd><dt>' . Html::escape($t->get('error')) . '</dt><dd>' . Html::escape((string) ($sync['error_detail'] ?? '—')) . '</dd></dl><table><thead><tr><th>' . Html::escape($t->get('time')) . '</th><th>' . Html::escape($t->get('phase')) . '</th><th>' . Html::escape($t->get('message')) . '</th></tr></thead><tbody>' . $logs . '</tbody></table>';
            return $this->page($t->get('sync_status'), $content);
        } catch (AuthorizationException $exception) { return $this->page($t->get('access_denied'), '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
        catch (Throwable) { return $this->page($t->get('sync_status'), '<p class="error">' . Html::escape($t->get('unavailable')) . '</p>', 404); }
    }

    public function dashboard(Request $request): Response
    {
        $t = $this->factory->translator();
        try {
            [, $auth] = $this->factory->syncServices(); $actor = $this->actor($auth); $website = $this->id($request->query['website'] ?? null, 'Website');
            $model = $this->factory->dashboard()->dashboard($actor, $website, $request->query);
            $status = '<section class="status-card"><h2>' . Html::escape($t->get('connection_status')) . '</h2><dl><dt>' . Html::escape($t->get('status')) . '</dt><dd>' . Html::escape($t->get($model['state'])) . '</dd>';
            if ($model['property'] !== null) $status .= '<dt>' . Html::escape($t->get('property')) . '</dt><dd>' . Html::escape((string) $model['property']['property_uri']) . '</dd>';
            if ($model['latest_sync'] !== null) $status .= '<dt>' . Html::escape($t->get('last_sync')) . '</dt><dd>' . Html::escape((string) ($model['latest_sync']['completed_at'] ?? $model['latest_sync']['created_at'])) . '</dd><dt>' . Html::escape($t->get('sync_status')) . '</dt><dd>' . Html::escape($t->get((string) $model['latest_sync']['status'])) . '</dd>';
            $status .= '</dl></section>';
            if (in_array($model['state'], ['module_disabled', 'not_connected', 'authorization_expired'], true)) {
                $action = $model['state'] === 'module_disabled' ? '' : '<form method="post" action="/websites/search-console/connect">' . $this->csrf() . '<input type="hidden" name="website" value="' . Html::escape($website) . '"><button>' . Html::escape($t->get('connect')) . '</button></form>';
                return $this->page($t->get('dashboard_title'), $status . '<p class="empty-state">' . Html::escape($t->get($model['state'] . '_help')) . '</p>' . $action);
            }
            $filters = $model['filters'];
            $filter = '<form class="filters" method="get" action="/websites/search-console/dashboard"><input type="hidden" name="website" value="' . Html::escape($website) . '"><label>' . Html::escape($t->get('start_date')) . '<input type="date" name="start_date" value="' . Html::escape($filters['start_date']) . '"></label><label>' . Html::escape($t->get('end_date')) . '<input type="date" name="end_date" value="' . Html::escape($filters['end_date']) . '"></label><label>' . Html::escape($t->get('query')) . '<input name="query" maxlength="200" value="' . Html::escape($filters['query']) . '"></label><label>' . Html::escape($t->get('page')) . '<input type="url" name="page" maxlength="2048" value="' . Html::escape($filters['page']) . '"></label><label>' . Html::escape($t->get('device')) . '<select name="device">' . $this->dashboardOptions(['all', 'desktop', 'mobile', 'tablet'], $filters['device'], $t) . '</select></label><label>' . Html::escape($t->get('country')) . '<input name="country" maxlength="3" value="' . Html::escape($filters['country']) . '"></label><label>' . Html::escape($t->get('search_type')) . '<select name="search_type">' . $this->dashboardOptions(['all', 'web', 'image', 'video', 'news', 'discover', 'googleNews'], $filters['search_type'], $t) . '</select></label><button>' . Html::escape($t->get('apply')) . '</button></form>';
            $metrics = $model['metrics']; $cards = '<div class="metric-grid">' . $this->metric($t->get('clicks'), (string) $metrics['clicks'], $metrics['changes']['clicks'] ?? null, false, $t) . $this->metric($t->get('impressions'), (string) $metrics['impressions'], $metrics['changes']['impressions'] ?? null, false, $t) . $this->metric($t->get('ctr'), number_format($metrics['ctr'] * 100, 2) . '%', isset($metrics['changes']['ctr']) ? $metrics['changes']['ctr'] * 100 : null, false, $t) . $this->metric($t->get('search_console_average_position'), $metrics['average_position'] === null ? '—' : number_format($metrics['average_position'], 2), $metrics['changes']['average_position'] ?? null, true, $t) . $this->metric($t->get('queries'), (string) $metrics['queries'], null, false, $t) . $this->metric($t->get('pages'), (string) $metrics['pages'], null, false, $t) . '</div>';
            $trendRows = ''; foreach ($model['trend'] as $row) $trendRows .= '<tr><td>' . Html::escape((string) $row['data_date']) . '</td><td>' . (int) $row['clicks'] . '</td><td>' . (int) $row['impressions'] . '</td><td>' . number_format((float) $row['ctr'] * 100, 2) . '%</td><td>' . ($row['average_position'] === null ? '—' : number_format((float) $row['average_position'], 2)) . '</td></tr>';
            $trend = '<h2>' . Html::escape($t->get('trends')) . '</h2><div class="table-scroll"><table><thead><tr><th>' . Html::escape($t->get('date')) . '</th><th>' . Html::escape($t->get('clicks')) . '</th><th>' . Html::escape($t->get('impressions')) . '</th><th>' . Html::escape($t->get('ctr')) . '</th><th>' . Html::escape($t->get('search_console_average_position')) . '</th></tr></thead><tbody>' . $trendRows . '</tbody></table></div>';
            $empty = $model['state'] === 'ready' ? '' : '<p class="empty-state">' . Html::escape($t->get($model['state'] . '_help')) . '</p>';
            $actions = '<p><a href="/websites/search-console?website=' . rawurlencode($website) . '">' . Html::escape($t->get('connection_and_sync_settings')) . '</a></p>';
            return $this->page($t->get('dashboard_title'), $status . $actions . $filter . $empty . $cards . $trend . $this->breakdownTable($t->get('top_queries'), $t->get('query'), $model['queries'], $t) . $this->breakdownTable($t->get('top_pages'), $t->get('page'), $model['pages'], $t) . $this->breakdownTable($t->get('devices'), $t->get('device'), $model['devices'], $t));
        } catch (AuthorizationException $exception) { return $this->page($t->get('access_denied'), '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
        catch (InvalidArgumentException $exception) { return $this->page($t->get('dashboard_title'), '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 422); }
        catch (Throwable) { return $this->page($t->get('dashboard_title'), '<p class="error">' . Html::escape($t->get('unavailable')) . '</p>', 503); }
    }

    public function combined(Request $request): Response
    {
        $t = $this->factory->translator();
        try {
            [, $auth] = $this->factory->syncServices(); $actor = $this->actor($auth);
            $website = $this->id($request->query['website'] ?? null, 'Website'); $keyword = $this->id($request->query['keyword'] ?? null, 'Keyword');
            $model = $this->factory->combinedAnalytics()->compare($actor, $website, $keyword, (string) ($request->query['range'] ?? '30'));
            $filters = '<form class="filters" method="get" action="/websites/search-console/combined"><input type="hidden" name="website" value="' . Html::escape($website) . '"><input type="hidden" name="keyword" value="' . Html::escape($keyword) . '"><label>' . Html::escape($t->get('date_range')) . '<select name="range">' . $this->rangeOptions((string) $model['range'], $t) . '</select></label><button>' . Html::escape($t->get('apply')) . '</button></form>';
            $rank = $model['rank_tracker_latest']; $search = $model['search_console_summary'];
            $cards = '<div class="source-grid"><section class="source-card rank-source"><h2>' . Html::escape($t->get('rank_tracker_source')) . '</h2><p>' . Html::escape($t->get('rank_tracker_definition')) . '</p><strong>' . ($rank === null || $rank['position'] === null ? '—' : '#' . (int) $rank['position']) . '</strong></section><section class="source-card search-console-source"><h2>' . Html::escape($t->get('search_console_source')) . '</h2><p>' . Html::escape($t->get('search_console_definition')) . '</p><strong>' . ($search['average_position'] === null ? '—' : number_format((float) $search['average_position'], 2)) . '</strong><small>' . (int) $search['clicks'] . ' ' . Html::escape($t->get('clicks')) . ' · ' . (int) $search['impressions'] . ' ' . Html::escape($t->get('impressions')) . '</small></section></div>';
            $rows = ''; foreach ($model['timeline'] as $row) $rows .= '<tr><td>' . Html::escape((string) $row['date']) . '</td><td>' . ($row['rank_tracker_position'] === null ? '—' : '#' . (int) $row['rank_tracker_position']) . '</td><td>' . ($row['search_console_average_position'] === null ? '—' : number_format((float) $row['search_console_average_position'], 2)) . '</td><td>' . ($row['search_console_clicks'] === null ? '—' : (int) $row['search_console_clicks']) . '</td><td>' . ($row['search_console_impressions'] === null ? '—' : (int) $row['search_console_impressions']) . '</td></tr>';
            $table = '<div class="table-scroll"><table><thead><tr><th>' . Html::escape($t->get('date')) . '</th><th>' . Html::escape($t->get('rank_tracker_position')) . '</th><th>' . Html::escape($t->get('search_console_average_position')) . '</th><th>' . Html::escape($t->get('clicks')) . '</th><th>' . Html::escape($t->get('impressions')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
            $limitations = '<ul class="comparison-notes">'; foreach ($model['limitations'] as $limitation) $limitations .= '<li>' . Html::escape($t->get('limitation_' . $limitation)) . '</li>'; $limitations .= '</ul>';
            $displayState = in_array($model['search_console_state'], ['module_disabled', 'not_connected', 'unsupported_engine'], true) ? $model['search_console_state'] : $model['state'];
            $empty = $model['state'] === 'matched' ? '' : '<p class="empty-state">' . Html::escape($t->get('combined_state_' . $displayState)) . '</p>';
            return $this->page($t->get('combined_analysis') . ' — ' . (string) $model['keyword']['keyword_text'], $filters . '<p class="metric-warning">' . Html::escape($t->get('metrics_not_equivalent')) . '</p>' . $empty . $cards . $table . '<h2>' . Html::escape($t->get('mapping_limitations')) . '</h2>' . $limitations);
        } catch (AuthorizationException) { return $this->page($t->get('access_denied'), '<p class="error">' . Html::escape($t->get('access_denied')) . '</p>', 403); }
        catch (InvalidArgumentException) { return $this->page($t->get('combined_analysis'), '<p class="error">' . Html::escape($t->get('not_found')) . '</p>', 404); }
        catch (Throwable) { return $this->page($t->get('combined_analysis'), '<p class="error">' . Html::escape($t->get('unavailable')) . '</p>', 503); }
    }

    private function actor(object $auth): int { $user = $auth->user(); if ($user === null) throw new AuthorizationException('Authentication required.'); return (int) $user['id']; }
    private function id(mixed $value, string $label): string { if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/', $value)) throw new InvalidArgumentException($label . ' not found.'); return $value; }
    private function csrf(): string { return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">'; }
    private function dashboardOptions(array $values, string $selected, object $t): string { $html = ''; foreach ($values as $value) $html .= '<option value="' . Html::escape($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . Html::escape($t->get('filter_' . $value)) . '</option>'; return $html; }
    private function rangeOptions(string $selected, object $t): string { $html = ''; foreach (['7', '30', '90'] as $range) $html .= '<option value="' . $range . '"' . ($selected === $range ? ' selected' : '') . '>' . Html::escape($t->get('days', ['count' => $range])) . '</option>'; return $html; }
    private function metric(string $label, string $value, ?float $change, bool $lowerIsBetter, object $t): string { $trend = ''; if ($change !== null) { $class = $change == 0.0 ? 'unchanged' : (($lowerIsBetter ? $change < 0 : $change > 0) ? 'improved' : 'dropped'); $trend = '<small class="change ' . $class . '">' . Html::escape(($change > 0 ? '+' : '') . number_format($change, 2) . ' ' . $t->get('vs_previous_period')) . '</small>'; } return '<section class="metric-card"><h3>' . Html::escape($label) . '</h3><strong>' . Html::escape($value) . '</strong>' . $trend . '</section>'; }
    private function breakdownTable(string $title, string $dimensionLabel, array $rows, object $t): string { $body = ''; foreach ($rows as $row) $body .= '<tr><td class="url-cell">' . Html::escape((string) ($row['dimension'] === '' ? $t->get('unavailable') : $row['dimension'])) . '</td><td>' . (int) $row['clicks'] . '</td><td>' . (int) $row['impressions'] . '</td><td>' . number_format((float) $row['ctr'] * 100, 2) . '%</td><td>' . ($row['average_position'] === null ? '—' : number_format((float) $row['average_position'], 2)) . '</td></tr>'; return '<h2>' . Html::escape($title) . '</h2><div class="table-scroll"><table><thead><tr><th>' . Html::escape($dimensionLabel) . '</th><th>' . Html::escape($t->get('clicks')) . '</th><th>' . Html::escape($t->get('impressions')) . '</th><th>' . Html::escape($t->get('ctr')) . '</th><th>' . Html::escape($t->get('search_console_average_position')) . '</th></tr></thead><tbody>' . $body . '</tbody></table></div>'; }

    private function page(string $title, string $content, int $status = 200): Response
    {
        $t = $this->factory->translator(); return Response::html('<!doctype html><html lang="' . Html::escape($t->locale()) . '" dir="' . ($this->factory->isRtl() ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status);
    }
}
