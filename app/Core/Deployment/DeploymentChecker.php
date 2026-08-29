<?php

declare(strict_types=1);

namespace App\Core\Deployment;

use App\Core\Config\Config;
use Closure;

final class DeploymentChecker
{
    private readonly Closure $extensionLoaded;

    public function __construct(private readonly string $basePath, private readonly Config $config, ?Closure $extensionLoaded = null)
    {
        $this->extensionLoaded = $extensionLoaded ?? static fn (string $extension): bool => extension_loaded($extension);
    }

    /** @return list<array{key:string,pass:bool,severity:string,message:string}> */
    public function check(): array
    {
        $checks = [];
        $checks[] = $this->result('php_version', version_compare(PHP_VERSION, '8.1.0', '>='), 'error', 'PHP 8.1 or newer is required.');
        foreach (['json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session'] as $extension) {
            $checks[] = $this->result('extension_' . $extension, ($this->extensionLoaded)($extension), 'error', 'Required PHP extension: ' . $extension);
        }
        foreach (['storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $directory) {
            $path = $this->basePath . '/' . $directory;
            $checks[] = $this->result('writable_' . str_replace('/', '_', $directory), is_dir($path) && is_writable($path), 'error', 'Runtime directory must exist and be writable: ' . $directory);
        }
        foreach (['public/index.php', 'public/.htaccess', '.htaccess'] as $file) {
            $checks[] = $this->result('present_' . str_replace(['/', '.'], '_', $file), is_file($this->basePath . '/' . $file), 'error', 'Deployment protection file must exist: ' . $file);
        }

        $production = strtolower((string) $this->config->get('app.env', 'production')) === 'production';
        $url = (string) $this->config->get('app.url', '');
        $checks[] = $this->result('production_environment', $production, 'error', 'APP_ENV must be production.');
        $checks[] = $this->result('debug_disabled', !(bool) $this->config->get('app.debug', false), 'error', 'APP_DEBUG must be false.');
        $checks[] = $this->result('https_application_url', $this->validHttpsUrl($url), 'error', 'APP_URL must be a public HTTPS URL without credentials.');
        $checks[] = $this->result('trusted_hosts', $this->trustedHostMatchesUrl($url), 'error', 'APP_TRUSTED_HOSTS must include the APP_URL host.');
        $checks[] = $this->result('application_key', strlen((string) $this->config->get('app.key', '')) >= 32, 'error', 'APP_KEY must contain at least 32 unpredictable characters.');
        $checks[] = $this->result('secure_session_cookie', (bool) $this->config->get('session.secure', false), 'error', 'SESSION_SECURE must be true for production HTTPS.');

        $checks[] = $this->result('mysql_connection', $this->config->get('database.default') === 'mysql', 'error', 'DB_CONNECTION must be mysql for DirectAdmin production.');
        $mysql = (array) $this->config->get('database.connections.mysql', []);
        foreach (['host', 'database', 'username', 'password'] as $key) {
            $checks[] = $this->result('mysql_' . $key, trim((string) ($mysql[$key] ?? '')) !== '', 'error', 'MySQL ' . $key . ' must be configured.');
        }
        $checks[] = $this->result('mysql_charset', strtolower((string) ($mysql['charset'] ?? '')) === 'utf8mb4', 'error', 'DB_CHARSET must be utf8mb4.');

        $search = (array) $this->config->get('search_console', []);
        $oauthConfigured = trim((string) ($search['client_id'] ?? '')) !== '' || trim((string) ($search['client_secret'] ?? '')) !== '' || trim((string) ($search['redirect_uri'] ?? '')) !== '' || trim((string) ($search['encryption_key'] ?? '')) !== '';
        if ($oauthConfigured) {
            $key = base64_decode((string) ($search['encryption_key'] ?? ''), true);
            $checks[] = $this->result('oauth_client_id', trim((string) ($search['client_id'] ?? '')) !== '', 'error', 'Search Console client ID is required when OAuth is configured.');
            $checks[] = $this->result('oauth_client_secret', trim((string) ($search['client_secret'] ?? '')) !== '', 'error', 'Search Console client secret is required when OAuth is configured.');
            $checks[] = $this->result('oauth_redirect_uri', $this->validHttpsUrl((string) ($search['redirect_uri'] ?? '')), 'error', 'Search Console redirect URI must be HTTPS.');
            $checks[] = $this->result('oauth_encryption_key', is_string($key) && strlen($key) === 32, 'error', 'Search Console encryption key must decode to exactly 32 bytes.');
            $checks[] = $this->result('oauth_https_streams', filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'error', 'allow_url_fopen must be enabled for Search Console HTTPS requests.');
        }

        $checks[] = $this->result('memory_limit', $this->memoryLimitBytes((string) ini_get('memory_limit')) >= 128 * 1024 * 1024 || ini_get('memory_limit') === '-1', 'warning', 'A PHP memory_limit of at least 128M is recommended.');
        return $checks;
    }

    public function passes(): bool
    {
        foreach ($this->check() as $check) {
            if (!$check['pass'] && $check['severity'] === 'error') return false;
        }
        return true;
    }

    private function validHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    private function trustedHostMatchesUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && in_array(strtolower($host), array_map('strtolower', (array) $this->config->get('app.trusted_hosts', [])), true);
    }

    private function memoryLimitBytes(string $value): int
    {
        if ($value === '-1') return PHP_INT_MAX;
        if (!preg_match('/^(\d+)([KMG]?)$/i', trim($value), $matches)) return 0;
        $multiplier = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper($matches[2])] ?? 1;
        return (int) $matches[1] * $multiplier;
    }

    private function result(string $key, bool $pass, string $severity, string $message): array
    {
        return compact('key', 'pass', 'severity', 'message');
    }
}
