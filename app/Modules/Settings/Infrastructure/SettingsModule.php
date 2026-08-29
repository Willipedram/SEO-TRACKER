<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure;

use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Modules\Module;
use App\Core\Modules\ModuleContext;
use App\Modules\Settings\Presentation\SettingsController;
use RuntimeException;

final class SettingsModule implements Module
{
    public function register(Router $router, ?ModuleContext $context = null): void { if ($context === null) throw new RuntimeException('Settings requires application context.'); $controller = new SettingsController(new SettingsFactory($context->basePath, $context->config)); $router->get('/settings', static fn (): Response => $controller->user()); $router->post('/settings', static fn ($request): Response => $controller->saveUser($request)); $router->get('/admin/settings', static fn ($request): Response => $controller->admin($request)); $router->post('/admin/settings', static fn ($request): Response => $controller->saveAdmin($request)); $router->post('/admin/modules', static fn ($request): Response => $controller->module($request)); }
}
