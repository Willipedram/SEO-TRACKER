<?php

declare(strict_types=1);

return [
    'max_attempts' => 3,
    'lease_seconds' => 300,
    'page_size' => 25000,
    'max_rows' => 250000,
    'max_range_days' => 31,
    'log_path' => 'storage/logs/search-console.log',
];
