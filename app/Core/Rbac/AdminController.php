<?php

declare(strict_types=1);

namespace App\Core\Rbac;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Core\Security\Html;
use InvalidArgumentException;
use Throwable;

final class AdminController
{
    public function __construct(private readonly RbacFactory $factory) {}

    public function users(): Response
    {
        try {
            [$users, , , $auth] = $this->factory->services();
            $actor = $auth->user();
            if ($actor === null) return Response::redirect('/login', 302);
            $rows = '';
            foreach ($users->all((int) $actor['id']) as $user) {
                $id = (int) $user['id'];
                $state = $user['disabled_at'] === null ? 'Active' : 'Disabled';
                $rows .= '<tr><td>' . $id . '</td><td>' . Html::escape((string) $user['name']) . '</td><td>' . Html::escape((string) $user['email']) . '</td><td>' . Html::escape((string) $user['roles']) . '</td><td>' . $state . '</td><td><a href="/admin/users/edit?id=' . $id . '">Edit</a> · <a href="/admin/users/roles?id=' . $id . '">Roles</a></td></tr>';
            }
            return $this->page('Users', '<p><a class="button" href="/admin/users/create">Create user</a> <a href="/admin/roles">Manage roles</a></p><table><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table>');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable) { return $this->failure(); }
    }

    public function createUserForm(): Response
    {
        return $this->authorizedPage('users.create', 'Create user', '<form method="post" action="/admin/users/create">' . $this->csrf() . '<label>Name<input required name="name" maxlength="100"></label><label>Email<input required type="email" name="email" maxlength="254"></label><label>Temporary password<input required type="password" name="password" minlength="12" maxlength="1024" autocomplete="new-password"></label><button>Create user</button></form>');
    }

    public function createUser(Request $request): Response
    {
        try {
            [$users, , , $auth] = $this->factory->services(); $actor = $this->actor($auth);
            $users->create($actor, (string) ($request->body['name'] ?? ''), (string) ($request->body['email'] ?? ''), (string) ($request->body['password'] ?? ''));
            return Response::redirect('/admin/users');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->errorPage('Create user', $exception); }
        catch (Throwable) { return $this->errorPage('Create user', new InvalidArgumentException('The user could not be created. Verify that the email is unique.')); }
    }

    public function editUserForm(Request $request): Response
    {
        try {
            [$users, , $authorization, $auth] = $this->factory->services(); $actor = $this->actor($auth); $authorization->require($actor, 'users.edit');
            $id = $this->id($request->query['id'] ?? null);
            $databaseUser = array_values(array_filter($users->all($actor), static fn (array $user): bool => (int) $user['id'] === $id))[0] ?? null;
            if ($databaseUser === null) throw new InvalidArgumentException('User not found.');
            return $this->page('Edit user', '<form method="post" action="/admin/users/edit">' . $this->csrf() . '<input type="hidden" name="id" value="' . $id . '"><label>Name<input required name="name" maxlength="100" value="' . Html::escape((string) $databaseUser['name']) . '"></label><label>Email<input required type="email" name="email" maxlength="254" value="' . Html::escape((string) $databaseUser['email']) . '"></label><button>Save user</button></form><form method="post" action="/admin/users/status">' . $this->csrf() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="disabled" value="' . ($databaseUser['disabled_at'] === null ? '1' : '0') . '"><button>' . ($databaseUser['disabled_at'] === null ? 'Disable' : 'Enable') . ' user</button></form><form method="post" action="/admin/users/delete">' . $this->csrf() . '<input type="hidden" name="id" value="' . $id . '"><button class="danger">Delete user</button></form>');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (InvalidArgumentException $exception) { return $this->errorPage('Edit user', $exception, 404); }
        catch (Throwable) { return $this->failure(); }
    }

    public function updateUser(Request $request): Response { return $this->userAction($request, 'update'); }
    public function statusUser(Request $request): Response { return $this->userAction($request, 'status'); }
    public function deleteUser(Request $request): Response { return $this->userAction($request, 'delete'); }

    public function roles(): Response
    {
        try {
            [, $roles, , $auth] = $this->factory->services(); $actor = $this->actor($auth);
            $rows = '';
            foreach ($roles->roles($actor) as $role) {
                $rows .= '<tr><td>' . (int) $role['id'] . '</td><td>' . Html::escape((string) $role['name']) . '</td><td><code>' . Html::escape((string) $role['role_key']) . '</code></td><td>' . (int) $role['user_count'] . '</td><td>' . (int) $role['permission_count'] . '</td><td><a href="/admin/roles/permissions?id=' . (int) $role['id'] . '">Permissions</a></td></tr>';
            }
            return $this->page('Roles', '<form method="post" action="/admin/roles/create">' . $this->csrf() . '<label>Role key<input required name="key" pattern="[a-z][a-z0-9_.-]+" maxlength="100"></label><label>Name<input required name="name" maxlength="150"></label><button>Create role</button></form><table><thead><tr><th>ID</th><th>Name</th><th>Key</th><th>Users</th><th>Permissions</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table><p><a href="/admin/users">Back to users</a></p>');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable) { return $this->failure(); }
    }

    public function createRole(Request $request): Response
    {
        try { [, $roles, , $auth] = $this->factory->services(); $roles->create($this->actor($auth), (string) ($request->body['key'] ?? ''), (string) ($request->body['name'] ?? '')); return Response::redirect('/admin/roles'); }
        catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable $exception) { return $this->errorPage('Create role', $exception instanceof InvalidArgumentException ? $exception : new InvalidArgumentException('The role could not be created.')); }
    }

    public function userRolesForm(Request $request): Response
    {
        try {
            [, $roles, , $auth] = $this->factory->services(); $actor = $this->actor($auth); $userId = $this->id($request->query['id'] ?? null);
            $selected = $roles->userRoleIds($actor, $userId); $checkboxes = '';
            foreach ($roles->roles($actor) as $role) { $id = (int) $role['id']; $checkboxes .= '<label><input type="checkbox" name="roles[]" value="' . $id . '"' . (in_array($id, $selected, true) ? ' checked' : '') . '> ' . Html::escape((string) $role['name']) . '</label>'; }
            return $this->page('Assign user roles', '<form method="post" action="/admin/users/roles">' . $this->csrf() . '<input type="hidden" name="user_id" value="' . $userId . '">' . $checkboxes . '<button>Save roles</button></form>');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable $exception) { return $this->errorPage('Assign user roles', $exception instanceof InvalidArgumentException ? $exception : new InvalidArgumentException('Unable to load assignments.'), 422); }
    }

    public function assignUserRoles(Request $request): Response
    {
        try { [, $roles, , $auth] = $this->factory->services(); $roles->assignRoles($this->actor($auth), $this->id($request->body['user_id'] ?? null), (array) ($request->body['roles'] ?? [])); return Response::redirect('/admin/users'); }
        catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable $exception) { return $this->errorPage('Assign user roles', $exception instanceof InvalidArgumentException ? $exception : new InvalidArgumentException('Roles could not be assigned.')); }
    }

