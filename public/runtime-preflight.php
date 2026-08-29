<?php

declare(strict_types=1);

/**
 * This file must remain parseable by PHP 8.0/8.1. It runs before application
 * classes, which intentionally use PHP 8.2 syntax such as readonly classes.
 *
 * @return array{supported:bool,current:string,required:string}
 */
function seo_tracker_runtime_preflight(?int $versionId = null, ?string $version = null): array
{
    $versionId ??= PHP_VERSION_ID;

    return [
        'supported' => $versionId >= 80200,
        'current' => $version ?? PHP_VERSION,
        'required' => '8.2.0',
    ];
}
