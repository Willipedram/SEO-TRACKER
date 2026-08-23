<?php

declare(strict_types=1);

return [
    'name' => 'seo_tracker_session',
    'path' => dirname(__DIR__) . '/storage/framework/sessions',
    'secure' => env_bool('SESSION_SECURE', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),
    'lifetime' => 7200,
];
