<?php

declare(strict_types=1);

namespace App\Modules\Reports\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleContext;
use App\Modules\Reports\Presentation\ReportsController;
use RuntimeException;

final class ReportsModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void
    {
        if ($context === null) throw new RuntimeException('Reports requires application context.'); $controller = new ReportsController(new ReportsFactory($context->basePath, $context->config));
        $router->get('/reports', static fn ($request): Response => $controller->index($request)); $router->get('/reports/export.csv', static fn ($request): Response => $controller->csv($request));
    }
}
