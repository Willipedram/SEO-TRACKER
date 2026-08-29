<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config\Config;
use App\Core\Deployment\DeploymentChecker;
use Tests\TestCase;

final class DeploymentCheckerTest extends TestCase
{
    public function testValidDirectAdminProductionConfigurationPassesWithoutExposingSecrets(): void
    {
        $checker = new DeploymentChecker(dirname(__DIR__, 2), $this->config(), static fn (): bool => true);
        $checks = $checker->check();
        $this->assertTrue($checker->passes());
        $encoded = json_encode($checks, JSON_THROW_ON_ERROR);
        $this->assertTrue(!str_contains($encoded, 'database-secret-value'));
        $this->assertTrue(!str_contains($encoded, 'oauth-secret-value'));
        $this->assertTrue(!str_contains($encoded, 'application-key-material'));
    }

    public function testUnsafeProductionAndDatabaseConfigurationFailsClosed(): void
    {
        $config = $this->config([
            'app' => ['env' => 'local', 'debug' => true, 'url' => 'http://example.test', 'key' => '', 'trusted_hosts' => ['wrong.example.test']],
            'session' => ['secure' => false],
            'database' => ['default' => 'sqlite', 'connections' => ['mysql' => ['host' => '', 'database' => '', 'username' => '', 'password' => '', 'charset' => 'latin1']]],
        ]);
        $checker = new DeploymentChecker(dirname(__DIR__, 2), $config, static fn (): bool => true);
        $this->assertTrue(!$checker->passes());
        $failed = array_column(array_filter($checker->check(), static fn (array $check): bool => !$check['pass']), 'key');
        foreach (['production_environment', 'debug_disabled', 'https_application_url', 'trusted_hosts', 'application_key', 'secure_session_cookie', 'mysql_connection', 'mysql_charset'] as $key) {
            $this->assertTrue(in_array($key, $failed, true));
        }
    }

    public function testPartiallyConfiguredOAuthRequiresCompleteSecureSecrets(): void
    {
        $checker = new DeploymentChecker(dirname(__DIR__, 2), $this->config([
            'search_console' => ['client_id' => 'configured-id', 'client_secret' => '', 'redirect_uri' => 'http://example.test/callback', 'encryption_key' => 'invalid'],
        ]), static fn (): bool => true);
        $this->assertTrue(!$checker->passes());
        $failed = array_column(array_filter($checker->check(), static fn (array $check): bool => !$check['pass']), 'key');
        $this->assertTrue(in_array('oauth_client_secret', $failed, true));
        $this->assertTrue(in_array('oauth_redirect_uri', $failed, true));
        $this->assertTrue(in_array('oauth_encryption_key', $failed, true));
    }

    public function testCompatibilityHtaccessDeniesSensitivePathsAndArchives(): void
    {
        $rules = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
        foreach (['app|bin|bootstrap|config|database|docs|lang|routes|storage|tests|vendor', 'RedirectMatch 404', 'composer', 'zip|tar|tgz|gz', 'Options -Indexes'] as $required) {
            $this->assertTrue(str_contains($rules, $required));
        }
    }

    public function testMissingRequiredExtensionIsADeploymentFailure(): void
    {
        $checker = new DeploymentChecker(dirname(__DIR__, 2), $this->config(), static fn (string $extension): bool => $extension !== 'openssl');
        $this->assertTrue(!$checker->passes());
        $failed = array_column(array_filter($checker->check(), static fn (array $check): bool => !$check['pass']), 'key');
        $this->assertTrue(in_array('extension_openssl', $failed, true));
    }

    private function config(array $overrides = []): Config
    {
        $base = [
            'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://example.test', 'key' => 'application-key-material-1234567890', 'trusted_hosts' => ['example.test']],
            'session' => ['secure' => true],
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => ['host' => 'localhost', 'database' => 'account_tracker', 'username' => 'account_tracker', 'password' => 'database-secret-value', 'charset' => 'utf8mb4']]],
            'search_console' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'encryption_key' => ''],
        ];
        return new Config(array_replace_recursive($base, $overrides));
    }
}
