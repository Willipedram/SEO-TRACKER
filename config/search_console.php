<?php

declare(strict_types=1);

return [
    'client_id' => (string) env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID', ''),
    'client_secret' => (string) env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET', ''),
    'redirect_uri' => (string) env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/') . '/oauth/search-console/callback'),
    'encryption_key' => (string) env('SEARCH_CONSOLE_ENCRYPTION_KEY', ''),
    'encryption_key_version' => (string) env('SEARCH_CONSOLE_ENCRYPTION_KEY_VERSION', 'v1'),
    'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly'],
];
