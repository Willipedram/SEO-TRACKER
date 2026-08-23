<?php

declare(strict_types=1);

use App\Core\Http\Response;
use App\Core\Installer\InstallerController;
use App\Core\Update\UpdaterController;
use App\Core\Auth\AuthController;
use App\Core\Auth\AuthFactory;

$installer = new InstallerController($basePath, $config);
$updater = new UpdaterController($basePath, $config);
$auth = new AuthController(new AuthFactory($basePath, $config));
$router->get('/', static fn (): Response => $updater->home());
$router->get('/install', static fn ($request): Response => $installer->show($request));
$router->post('/install', static fn ($request): Response => $installer->submit($request));
$router->get('/update', static fn (): Response => $updater->show());
$router->post('/update', static fn ($request): Response => $updater->run($request));
$router->get('/login', static fn (): Response => $auth->form());
$router->post('/login', static fn ($request): Response => $auth->login($request));
$router->post('/logout', static fn (): Response => $auth->logout());
$router->get('/account', static fn (): Response => $auth->account());
