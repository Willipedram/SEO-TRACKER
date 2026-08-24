<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Presentation;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Rbac\AuthorizationException;
use App\Core\Security\Html;
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

    private function actor(object $auth): int { $user = $auth->user(); if ($user === null) throw new AuthorizationException('Authentication required.'); return (int) $user['id']; }
    private function id(mixed $id, string $label): string { if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new InvalidArgumentException($label . ' not found.'); return $id; }
    private function error(string $message, int $status): Response { return $this->page('Rank Tracking', '<p class="error">' . Html::escape($message) . '</p>', $status); }
    private function page(string $title, string $content, int $status = 200): Response { return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status); }
}
