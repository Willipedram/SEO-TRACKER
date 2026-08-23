<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config\Environment;
use App\Core\Installer\DatabaseConfiguration;
use App\Core\Installer\EnvironmentWriter;
use Tests\TestCase;

final class EnvironmentWriterTest extends TestCase
{
    public function testCredentialsAreWrittenProtectedAndRoundTrip(): void
    {
        $path = sys_get_temp_dir() . '/seo-env-' . bin2hex(random_bytes(4));
        $password = 'complex"password\\value';
        (new EnvironmentWriter($path))->write(new DatabaseConfiguration('127.0.0.1', 3306, 'seo_tracker', 'seo_user', $password), 'https://tracker.example');
        $this->assertSame(0600, fileperms($path) & 0777);
        unset($_ENV['DB_PASSWORD']);
        putenv('DB_PASSWORD');
        Environment::load($path);
        $this->assertSame($password, $_ENV['DB_PASSWORD']);
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'APP_TIMEZONE', 'APP_TRUSTED_HOSTS', 'LOG_LEVEL', 'LOG_PATH', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'SESSION_SECURE', 'SESSION_SAME_SITE'] as $name) {
            unset($_ENV[$name]);
            putenv($name);
        }
        unlink($path);
    }
}
