<?php

declare(strict_types=1);

return [
    'idle_timeout' => env_int('AUTH_IDLE_TIMEOUT', 1800),
    'absolute_timeout' => env_int('AUTH_ABSOLUTE_TIMEOUT', 43200),
    'max_attempts' => env_int('AUTH_MAX_ATTEMPTS', 5),
    'attempt_window' => env_int('AUTH_ATTEMPT_WINDOW', 900),
    'reset_lifetime' => env_int('AUTH_RESET_LIFETIME', 3600),
];
