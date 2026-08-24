<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Presentation;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Rbac\AuthorizationException;
use App\Core\Security\Html;
use App\Core\Localization\Translator;
use App\Modules\RankTracking\Infrastructure\RankTrackingFactory;
use InvalidArgumentException;
use Throwable;

final class RankTrackingController
{
    public function __construct(private readonly RankTrackingFactory $factory) {}

    public function submit(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $id = $manager->submit($this->actor($auth), $this->id($request->body['website'] ?? null, 'Website'), $this->id($request->body['keyword'] ?? null, 'Keyword'));
            return Response::redirect('/rank-checks/status?id=' . $id);
        } catch (AuthorizationException $exception) { return $this->error($exception->getMessage(), 403); }
        catch (InvalidArgumentException $exception) { return $this->error($exception->getMessage(), 422); }
        catch (Throwable) { return $this->error('Rank check could not be submitted.', 503); }
    }

    public function status(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $check = $manager->status($this->actor($auth), $this->id($request->query['id'] ?? null, 'Rank check'));
            $result = $check['result'];
            $detail = $result === null ? '<p>No observation has been accepted.</p>' : '<dl><dt>Result</dt><dd>' . Html::escape((string) $result['result_type']) . '</dd><dt>Position</dt><dd>' . ($result['position'] === null ? '—' : (int) $result['position']) . '</dd><dt>Ranking URL</dt><dd>' . ($result['ranking_url'] === null ? '—' : Html::escape((string) $result['ranking_url'])) . '</dd><dt>Execution device</dt><dd>' . Html::escape((string) $result['execution_device']) . '</dd></dl>';
            return $this->page('Rank check', '<dl><dt>Job ID</dt><dd><code>' . Html::escape((string) $check['public_id']) . '</code></dd><dt>Status</dt><dd>' . Html::escape((string) $check['status']) . '</dd><dt>Requested device</dt><dd>' . Html::escape((string) $check['requested_device']) . '</dd><dt>Execution source</dt><dd>' . Html::escape((string) $check['execution_source']) . '</dd><dt>Error</dt><dd>' . Html::escape((string) ($check['error_code'] ?? '—')) . '</dd></dl>' . $detail);
        } catch (AuthorizationException $exception) { return $this->error($exception->getMessage(), 403); }
        catch (InvalidArgumentException $exception) { return $this->error($exception->getMessage(), 404); }
        catch (Throwable) { return $this->error('Rank check status is unavailable.', 503); }
    }

    public function history(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $rows = '';
            foreach ($manager->history($this->actor($auth), $this->id($request->query['website'] ?? null, 'Website'), $this->id($request->query['keyword'] ?? null, 'Keyword')) as $result) {
                $rows .= '<tr><td>' . Html::escape((string) $result['observed_at']) . '</td><td>' . Html::escape((string) $result['result_type']) . '</td><td>' . ($result['position'] === null ? '—' : (int) $result['position']) . '</td><td>' . Html::escape((string) ($result['ranking_url'] ?? '—')) . '</td><td>' . Html::escape((string) $result['requested_device']) . ' / ' . Html::escape((string) $result['execution_device']) . '</td><td>' . Html::escape((string) $result['execution_source']) . '</td></tr>';
            }
            return $this->page('Rank history', '<table><thead><tr><th>Observed</th><th>Result</th><th>Position</th><th>URL</th><th>Device</th><th>Source</th></tr></thead><tbody>' . $rows . '</tbody></table>');
        } catch (AuthorizationException $exception) { return $this->error($exception->getMessage(), 403); }
        catch (InvalidArgumentException $exception) { return $this->error($exception->getMessage(), 404); }
        catch (Throwable) { return $this->error('Rank history is unavailable.', 503); }
    }

    public function dashboard(Request $request): Response
    {
        $translator = $this->factory->translator();
        try {
            [, $auth] = $this->factory->services();
            $website = $this->id($request->query['website'] ?? null, $translator->get('website'));
            $keyword = $request->query['keyword'] ?? null;
            if ($keyword === '') $keyword = null;
            $model = $this->factory->dashboard()->dashboard($this->actor($auth), $website, is_string($keyword) ? $keyword : null, (string) ($request->query['device'] ?? 'all'), (string) ($request->query['range'] ?? '30'));
            $filters = '<form class="filters" method="get" action="/rank-dashboard"><input type="hidden" name="website" value="' . Html::escape($website) . '"><label>' . Html::escape($translator->get('keyword')) . '<select name="keyword"><option value="">' . Html::escape($translator->get('all')) . '</option>';
            foreach ($model['keywords'] as $option) $filters .= '<option value="' . Html::escape((string) $option['public_id']) . '"' . ($keyword === $option['public_id'] ? ' selected' : '') . '>' . Html::escape((string) $option['keyword_text'] . ' — ' . $translator->get((string) $option['device'])) . '</option>';
            $filters .= '</select></label><label>' . Html::escape($translator->get('device')) . '<select name="device">' . $this->options(['all', 'desktop', 'mobile'], $model['device'], $translator) . '</select></label><label>' . Html::escape($translator->get('date_range')) . '<select name="range">' . $this->rangeOptions($model['range'], $translator) . '</select></label><button>' . Html::escape($translator->get('apply')) . '</button></form>';
            $rows = '';
            $hasHistory = false;
            foreach ($model['rows'] as $row) {
                $hasHistory = $hasHistory || $row['last_checked'] !== null;
                $change = match ($row['change_state']) {
                    'improved' => $translator->get('improved', ['count' => $row['change']]),
                    'dropped' => $translator->get('dropped', ['count' => abs((int) $row['change'])]),
                    'unchanged' => $translator->get('unchanged'),
                    default => $translator->get('unavailable'),
                };
                $chart = '/rank-dashboard/chart?website=' . $website . '&keyword=' . rawurlencode((string) $row['public_id']) . '&range=' . rawurlencode($model['range']) . '&device=all';
                $rows .= '<tr><td>' . Html::escape((string) $row['keyword_text']) . '<small>' . Html::escape($translator->get((string) $row['device'])) . '</small></td><td>' . $this->position($row['current_position'], $translator) . '</td><td>' . $this->position($row['previous_position'], $translator) . '</td><td><span class="change ' . Html::escape((string) $row['change_state']) . '">' . Html::escape($change) . '</span></td><td>' . $this->position($row['best_position'], $translator) . '</td><td>' . $this->position($row['worst_position'], $translator) . '</td><td class="url-cell">' . ($row['ranking_url'] === null ? '—' : Html::escape((string) $row['ranking_url'])) . '</td><td>' . ($row['last_checked'] === null ? '—' : Html::escape((string) $row['last_checked'])) . '</td><td>' . $this->position($row['desktop_position'], $translator) . '</td><td>' . $this->position($row['mobile_position'], $translator) . '</td><td><a href="' . Html::escape($chart) . '">' . Html::escape($translator->get('view_chart')) . '</a></td></tr>';
            }
            $empty = !$hasHistory ? '<p class="empty-state">' . Html::escape($translator->get('no_history')) . '</p>' : '';
            $table = '<div class="table-scroll"><table><thead><tr>' . $this->headings($translator) . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
            return $this->localizedPage($translator->get('dashboard') . ' — ' . $model['website']['site_name'], $filters . $empty . $table, $translator);
        } catch (AuthorizationException) { return $this->localizedPage($translator->get('access_denied'), '<p class="error">' . Html::escape($translator->get('access_denied')) . '</p>', $translator, 403); }
        catch (InvalidArgumentException) { return $this->localizedPage($translator->get('not_found'), '<p class="error">' . Html::escape($translator->get('not_found')) . '</p>', $translator, 404); }
        catch (Throwable) { return $this->localizedPage($translator->get('dashboard'), '<p class="error">' . Html::escape($translator->get('not_found')) . '</p>', $translator, 503); }
    }

    public function chart(Request $request): Response
    {
        $translator = $this->factory->translator();
        try {
            [, $auth] = $this->factory->services();
            $website = $this->id($request->query['website'] ?? null, $translator->get('website'));
            $keyword = $this->id($request->query['keyword'] ?? null, $translator->get('keyword'));
            $model = $this->factory->dashboard()->chart($this->actor($auth), $website, $keyword, (string) ($request->query['device'] ?? 'all'), (string) ($request->query['range'] ?? '30'));
            $chart = (new RankChartRenderer($translator))->render($model);
            $legend = '<div class="chart-legend"><span class="desktop">' . Html::escape($translator->get('desktop')) . '</span><span class="mobile">' . Html::escape($translator->get('mobile')) . '</span></div>';
            $history = ''; $observations = [];
            foreach ($model['series'] as $device => $points) foreach ($points as $point) $observations[] = $point + ['device_label' => $device];
            usort($observations, static fn (array $left, array $right): int => strcmp((string) $left['observed_at'], (string) $right['observed_at']));
            foreach ($observations as $point) $history .= '<tr><td>' . Html::escape((string) $point['observed_at']) . '</td><td>' . Html::escape($translator->get((string) $point['device_label'])) . '</td><td>' . Html::escape($translator->get($point['result_type'] === 'not_found' ? 'not_found_result' : 'ranked')) . '</td><td>' . $this->position($point['position'] === null ? null : (int) $point['position'], $translator) . '</td><td class="url-cell">' . Html::escape((string) ($point['ranking_url'] ?? '—')) . '</td></tr>';
            $filters = '<form class="filters" method="get" action="/rank-dashboard/chart"><input type="hidden" name="website" value="' . Html::escape($website) . '"><input type="hidden" name="keyword" value="' . Html::escape($keyword) . '"><label>' . Html::escape($translator->get('device')) . '<select name="device">' . $this->options(['all', 'desktop', 'mobile'], $model['device'], $translator) . '</select></label><label>' . Html::escape($translator->get('date_range')) . '<select name="range">' . $this->rangeOptions($model['range'], $translator) . '</select></label><button>' . Html::escape($translator->get('apply')) . '</button></form>';
            $table = '<div class="table-scroll"><table><thead><tr><th>' . Html::escape($translator->get('observed_at')) . '</th><th>' . Html::escape($translator->get('device')) . '</th><th>' . Html::escape($translator->get('result')) . '</th><th>' . Html::escape($translator->get('position')) . '</th><th>' . Html::escape($translator->get('ranking_url')) . '</th></tr></thead><tbody>' . $history . '</tbody></table></div>';
            $empty = $history === '' ? '<p class="empty-state">' . Html::escape($translator->get('no_history')) . '</p>' : '';
            return $this->localizedPage($translator->get('chart') . ' — ' . $model['keyword']['keyword_text'], $filters . '<p>' . Html::escape($translator->get('chart_description')) . '</p>' . $legend . $chart . $empty . $table, $translator);
        } catch (AuthorizationException) { return $this->localizedPage($translator->get('access_denied'), '<p class="error">' . Html::escape($translator->get('access_denied')) . '</p>', $translator, 403); }
        catch (Throwable) { return $this->localizedPage($translator->get('not_found'), '<p class="error">' . Html::escape($translator->get('not_found')) . '</p>', $translator, 404); }
    }

    private function headings(Translator $t): string
    {
        return implode('', array_map(static fn (string $key): string => '<th>' . Html::escape($t->get($key)) . '</th>', ['keyword', 'current', 'previous', 'change', 'best', 'worst', 'ranking_url', 'last_checked', 'desktop_position', 'mobile_position', 'actions']));
    }

    private function options(array $values, string $selected, Translator $t): string
    {
        $html = ''; foreach ($values as $value) $html .= '<option value="' . $value . '"' . ($value === $selected ? ' selected' : '') . '>' . Html::escape($t->get($value)) . '</option>'; return $html;
    }

    private function rangeOptions(string $selected, Translator $t): string
    {
        $html = '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>' . Html::escape($t->get('all')) . '</option>'; foreach (['7', '30', '90', '365'] as $range) $html .= '<option value="' . $range . '"' . ($selected === $range ? ' selected' : '') . '>' . Html::escape($t->get('days', ['count' => $range])) . '</option>'; return $html;
    }

    private function position(?int $position, Translator $t): string { return $position === null ? '<span class="unavailable">' . Html::escape($t->get('unavailable')) . '</span>' : '#' . $position; }

    private function localizedPage(string $title, string $content, Translator $t, int $status = 200): Response
    {
        $dir = $this->factory->isRtl() ? 'rtl' : 'ltr';
        return Response::html('<!doctype html><html lang="' . Html::escape($t->locale()) . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status);
    }

    private function actor(object $auth): int { $user = $auth->user(); if ($user === null) throw new AuthorizationException('Authentication required.'); return (int) $user['id']; }
    private function id(mixed $id, string $label): string { if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new InvalidArgumentException($label . ' not found.'); return $id; }
    private function error(string $message, int $status): Response { return $this->page('Rank Tracking', '<p class="error">' . Html::escape($message) . '</p>', $status); }
    private function page(string $title, string $content, int $status = 200): Response { return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status); }
}
