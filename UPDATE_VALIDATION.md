# Phase 23 source-replacement update validation

## Status and scope

Phase 23 is **passed for the locally available SQLite deployment path**. The automated E2E test performs a genuine two-source update: it constructs and extracts a schema-12/application-2.1.0 predecessor release, creates persistent application data, backs up the database, deletes the predecessor source tree, extracts the current release, and runs the real updater against the original database. It does not replace, reset, or reseed that database after the upgrade begins.

The predecessor is a reproducible compatibility fixture derived from the distribution with schema 13's migration removed. It is not represented as a separately published historical artifact. MySQL/MariaDB execution remains host-specific because no database server is available in this environment; this run must not be described as a MySQL update.

## Starting state and persisted records

Version 1 is application `2.1.0`, schema `12`. Before replacement, the test creates an administrator and second user, roles and permission assignments, an application setting, a website, desktop and mobile keywords (including Persian), a completed rank request/attempt/result, enabled Search Console module state, and Search Console connection, opaque credential envelope, selected property, sync, and metrics data.

No sensitive credential value is emitted in test output or this document.

## Source replacement steps exercised

1. Build and checksum a release ZIP with `ReleaseBuilder`.
2. Extract the schema-12 predecessor into its own source directory.
3. Install and migrate an empty SQLite database to schema 12, then create representative records.
4. Copy the database to a separate backup and compare SHA-256 hashes.
5. Delete the entire predecessor source directory while leaving the database and backup outside it.
6. Extract the version-2 release into a new source directory.
7. Boot the real `UpdaterController` from version-2 source against the original database.
8. Verify old-schema detection routes to `/update`, reject invalid administrator credentials, authorize with the existing administrator, run all pending migrations, boot the upgraded application, and run the updater again as a no-op.

## Migration and preservation results

Migration `2026_08_24_020000_settings_system` runs once and creates managed-settings storage and missing module metadata. Current releases also apply later forward migrations, advance the source/schema markers, and leave no migration-failure record. A second runner invocation remains a no-op and does not duplicate ledger records.

Post-update assertions verify the exact pre-upgrade users, password authentication, roles and permissions, base setting, website, both keywords, exact rank observation, module state, opaque OAuth credential record, property, sync history, and Search Console metric values. Persistent data lives outside each replaceable release tree.

## Failure and recovery coverage

The E2E scenario checks denied update authorization and no-op behavior. The migration regression suite separately executes multiple sequential migrations, a deliberately failing migration, persisted failure diagnostics, safe stopping before later migrations, lock contention/interruption behavior, and successful retry after correction. These tests exercise the same `MigrationRunner` used by the source-replacement scenario.

## Remaining environment-specific validation

- A DirectAdmin/Apache and MySQL/MariaDB update must still be executed on the target host before production rollout.
- Live Google OAuth/Search Console is not contacted; preservation uses already-stored opaque metadata.
- No live rank provider is invoked; this verifies preservation of an existing observation, not provider operation.

Phase 24 work is outside this validation.
