<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Logging\Logger;

final class AuthFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function make(?SessionStore $session = null): Authenticator
    {
        $database = (new ConnectionFactory($this->config))->connect();
        $logPath = (string) $this->config->get('logging.path', 'storage/logs/application.log');
        if (!str_starts_with($logPath, '/')) {
            $logPath = $this->basePath . '/' . $logPath;
        }
        $key = (string) $this->config->get('app.key');
        return new Authenticator(
            $database,
            new PasswordHasher(),
            $session ?? new NativeSessionStore(),
            new LoginRateLimiter($database, $key, (int) $this->config->get('auth.max_attempts', 5), (int) $this->config->get('auth.attempt_window', 900)),
            new Logger($logPath, (string) $this->config->get('logging.level', 'info')),
            (int) $this->config->get('auth.idle_timeout', 1800),
            (int) $this->config->get('auth.absolute_timeout', 43200),
            $key,
        );
    }
}
