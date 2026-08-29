# Module and Settings System

## Module management

Phase 18 centralizes persisted status metadata for Core, Authentication, Websites, Keywords, Rank Tracking, Reports, Search Console, and Settings. Core, Authentication, Websites, Keywords, Rank Tracking, and Settings are foundational and locked enabled. Reports and Search Console can be toggled by a user with `settings.manage` from the always-available Settings module.

Disabling Search Console changes only its `modules.enabled` state. OAuth connections, encrypted token envelopes, properties, sync history, and synchronized data are not deleted. Rank Tracking and Core do not depend on that state. Re-enabling requires installed module source. Module changes are audited.

## Setting contract

Every managed setting has a code-owned definition specifying a namespaced key (`system.*`, `user.*`, `module.*`, or `feature.*`), one scope, an explicit type/default, validation constraints, and feature-flag/security classification. Only registered settings can be persisted. The store is not used for Websites, Keywords, rankings, OAuth connections, or other domain records.

System and module writes require `settings.manage`; an authenticated user may change only their own allow-listed user preferences. Values are JSON encoded to preserve types. The manager uses an in-process/request-local read-through cache and invalidates the exact entry after a write. It has no cross-process cache, avoiding stale shared-host state.

## Secrets and feature flags

Database credentials, OAuth client secrets, token envelopes, and encryption keys remain in the established environment/encrypted secret stores and have no editable setting definitions or UI fields.

Two operational kill switches are defined: `feature.rank_manual_checks` and `feature.search_console_sync`. They gate new manual jobs in the corresponding application factories; they do not replace module boundaries, delete queued/history data, or grant permission.
