<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Config\Config;

final class ModuleContext
{
    public function __construct(public readonly string $basePath, public readonly Config $config) {}
}
