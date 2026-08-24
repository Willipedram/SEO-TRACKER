# Update Architecture

Phase 04 implements source/database version detection and a forward-only migration
runner. Updates remain release-based, authenticated, observable, and recoverable;
the running application never downloads or self-modifies source files during an
HTTP request.

## Implemented version contract

`config/version.php` is the source authority for application version `0.7.0` and
target schema version `5`. Persistent `app_installations.source_version` and
`schema_version` record what successfully completed. Migration files are ordered by
strict timestamp identifiers, each declare one unique integer schema version, and
successful IDs are stored in `migrations`. Source older than the installed source
or schema is rejected rather than silently downgrading the database.

On a request after ZIP/source replacement, `/` compares persistent and source
versions. Pending work redirects to `/update`, which displays the exact plan and
requires the email and password of a database-backed administrator. The POST is
CSRF-protected. CLI operators can inspect with `php bin/console update:status` and
run with `php bin/console update:run --force` after verifying backups.

Migration discovery is restricted to source-controlled PHP files in
`database/migrations` with a strict filename and `Migration` object contract.
Releases must be verified before extraction because PHP migrations are executable
source code; the updater never accepts a path, class, SQL, URL, or upload from an
HTTP request.

## Execution and failure records

The runner validates that every schema version through the source target exists,
compares the installation marker, removes already-applied IDs, and executes pending
migrations in numeric schema order. Migrations explicitly declare whether their
operations are transactional. MySQL/MariaDB DDL migrations must declare false
because those engines may implicitly commit DDL. The runner takes a MySQL advisory
lock, stops on the first failure, logs redacted technical context, records the
failed ID/class/message in `migration_failures`, and leaves the target schema and
source versions unchanged. Retrying clears that migration's prior failure record;
migrations with non-transactional DDL must be idempotent so a partial attempt can
resume. Later migrations never run after a failure.

## Release contract

Each release has an application version, schema compatibility range, required PHP
and extension versions, dependency lock files, asset build, checksums, and a
signed manifest from a configured trust root. Signature verification is mandatory
for any automated download. Manual deployments still verify checksums and source.
Never accept an update URL or public key from an untrusted request.

## Procedure

1. Read release notes, supported upgrade path, compatibility window, and backup
   requirements; reject skipped versions that are not explicitly supported.
2. Verify signature/checksum, disk space, PHP/extensions, database engine, module
   compatibility, configuration additions, and writable/persistent paths.
3. Back up database and persistent files, record versions, and verify the backup
   is readable. A restore procedure must be rehearsed, not assumed.
4. Build/extract a new immutable release outside the active path; install locked
   production dependencies and compiled assets without executing untrusted hooks.
5. Enable maintenance/drain mode when compatibility requires it; stop new jobs and
   allow bounded in-flight work to finish.
6. Link persistent paths, run forward migrations once under a distributed/database
   update lock, run bounded data upgrades, clear/rebuild caches, and execute health
   and smoke checks.
7. Atomically switch `current`, restart long-lived workers so code versions match,
   restore scheduling/traffic, and monitor errors/queue depth.
8. Record the release/module/schema versions and audited updater actor/outcome.

## Compatibility and failure

Prefer backward-compatible expand/migrate/contract migrations so old and new code
can coexist during the switch. Code rollback is allowed only while its schema
compatibility range includes the migrated schema. Otherwise restore the database
and persistent-data backup together with the previous release. Failed updates
retain logs and the staged release, release locks safely, and never mark a version
successful prematurely.

Module manifests declare application and dependency compatibility. Required Core
module migrations run in deterministic dependency order; an incompatible optional
module blocks update until upgraded or explicitly disabled under its safe lifecycle
rules. Disabling never deletes its tables.

The Phase 04 migration adds tracking status/timestamps and persistent source
version only; it does not modify or delete users, settings, or any business data.
Tests upgrade a Phase 03 database, verify user preservation, exercise multiple
ordered migrations, force a redacted failure, and successfully retry it.

Phase 05 schema version 3 is an idempotent forward migration adding only
authentication account state, temporary throttling, and reset-token storage. It
preserves all existing users and updates source/schema markers through the same
runner.

Phase 06 schema version 4 adds RBAC definitions/assignments and audit storage, then
idempotently grants the existing administrator role the initial permission catalog.
It does not implement or modify website, keyword, ranking, or integration data.

Phase 07 schema version 5 adds the owner-scoped website table and indexes. It does
not create, transform, archive, or delete website rows during upgrade.

## Shared-host limitations

Where atomic symlink switching or workers are unavailable, use maintenance mode,
a staging directory, bounded CLI update commands, and a carefully ordered rename.
Browser-triggered updates are a last-resort wrapper around the same service and
require recent privileged re-authentication, CSRF protection, update lock, short
request steps with resumable state, and no secret output. Database migration work
must not depend on the browser connection remaining open.
