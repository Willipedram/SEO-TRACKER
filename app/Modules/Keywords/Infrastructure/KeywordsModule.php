<?php

declare(strict_types=1);

namespace App\Modules\Keywords\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleContext;
use App\Modules\Keywords\Presentation\KeywordController;
use RuntimeException;

final class KeywordsModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void
    {
        if ($context === null) throw new RuntimeException('Keywords requires application context.');
        $controller = new KeywordController(new KeywordFactory($context->basePath, $context->config));
        $router->get('/keywords', static fn ($request): Response => $controller->index($request));
        $router->get('/keywords/create', static fn ($request): Response => $controller->createForm($request));
        $router->post('/keywords/create', static fn ($request): Response => $controller->create($request));
        $router->get('/keywords/edit', static fn ($request): Response => $controller->editForm($request));
        $router->post('/keywords/edit', static fn ($request): Response => $controller->update($request));
        $router->post('/keywords/status', static fn ($request): Response => $controller->status($request));
        $router->post('/keywords/delete', static fn ($request): Response => $controller->delete($request));
    }
}
