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
        $this->assertSame('Asia/Tehran', $_ENV['APP_TIMEZONE']);
        $this->assertSame('https://tracker.example/oauth/search-console/callback', $_ENV['GOOGLE_SEARCH_CONSOLE_REDIRECT_URI']);
        $this->assertSame(32, strlen((string)base64_decode($_ENV['SEARCH_CONSOLE_ENCRYPTION_KEY'], true)));
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'APP_TIMEZONE', 'APP_TRUSTED_HOSTS', 'LOG_LEVEL', 'LOG_PATH', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'SESSION_SECURE', 'SESSION_SAME_SITE', 'SESSION_LIFETIME', 'GOOGLE_SEARCH_CONSOLE_REDIRECT_URI', 'SEARCH_CONSOLE_ENCRYPTION_KEY', 'SEARCH_CONSOLE_ENCRYPTION_KEY_VERSION'] as $name) {
            unset($_ENV[$name]);
            putenv($name);
        }
        unlink($path);
    }

    public function testExplicitUpdateReplacesDatabaseValuesButPreservesApplicationSecrets(): void
    {
        $path=sys_get_temp_dir().'/seo-env-replace-'.bin2hex(random_bytes(4));
        file_put_contents($path,"APP_KEY=\"base64:keep-this-key\"\nCUSTOM_SETTING=\"keep-me\"\nDB_HOST=\"old-host\"\nDB_DATABASE=\"old_database\"\n"); chmod($path,0600);
        $writer=new EnvironmentWriter($path);
        $temporary=$writer->prepare(new DatabaseConfiguration('localhost',3306,'new_database','new_user','new_password'),'https://tracker.example',true,true);
        $writer->commit($temporary); $content=(string)file_get_contents($path);
        foreach(['APP_KEY="base64:keep-this-key"','CUSTOM_SETTING="keep-me"','DB_HOST="localhost"','DB_DATABASE="new_database"','DB_USERNAME="new_user"'] as $required)$this->assertTrue(str_contains($content,$required));
        $this->assertTrue(!str_contains($content,'old_database')); $this->assertSame(0600,fileperms($path)&0777); unlink($path);
    }

    public function testExplicitCleanInstallMayReplaceStaleConfiguration(): void
    {
        $path=sys_get_temp_dir().'/seo-env-clean-'.bin2hex(random_bytes(4)); file_put_contents($path,"APP_KEY=\"stale-key\"\n"); chmod($path,0600);
        $writer=new EnvironmentWriter($path); $temporary=$writer->prepare(new DatabaseConfiguration('localhost',3306,'clean_database','clean_user','clean_password'),'https://tracker.example',true); $writer->commit($temporary);
        $content=(string)file_get_contents($path); $this->assertTrue(str_contains($content,'DB_DATABASE="clean_database"')); $this->assertTrue(!str_contains($content,'APP_KEY="stale-key"')); unlink($path);
    }
}
