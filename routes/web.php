<?php

declare(strict_types=1);

use App\Core\Http\Response;
use App\Core\Installer\InstallerController;
use App\Core\Update\UpdaterController;

$installer = new InstallerController($basePath, $config);
$updater = new UpdaterController($basePath, $config);
$router->get('/', static fn (): Response => $updater->home());
$router->get('/install', static fn ($request): Response => $installer->show($request));
$router->post('/install', static fn ($request): Response => $installer->submit($request));
$router->get('/update', static fn (): Response => $updater->show());
$router->post('/update', static fn ($request): Response => $updater->run($request));
