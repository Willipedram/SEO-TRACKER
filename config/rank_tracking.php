<?php

declare(strict_types=1);

return [
    // Disabled until an adapter passes the ADR 0012 approval gate.
    'adapter' => env('RANK_ADAPTER', ''),
    'max_attempts' => 3,
    'rate_limit' => 10,
    'rate_window' => 900,
    'lease_seconds' => 120,
];
