<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Rbac\AuthorizationException;
use App\Core\Security\Html;
use App\Modules\Reports\Application\ReportService;
use App\Modules\Reports\Infrastructure\ReportsFactory;
use InvalidArgumentException;
use Throwable;

final class ReportsController
{
    public function __construct(private readonly ReportsFactory $factory) {}
    public function index(Request $request): Response
    {
        $t = $this->factory->translator(); try { [$service, $auth] = $this->factory->services(); $actor = $this->actor($auth); $type = is_string($request->query['type'] ?? null) ? $request->query['type'] : 'website'; $model = $service->report($actor, $type, $request->query); $available = $service->filterOptions($actor);
            $options = ''; foreach (ReportService::TYPES as $value) $options .= '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . '>' . Html::escape($t->get('type_' . $value)) . '</option>';
            $websiteOptions = '<option value="">' . Html::escape($t->get('option_all')) . '</option>'; foreach ($available['websites'] as $website) $websiteOptions .= '<option value="' . Html::escape((string) $website['public_id']) . '"' . ($model['filters']['website'] === $website['public_id'] ? ' selected' : '') . '>' . Html::escape((string) $website['site_name']) . '</option>'; $keywordOptions = '<option value="">' . Html::escape($t->get('option_all')) . '</option>'; foreach ($available['keywords'] as $keyword) $keywordOptions .= '<option value="' . Html::escape((string) $keyword['public_id']) . '"' . ($model['filters']['keyword'] === $keyword['public_id'] ? ' selected' : '') . '>' . Html::escape((string) $keyword['keyword_text'] . ' — ' . $t->get('option_' . $keyword['device'])) . '</option>';
            $filters = '<form class="filters" method="get" action="/reports"><label>' . Html::escape($t->get('report')) . '<select name="type">' . $options . '</select></label><label>' . Html::escape($t->get('website')) . '<select name="website">' . $websiteOptions . '</select></label><label>' . Html::escape($t->get('keyword')) . '<select name="keyword">' . $keywordOptions . '</select></label><label>' . Html::escape($t->get('start_date')) . '<input type="date" name="start_date" value="' . Html::escape($model['filters']['start_date']) . '"></label><label>' . Html::escape($t->get('end_date')) . '<input type="date" name="end_date" value="' . Html::escape($model['filters']['end_date']) . '"></label><label>' . Html::escape($t->get('device')) . '<select name="device">' . $this->options(['all','desktop','mobile','tablet'], $model['filters']['device'], $t) . '</select></label><label>' . Html::escape($t->get('country')) . '<input maxlength="3" name="country" value="' . Html::escape($model['filters']['country']) . '"></label><label>' . Html::escape($t->get('search_type')) . '<select name="search_type">' . $this->options(['all','web','image','video','news','discover','googleNews'], $model['filters']['search_type'], $t) . '</select></label><label>' . Html::escape($t->get('query')) . '<input maxlength="200" name="query" value="' . Html::escape($model['filters']['query']) . '"></label><label>' . Html::escape($t->get('page_url')) . '<input type="url" maxlength="2048" name="page_url" value="' . Html::escape($model['filters']['page_url']) . '"></label><button>' . Html::escape($t->get('apply')) . '</button></form>';
            $source = '<p class="source-label ' . Html::escape($model['source']) . '">' . Html::escape($t->get('source_' . $model['source'])) . '</p>'; $empty = $model['state'] === 'ready' ? '' : '<p class="empty-state">' . Html::escape($t->get('state_' . $model['state'])) . '</p>'; $table = $this->table($model['rows'], $t); $query = $request->query; $query['type'] = $type; unset($query['page']); $export = '<p><a class="button" href="/reports/export.csv?' . Html::escape(http_build_query($query)) . '">' . Html::escape($t->get('export_csv')) . '</a></p>'; $pagination = '<p>' . Html::escape($t->get('pagination', ['page' => $model['page'], 'pages' => $model['pages'], 'total' => $model['total']])) . '</p>';
            return $this->page($t->get('title'), $filters . $source . $empty . $table . $pagination . $export);
        } catch (AuthorizationException) { return $this->page($t->get('access_denied'), '<p class="error">' . Html::escape($t->get('access_denied')) . '</p>', 403); } catch (InvalidArgumentException $e) { return $this->page($t->get('title'), '<p class="error">' . Html::escape($e->getMessage()) . '</p>', 422); } catch (Throwable) { return $this->page($t->get('title'), '<p class="error">' . Html::escape($t->get('unavailable')) . '</p>', 503); }
    }
    public function csv(Request $request): Response
    {
        try { [$service, $auth] = $this->factory->services(); $type = is_string($request->query['type'] ?? null) ? $request->query['type'] : 'website'; $body = $service->csv($this->actor($auth), $type, $request->query); return new Response($body, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="seo-report-' . $type . '.csv"', 'X-Content-Type-Options' => 'nosniff']); } catch (AuthorizationException) { return Response::json(['error' => 'Access denied.'], 403); } catch (Throwable) { return Response::json(['error' => 'Report export unavailable.'], 422); }
    }
    private function table(array $rows, object $t): string { if ($rows === []) return ''; $headers = array_keys($rows[0]); $head = ''; foreach ($headers as $header) $head .= '<th>' . Html::escape($t->get('column_' . $header)) . '</th>'; $body = ''; foreach ($rows as $row) { $body .= '<tr>'; foreach ($headers as $header) { $value = $row[$header] ?? null; if ($header === 'ctr' && $value !== null) $value = number_format((float) $value * 100, 2) . '%'; elseif (is_float($value)) $value = number_format($value, 2); $body .= '<td class="url-cell">' . Html::escape($value === null ? '—' : (string) $value) . '</td>'; } $body .= '</tr>'; } return '<div class="table-scroll"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'; }
    private function options(array $values, string $selected, object $t): string { $html = ''; foreach ($values as $value) $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . Html::escape($t->get('option_' . $value)) . '</option>'; return $html; }
    private function actor(object $auth): int { $user = $auth->user(); if ($user === null) throw new AuthorizationException('Authentication required.'); return (int) $user['id']; }
    private function page(string $title, string $content, int $status = 200): Response { $t = $this->factory->translator(); return Response::html('<!doctype html><html lang="' . Html::escape($t->locale()) . '" dir="' . ($this->factory->isRtl() ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status); }
}
