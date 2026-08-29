<?php

declare(strict_types=1);

namespace App\Core\Installer;

final class EnvironmentWriter
{
    public function __construct(private readonly string $path) {}

    public function write(DatabaseConfiguration $database, string $applicationUrl): void
    {
        $this->commit($this->prepare($database, $applicationUrl));
    }

    public function prepare(DatabaseConfiguration $database, string $applicationUrl): string
    {
        if (is_file($this->path)) {
            throw new InstallerException('Configuration already exists. Refusing to overwrite it.');
        }
        $values = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $applicationUrl,
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'APP_TIMEZONE' => 'UTC', 'APP_LOCALE' => 'fa', 'APP_TRUSTED_HOSTS' => (string) parse_url($applicationUrl, PHP_URL_HOST),
            'LOG_LEVEL' => 'info', 'LOG_PATH' => 'storage/logs/application.log',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database->host, 'DB_PORT' => (string) $database->port,
            'DB_DATABASE' => $database->database, 'DB_USERNAME' => $database->username, 'DB_PASSWORD' => $database->password,
            'DB_CHARSET' => 'utf8mb4', 'SESSION_SECURE' => str_starts_with($applicationUrl, 'https://') ? 'true' : 'false', 'SESSION_SAME_SITE' => 'Lax', 'SESSION_LIFETIME' => '43200',
        ];
        $content = '';
        foreach ($values as $key => $value) {
            $content .= $key . '=' . $this->quote($value) . PHP_EOL;
        }
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new InstallerException('Could not write protected configuration. Check directory ownership and permissions.');
        }
        return $temporary;
    }

    public function commit(string $temporary): void
    {
        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new InstallerException('Could not activate protected configuration. Check directory ownership and permissions.');
        }
    }

    public function discard(?string $temporary): void
    {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value) . '"';
    }
}
