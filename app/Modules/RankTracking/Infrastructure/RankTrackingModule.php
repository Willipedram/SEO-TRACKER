<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleContext;
use App\Modules\RankTracking\Presentation\RankTrackingController;
use RuntimeException;

final class RankTrackingModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void
    {
        if ($context === null) throw new RuntimeException('Rank Tracking requires application context.');
        $controller = new RankTrackingController(new RankTrackingFactory($context->basePath, $context->config));
        $router->post('/rank-checks', static fn ($request): Response => $controller->submit($request));
        $router->get('/rank-checks/status', static fn ($request): Response => $controller->status($request));
        $router->get('/rank-checks/history', static fn ($request): Response => $controller->history($request));
        $router->get('/rank-dashboard', static fn ($request): Response => $controller->dashboard($request));
        $router->get('/rank-dashboard/chart', static fn ($request): Response => $controller->chart($request));
    }
}
