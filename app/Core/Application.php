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
use App\Core\Localization\UiLocalizer;
use App\Core\Modules\ModuleLoader;
use App\Core\Modules\ModuleContext;
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
        $modules = new ModuleLoader((array) $config->get('modules.paths', []), (array) $config->get('modules.enabled', []), (array) $config->get('modules.optional', []));
        $modules->load($router, new ModuleContext($basePath, $config));
        require $basePath . '/routes/web.php';
        $debug = strtolower((string) $config->get('app.env', 'production')) !== 'production'
            && (bool) $config->get('app.debug', false);
        return new self($basePath, $config, $router, $logger, $modules, new ErrorHandler($logger, $debug), new ConnectionFactory($config));
    }

    public function handle(Request $request): Response
    {
        $requestId = self::requestId($request->header('x-request-id'));
        try {
            $trustedHosts = (array) $this->config->get('app.trusted_hosts', []);
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/', $request->host()) || ($trustedHosts !== [] && !in_array($request->host(), $trustedHosts, true))) {
                return SecurityHeaders::apply($this->localize(Response::json(['error' => 'Invalid host.'], 400)), $requestId, $request->scheme === 'https');
            }
            if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $token = $request->header('x-csrf-token') ?? ($request->body['_token'] ?? null);
                if (!Csrf::valid(is_string($token) ? $token : null)) {
                    return SecurityHeaders::apply($this->localize(Response::json(['error' => 'Invalid CSRF token.'], 419)), $requestId, $request->scheme === 'https');
                }
            }
            return SecurityHeaders::apply($this->localize($this->router->dispatch($request)), $requestId, $request->scheme === 'https');
        } catch (Throwable $exception) {
            return SecurityHeaders::apply($this->localize($this->errors->render($exception, $requestId)), $requestId, $request->scheme === 'https');
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
        $cookie = $this->sessionCookieParameters();
        ini_set('session.gc_maxlifetime', (string) $cookie['lifetime']);
        session_set_cookie_params($cookie);
        if (!session_start()) {
            throw new RuntimeException('Unable to start session.');
        }
    }

    public function sessionCookieParameters(): array
    {
        $lifetime = (int) $this->config->get('session.lifetime', 43200);
        $secure = (bool) $this->config->get('session.secure', true);
        $sameSite = ucfirst(strtolower((string) $this->config->get('session.same_site', 'Lax')));
        if ($lifetime < 300 || $lifetime > 604800) {
            throw new RuntimeException('Session lifetime must be between 5 minutes and 7 days.');
        }
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true) || ($sameSite === 'None' && !$secure)) {
            throw new RuntimeException('Invalid session SameSite configuration.');
        }
        return [
            'lifetime' => $lifetime,
            'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => $sameSite,
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

    private function localize(Response $response): Response
    {
        return (new UiLocalizer((string) $this->config->get('app.locale', 'fa'), $this->basePath))->response($response);
    }
}
