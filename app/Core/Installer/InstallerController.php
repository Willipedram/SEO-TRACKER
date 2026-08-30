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
            'database-detected' => isset($_SESSION['installer_database_state']) ? $this->databaseDetected() : Response::redirect('/install?step=database'),
            'admin' => ($_SESSION['installer_database_state'] ?? null) === DatabaseInspector::EMPTY && isset($_SESSION['installer_database']) ? $this->adminForm() : Response::redirect('/install?step=database'),
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
            'database_choice' => $this->databaseChoice($request),
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
            $_SESSION['installer_database_state'] = $state;
            if ($state !== DatabaseInspector::UNKNOWN) $_SESSION['installer_database'] = $configuration->sessionValue();
            else unset($_SESSION['installer_database']);
            $_SESSION['installer_url'] = $request->scheme . '://' . $request->host() . $request->baseUrl;
            return Response::redirect('/install?step=database-detected');
        } catch (Throwable $exception) {
            unset($_SESSION['installer_database']);
            unset($_SESSION['installer_database_state']);
            $message = $exception instanceof InstallerException || $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Database validation failed.';
            return $this->databaseForm($message, 422);
        }
    }

    private function databaseChoice(Request $request): Response
    {
        $state = $_SESSION['installer_database_state'] ?? null;
        $choice = (string)($request->body['choice'] ?? '');
        if ($choice === 'clean' && $state === DatabaseInspector::EMPTY) return Response::redirect('/install?step=admin');
        if ($choice === 'different') {
            unset($_SESSION['installer_database'], $_SESSION['installer_database_state']);
            return Response::redirect('/install?step=database');
        }
        if ($choice === 'update' && $state === DatabaseInspector::APPLICATION) {
            $stored = $_SESSION['installer_database'] ?? null;
            if (!is_array($stored)) return Response::redirect('/install?step=database');
            $temporary = null; $writer = new EnvironmentWriter($this->basePath . '/.env');
            try {
                $temporary = $writer->prepare(DatabaseConfiguration::fromArray($stored), (string)($_SESSION['installer_url'] ?? $this->config->get('app.url','http://localhost')), true, true);
                $writer->commit($temporary); $temporary = null;
                unset($_SESSION['installer_database'], $_SESSION['installer_database_state'], $_SESSION['installer_url']);
                return Response::redirect('/update');
            } catch (Throwable $exception) {
                $writer->discard($temporary);
                return $this->databaseDetected($exception instanceof InstallerException ? $exception->getMessage() : 'Could not prepare the existing installation for update.', 422);
            }
        }
        return $this->databaseDetected('Select one of the available database actions.', 422);
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
            $temporaryEnvironment = $writer->prepare($database, (string) ($_SESSION['installer_url'] ?? $this->config->get('app.url', 'http://localhost')), true);
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
            unset($_SESSION['installer_database_state']);
            unset($_SESSION['installer_url']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            return $this->page('Installation complete', '<p class="success">SEO Tracker is installed and the administrator account is ready.</p><p><a class="button" href="/">Open SEO Tracker</a></p>', 200, 4);
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
        return $this->page('Environment check', '<p>Step 1 of 4 — Confirm this server can run SEO Tracker safely.</p><ul class="checks installer-checks">' . $rows . '</ul>' . $next, $this->environment()->passes() ? 200 : 503, 1);
    }

    private function databaseForm(?string $error = null, int $status = 200): Response
    {
        $message = $error === null ? '' : '<p class="error">' . Html::escape($error) . '</p>';
        return $this->page('Database configuration', '<p>Step 2 of 4 — Enter your MySQL/MariaDB connection details. Credentials are never logged.</p>' . $message . '<div class="installer-note"><strong>Database details</strong><p>Like the WordPress installer, SEO Tracker will test the connection before changing anything.</p></div><form class="installer-database-form" method="post" action="/install">' . $this->csrf() . '<input type="hidden" name="action" value="database"><div class="installer-fields"><label>Database name<input required name="database" autocomplete="off" placeholder="seo_tracker"></label><label>Database username<input required name="username" autocomplete="username" placeholder="database_user"></label><label>Database password<input name="password" type="password" autocomplete="new-password"></label><label>Database host<input required name="host" value="localhost" autocomplete="off"><small>Usually localhost.</small></label><label>Port<input required name="port" type="number" min="1" max="65535" value="3306"></label></div><button type="submit">Test database and continue</button></form>', $status, 2);
    }

    private function databaseDetected(?string $error = null, int $status = 200): Response
    {
        $state = $_SESSION['installer_database_state'] ?? DatabaseInspector::UNKNOWN;
        $message = $error === null ? '' : '<p class="error">'.Html::escape($error).'</p>';
        if ($state === DatabaseInspector::EMPTY) {
            $content='<div class="database-state state-empty"><span class="state-icon">✓</span><div><h2>Empty database detected</h2><p>The database is ready. Choose the installation mode.</p></div></div><div class="installer-choice-grid"><section class="installer-choice recommended"><span class="badge">Recommended</span><h3>Clean installation</h3><p>Create a new SEO Tracker system in this empty database. No existing table will be deleted.</p><form method="post" action="/install">'.$this->csrf().'<input type="hidden" name="action" value="database_choice"><button name="choice" value="clean">Continue with clean installation</button></form></section><section class="installer-choice unavailable-choice"><h3>Update existing system</h3><p>No SEO Tracker installation was found in this database, so update is not available.</p><button type="button" disabled>Update not available</button></section></div>';
        } elseif ($state === DatabaseInspector::APPLICATION) {
            $content='<div class="database-state state-existing"><span class="state-icon">↻</span><div><h2>Existing SEO Tracker installation detected</h2><p>Users, websites, keywords, credentials and reports were found. Choose how to continue.</p></div></div><div class="installer-choice-grid"><section class="installer-choice recommended"><span class="badge">Recommended</span><h3>Update existing system</h3><p>Keep all current data and continue to the safe migration screen.</p><form method="post" action="/install">'.$this->csrf().'<input type="hidden" name="action" value="database_choice"><button name="choice" value="update">Continue to update</button></form></section><section class="installer-choice"><h3>Clean installation</h3><p>For safety, SEO Tracker never deletes this database. Select a different empty database for a clean install.</p><form method="post" action="/install">'.$this->csrf().'<input type="hidden" name="action" value="database_choice"><button class="button-secondary" name="choice" value="different">Choose an empty database</button></form></section></div>';
        } else {
            $content='<div class="database-state state-unknown"><span class="state-icon">!</span><div><h2>Unknown database data detected</h2><p>This is not a recognized SEO Tracker installation. No table was changed or deleted.</p></div></div><form method="post" action="/install">'.$this->csrf().'<input type="hidden" name="action" value="database_choice"><button name="choice" value="different">Choose an empty database</button></form>';
        }
        return $this->page('Database detection', '<p>Step 3 of 4 — Review the detected database state before continuing.</p>'.$message.$content, $status, 3);
    }

    private function adminForm(?string $error = null, int $status = 200): Response
    {
        $message = $error === null ? '' : '<p class="error">' . Html::escape($error) . '</p>';
        return $this->page('Create administrator', '<p>Step 4 of 4 — Create the first administrator and application settings.</p><p class="success"><strong>Clean install:</strong> an empty database was confirmed. No existing tables will be deleted.</p>' . $message . '<form method="post" action="/install">' . $this->csrf() . '<input type="hidden" name="action" value="install"><label>Application name<input required name="site_name" maxlength="120" value="SEO Tracker"></label><label>Administrator name<input required name="name" maxlength="100" autocomplete="name"></label><label>Email<input required name="email" type="email" maxlength="254" autocomplete="email"></label><label>Password<input required name="password" type="password" minlength="12" autocomplete="new-password"><small>At least 12 characters.</small></label><button type="submit">Install SEO Tracker</button></form>', $status, 4);
    }

    private function page(string $title, string $content, int $status = 200, int $step = 1): Response
    {
        $labels=['Environment','Database','Detection','Administrator']; $progress='<ol class="installer-steps">'; foreach($labels as $index=>$label){$number=$index+1;$class=$number<$step?'complete':($number===$step?'active':'');$progress.='<li class="'.$class.'"><span>'.$number.'</span><small>'.$label.'</small></li>';}$progress.='</ol>';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card installer-shell"><header class="installer-brand"><span class="installer-logo">S</span><div><strong>SEO Tracker</strong><small>Installation Wizard</small></div></header>'.$progress.'<section class="installer-panel"><h1>' . Html::escape($title) . '</h1>' . $content . '</section></main></body></html>';
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
        try {
            $row = (new ConnectionFactory($this->config))->connect()->fetchOne('SELECT application_id FROM app_installations WHERE application_id = :id', ['id' => SchemaInstaller::APPLICATION_ID]);
            return ($row['application_id'] ?? null) === SchemaInstaller::APPLICATION_ID;
        } catch (Throwable) {
            // A deployment archive or incomplete extraction may leave a stale
            // lock behind. It is meaningful only together with persisted
            // environment configuration; otherwise first-run setup must open.
            return is_file($this->basePath . '/storage/installed.lock') && is_file($this->basePath . '/.env');
        }
    }
}
