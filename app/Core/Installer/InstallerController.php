<?php

declare(strict_types=1);

namespace App\Core\Installer;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use App\Core\Database\Database;
use App\Core\Logging\Logger;
use App\Core\Update\MigrationDiscovery;
use App\Core\Update\MigrationRunner;
use Throwable;

final class InstallerController
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function show(Request $request): Response
    {
        if ($this->installed()) {
            return Response::json(['error' => 'Not found.'], 404);
        }
        $step = (string) ($request->query['step'] ?? 'environment');
        return match ($step) {
            'database' => $this->environment()->passes() ? $this->databaseForm() : Response::redirect('/install'),
            'admin' => isset($_SESSION['installer_database']) ? $this->adminForm() : Response::redirect('/install?step=database'),
            default => $this->environmentPage(),
        };
    }

    public function submit(Request $request): Response
    {
        if ($this->installed()) {
            return Response::json(['error' => 'Not found.'], 404);
        }
        return match ((string) ($request->body['action'] ?? '')) {
            'database' => $this->database($request),
            'install' => $this->install($request->body),
            default => $this->page('Invalid request', '<p>The installer action was not recognized.</p>', 422),
        };
    }

    public function home(): Response
    {
        return $this->installed()
            ? Response::json(['application' => 'SEO Tracker', 'status' => 'ready'])
            : Response::redirect('/install', 302);
    }

    private function database(Request $request): Response
    {
        if (!$this->environment()->passes()) {
            return Response::redirect('/install');
        }
        try {
            $configuration = DatabaseConfiguration::fromArray($request->body);
            $state = (new DatabaseInspector())->inspect($configuration->connect());
            if ($state === DatabaseInspector::UNKNOWN) {
                unset($_SESSION['installer_database']);
                return $this->page('Database contains unrelated data', '<p class="error">No tables were changed. Select an empty database created for SEO Tracker. The installer never cleans an unknown database.</p><p><a href="/install?step=database">Choose another database</a></p>', 409);
            }
            if ($state === DatabaseInspector::APPLICATION) {
                unset($_SESSION['installer_database']);
                return $this->page('Existing installation detected', '<p>This database belongs to SEO Tracker. This is an <strong>existing installation</strong>, not a clean install.</p><p>The upgrade workflow must be used; the installer will not overwrite or delete its users, websites, credentials, or reports.</p>', 409);
            }
            $_SESSION['installer_database'] = $configuration->sessionValue();
            $_SESSION['installer_url'] = $request->scheme . '://' . $request->host();
            return Response::redirect('/install?step=admin');
        } catch (Throwable $exception) {
            unset($_SESSION['installer_database']);
            $message = $exception instanceof InstallerException || $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Database validation failed.';
            return $this->databaseForm($message, 422);
        }
    }

    private function install(array $input): Response
    {
        $temporaryEnvironment = null;
        $writer = new EnvironmentWriter($this->basePath . '/.env');
        try {
            $stored = $_SESSION['installer_database'] ?? null;
            if (!is_array($stored)) {
                return Response::redirect('/install?step=database');
            }
            $database = DatabaseConfiguration::fromArray($stored);
            $temporaryEnvironment = $writer->prepare($database, (string) ($_SESSION['installer_url'] ?? $this->config->get('app.url', 'http://localhost')));
            $pdo = $database->connect();
            (new SchemaInstaller())->install($pdo, trim((string) ($input['name'] ?? '')), trim((string) ($input['email'] ?? '')), (string) ($input['password'] ?? ''), trim((string) ($input['site_name'] ?? '')));
            $logPath = (string) $this->config->get('logging.path', 'storage/logs/application.log');
            if (!str_starts_with($logPath, '/')) {
                $logPath = $this->basePath . '/' . $logPath;
            }
            (new MigrationRunner(new Database($pdo), new MigrationDiscovery($this->basePath . '/database/migrations'), (string) $this->config->get('version.application'), (int) $this->config->get('version.schema'), new Logger($logPath, (string) $this->config->get('logging.level', 'info'))))->run();
            $writer->commit($temporaryEnvironment);
            $temporaryEnvironment = null;
            $lock = $this->basePath . '/storage/installed.lock';
            if (@file_put_contents($lock, SchemaInstaller::APPLICATION_ID . PHP_EOL, LOCK_EX) !== false) {
                @chmod($lock, 0600);
            }
            unset($_SESSION['installer_database']);
            unset($_SESSION['installer_url']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            return $this->page('Installation complete', '<p class="success">SEO Tracker is installed and the administrator account is ready.</p><p><a class="button" href="/">Open SEO Tracker</a></p>');
        } catch (Throwable $exception) {
            $writer->discard($temporaryEnvironment);
            $message = $exception instanceof InstallerException ? $exception->getMessage() : 'Installation failed safely. Review the protected application log and retry.';
            return $this->adminForm($message, 422);
        }
    }

    private function environmentPage(): Response
    {
        $checks = $this->environment()->check();
        $rows = '';
        foreach ($checks as $check) {
            $rows .= sprintf('<li class="%s"><strong>%s</strong><span>%s</span></li>', $check['pass'] ? 'pass' : 'fail', Html::escape($check['label']), $check['pass'] ? 'Ready' : Html::escape($check['help']));
        }
        $next = $this->environment()->passes() ? '<a class="button" href="/install?step=database">Continue to database</a>' : '<p class="error">Resolve every failed requirement, then reload this page.</p>';
        return $this->page('Environment check', '<p>Step 1 of 3 — Confirm this server can run SEO Tracker safely.</p><ul class="checks">' . $rows . '</ul>' . $next, $this->environment()->passes() ? 200 : 503);
    }

    private function databaseForm(?string $error = null, int $status = 200): Response
    {
        $message = $error === null ? '' : '<p class="error">' . Html::escape($error) . '</p>';
        return $this->page('Database configuration', '<p>Step 2 of 3 — Use an empty MySQL/MariaDB database. Credentials are never logged.</p>' . $message . '<form method="post" action="/install">' . $this->csrf() . '<input type="hidden" name="action" value="database"><label>Host<input required name="host" value="127.0.0.1" autocomplete="off"></label><label>Port<input required name="port" type="number" min="1" max="65535" value="3306"></label><label>Database name<input required name="database" autocomplete="off"></label><label>Database username<input required name="username" autocomplete="username"></label><label>Database password<input name="password" type="password" autocomplete="new-password"></label><button type="submit">Test database and continue</button></form>', $status);
    }

    private function adminForm(?string $error = null, int $status = 200): Response
    {
        $message = $error === null ? '' : '<p class="error">' . Html::escape($error) . '</p>';
        return $this->page('Create administrator', '<p>Step 3 of 3 — Create the first administrator and application settings.</p><p class="success"><strong>Clean install:</strong> an empty database was confirmed. No existing tables will be deleted.</p>' . $message . '<form method="post" action="/install">' . $this->csrf() . '<input type="hidden" name="action" value="install"><label>Application name<input required name="site_name" maxlength="120" value="SEO Tracker"></label><label>Administrator name<input required name="name" maxlength="100" autocomplete="name"></label><label>Email<input required name="email" type="email" maxlength="254" autocomplete="email"></label><label>Password<input required name="password" type="password" minlength="12" autocomplete="new-password"><small>At least 12 characters.</small></label><button type="submit">Install SEO Tracker</button></form>', $status);
    }

    private function page(string $title, string $content, int $status = 200): Response
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card"><p><strong>SEO Tracker</strong> installer</p><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>';
        return Response::html($html, $status);
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">';
    }

    private function environment(): EnvironmentChecker
    {
        return new EnvironmentChecker($this->basePath);
    }

    private function installed(): bool
    {
        if (is_file($this->basePath . '/storage/installed.lock')) {
            return true;
        }
        try {
            $row = (new ConnectionFactory($this->config))->connect()->fetchOne('SELECT application_id FROM app_installations WHERE application_id = :id', ['id' => SchemaInstaller::APPLICATION_ID]);
            return ($row['application_id'] ?? null) === SchemaInstaller::APPLICATION_ID;
        } catch (Throwable) {
            return false;
        }
    }
}
