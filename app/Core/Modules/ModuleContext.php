<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Config\Config;

final readonly class ModuleContext
{
    public function __construct(public string $basePath, public Config $config) {}
}