    public function rolePermissionsForm(Request $request): Response
    {
        try {
            [, $roles, , $auth] = $this->factory->services(); $actor = $this->actor($auth); $roleId = $this->id($request->query['id'] ?? null);
            $selected = $roles->rolePermissionIds($actor, $roleId); $checkboxes = '';
            foreach ($roles->permissions($actor) as $permission) { $id = (int) $permission['id']; $checkboxes .= '<label><input type="checkbox" name="permissions[]" value="' . $id . '"' . (in_array($id, $selected, true) ? ' checked' : '') . '> <code>' . Html::escape((string) $permission['permission_key']) . '</code> — ' . Html::escape((string) $permission['description']) . '</label>'; }
            return $this->page('Assign role permissions', '<form method="post" action="/admin/roles/permissions">' . $this->csrf() . '<input type="hidden" name="role_id" value="' . $roleId . '">' . $checkboxes . '<button>Save permissions</button></form>');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable $exception) { return $this->errorPage('Assign role permissions', $exception instanceof InvalidArgumentException ? $exception : new InvalidArgumentException('Unable to load permissions.')); }
    }

    public function assignRolePermissions(Request $request): Response
    {
        try { [, $roles, , $auth] = $this->factory->services(); $roles->assignPermissions($this->actor($auth), $this->id($request->body['role_id'] ?? null), (array) ($request->body['permissions'] ?? [])); return Response::redirect('/admin/roles'); }
        catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable $exception) { return $this->errorPage('Assign role permissions', $exception instanceof InvalidArgumentException ? $exception : new InvalidArgumentException('Permissions could not be assigned.')); }
    }

    public function capabilities(): Response
    {
        try { [, , $authorization, $auth] = $this->factory->services(); $actor = $this->actor($auth); return Response::json(['permissions' => $authorization->permissions($actor)]); }
        catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable) { return Response::json(['error' => 'Unauthenticated.'], 401); }
    }

    private function userAction(Request $request, string $action): Response
    {
        try {
            [$users, , , $auth] = $this->factory->services(); $actor = $this->actor($auth); $id = $this->id($request->body['id'] ?? null);
            match ($action) {
                'update' => $users->update($actor, $id, (string) ($request->body['name'] ?? ''), (string) ($request->body['email'] ?? '')),
                'status' => $users->setDisabled($actor, $id, filter_var($request->body['disabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException('Invalid status.')),
                'delete' => $users->delete($actor, $id),
            };
            return Response::redirect('/admin/users');
        } catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable $exception) { return $this->errorPage('User action', $exception instanceof InvalidArgumentException ? $exception : new InvalidArgumentException('The action could not be completed.')); }
    }

    private function authorizedPage(string $permission, string $title, string $content): Response
    {
        try { [, , $authorization, $auth] = $this->factory->services(); $authorization->require($this->actor($auth), $permission); return $this->page($title, $content); }
        catch (AuthorizationException $exception) { return $this->denied($exception); }
        catch (Throwable) { return $this->failure(); }
    }

    private function actor(object $auth): int { $user = $auth->user(); if ($user === null) throw new AuthorizationException('Authentication required.'); return (int) $user['id']; }
    private function id(mixed $value): int { $id = filter_var($value, FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new InvalidArgumentException('Invalid identifier.'); return $id; }
    private function csrf(): string { return '<input type="hidden" name="_token" value="' . Html::escape(Csrf::token()) . '">'; }
    private function denied(AuthorizationException $exception): Response { return $this->page('Access denied', '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', 403); }
    private function failure(): Response { return Response::json(['error' => 'Management interface is unavailable.'], 503); }
    private function errorPage(string $title, Throwable $exception, int $status = 422): Response { return $this->page($title, '<p class="error">' . Html::escape($exception->getMessage()) . '</p>', $status); }
    private function page(string $title, string $content, int $status = 200): Response { return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' — SEO Tracker</title><link rel="stylesheet" href="/assets/installer.css"></head><body><main class="card wide"><p><strong>SEO Tracker administration</strong></p><h1>' . Html::escape($title) . '</h1>' . $content . '</main></body></html>', $status); }
}
