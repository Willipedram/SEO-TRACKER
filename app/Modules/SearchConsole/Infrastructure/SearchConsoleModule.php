<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleContext;
use App\Modules\SearchConsole\Presentation\SearchConsoleController;
use RuntimeException;

final class SearchConsoleModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void
    {
        if ($context === null) throw new RuntimeException('Search Console requires application context.');
        $controller = new SearchConsoleController(new SearchConsoleFactory($context->basePath, $context->config));
        // This management shell remains reachable to re-enable the module. No OAuth/API routes
        // or hooks are registered until their later phases and an enabled/ready state check.
        $router->get('/admin/modules/search-console', static fn (): Response => $controller->status());
        $router->post('/admin/modules/search-console/status', static fn ($request): Response => $controller->setStatus($request));
        $router->get('/websites/search-console', static fn ($request): Response => $controller->website($request));
        $router->post('/websites/search-console/connect', static fn ($request): Response => $controller->connect($request));
        $router->get('/oauth/search-console/callback', static fn ($request): Response => $controller->callback($request));
        $router->post('/websites/search-console/property', static fn ($request): Response => $controller->selectProperty($request));
        $router->post('/websites/search-console/disconnect', static fn ($request): Response => $controller->disconnect($request));
        $router->post('/websites/search-console/sync', static fn ($request): Response => $controller->sync($request));
        $router->get('/websites/search-console/sync-status', static fn ($request): Response => $controller->syncStatus($request));
        $router->get('/websites/search-console/dashboard', static fn ($request): Response => $controller->dashboard($request));
        $router->get('/websites/search-console/combined', static fn ($request): Response => $controller->combined($request));
    }
}
