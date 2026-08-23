<?php

declare(strict_types=1);

namespace App\Modules\Foundation\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;

final class FoundationModule implements Module
{
    public function register(Router $router): void
    {
        $router->get('/health', static fn (): Response => Response::json(['status' => 'ok']));
    }
}
