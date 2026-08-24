<?php

declare(strict_types=1);

namespace App\Modules\Websites\Presentation;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Rbac\AuthorizationException;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use App\Modules\Websites\Domain\WebsiteInput;
use App\Modules\Websites\Infrastructure\WebsiteFactory;
use InvalidArgumentException;
use Throwable;

final class WebsiteController
{
    public function __construct(private readonly WebsiteFactory $factory) {}

    public function index(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $actor = $this->actor($auth);
            $includeArchived = ($request->query['archived'] ?? '') === '1';
            $rows = '';
            foreach ($manager->list($actor, $includeArchived) as $website) {
                $id = Html::escape((string) $website['public_id']);
                $rows .= '<tr><td><a href="/websites/dashboard?id=' . $id . '">' . Html::escape((string) $website['site_name']) . '</a></td><td>' . Html::escape((string) $website['normalized_domain']) . '</td><td>' . Html::escape((string) $website['status']) . '</td><td><a href="/websites/edit?id=' . $id . '">Edit</a> · <a href="/websites/settings?id=' . $id . '">Settings</a></td></tr>';
            }
            return $this->page('Websites', '<p><a class="button" href="/websites/create">Add website</a> <a href="/websites?archived=1">Include archived</a></p><table><thead><tr><th>Site</th><th>Domain</th><th>Status</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table>');
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function createForm(): Response
    {
        return $this->authorizedForm('websites.create', 'Add website', $this->websiteForm('/websites/create'));
    }

    public function create(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $publicId = $manager->create($this->actor($auth), $this->input($request));
            return Response::redirect('/websites/dashboard?id=' . rawurlencode($publicId));
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Add website', $exception);
        } catch (Throwable) {
            return $this->error('Add website', new InvalidArgumentException('The website could not be created.'));
        }
    }

    public function editForm(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $actor = $this->actor($auth);
            $website = $manager->findForEdit($actor, $this->id($request));
            return $this->authorizedForm('websites.edit', 'Edit website', $this->websiteForm('/websites/edit', $website));
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Edit website', $exception, 404);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function update(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $id = $this->bodyId($request);
            $manager->update($this->actor($auth), $id, $this->input($request));
            return Response::redirect('/websites/dashboard?id=' . rawurlencode($id));
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Edit website', $exception);
        } catch (Throwable) {
            return $this->error('Edit website', new InvalidArgumentException('The website could not be updated.'));
        }
    }

    public function dashboard(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $website = $manager->findForEdit($this->actor($auth), $this->id($request));
            $id = Html::escape((string) $website['public_id']);
            $content = '<p><a href="/websites">All websites</a> · <a href="/websites/edit?id=' . $id . '">Edit</a> · <a href="/websites/settings?id=' . $id . '">Settings</a></p>';
            $content .= '<dl><dt>URL</dt><dd><code>' . Html::escape((string) $website['canonical_url']) . '</code></dd><dt>Status</dt><dd>' . Html::escape((string) $website['status']) . '</dd><dt>Timezone</dt><dd>' . Html::escape((string) $website['timezone']) . '</dd><dt>Description</dt><dd>' . nl2br(Html::escape((string) $website['description'])) . '</dd></dl>';
            $content .= '<section><h2>Tracking overview</h2><p>Keyword and rank tracking will become available in their respective modules.</p></section>';
            return $this->page((string) $website['site_name'], $content);
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Website', $exception, 404);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function settingsForm(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $website = $manager->find($this->actor($auth), $this->id($request));
            $id = Html::escape((string) $website['public_id']);
            $timezones = '';
            foreach (timezone_identifiers_list() as $timezone) {
                $timezones .= '<option' . ($timezone === $website['timezone'] ? ' selected' : '') . '>' . Html::escape($timezone) . '</option>';
            }
            $status = (string) $website['status'];
            $form = '<form method="post" action="/websites/settings">' . $this->csrf() . '<input type="hidden" name="id" value="' . $id . '"><label>Timezone<select name="timezone">' . $timezones . '</select></label><label>Status<select name="status"><option value="active"' . ($status === 'active' ? ' selected' : '') . '>Active</option><option value="paused"' . ($status === 'paused' ? ' selected' : '') . '>Paused</option></select></label><button>Save settings</button></form><form method="post" action="/websites/archive">' . $this->csrf() . '<input type="hidden" name="id" value="' . $id . '"><button class="danger">Archive website</button></form>';
            return $this->authorizedForm('websites.edit', 'Website settings', $form);
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Website settings', $exception, 404);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    public function settings(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $id = $this->bodyId($request);
            $manager->settings($this->actor($auth), $id, (string) ($request->body['timezone'] ?? ''), (string) ($request->body['status'] ?? ''));
            return Response::redirect('/websites/dashboard?id=' . rawurlencode($id));
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Website settings', $exception);
        } catch (Throwable) {
            return $this->error('Website settings', new InvalidArgumentException('Settings could not be saved.'));
        }
    }

    public function archive(Request $request): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $manager->archive($this->actor($auth), $this->bodyId($request));
            return Response::redirect('/websites');
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Archive website', $exception);
        } catch (Throwable) {
            return $this->error('Archive website', new InvalidArgumentException('The website could not be archived.'));
        }
    }

    private function input(Request $request): WebsiteInput
    {
        return WebsiteInput::from((string) ($request->body['name'] ?? ''), (string) ($request->body['url'] ?? ''), (string) ($request->body['description'] ?? ''));
    }

    private function id(Request $request): string
    {
        return $this->validId($request->query['id'] ?? null);
    }

    private function bodyId(Request $request): string
    {
        return $this->validId($request->body['id'] ?? null);
    }

    private function validId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new InvalidArgumentException('Website not found.');
        return $id;
    }

    private function actor(object $auth): int
    {
        $user = $auth->user();
        if ($user === null) throw new AuthorizationException('Authentication required.');
        return (int) $user['id'];
    }

    private function authorizedForm(string $permission, string $title, string $form): Response
    {
        try {
            [$manager, $auth] = $this->factory->services();
            $manager->authorize($this->actor($auth), $permission);
            return $this->page($title, $form);
        } catch (AuthorizationException $exception) {
            return $this->denied($exception);
        } catch (Throwable) {
            return $this->failure();
        }
    }

    private function websiteForm(string $action, ?array $website = null): string
    {
        $hidden = $website === null ? '' : '<input type="hidden" name="id" value="' . Html::escape((string) $website['public_id']) . '">';
        return '<form method="post" action="' . $action . '">' . $this->csrf() . $hidden . '<label>Site name<input required name="name" maxlength="150" value="' . Html::escape((string) ($website['site_name'] ?? '')) . '"></label><label>Website URL<input required type="url" name="url" maxlength="2048" placeholder="https://example.com" value="' . Html::escape((string) ($website['canonical_url'] ?? '')) . '"></label><label>Description<textarea name="description" maxlength="5000">' . Html::escape((string) ($website['description'] ?? '')) . '</textarea></label><button>Save website</button></form>';
    }

    private function csrf(): string { return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">'; }
    private function denied(AuthorizationException $exception): Response { return $this->page('Access denied', '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
    private function error(string $title, Throwable $exception, int $status = 422): Response { return $this->page($title, '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', $status); }
    private function failure(): Response { return Response::json(['error' => 'Website management is unavailable.'], 503); }
    private function page(string $title, string $content, int $status = 200): Response { return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><p><strong>SEO Tracker websites</strong></p><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status); }
}
