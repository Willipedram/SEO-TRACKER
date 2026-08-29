<?php

declare(strict_types=1);

use App\Core\Http\Request;

ini_set('display_errors', '0');

$application = null;
$request = null;

try {
    $application = require dirname(__DIR__) . '/bootstrap/app.php';
    $application->startSession();
    $request = Request::capture();
    $application->handle($request)->send();
} catch (Throwable $exception) {
    if ($application instanceof App\Core\Application) {
        $request ??= Request::capture();
        $application->renderBootstrapFailure($exception, $request)->send();
        return;
    }

    $requestId = bin2hex(random_bytes(16));
    $message = preg_replace(
        '/(?i)(password|secret|token|authorization|cookie|session[_-]?id|(?:app|api|encryption)[_-]?key)(\s*[=:]\s*)([^\s,;]+)/',
        '$1$2[REDACTED]',
        $exception->getMessage(),
    ) ?? 'Bootstrap failure';
    $record = json_encode([
        'timestamp' => gmdate('c'), 'level' => 'CRITICAL', 'message' => 'Application bootstrap failed.',
        'context' => ['request_id' => $requestId, 'exception' => $exception::class, 'message' => $message, 'file' => $exception->getFile(), 'line' => $exception->getLine()],
    ], JSON_UNESCAPED_SLASHES);
    if (is_string($record)) {
        @file_put_contents(dirname(__DIR__) . '/storage/logs/application.log', $record . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    error_log(sprintf('Application bootstrap failed [%s]: %s: %s at %s:%d', $requestId, $exception::class, $message, $exception->getFile(), $exception->getLine()));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Request-ID: ' . $requestId);
    echo json_encode(['error' => 'برنامه در دسترس نیست. شناسه پیگیری را برای پشتیبانی نگه دارید.', 'request_id' => $requestId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
