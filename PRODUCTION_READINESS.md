# Phase 24 production-readiness review

## Release status

Application 2.4.0/schema 14 is ready for a controlled production deployment after the target host passes `deploy:check`, the release checksum and Apache denial probes are verified, and a restore rehearsal succeeds. This review does not claim that the unavailable DirectAdmin/MySQL or live-provider environments were exercised locally.

## Security and configuration review

Phase 19 controls remain enforced: production debug output is suppressed, unhandled failures receive a request ID and are logged, state-changing browser requests require CSRF, sessions are strict cookie-only with HttpOnly/Secure/SameSite validation, authorization and website ownership are server-side, database values are bound, output is escaped, OAuth state/PKCE and encrypted token storage remain intact, and logs redact credential-shaped keys and values.

The production release builder now excludes tests, VCS/editor metadata, environment files, databases, backups, archives, runtime logs, and generated storage/cache content. Operational documentation and `.env.example` remain included intentionally. A release must be built from a clean trusted checkout; `deploy:check` must report production mode, HTTPS, trusted hosts, secure cookies, MySQL/utf8mb4, required extensions, and writable runtime paths before traffic is enabled.

## Performance and database evidence

The review traced report, dashboard, sync, and rank-worker SQL rather than adding speculative indexes:

- website reports constrain rank history by `website_id` and `observed_at` but the prior index placed unconstrained device between those columns;
- movement reports bound `rank_results` by `observed_at` before partitioning by keyword;
- sync throttling counts jobs by `user_id` and `created_at`, while the prior owner index placed website between those columns.

Schema 14 therefore adds exactly `rank_results_website_time`, `rank_results_observed_keyword`, and `search_console_syncs_user_created`. Existing property/date and dimension indexes continue to serve Search Console aggregates. Existing queue status/availability and lease indexes continue to serve bounded worker claims. Reports paginate to at most 100 rows, CSV iterates pages with a hard cap, dashboard breakdowns are database aggregates with fixed limits, and sync publication uses staged set-based upserts rather than loading the entire history.

The rank dashboard currently loads the selected website's bounded date window into memory to calculate current/previous/best/worst values. The permitted maximum is one year (or explicitly all history); very large installations should monitor query latency and PHP memory. Changing that calculation to engine-specific window queries is a future optimization, not required for the current supported scale.

## Logging, errors, and operations

Application logs are append-only JSON lines with UTC timestamps, severity, redacted context, and request IDs for unhandled web errors. Migration, rank, and sync failures retain safe diagnostic state in the database; optional-module failures are isolated by module boundaries. Production should use `LOG_LEVEL=info` normally, temporarily raise it only during an incident, and never enable application debug.

The application does not rotate files. Configure the hosting panel or `logrotate` for application and cron logs: rotate daily, retain 14–30 days according to policy, compress old files, use `copytruncate` where PHP keeps no persistent descriptor, and cap total storage. Restrict logs to the application account, monitor repeated ERROR/CRITICAL events, failed jobs, stale leases, pending-queue growth, disk usage, and failed cron invocations. Export longer-lived audit evidence to protected storage according to organizational retention requirements.

## Backup and restore validation

A complete recovery set contains one transactionally consistent database dump, protected `.env`/keys, shared storage, release version and checksum, and cron definitions. Keep it encrypted and outside the web root and hosting account where possible. Before each deployment:

```bash
umask 077
mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 \
  -h DB_HOST -u DB_USER -p DB_NAME | gzip > /private-backup/seo-tracker-YYYYMMDD.sql.gz
sha256sum /private-backup/seo-tracker-YYYYMMDD.sql.gz > /private-backup/seo-tracker-YYYYMMDD.sql.gz.sha256
tar -C /home/USER/apps/seo-tracker/shared -czf /private-backup/seo-tracker-shared-YYYYMMDD.tar.gz .env storage
```

Do not put a password in command arguments; use an interactive prompt or protected MySQL option file. Verify checksums and archive readability. Restore rehearsal must use an empty staging database and private staging host:

1. verify both checksums and extract the shared archive with restrictive permissions;
2. create an empty database/user and import the dump with `gunzip -c ... | mysql ...`;
3. deploy the recorded matching release, then restore the same app/encryption keys;
4. change only staging URL/trusted-host/OAuth callback values, never production keys or encrypted token rows;
5. run `deploy:check`, `app:check`, and `update:status`, then verify login, RBAC, website/keyword counts, rank history, settings/module state, and Search Console metadata;
6. keep cron disabled until validation finishes and securely destroy the rehearsal copy afterward.

The automated readiness test also performs a destructive local SQLite restore: it backs up a migrated database, deletes the original, restores the backup, and verifies both persistent content and schema marker. This validates the procedure mechanics, not MySQL tooling on the target host.

## Upgrade and rollback

Use new immutable release directories, shared secrets/storage, paused cron, verified backups, preflight checks, the existing update plan, and an atomic `current` switch. Schema 14 is additive and index-only. A failed migration leaves source/schema markers unadvanced and a diagnostic row; correct the cause and retry before enabling traffic.

Rollback is not an automatic down-migration. Before any migration, switching `current` back is sufficient. After a migration, old source may be selected only when its declared schema compatibility includes the installed schema. Otherwise stop traffic and cron, preserve failure evidence, restore the pre-deployment database plus matching shared secrets/storage, atomically select the old release, run checks, and then reopen traffic. Never combine an old database with new encrypted state or partially overwrite a live release.

## Remaining release gates

- Execute MySQL/MariaDB migration 14 and inspect query plans on production-like volume.
- Complete the DirectAdmin/Apache/TLS/cron denial and reliability checks on the target account.
- Rehearse the documented MySQL restore and time it against the recovery objective.
- Live rank and Google integrations require configured providers and credentials; isolated automated coverage is not live verification.

Phase 25 is not started.
