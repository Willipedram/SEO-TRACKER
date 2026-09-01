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
            $rows = '';
            foreach ($manager->list($actor, $website) as $keyword) {
                $id = Html::escape((string) $keyword['public_id']);
                $target = $keyword['target_url'] === null ? '—' : Html::escape((string) $keyword['target_url']);
                $status = (int) $keyword['active'] === 1 ? 'Active' : 'Inactive';
                $rows .= '<tr><td>' . Html::escape((string) $keyword['keyword_text']) . '</td><td>' . Html::escape((string) $keyword['search_engine']) . '</td><td>' . Html::escape((string) $keyword['country_code']) . ' / ' . Html::escape((string) $keyword['language_code']) . '</td><td>' . Html::escape((string) $keyword['device']) . '</td><td>' . $target . '</td><td>' . $status . '</td><td><a href="/keywords/edit?website=' . $website . '&amp;id=' . $id . '">Edit</a></td></tr>';
            }
            return $this->page('Keywords', '<p><a href="/websites/dashboard?id=' . $website . '">Website dashboard</a> · <a class="button" href="/keywords/create?website=' . $website . '">Add keyword</a></p><table><thead><tr><th>Keyword</th><th>Engine</th><th>Market</th><th>Device</th><th>Target URL</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>');
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
            $manager->create($this->actor($auth), $website, KeywordInput::from($request->body, $engines, $devices, $countries));
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
            $form = '<form method="post" action="/keywords/' . ($editing ? 'edit' : 'create') . '">' . $this->csrf() . '<input type="hidden" name="website" value="' . $website . '">';
            if ($editing) $form .= '<input type="hidden" name="id" value="' . Html::escape((string) $keyword['public_id']) . '">';
            $form .= '<label>Keyword<input required name="keyword" maxlength="255" value="' . Html::escape((string) ($keyword['keyword_text'] ?? '')) . '"></label><label>Target URL (optional)<input type="url" name="target_url" maxlength="2048" value="' . Html::escape((string) ($keyword['target_url'] ?? '')) . '"></label>';
            $form .= '<label>Search engine<select name="search_engine">' . $this->options($engines, (string) ($keyword['search_engine'] ?? 'google')) . '</select></label><label>Country code<input required name="country" minlength="2" maxlength="2" value="' . Html::escape((string) ($keyword['country_code'] ?? 'US')) . '"></label><label>Language<input required name="language" maxlength="35" value="' . Html::escape((string) ($keyword['language_code'] ?? 'en')) . '"></label><label>Device<select name="device">' . $this->options($devices, (string) ($keyword['device'] ?? 'desktop')) . '</select></label><label><input type="checkbox" name="active" value="1"' . (!$editing || (int) $keyword['active'] === 1 ? ' checked' : '') . '> Active</label><button>Save keyword</button></form>';
            if ($editing) {
                $active = (int) $keyword['active'] === 1;
                $form .= '<form method="post" action="/keywords/status">' . $this->csrf() . '<input type="hidden" name="website" value="' . $website . '"><input type="hidden" name="id" value="' . Html::escape((string) $keyword['public_id']) . '"><input type="hidden" name="active" value="' . ($active ? '0' : '1') . '"><button>' . ($active ? 'Deactivate' : 'Activate') . '</button></form>';
                $form .= '<form method="post" action="/keywords/delete">' . $this->csrf() . '<input type="hidden" name="website" value="' . $website . '"><input type="hidden" name="id" value="' . Html::escape((string) $keyword['public_id']) . '"><button class="danger">Delete keyword</button></form>';
                $form .= '<p class="rank-keyword-links"><a class="button" href="/rank-dashboard?website=' . $website . '&keyword=' . Html::escape((string) $keyword['public_id']) . '">Open rank dashboard</a><a href="/rank-checks/history?website=' . $website . '&keyword=' . Html::escape((string) $keyword['public_id']) . '">View rank history</a></p>';
            }
            return $this->page($editing ? 'Edit keyword' : 'Add keyword', $form);
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
