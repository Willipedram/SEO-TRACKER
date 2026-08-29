# ADR 0020: Typed scoped settings and locked foundational modules

- Status: Accepted
- Date: 2026-08-24

## Decision

Managed settings use a code-owned registry and a scope-aware persistence table. Unknown keys are rejected. System/module writes require `settings.manage`; user preferences are self-scoped. Secrets remain outside this store. Reads use request-local caching with write invalidation.

The module registry locks foundational modules. Only Reports and optional Search Console are runtime-toggleable. Disablement changes state and audit history only; it never deletes domain or OAuth data. A non-disableable Settings module provides browser recovery.

Feature flags are restricted to two operational job-admission switches and are evaluated after normal authorization. They do not replace modules or RBAC.
