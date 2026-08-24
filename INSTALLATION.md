# Installation Architecture

Phase 02 provides an executable application and `bin/console app:check`, but not
the later interactive installer. The following remains its implementation contract.

## Supported installation modes

1. **CLI (preferred):** deploy a verified release, run Composer with production
   flags, create host-managed environment configuration, execute an idempotent
   preflight/install command, migrate, seed only deterministic system data, cache
   configuration/routes/views, and configure cron/worker operation.
2. **Web installer (implemented shared-host flow):** available before verified
   database installation state exists. It is intentionally discoverable when a
   newly uploaded site is opened, protects writes with the installation session and
   CSRF tokens, never displays stored secrets, modifies only an empty database, and
   permanently hides itself after the database marker is committed.

The web installer must refuse requests when the application is already installed
and must not be re-enabled merely by deleting a browser cookie. A filesystem lock
in persistent storage plus installed database state provides defense in depth.

### ZIP upload quick start

For DirectAdmin hosting where the release ZIP must be extracted directly into
`public_html`, the root `index.php` and defense-in-depth `.htaccess` route requests
to the public front controller and deny application, configuration, storage,
documentation, test, dependency, dotfile, and backup paths. Visit the domain and
complete the three-step wizard. This compatibility layout depends on Apache
honoring `.htaccess`; the environment page must not be treated as proof that deny
rules work. The preferred production layout remains a document root pointing to
`public/`, because it makes source exposure impossible at the web-server boundary.

The wizard checks the environment, validates and connects to MySQL/MariaDB,
classifies the database as empty/application/unknown, creates the baseline only in
an empty database, creates the first administrator, records migration/module/
installation state, and atomically creates `.env` with mode `0600`. Once the
database marker is present, `/install` returns 404 and never offers reinstall.
Table prefixes are intentionally unsupported: module ownership and stable migration
names require predictable table names, and each installation must use a dedicated
database rather than sharing unrelated application tables.

## Preflight

The foundation currently pins PHP 8.2+, JSON, PDO, and PDO MySQL in Composer;
development checks additionally require PDO SQLite. The preflight checks production
constraints and future pinned Composer dependencies,
Apache rewrite/front-controller behavior, HTTPS/base URL, database engine/version/
charset/strict mode/privileges, clock/timezone, writable paths, disk space,
encryption key presence and strength, and scheduler/queue configuration. It emits
actionable redacted errors and makes no partial schema changes.

## DirectAdmin and `public_html`

Point the domain document root at `<release>/public` whenever the panel permits.
Otherwise use a `public_html` symlink to that directory. If symlinks are forbidden,
deploy only the contents intended for `public/` to `public_html` and use a small,
release-generated front controller with absolute paths outside `public_html`.
That layout must be tested on the host and regenerated per release; source,
`.env`, `storage`, backups, and `vendor` must remain outside the web root.

Apache must route nonexistent files to the front controller, deny directory
listing/dotfiles, and preserve authorization headers only as required. HTTPS is
mandatory in production. Panel-managed PHP CLI and web versions must match the
application constraint.

## Filesystem and persistent state

Recommended layout:

```text
~/apps/seo-tracker/
  releases/<release-id>/    # immutable application source and vendor bundle
  current -> releases/...   # active release
  shared/.env               # secrets/deployment configuration, mode 0600/0640
  shared/storage/           # logs, sessions/cache if file-backed, private exports
  shared/public-uploads/    # only if a future feature explicitly needs uploads
~/domains/example/public_html -> ~/apps/seo-tracker/current/public
```

Link shared paths into each release before activation. The web/CLI account writes
only shared runtime directories and `bootstrap/cache`; release source otherwise
remains read-only. User data includes the database, encrypted credentials,
uploads/exports, installation lock, and operational state. It is never placed in
release archives or deleted during deployment.

## Environment contract

Future `.env.example` must document application URL/environment/debug flag,
encryption key, database, log, cache/session/queue, mail, trusted proxy/host, and
optional provider configuration. Production debug is false. Generate keys using
application tooling; never ship a common key or accept empty/default credentials.
Validate configuration before migrations and redact it from diagnostic output.

## Completion and verification

Installation is complete only after schema/version records and install lock are
durable. Perform a local health check, database read/write check that cleans up
after itself, cache/queue check if configured, scheduler setup verification, and
permissions/web-root exposure check. Create the first administrator through a
one-time secure flow, not a seeded password. Remove the bootstrap token and retain
an install report containing versions and checks but no secrets.

## Rank worker cron

After a Rank Tracking adapter is separately approved, registered, and configured,
schedule `php /path/to/bin/console rank:work --limit=10` once per minute in
DirectAdmin cron. Leave this cron absent while `RANK_ADAPTER` is blank; rank
submission is then explicitly unavailable rather than queued indefinitely.
# Optional Search Console OAuth

Phase 13 requires PHP `ext-openssl`. To enable Google Search Console connections, set
the client ID, client secret, exact HTTPS redirect URI, and a separately generated
base64-encoded 32-byte `SEARCH_CONSOLE_ENCRYPTION_KEY` in the deployment environment.
For example, generate the key outside Git with `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`.
Register the exact configured redirect URI in Google Cloud. Never paste credentials or
the generated key into tracked configuration files.

For Phase 14 manual syncs, configure DirectAdmin cron to run the release's PHP CLI with
`bin/console search-console:work --limit=3` at a suitable interval. Do not expose this
command through HTTP. The application database account needs ordinary DML access to the
module queue/data tables; the update process must first apply schema version 11.
