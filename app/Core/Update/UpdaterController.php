<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Logger;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use Throwable;

final class UpdaterController
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function home(): Response
    {
        try {
            return $this->runner()->plan()->required()
                ? Response::redirect('/update', 302)
                : Response::json(['application' => 'SEO Tracker', 'status' => 'ready', 'version' => $this->config->get('version.application')]);
        } catch (UpdateException) {
            return Response::redirect('/update', 302);
        } catch (Throwable) {
            return is_file($this->basePath . '/storage/installed.lock')
                ? Response::json(['error' => 'Application database is unavailable.'], 503)
                : Response::redirect('/install', 302);
        }
    }

    public function show(): Response
    {
        try {
            $plan = $this->runner()->plan();
            if (!$plan->required()) {
                return Response::redirect('/');
            }
            $items = '';
            foreach ($plan->pending as $migration) {
                $items .= '<li><code>' . Html::escape($migration->id) . '</code> → schema ' . $migration->schemaVersion . '</li>';
            }
            if ($items === '') {
                $items = '<li>Record source version ' . Html::escape($plan->sourceApplicationVersion) . '</li>';
            }
            return $this->page('Database update required', '<p>Installed source: <strong>' . Html::escape($plan->installedApplicationVersion) . '</strong>; uploaded source: <strong>' . Html::escape($plan->sourceApplicationVersion) . '</strong>.</p><p>Installed schema: <strong>' . $plan->installedSchemaVersion . '</strong>; target schema: <strong>' . $plan->targetSchemaVersion . '</strong>.</p><ol>' . $items . '</ol><p>Create and verify a database and persistent-file backup before continuing.</p><form method="post" action="/update">' . $this->csrf() . '<label>Administrator email<input required type="email" name="email" autocomplete="username"></label><label>Administrator password<input required type="password" name="password" autocomplete="current-password"></label><button type="submit">Run database update</button></form>');
        } catch (UpdateException $exception) {
            return $this->page('Update cannot start', '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 409);
        } catch (Throwable) {
            return Response::json(['error' => 'Not found.'], 404);
        }
    }

    public function run(Request $request): Response
    {
        try {
            $attempts = array_values(array_filter((array) ($_SESSION['update_auth_attempts'] ?? []), static fn (int $time): bool => $time > time() - 900));
            if (count($attempts) >= 5) {
                return $this->page('Too many attempts', '<p class="error">Update authorization is temporarily locked for this session. Wait 15 minutes or use the authenticated CLI workflow.</p>', 429);
            }
            $database = (new ConnectionFactory($this->config))->connect();
            $email = strtolower(trim((string) ($request->body['email'] ?? '')));
            $password = (string) ($request->body['password'] ?? '');
            $administrator = $database->fetchOne('SELECT users.password_hash FROM users JOIN user_roles ON user_roles.user_id = users.id JOIN roles ON roles.id = user_roles.role_id WHERE users.email = :email AND roles.role_key = :role LIMIT 1', ['email' => $email, 'role' => 'administrator']);
            if (!is_string($administrator['password_hash'] ?? null) || !password_verify($password, $administrator['password_hash'])) {
                $attempts[] = time();
                $_SESSION['update_auth_attempts'] = $attempts;
                return $this->page('Authorization failed', '<p class="error">Valid administrator credentials are required to update an existing installation.</p>', 403);
            }
            unset($_SESSION['update_auth_attempts']);
            $plan = $this->runner($database)->run();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            return $this->page('Update complete', '<p class="success">Database schema ' . $plan->installedSchemaVersion . ' and source version ' . Html::escape($plan->installedApplicationVersion) . ' are active.</p><p><a class="button" href="/">Continue</a></p>');
        } catch (UpdateException $exception) {
            return $this->page('Update failed safely', '<p class="error">' . Html::escape($exception->getMessage()) . '</p><p>Review the protected application log and migration failure record, correct the cause, verify backups, and retry.</p>', 500);
        } catch (Throwable) {
            return Response::json(['error' => 'Not found.'], 404);
        }
    }

    private function runner(?\App\Core\Database\Database $database = null): MigrationRunner
    {
        $database ??= (new ConnectionFactory($this->config))->connect();
        $logPath = (string) $this->config->get('logging.path', 'storage/logs/application.log');
        if (!str_starts_with($logPath, '/')) {
            $logPath = $this->basePath . '/' . $logPath;
        }
        return new MigrationRunner($database, new MigrationDiscovery($this->basePath . '/database/migrations'), (string) $this->config->get('version.application'), (int) $this->config->get('version.schema'), new Logger($logPath, (string) $this->config->get('logging.level', 'info')));
    }

    private function page(string $title, string $content, int $status = 200): Response
    {
        return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card"><p><strong>SEO Tracker</strong> updater</p><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status);
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">';
    }
}
