# ADR 0005: Forward-only, compatibility-aware migrations

- Status: Accepted
- Date: 2026-08-21

## Context

MySQL/MariaDB DDL rollback is not uniformly transactional or safe. Rank history
may become large and shared hosting constrains execution time.

## Decision

Migration files and the framework ledger are authoritative. Changes are ordered,
idempotent where practical, network-independent, and tested from empty and upgrade
fixtures on every supported engine. Use expand/migrate/contract for destructive or
large changes and resumable bounded jobs for backfills. Acquire one update lock.
Recover incompatible failures with a verified database/persistent-data backup and
the matching prior release rather than promising universal `down` migrations.

## Consequences

Updates favor safety and mixed-version compatibility but schema cleanup takes
multiple releases. Backups and restore drills are mandatory. Exact MySQL/MariaDB
versions must be selected and tested before claiming support.
