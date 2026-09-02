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

    public function prepare(DatabaseConfiguration $database, string $applicationUrl, bool $replaceExisting = false, bool $preserveExisting = false): string
    {
        if (is_file($this->path) && !$replaceExisting) {
            throw new InstallerException('Configuration already exists. Refusing to overwrite it.');
        }
        $values = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $applicationUrl,
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'APP_TIMEZONE' => 'Asia/Tehran', 'APP_LOCALE' => 'fa', 'APP_TRUSTED_HOSTS' => (string) parse_url($applicationUrl, PHP_URL_HOST),
            'LOG_LEVEL' => 'info', 'LOG_PATH' => 'storage/logs/application.log',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database->host, 'DB_PORT' => (string) $database->port,
            'DB_DATABASE' => $database->database, 'DB_USERNAME' => $database->username, 'DB_PASSWORD' => $database->password,
            'DB_CHARSET' => 'utf8mb4', 'SESSION_SECURE' => str_starts_with($applicationUrl, 'https://') ? 'true' : 'false', 'SESSION_SAME_SITE' => 'Lax', 'SESSION_LIFETIME' => '43200',
            'GOOGLE_SEARCH_CONSOLE_REDIRECT_URI' => rtrim($applicationUrl, '/') . '/oauth/search-console/callback',
            'SEARCH_CONSOLE_ENCRYPTION_KEY' => base64_encode(random_bytes(32)), 'SEARCH_CONSOLE_ENCRYPTION_KEY_VERSION' => 'v1',
        ];
        $existing = $preserveExisting && is_file($this->path) ? file_get_contents($this->path) : null;
        if ($preserveExisting && !is_string($existing)) throw new InstallerException('Existing configuration could not be read safely.');
        $content = is_string($existing) ? $this->merge($existing, $values) : $this->serialize($values);
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new InstallerException('Could not write protected configuration. Check directory ownership and permissions.');
        }
        return $temporary;
    }

    private function serialize(array $values): string
    {
        $content=''; foreach($values as $key=>$value)$content.=$key.'='.$this->quote($value).PHP_EOL; return $content;
    }

    private function merge(string $existing, array $values): string
    {
        $replace = array_intersect_key($values, array_flip(['APP_URL','APP_TRUSTED_HOSTS','DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_CHARSET']));
        $seen=[]; $lines=preg_split('/\R/',$existing)?:[];
        foreach($lines as &$line){if(preg_match('/^([A-Z][A-Z0-9_]*)=/',trim($line),$match)!==1)continue;$key=$match[1];if(array_key_exists($key,$replace)){$line=$key.'='.$this->quote((string)$replace[$key]);$seen[$key]=true;}}
        unset($line);
        foreach($replace as $key=>$value)if(!isset($seen[$key]))$lines[]=$key.'='.$this->quote((string)$value);
        return rtrim(implode(PHP_EOL,$lines),"\r\n").PHP_EOL;
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
