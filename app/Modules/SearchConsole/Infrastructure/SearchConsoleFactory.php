<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Infrastructure;

use App\Core\Auth\AuthFactory;
use App\Core\Auth\NativeSessionStore;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\SearchConsole\Application\SearchConsoleManager;
use App\Modules\SearchConsole\Application\OAuthStateStore;
use App\Modules\SearchConsole\Application\SearchConsoleConnectionService;
use App\Modules\SearchConsole\Application\SearchConsoleSyncManager;
use App\Modules\SearchConsole\Application\SearchConsoleSyncWorker;
use App\Modules\SearchConsole\Application\SearchConsoleDashboardService;
use App\Modules\SearchConsole\Application\CombinedAnalyticsService;
use App\Core\Logging\Logger;
use App\Core\Localization\Translator;
use App\Core\Settings\SettingsManager;

final class SearchConsoleFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function services(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        return [
            new SearchConsoleManager($database, new Authorization($database), new AuditRecorder($database), (array) $this->config->get('search_console', [])),
            (new AuthFactory($this->basePath, $this->config))->make(),
        ];
    }

    public function connectionServices(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        $oauth = (array) $this->config->get('search_console', []);
        $authorization = new Authorization($database);
        return [
            new SearchConsoleConnectionService(
                $database, $authorization, new AuditRecorder($database), new OAuthStateStore(new NativeSessionStore()),
                new GoogleSearchConsoleGateway((string) ($oauth['client_id'] ?? ''), (string) ($oauth['client_secret'] ?? ''), (string) ($oauth['redirect_uri'] ?? ''), (array) ($oauth['scopes'] ?? [])),
                new OpenSslTokenVault((string) ($oauth['encryption_key'] ?? ''), (string) ($oauth['encryption_key_version'] ?? 'v1')),
            ),
            (new AuthFactory($this->basePath, $this->config))->make(),
        ];
    }

    public function syncServices(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        return [
            new SearchConsoleSyncManager($database, $authorization = new Authorization($database), $audit = new AuditRecorder($database), (int) $this->config->get('search_console_sync.max_range_days', 31), static fn (string $key): bool => (new SettingsManager($database, $authorization, $audit))->featureEnabled($key)),
            (new AuthFactory($this->basePath, $this->config))->make(),
        ];
    }

    public function syncWorker(): SearchConsoleSyncWorker
    {
        $database = (new ConnectionFactory($this->config))->connect(); $oauth = (array) $this->config->get('search_console', []);
        $gateway = new GoogleSearchConsoleGateway((string) ($oauth['client_id'] ?? ''), (string) ($oauth['client_secret'] ?? ''), (string) ($oauth['redirect_uri'] ?? ''), (array) ($oauth['scopes'] ?? []));
        $authorization = new Authorization($database); $audit = new AuditRecorder($database);
        $connections = new SearchConsoleConnectionService($database, $authorization, $audit, new OAuthStateStore(new NativeSessionStore()), $gateway, new OpenSslTokenVault((string) ($oauth['encryption_key'] ?? ''), (string) ($oauth['encryption_key_version'] ?? 'v1')));
        $logPath = (string) $this->config->get('search_console_sync.log_path', 'storage/logs/search-console.log'); if (!str_starts_with($logPath, '/')) $logPath = $this->basePath . '/' . $logPath;
        return new SearchConsoleSyncWorker($database, $connections, $gateway, new Logger($logPath, (string) $this->config->get('logging.level', 'info')), (int) $this->config->get('search_console_sync.max_attempts', 3), (int) $this->config->get('search_console_sync.lease_seconds', 300), (int) $this->config->get('search_console_sync.page_size', 25000), (int) $this->config->get('search_console_sync.max_rows', 250000));
    }

    public function dashboard(): SearchConsoleDashboardService
    {
        $database = (new ConnectionFactory($this->config))->connect();
        return new SearchConsoleDashboardService($database, new Authorization($database));
    }

    public function combinedAnalytics(): CombinedAnalyticsService
    {
        $database = (new ConnectionFactory($this->config))->connect();
        return new CombinedAnalyticsService($database, new Authorization($database));
    }

    public function translator(): Translator
    {
        return new Translator((string) $this->config->get('app.locale', 'en'), $this->basePath, 'search_console');
    }

    public function isRtl(): bool
    {
        $locale = (string) $this->config->get('app.locale', 'en');
        return in_array(explode('-', $locale, 2)[0], (array) $this->config->get('app.rtl_locales', []), true);
    }
}
