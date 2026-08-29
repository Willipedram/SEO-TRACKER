<?php

declare(strict_types=1);

namespace App\Modules\Websites\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleContext;
use App\Modules\Websites\Presentation\WebsiteController;
use RuntimeException;

final class WebsitesModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void
    {
        if ($context === null) throw new RuntimeException('Websites requires application context.');
        $controller = new WebsiteController(new WebsiteFactory($context->basePath, $context->config));
        $router->get('/websites', static fn ($request): Response => $controller->index($request));
        $router->get('/websites/create', static fn (): Response => $controller->createForm());
        $router->post('/websites/create', static fn ($request): Response => $controller->create($request));
        $router->get('/websites/dashboard', static fn ($request): Response => $controller->dashboard($request));
        $router->get('/websites/edit', static fn ($request): Response => $controller->editForm($request));
        $router->post('/websites/edit', static fn ($request): Response => $controller->update($request));
        $router->get('/websites/settings', static fn ($request): Response => $controller->settingsForm($request));
        $router->post('/websites/settings', static fn ($request): Response => $controller->settings($request));
        $router->post('/websites/archive', static fn ($request): Response => $controller->archive($request));
    }
}
