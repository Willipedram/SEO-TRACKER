<?php

declare(strict_types=1);

use App\Core\Http\Request;

ini_set('display_errors', '0');

try {
    $application = require dirname(__DIR__) . '/bootstrap/app.php';
    $application->startSession();
    $application->handle(Request::capture())->send();
} catch (Throwable $exception) {
    $requestId = bin2hex(random_bytes(16));
    error_log(sprintf('Application bootstrap failed [%s]: %s at %s:%d', $requestId, $exception::class, $exception->getFile(), $exception->getLine()));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Request-ID: ' . $requestId);
    echo json_encode(['error' => 'Application unavailable.', 'request_id' => $requestId], JSON_THROW_ON_ERROR);
}
