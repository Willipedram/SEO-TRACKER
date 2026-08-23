<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Error\ErrorHandler;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Logging\Logger;
use App\Core\Modules\ModuleLoader;
use App\Core\Security\SecurityHeaders;
use App\Core\Security\Csrf;
use RuntimeException;
use Throwable;

final class Application
{
    private function __construct(
        private readonly string $basePath,
        private readonly Config $config,
        private readonly Router $router,
        private readonly Logger $logger,
        private readonly ModuleLoader $modules,
        private readonly ErrorHandler $errors,
        private readonly ConnectionFactory $database,
    ) {}

    public static function build(string $basePath, Config $config): self
    {
        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
        $logPath = (string) $config->get('logging.path', 'storage/logs/application.log');
        if (!str_starts_with($logPath, '/')) {
            $logPath = $basePath . '/' . $logPath;
        }
        $logger = new Logger($logPath, strtolower((string) $config->get('logging.level', 'info')));
        $router = new Router();
        $modules = new ModuleLoader((array) $config->get('modules.paths', []), (array) $config->get('modules.enabled', []));
        $modules->load($router);
        require $basePath . '/routes/web.php';
        return new self($basePath, $config, $router, $logger, $modules, new ErrorHandler($logger, (bool) $config->get('app.debug', false)), new ConnectionFactory($config));
    }

    public function handle(Request $request): Response
    {
        $requestId = self::requestId($request->header('x-request-id'));
        try {
            $trustedHosts = (array) $this->config->get('app.trusted_hosts', []);
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/', $request->host()) || ($trustedHosts !== [] && !in_array($request->host(), $trustedHosts, true))) {
                return SecurityHeaders::apply(Response::json(['error' => 'Invalid host.'], 400), $requestId);
            }
            if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $token = $request->header('x-csrf-token') ?? ($request->body['_token'] ?? null);
                if (!Csrf::valid(is_string($token) ? $token : null)) {
                    return SecurityHeaders::apply(Response::json(['error' => 'Invalid CSRF token.'], 419), $requestId);
                }
            }
            return SecurityHeaders::apply($this->router->dispatch($request), $requestId);
        } catch (Throwable $exception) {
            return SecurityHeaders::apply($this->errors->render($exception, $requestId), $requestId);
        }
    }

    public function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) $this->config->get('session.name'));
        $sessionPath = (string) $this->config->get('session.path');
        if (!is_dir($sessionPath) && !mkdir($sessionPath, 0750, true) && !is_dir($sessionPath)) {
            throw new RuntimeException('Unable to create session directory.');
        }
        session_save_path($sessionPath);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params($this->sessionCookieParameters());
        if (!session_start()) {
            throw new RuntimeException('Unable to start session.');
        }
    }

    public function sessionCookieParameters(): array
    {
        return [
            'lifetime' => (int) $this->config->get('session.lifetime', 43200),
            'path' => '/', 'secure' => (bool) $this->config->get('session.secure', true),
            'httponly' => true, 'samesite' => (string) $this->config->get('session.same_site', 'Lax'),
        ];
    }

    public function router(): Router { return $this->router; }
    public function modules(): ModuleLoader { return $this->modules; }
    public function database(): ConnectionFactory { return $this->database; }
    public function config(): Config { return $this->config; }
    public function basePath(): string { return $this->basePath; }

    private static function requestId(?string $candidate): string
    {
        return is_string($candidate) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate)
            ? $candidate : bin2hex(random_bytes(16));
    }
}
