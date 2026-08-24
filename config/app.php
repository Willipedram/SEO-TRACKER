<?php

declare(strict_types=1);

return [
    'name' => 'SEO Tracker',
    'env' => env('APP_ENV', 'production'),
    'debug' => env_bool('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'key' => env('APP_KEY'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => env('APP_LOCALE', 'en'),
    'rtl_locales' => ['fa', 'ar', 'he', 'ur'],
    'trusted_hosts' => env_list('APP_TRUSTED_HOSTS'),
];
