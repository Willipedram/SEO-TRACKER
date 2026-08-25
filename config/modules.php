<?php

declare(strict_types=1);

return [
    'paths' => [dirname(__DIR__) . '/app/Modules'],
    'enabled' => ['Foundation', 'Settings', 'Websites', 'Keywords', 'RankTracking', 'Reports', 'SearchConsole'],
    // Optional modules are isolated: missing or broken source is reported, never fatal to Core.
    'optional' => ['SearchConsole'],
];
