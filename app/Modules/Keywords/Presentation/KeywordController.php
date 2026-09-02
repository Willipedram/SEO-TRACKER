<?php

declare(strict_types=1);

namespace App\Modules\Keywords\Presentation;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Rbac\AuthorizationException;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use App\Modules\Keywords\Domain\KeywordInput;
use App\Modules\Keywords\Infrastructure\KeywordFactory;
use InvalidArgumentException;
use Throwable;

final class KeywordController
{
    public function __construct(private readonly KeywordFactory $factory) {}

    public function index(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $actor = $this->actor($auth);
            if (!is_string($request->query['website'] ?? null) || $request->query['website'] === '') {
                return $this->websiteSelection($manager->websites($actor));
            }
            $website = $this->websiteId($request->query['website'] ?? null);
            $notice = is_string($_SESSION['keyword_notice'] ?? null) ? (string) $_SESSION['keyword_notice'] : '';
            unset($_SESSION['keyword_notice']);
            $noticeHtml = $notice === '' ? '' : '<div class="alert alert-info keyword-import-notice" role="status">' . Html::escape($notice) . '</div>';
            $rows = '';
            foreach ($manager->list($actor, $website) as $keyword) {
                $id = Html::escape((string) $keyword['public_id']);
                $target = $keyword['target_url'] === null ? '—' : Html::escape((string) $keyword['target_url']);
                $status = (int) $keyword['active'] === 1 ? 'Active' : 'Inactive';
                $editUrl = '/keywords/edit?website=' . $website . '&amp;id=' . $id;
                $rows .= '<tr><td><strong>' . Html::escape((string) $keyword['keyword_text']) . '</strong></td><td><span class="badge text-bg-light">' . Html::escape((string) $keyword['search_engine']) . '</span></td><td>' . Html::escape((string) $keyword['country_code']) . ' / ' . Html::escape((string) $keyword['language_code']) . '</td><td>' . Html::escape((string) $keyword['device']) . '</td><td>' . $target . '</td><td><span class="badge ' . ((int)$keyword['active'] === 1 ? 'text-bg-success' : 'text-bg-secondary') . '">' . $status . '</span></td><td><button class="btn btn-sm btn-outline-primary keyword-edit-open" type="button" data-edit-url="' . $editUrl . '"><i class="bi bi-pencil-square" aria-hidden="true"></i><span>Edit</span></button></td></tr>';
            }
            [, , $engines, $devices] = $this->factory->services();
            $createModal = '<div class="keyword-modal" id="keyword-create-modal" hidden><div class="keyword-modal-dialog card" role="dialog" aria-modal="true" aria-labelledby="keyword-create-title"><div class="keyword-modal-header"><div><span class="keyword-modal-kicker">Keyword manager</span><h2 id="keyword-create-title">Add keywords</h2></div><button class="btn-close" type="button" data-keyword-modal-close aria-label="Close"></button></div>' . $this->bulkCreateForm($website, $engines, $devices) . '</div></div>';
            $editModal = '<div class="keyword-modal" id="keyword-edit-modal" hidden><div class="keyword-modal-dialog card" role="dialog" aria-modal="true" aria-labelledby="keyword-edit-title"><div class="keyword-modal-header"><div><span class="keyword-modal-kicker">Keyword manager</span><h2 id="keyword-edit-title">Edit keyword</h2></div><button class="btn-close" type="button" data-keyword-modal-close aria-label="Close"></button></div><div class="keyword-edit-modal-body" data-keyword-edit-content><div class="d-flex align-items-center justify-content-center gap-2 py-5"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading…</span></div></div></div></div>';
            return $this->page('Keywords', $noticeHtml . '<div class="keyword-toolbar"><a class="btn btn-light border" href="/websites/dashboard?id=' . $website . '"><i class="bi bi-arrow-right" aria-hidden="true"></i>Website dashboard</a><button class="btn btn-primary keyword-add-button" type="button" data-keyword-modal-open><i class="bi bi-plus-lg" aria-hidden="true"></i>Add keyword list</button></div><div class="table-scroll"><table><thead><tr><th>Keyword</th><th>Engine</th><th>Market</th><th>Device</th><th>Target URL</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $createModal . $editModal);
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->error('Keywords', $exception, 404); }
        catch (Throwable) { return $this->failure(); }
    }

    public function createForm(Request $request): Response
    {
        return $this->formPage($request, false);
    }

