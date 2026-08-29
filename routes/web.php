<?php

declare(strict_types=1);

use App\Core\Http\Response;
use App\Core\Installer\InstallerController;
use App\Core\Update\UpdaterController;
use App\Core\Auth\AuthController;
use App\Core\Auth\AuthFactory;
use App\Core\Rbac\AdminController;
use App\Core\Rbac\RbacFactory;

$installer = new InstallerController($basePath, $config);
$updater = new UpdaterController($basePath, $config);
$auth = new AuthController(new AuthFactory($basePath, $config));
$admin = new AdminController(new RbacFactory($basePath, $config));
$router->get('/', static fn (): Response => $updater->home());
$router->get('/install', static fn ($request): Response => $installer->show($request));
$router->post('/install', static fn ($request): Response => $installer->submit($request));
$router->get('/update', static fn (): Response => $updater->show());
$router->post('/update', static fn ($request): Response => $updater->run($request));
$router->get('/login', static fn (): Response => $auth->form());
$router->post('/login', static fn ($request): Response => $auth->login($request));
$router->post('/logout', static fn (): Response => $auth->logout());
$router->get('/account', static fn (): Response => $auth->account());
$router->get('/admin/users', static fn (): Response => $admin->users());
$router->get('/admin/users/create', static fn (): Response => $admin->createUserForm());
$router->post('/admin/users/create', static fn ($request): Response => $admin->createUser($request));
$router->get('/admin/users/edit', static fn ($request): Response => $admin->editUserForm($request));
$router->post('/admin/users/edit', static fn ($request): Response => $admin->updateUser($request));
$router->post('/admin/users/status', static fn ($request): Response => $admin->statusUser($request));
$router->post('/admin/users/delete', static fn ($request): Response => $admin->deleteUser($request));
$router->get('/admin/users/roles', static fn ($request): Response => $admin->userRolesForm($request));
$router->post('/admin/users/roles', static fn ($request): Response => $admin->assignUserRoles($request));
$router->get('/admin/roles', static fn (): Response => $admin->roles());
$router->post('/admin/roles/create', static fn ($request): Response => $admin->createRole($request));
$router->get('/admin/roles/permissions', static fn ($request): Response => $admin->rolePermissionsForm($request));
$router->post('/admin/roles/permissions', static fn ($request): Response => $admin->assignRolePermissions($request));
$router->get('/internal/permissions', static fn (): Response => $admin->capabilities());
