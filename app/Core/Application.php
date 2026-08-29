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
            $request = $this->resolveVirtualMount($request, $requestId);
            $trustedHosts = (array) $this->config->get('app.trusted_hosts', []);
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/', $request->host()) || ($trustedHosts !== [] && !in_array($request->host(), $trustedHosts, true))) {
                return SecurityHeaders::apply($this->mount($this->localize(Response::json(['error' => 'Invalid host.'], 400), $request), $request), $requestId, $request->scheme === 'https');
            }
            if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $token = $request->header('x-csrf-token') ?? ($request->body['_token'] ?? null);
                if (!Csrf::valid(is_string($token) ? $token : null)) {
                    return SecurityHeaders::apply($this->mount($this->localize(Response::json(['error' => 'Invalid CSRF token.'], 419), $request), $request), $requestId, $request->scheme === 'https');
                }
            }
            $response = $this->router->dispatch($request);
            if ($response->status === 404) {
                $this->logger->warning('Route not found.', [
                    'request_id' => $requestId,
                    'method' => $request->method,
                    'route_path' => $request->path,
                    'mount_path' => $request->baseUrl === '' ? '/' : $request->baseUrl,
                    'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? $request->path),
                    'script_name' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
                    'script_filename' => (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''),
                    'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
                ]);
                $response = new Response($response->body, $response->status, $response->headers + [
                    'X-Route-Path' => preg_replace('/[\x00-\x1F\x7F]/', '', $request->path) ?? '/',
                    'X-Mount-Path' => preg_replace('/[\x00-\x1F\x7F]/', '', $request->baseUrl === '' ? '/' : $request->baseUrl) ?? '/',
                ]);
            }
            return SecurityHeaders::apply($this->mount($this->localize($response, $request), $request), $requestId, $request->scheme === 'https');
        } catch (Throwable $exception) {
            return SecurityHeaders::apply($this->mount($this->localize($this->errors->render($exception, $requestId), $request), $request), $requestId, $request->scheme === 'https');
        }
    }

    /**
     * Handle failures that occur after the kernel has booted but before route
     * dispatch, such as an unavailable session directory.
     */
    public function renderBootstrapFailure(Throwable $exception, Request $request): Response
    {
        $requestId = self::requestId($request->header('x-request-id'));
        return SecurityHeaders::apply(
            $this->localize($this->errors->render($exception, $requestId), $request),
            $requestId,
            $request->scheme === 'https',
        );
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

    private function localize(Response $response, ?Request $request = null): Response
    {
        return (new UiLocalizer((string) $this->config->get('app.locale', 'fa'), $this->basePath))->response(
            $response,
            $request?->path ?? '/',
            $this->uiContext(),
        );
    }

    private function uiContext(): array
    {
        $auth = $_SESSION['auth'] ?? null;
        $userId = is_array($auth) && is_int($auth['user_id'] ?? null) ? $auth['user_id'] : null;
        if ($userId === null) return ['authenticated' => false, 'permissions' => [], 'user' => null, 'version' => (string) $this->config->get('version.application', '')];
        try {
            $database = $this->database->connect();
            $user = $database->fetchOne('SELECT id,name,email FROM users WHERE id=:id AND disabled_at IS NULL', ['id'=>$userId]);
            $permissions = $user === null ? [] : (new \App\Core\Rbac\Authorization($database))->permissions($userId);
            $modules = $user === null ? [] : array_column($database->fetchAll('SELECT module_key FROM modules WHERE enabled=1'), 'module_key');
            return ['authenticated'=>$user !== null,'permissions'=>$permissions,'modules'=>$modules,'user'=>$user,'version'=>(string)$this->config->get('version.application', '')];
        } catch (Throwable) {
            return ['authenticated'=>false,'permissions'=>[],'user'=>null,'version'=>(string)$this->config->get('version.application', '')];
        }
    }

    private function mount(Response $response, Request $request): Response
    {
        if ($request->baseUrl === '') {
            return $response;
        }

        $headers = $response->headers;
        if (isset($headers['Location']) && str_starts_with($headers['Location'], '/')) {
            $headers['Location'] = $request->baseUrl . $headers['Location'];
        }

        $body = $response->body;
        if (str_starts_with((string) ($headers['Content-Type'] ?? ''), 'text/html')) {
            $body = preg_replace_callback(
                '/(\\b(?:href|src|action)=["\'])\\/(?!\\/)([^"\']*)/i',
                static function (array $match) use ($request): string {
                    // The compatibility .htaccess publishes /public/assets at
                    // the domain-root /assets URL. Application routes may have
                    // a mount prefix, but static files never do.
                    if (str_starts_with($match[2], 'assets/')) {
                        return $match[1] . '/' . $match[2];
                    }
                    return $match[1] . $request->baseUrl . '/' . $match[2];
                },
                $body,
            ) ?? $body;
        }

        return new Response($body, $response->status, $headers);
    }

    private function resolveVirtualMount(Request $request, string $requestId): Request
    {
        if ($request->baseUrl !== '' || $this->router->has($request->method, $request->path)) {
            return $request;
        }

        // Reverse proxies and some DirectAdmin rewrite layouts execute the
        // domain-root index.php, so every filesystem variable says "/" even
        // though the public URL is mounted below it. Resolve only a suffix
        // which is an actually registered route; a single unknown path remains
        // a real 404 rather than being mistaken for a mount point.
        $offset = 0;
        while (($offset = strpos($request->path, '/', $offset + 1)) !== false) {
            $candidate = substr($request->path, $offset) ?: '/';
            if (!$this->router->has($request->method, $candidate)) {
                continue;
            }

            $baseUrl = rtrim(substr($request->path, 0, $offset), '/');
            $this->logger->info('Virtual mount path inferred from registered route.', [
                'request_id' => $requestId,
                'original_path' => $request->path,
                'route_path' => $candidate,
                'mount_path' => $baseUrl,
            ]);
            return $request->routedThrough($candidate, $baseUrl);
        }

        return $request;
    }
}
