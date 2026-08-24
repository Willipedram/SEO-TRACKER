# ADR 0007: Explicit source and schema versions with forward migrations

- Status: Accepted
- Date: 2026-08-23

## Context

Immutable source releases may be replaced while the database and shared storage
persist. The application must distinguish source-only updates, pending schema work,
failed work, and accidental source downgrades without rebuilding the database.

## Decision

Keep current application/schema versions in source configuration and successful
installed versions in the database installation marker. Discover strict,
source-controlled migration objects, order them by unique integer schema version,
and track successful IDs plus separate failure state. Run explicitly transactional
migrations in transactions; treat MySQL/MariaDB DDL as forward-only and require
idempotent expand/migrate/contract steps. Serialize MySQL execution with an advisory
lock. Browser execution requires CSRF and administrator password verification; CLI
execution requires an explicit force flag.

## Consequences

Replacing source automatically produces a deterministic update plan without
deleting persistent data. Failed steps stop the chain, retain the prior target
version, and can be retried after correction. Executable migration files inherit
the trust of the verified source release. Universal automatic rollback is not
promised: incompatible DDL recovery uses a verified pre-update database backup and
the matching prior source release, while compatible source rollback is permitted
only within the documented schema compatibility range.
