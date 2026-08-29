# Roles and Permissions

## Model

Phase 06 implements database-driven many-to-many RBAC:

- users receive zero or more roles through `user_roles`;
- roles receive zero or more permissions through `role_permissions`;
- permissions are stable namespaced capability keys in `permissions`;
- effective permissions are the distinct union of every assigned role;
- disabled users have no effective permissions.

Authentication proves the user ID. `Authorization` resolves permission data on the
server for every protected operation and never trusts form fields, query parameters,
navigation visibility, or client-side state. Services repeat permission checks so
calling a manager directly cannot bypass a controller.

## Initial permission catalog

The source-controlled catalog seeds `users.view`, `users.create`, `users.edit`,
`users.delete`, `roles.manage`, website and keyword view/create/edit/delete keys,
rank tracking run/view, Search Console connect/sync, reports view, and settings
management. Runtime checks query database assignments; modules can add permissions
through later idempotent migrations without changing the authorization engine.

Permission definitions are not freely created through the browser because a key
has meaning only when server code enforces it. The UI lists definitions and assigns
them to roles. The initial administrator role receives all catalog permissions.

## Management interfaces

- `/admin/users`: list users, account state, and role summary.
- `/admin/users/create`: create a user without implicit roles.
- `/admin/users/edit?id=...`: edit identity, enable/disable, or delete.
- `/admin/users/roles?id=...`: replace a user's validated role set.
- `/admin/roles`: list/create roles.
- `/admin/roles/permissions?id=...`: replace a role's validated permission set.
- `/internal/permissions`: authenticated JSON capability response for internal UI.

All mutations are POST plus CSRF protection. IDs are positive integers, fetched
server-side, and checked for existence. Submitted arrays accept only unique existing
IDs and are bounded to 100 items. User creation never mass-assigns roles. Only an
actor with `roles.manage` can assign roles or permissions.

## Safety invariants

The current user cannot disable/delete itself or remove its own administrator role.
The last active administrator cannot be disabled, deleted, or stripped of the
administrator role. The administrator role must retain `roles.manage`. Assignment
replacement and its audit record share one database transaction.

Audit actions include user creation/update/disable/enable/delete, role creation,
user role changes, and role permission changes. Metadata is allow-listed scalar
data; passwords, session IDs, credentials, and submitted assignment contents are
not recorded.

## Boundary

Phase 06 does not implement websites, keywords, rank tracking, Search Console,
reports, or settings features. Their permission definitions exist so their future
server-side use cases can depend on stable data-driven keys.
