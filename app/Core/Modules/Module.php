<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Http\Router;

interface Module
{
    public function register(Router $router, ?ModuleContext $context = null): void;
}
