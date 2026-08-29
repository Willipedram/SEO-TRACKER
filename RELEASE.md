# SEO Tracker 2.4.0 final release

## Release status

The source, schema, documentation, and release archive are internally consistent and
the complete local quality pipeline passes. Release status is **READY for controlled
deployment**, subject to the explicitly blocked target-host and live-provider checks
below. No bundled component is represented as a live provider when it is a fake,
fixture, interface, or disabled optional module.

## Product checklist

The release provides the installation wizard, DirectAdmin/PHP deployment model,
existing-database detection, fresh installation and forward update, authentication,
RBAC, Website and Keyword management, desktop/mobile rank configurations, immutable
rank history, ranking dashboards/charts, optional Search Console OAuth/sync/history,
reports, security controls, modular architecture, tested source replacement, and
operational/test documentation.

Rank Tracking is a complete control plane but requires an approved provider adapter or
client to produce live observations. Search Console is complete as an optional module
but requires real Google credentials for live OAuth/API operation. Those external
facts do not affect core installation, authentication, management, reporting on stored
data, or disabled-module behavior.

## Final deployment procedure

1. **Prerequisites:** select PHP 8.2+ with `json`, `mbstring`, `openssl`, `pdo`,
   `pdo_mysql`, and `session`; use MySQL 8+/compatible MariaDB with `utf8mb4`; enable
   Apache rewrite/overrides; provision HTTPS before installation.
2. **Backup existing systems:** pause cron, let bounded workers finish, create and
   checksum a consistent database dump, and archive protected `.env`, keys, shared
   storage, current release checksum, and cron definitions outside the web root.
3. **Source:** verify the published ZIP checksum, extract into a new immutable release
   directory, attach shared `.env` and storage, apply owner-only/controlled-group
   permissions, and atomically select the release where hosting permits.
4. **Fresh system:** browse the HTTPS origin, complete environment/database/admin
   wizard steps, then confirm the installer is locked and schema 14 is recorded.
5. **Existing system:** never invoke the fresh installer. Open `/update` through normal
   detection or run the documented CLI update after reviewing the plan and backup;
   authenticate as an existing administrator and wait for schema/source markers.
6. **Environment secrets:** use `APP_ENV=production`, `APP_DEBUG=false`, a strong
   `APP_KEY`, exact trusted hosts/HTTPS URL, `SESSION_SECURE=true`, dedicated database
   credentials, and environment-only Google/encryption secrets when Search Console is
   enabled. Never place secrets in URLs, Git, module settings, or cron arguments.
7. **Permissions:** normally use directories `0750`/controlled `0770`, source files
   `0640`, `.env` `0600`, and writable shared storage/cache only. Never use blanket
   `0777`.
8. **Cron/queues:** invoke bounded `rank:work --limit=10` and, when enabled,
   `search-console:work --limit=3` once per minute using the same CLI PHP version.
   Database job state and leases remain authoritative; cron is only a launcher.
9. **Post-deployment:** run `deploy:check`, `app:check`, and `update:status`; verify
   sensitive-path denial probes, login/RBAC, Website/Keyword reads, stored history,
   module states, one controlled configured job, cron logs, TLS, backups, and monitoring.
10. **Rollback:** before migration, atomically reselect the prior release. After a
    schema migration, use old source only if schema-compatible; otherwise restore the
    complete pre-deployment recovery set. Do not run invented down migrations.

Detailed commands and host layouts remain in `DEPLOYMENT_DIRECTADMIN.md`, while
`PRODUCTION_READINESS.md` contains backup/restore and rollback detail.

## Verification boundary

Locally verified: all automated suites, PHP lint, Composer metadata/audit, release ZIP
construction and integrity, extracted fresh install, two-source update, authentication,
RBAC, management flows, rank queue/history/dashboard behavior with deterministic
adapters, fake-gateway OAuth/sync behavior, reports, module/settings behavior,
localization key parity, responsive/RTL CSS contracts, security regressions, migration
plans, and local backup restoration.

**UNVERIFIED/BLOCKED:** live DirectAdmin and Apache behavior, live MySQL/MariaDB
migration/query plans and restore timing, real Google OAuth/Search Console API traffic,
and a real rank provider/client. The environment contains no such hosting account,
database service, credentials, or provider adapter. Dedicated third-party static
analysis is also unavailable because no PHPStan/Psalm executable or package is present;
syntax lint and the full typed runtime suite pass, but they are not relabeled as
third-party static analysis.
