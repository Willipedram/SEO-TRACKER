# Source Replacement and Database Updates

Application **2.4.0** is the source authority for schema **14**. Source and database
versions are independent: `config/version.php` declares the release target, while
`app_installations` and `migrations` record durable completed state.

The executable two-release source-replacement validation and preservation scope are
documented in [`UPDATE_VALIDATION.md`](UPDATE_VALIDATION.md).

## Update model

Updates are release-based and forward-only. The application never downloads or edits
its own PHP source during an HTTP request. Deploy a verified release, retain persistent
secrets/storage, inspect the plan, and run migrations under the database update lock.
Source older than installed source/schema is rejected instead of silently downgrading.

After source replacement, `/` detects pending work and redirects to `/update`. The web
updater shows the migration plan, requires current database-backed administrator
credentials, is CSRF protected, and rate-limits failed update authorization in the
session. CLI operators use:

```bash
php bin/console deploy:check
php bin/console update:status
php bin/console update:run --force   # only after backup verification
php bin/console app:check
```

## Required procedure

1. Read release notes and verify the release checksum/signature and supported upgrade
   path. PHP, extensions, module compatibility, disk, and schema requirements must pass.
2. Pause Rank/Search Console cron and allow bounded in-flight jobs to complete.
3. Back up and verify the database, `.env`/keys, shared storage, install lock, cron
   definitions, and active release checksum. Keep backups outside the web root.
4. Extract into a new private release directory where possible. Do not overwrite the
   active source tree file-by-file when an atomic symlink/rename model is available.
5. Link the existing protected `.env` and persistent storage. Apply safe ownership and
   permissions; never use 0777.
6. Run `deploy:check` and `update:status`. Review the exact pending migrations. Run the
   CLI updater or authenticated `/update` workflow once.
7. Verify source/schema markers, health, routes/modules, login, Website/Keyword reads,
   and queue status. Switch the active release atomically or complete the maintenance
   rename, then restore cron and monitor logs/queue failures.

On constrained shared hosting without symlinks, upload/extract into a staging folder,
enable maintenance at the domain/panel layer, preserve `.env` and `storage`, and use a
carefully ordered directory rename. The database migration does not depend on a browser
connection when CLI is available. The browser updater is the supported fallback and
uses the same migration runner/lock.

## Migration guarantees

Migration discovery accepts only source-controlled files in `database/migrations` with
strict timestamp IDs, a matching `Migration` object, and one unique integer schema
version. Applied IDs are stored. Migrations execute in schema order and later work stops
after the first failure.

MySQL/MariaDB uses an advisory update lock. Because MySQL DDL may implicitly commit,
DDL migrations declare themselves non-transactional and must be idempotent. Failures
are recorded in `migration_failures`, source/schema markers are not advanced, and a
retry clears the prior failure only after the problem is corrected. Transactional data
migrations roll back on failure.

Schema 13 includes authentication/RBAC, owner-scoped Websites and Keywords, immutable
Rank Tracking requests/attempts/results, rank dashboard indexes, optional Search
Console connection/OAuth/sync/aggregate storage, and typed scoped settings. Optional
module disablement does not delete its stored records.

## Rollback and recovery

Code rollback is allowed only when the old source is compatible with the migrated
schema. Otherwise restore the database, matching `.env`/encryption keys, shared storage,
and previous release together. Never point older code at a newer incompatible schema.
Retain the failed release and protected logs until diagnosis is complete.

The full DirectAdmin layout, backup set, cron suspension/restoration, HTTPS, and
host-specific validation instructions are in `DEPLOYMENT_DIRECTADMIN.md`.
