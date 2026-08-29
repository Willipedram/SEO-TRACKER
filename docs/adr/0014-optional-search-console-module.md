# ADR 0014: Isolated optional Search Console module

- Status: Accepted
- Date: 2026-08-23

## Context

Google Search Console needs external credentials and a failure-prone provider, while
Core and rank tracking must work when its code, configuration, or service is absent.
A database-only enable flag cannot safely control discovery before installation, and
removing all routes would also remove the authorized path needed to re-enable it.

## Decision

Mark `SearchConsole` optional in source configuration. The loader tolerates missing
source or provider-registration failure only for explicitly optional modules and
reports `unavailable`/`failed`. A small management provider registers independently;
the persistent `modules.enabled` flag controls all feature capabilities and defaults
off. The management shell may inspect/change that flag with `settings.manage`, while
OAuth and sync routes remain absent until later phases. Disable never deletes data.

Credentials remain environment secrets. The status projection exposes configured
booleans, not values. Search Console owns its database and domain contracts; Core and
other business modules never reference its namespace or tables.

## Consequences

Core can boot after the optional directory is removed and external failures cannot
cross a future adapter boundary. Administrators retain a recovery surface. Two states
are intentionally distinct: source provider `loaded` and feature lifecycle `enabled`.
Malformed mandatory modules remain fatal. An installed module whose entire source is
missing must be restored before its web administration page can be used.
