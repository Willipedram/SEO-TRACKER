<?php

declare(strict_types=1);

namespace App\Core\Error;

use App\Core\Http\Response;
use App\Core\Logging\Logger;
use Throwable;

final class ErrorHandler
{
    public function __construct(private readonly Logger $logger, private readonly bool $debug) {}

    public function render(Throwable $exception, string $requestId): Response
    {
        $this->logger->error('Unhandled exception.', [
            'request_id' => $requestId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
        $data = ['error' => 'An unexpected error occurred.', 'request_id' => $requestId];
        if ($this->debug) {
            $data['exception'] = $exception::class;
            $data['message'] = $exception->getMessage();
            $data['trace'] = explode(PHP_EOL, $exception->getTraceAsString());
        }
        return Response::json($data, 500);
    }
}
