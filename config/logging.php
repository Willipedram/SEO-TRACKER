<?php

declare(strict_types=1);

return [
    'level' => env('LOG_LEVEL', 'info'),
    'path' => env('LOG_PATH', 'storage/logs/application.log'),
];