    public function create(Request $request): Response
    {
        try {
            [$manager, $auth, $engines, $devices, $countries] = $this->factory->services();
            $website = $this->websiteId($request->body['website'] ?? null);
            $terms = preg_split('/\R/u', (string) ($request->body['keywords'] ?? $request->body['keyword'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $terms = array_values(array_filter(array_map('trim', $terms), static fn (string $term): bool => $term !== ''));
            $selected = $request->body['devices'] ?? [$request->body['device'] ?? 'desktop'];
            if (!is_array($selected)) $selected = [$selected];
            $selected = array_values(array_intersect($devices, array_map('strval', $selected)));
            if ($terms === [] || $selected === []) throw new InvalidArgumentException('At least one keyword and one device are required.');
            $inputs = [];
            foreach ($terms as $term) foreach ($selected as $device) $inputs[] = KeywordInput::from(array_replace($request->body, ['keyword' => $term, 'device' => $device]), $engines, $devices, $countries);
            $result = $manager->createBatch($this->actor($auth), $website, $inputs);
            $skippedWords = array_values(array_unique(array_column($result['skipped'], 'keyword')));
            $message = count($result['created']) . ' ترکیب کلیدواژه و دستگاه افزوده شد.';
            if ($skippedWords !== []) $message .= ' قبلاً وارد شده و نادیده گرفته شد: «' . implode('»، «', $skippedWords) . '».';
            $_SESSION['keyword_notice'] = $message;
            if (strtolower((string) $request->header('x-requested-with')) === 'xmlhttprequest') {
                return Response::json(['redirect' => '/keywords?website=' . $website]);
            }
            return Response::redirect('/keywords?website=' . $website);
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->error('Add keyword', $exception); }
        catch (Throwable) { return $this->error('Add keyword', new InvalidArgumentException('The keyword could not be created.')); }
    }

    public function editForm(Request $request): Response
    {
        return $this->formPage($request, true);
    }

    public function update(Request $request): Response
    {
        try {
            [$manager, $auth, $engines, $devices, $countries] = $this->factory->services();
            $website = $this->websiteId($request->body['website'] ?? null);
            $id = $this->keywordId($request->body['id'] ?? null);
            $manager->update($this->actor($auth), $website, $id, KeywordInput::from($request->body, $engines, $devices, $countries));
            return Response::redirect('/keywords?website=' . $website);
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->error('Edit keyword', $exception); }
        catch (Throwable) { return $this->error('Edit keyword', new InvalidArgumentException('The keyword could not be updated.')); }
    }

    public function status(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $website = $this->websiteId($request->body['website'] ?? null);
            $active = filter_var($request->body['active'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($active === null) throw new InvalidArgumentException('Keyword status is invalid.');
            $manager->setActive($this->actor($auth), $website, $this->keywordId($request->body['id'] ?? null), $active);
            return Response::redirect('/keywords?website=' . $website);
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->error('Keyword status', $exception); }
        catch (Throwable) { return $this->failure(); }
    }

    public function delete(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $website = $this->websiteId($request->body['website'] ?? null);
            $manager->delete($this->actor($auth), $website, $this->keywordId($request->body['id'] ?? null));
            return Response::redirect('/keywords?website=' . $website);
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->error('Delete keyword', $exception); }
        catch (Throwable) { return $this->failure(); }
    }

    private function formPage(Request $request, bool $editing): Response
    {
        try {
            [$manager, $auth, $engines, $devices] = $this->factory->services();
            $actor = $this->actor($auth);
            $website = $this->websiteId($request->query['website'] ?? null);
            $permission = $editing ? 'keywords.edit' : 'keywords.create';
            $keyword = $editing ? $manager->find($actor, $website, $this->keywordId($request->query['id'] ?? null), $permission) : null;
            if (!$editing) $manager->authorize($actor, $website, $permission);
            if (!$editing) return $this->page('Add keyword', $this->bulkCreateForm($website, $engines, $devices));
            $form = '<div class="keyword-edit-panel"><form class="adminlte-form keyword-edit-form" method="post" action="/keywords/edit">' . $this->csrf() . '<input type="hidden" name="website" value="' . $website . '">';
            if ($editing) $form .= '<input type="hidden" name="id" value="' . Html::escape((string) $keyword['public_id']) . '">';
            $form .= '<div class="row g-3"><label class="col-12">Keyword<input class="form-control form-control-lg" required name="keyword" maxlength="255" value="' . Html::escape((string) ($keyword['keyword_text'] ?? '')) . '"></label><label class="col-12">Target URL (optional)<input class="form-control" type="url" name="target_url" maxlength="2048" value="' . Html::escape((string) ($keyword['target_url'] ?? '')) . '"></label>';
            $form .= '<label class="col-md-6">Search engine<select class="form-select" name="search_engine">' . $this->options($engines, (string) ($keyword['search_engine'] ?? 'google')) . '</select></label><label class="col-md-3">Country code<input class="form-control" required name="country" minlength="2" maxlength="2" value="' . Html::escape((string) ($keyword['country_code'] ?? 'US')) . '"></label><label class="col-md-3">Language<input class="form-control" required name="language" maxlength="35" value="' . Html::escape((string) ($keyword['language_code'] ?? 'en')) . '"></label><label class="col-md-6">Device<select class="form-select" name="device">' . $this->options($devices, (string) ($keyword['device'] ?? 'desktop')) . '</select></label><div class="col-md-6 d-flex align-items-end"><label class="form-check form-switch keyword-active-switch"><input class="form-check-input" type="checkbox" name="active" value="1"' . ((int) $keyword['active'] === 1 ? ' checked' : '') . '><span class="form-check-label">Active</span></label></div></div><div class="keyword-edit-primary-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i>Save keyword</button><button class="btn btn-light border" type="button" data-keyword-modal-close>Cancel</button></div></form>';
            if ($editing) {
                $active = (int) $keyword['active'] === 1;
                $form .= '<div class="keyword-edit-secondary-actions"><a class="btn btn-info text-white" href="/rank-dashboard?website=' . $website . '&keyword=' . Html::escape((string) $keyword['public_id']) . '"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i>Open rank dashboard</a><a class="btn btn-outline-secondary" href="/rank-checks/history?website=' . $website . '&keyword=' . Html::escape((string) $keyword['public_id']) . '"><i class="bi bi-clock-history" aria-hidden="true"></i>View rank history</a><form method="post" action="/keywords/status">' . $this->csrf() . '<input type="hidden" name="website" value="' . $website . '"><input type="hidden" name="id" value="' . Html::escape((string) $keyword['public_id']) . '"><input type="hidden" name="active" value="' . ($active ? '0' : '1') . '"><button class="btn btn-warning" type="submit"><i class="bi bi-toggle-' . ($active ? 'off' : 'on') . '" aria-hidden="true"></i>' . ($active ? 'Deactivate' : 'Activate') . '</button></form><form method="post" action="/keywords/delete">' . $this->csrf() . '<input type="hidden" name="website" value="' . $website . '"><input type="hidden" name="id" value="' . Html::escape((string) $keyword['public_id']) . '"><button class="btn btn-outline-danger danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i>Delete keyword</button></form></div></div>';
            }
            return $this->page('Edit keyword', $form);
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->error('Keyword', $exception, 404); }
        catch (Throwable) { return $this->failure(); }
    }

    private function options(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value) if (is_string($value)) $html .= '<option value="' . Html::escape($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . Html::escape(ucfirst($value)) . '</option>';
        return $html;
    }

    private function bulkCreateForm(string $website, array $engines, array $devices): string
    {
        $deviceChecks = '';
        foreach ($devices as $device) if (is_string($device)) $deviceChecks .= '<label class="form-check"><input class="form-check-input" type="checkbox" name="devices[]" value="' . Html::escape($device) . '" checked><span class="form-check-label">' . Html::escape(ucfirst($device)) . '</span></label>';
        return '<form class="adminlte-form keyword-bulk-form" method="post" action="/keywords/create">' . $this->csrf() . '<input type="hidden" name="website" value="' . Html::escape($website) . '"><div class="alert alert-info">Each line is saved as a separate keyword for every selected device.</div><label>Keyword list<textarea class="form-control" required name="keywords" rows="7" maxlength="20000" placeholder="One keyword per line"></textarea></label><label>Target URL (optional)<input class="form-control" type="url" name="target_url" maxlength="2048"></label><div class="row g-3"><label class="col-md-4">Search engine<select class="form-select" name="search_engine">' . $this->options($engines, 'google') . '</select></label><label class="col-md-4">Country code<input class="form-control" required name="country" minlength="2" maxlength="2" value="IR"></label><label class="col-md-4">Language<input class="form-control" required name="language" maxlength="35" value="fa"></label></div><fieldset class="keyword-device-fieldset"><legend>Devices</legend><div class="d-flex flex-wrap gap-3">' . $deviceChecks . '</div></fieldset><label class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked><span class="form-check-label">Active</span></label><div class="keyword-modal-actions"><button class="btn btn-outline-secondary" type="button" data-keyword-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save keywords</button></div></form>';
    }

    private function websiteSelection(array $websites): Response
    {
        if ($websites === []) {
            return $this->page('Keywords', '<div class="empty-state"><h2>No websites have been added yet.</h2><p>Add a website before creating and tracking keywords.</p><a class="button" href="/websites/create">Add the first website</a></div>');
        }
        $rows = '';
        foreach ($websites as $website) {
            $id = Html::escape((string) $website['public_id']);
            $rows .= '<tr><td><strong>' . Html::escape((string) $website['site_name']) . '</strong></td><td class="technical-ltr">' . Html::escape((string) $website['normalized_domain']) . '</td><td>' . (int) $website['keyword_count'] . '</td><td><a class="button" href="/keywords?website=' . $id . '">Open keywords</a> <a href="/keywords/create?website=' . $id . '">Add keyword</a></td></tr>';
        }
        return $this->page('Keywords', '<p>Select a website to manage its keywords.</p><div class="table-scroll"><table><thead><tr><th>Website</th><th>Domain</th><th>Keywords</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    }

    private function actor(object $auth): int { $user = $auth->user(); if ($user === null) throw new AuthorizationException('Authentication required.'); return (int) $user['id']; }
    private function websiteId(mixed $id): string { if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new InvalidArgumentException('Website not found.'); return $id; }
    private function keywordId(mixed $id): string { if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new InvalidArgumentException('Keyword not found.'); return $id; }
    private function csrf(): string { return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">'; }
    private function denied(AuthorizationException $exception): Response { return $this->page('Access denied', '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
    private function error(string $title, Throwable $exception, int $status = 422): Response { return $this->page($title, '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', $status); }
    private function failure(): Response { return Response::json(['error' => 'Keyword management is unavailable.'], 503); }
    private function page(string $title, string $content, int $status = 200): Response { return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><p><strong>SEO Tracker keywords</strong></p><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status); }
}
