<?php

declare(strict_types=1);

namespace App\Core\Installer;

final class EnvironmentChecker
{
    public function __construct(private readonly string $basePath) {}

    public function check(): array
    {
        $checks = [
            $this->result('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), 'Ask your host to select PHP 8.2 or newer for both the domain and CLI.'),
        ];
        foreach (['json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session'] as $extension) {
            $checks[] = $this->result('PHP extension: ' . $extension, extension_loaded($extension), 'Enable the ' . $extension . ' PHP extension in DirectAdmin or ask your host.');
        }
        foreach (['storage/logs', 'storage/framework/sessions', 'bootstrap/cache'] as $directory) {
            $path = $this->basePath . '/' . $directory;
            $checks[] = $this->result('Writable: ' . $directory, is_dir($path) && is_writable($path), 'Make this directory writable by the PHP user (normally 0750 or 0770; never 0777).');
        }
        $checks[] = $this->result('Configuration file location', is_writable($this->basePath), 'Allow the PHP user to create .env during installation, then restrict it to 0600 or 0640.');
        return $checks;
    }

    public function passes(): bool
    {
        return array_reduce($this->check(), static fn (bool $pass, array $check): bool => $pass && $check['pass'], true);
    }

    private function result(string $label, bool $pass, string $help): array
    {
        return compact('label', 'pass', 'help');
    }
}
