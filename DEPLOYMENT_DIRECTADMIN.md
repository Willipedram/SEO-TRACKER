# DirectAdmin and Standard PHP Hosting Deployment

This is the supported deployment runbook for application **2.4.0**, schema
**14**. It assumes a standard DirectAdmin account with Apache, PHP Selector, MySQL/
MariaDB, File Manager, cron, and optionally SSH. It does not require Docker, root,
Supervisor, systemd, Redis, or a persistent daemon.

## 1. Requirements

### PHP

- PHP 8.1 or newer for both the domain and the DirectAdmin cron/CLI binary.
- Extensions: `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, and `session`.
- HTTPS stream access (`allow_url_fopen=On`) is required only when the optional Search
  Console module makes Google API requests. The implementation does not require cURL.
- Recommended: `memory_limit=256M` (minimum deployment check: 128M),
  `max_execution_time=60`, `post_max_size=8M`, `upload_max_filesize=2M`,
  `display_errors=Off`, `log_errors=On`, OPcache enabled, and server timezone UTC.
  Rank and Search Console jobs are bounded CLI invocations; their CLI time limit should
  be at least 120 seconds. Adjust limits only after observing real provider workloads.

Select the same PHP major/minor version in **Account Manager → Domain Setup/PHP
Version Selector** and for cron. DirectAdmin hosts often expose versioned CLI paths
such as `/usr/local/php82/bin/php`; confirm with the host rather than guessing.

### Apache

Apache must allow `.htaccess` overrides and provide `mod_rewrite`. `mod_headers` is
recommended and `mod_alias` supplies an additional deny layer in the compatibility
layout. The public entry point is `public/index.php`; pretty routes rewrite only
nonexistent files/directories to it. Directory indexes and dotfile access are denied.

Run these after deployment from another machine and expect denial, never PHP/source:

```bash
curl -I https://tracker.example/app/Core/Application.php
curl -I https://tracker.example/.env
curl -I https://tracker.example/storage/logs/application.log
curl -I https://tracker.example/composer.json
```

Acceptable denial responses are 403 or 404. A 200 response is a deployment blocker.

### MySQL/MariaDB

Create a dedicated database and user in **Account Manager → MySQL Management**. Panel
names may be account-prefixed; copy the exact displayed names. Use `utf8mb4` and a
Unicode collation such as `utf8mb4_unicode_ci`. Do not share the database with another
application and do not use a table prefix.

The install/update identity needs `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`,
`ALTER`, `INDEX`, `REFERENCES`, and `DROP` on this database because forward migrations
may require DDL. It needs no global, file, process, replication, shutdown, grant, or
user-management privilege. The current application uses the same configured identity
at runtime, so DirectAdmin users must retain the schema privileges needed for future
updates. Use the panel backup facility or `mysqldump` before every source replacement.

## 2. Choose one web-root model

### Model A — private release with `public/` document root (preferred)

Keep source outside `public_html`:

```text
~/apps/seo-tracker/releases/2.4.0/  application release
~/apps/seo-tracker/current -> releases/2.4.0
~/apps/seo-tracker/shared/.env
~/apps/seo-tracker/shared/storage/
~/domains/tracker.example/public_html -> ~/apps/seo-tracker/current/public
```

In DirectAdmin File Manager, rename the original `public_html` only after confirming
the panel permits a symlink, or ask the host/reseller to set the domain DocumentRoot to
`.../current/public`. Link/copy the shared `.env` and runtime directories into the
release before switching `current`. Do not copy source or secrets into `public/`.

If symlinks are prohibited but files may live above the web root, place the release in
a private directory, copy only `public/` contents into `public_html`, and have the host
provide a front controller with the correct absolute private bootstrap path. The
tracked `public/index.php` assumes its normal release relationship and must not be
edited ad hoc without testing the resulting release procedure.

### Model B — extract the complete release into `public_html` (compatibility)

This repository also provides root `index.php` and root `.htaccess` for shared hosts
that cannot change DocumentRoot. Upload a verified release ZIP, extract it into an
empty `public_html`, and ensure hidden files were extracted. This model is acceptable
only after proving that Apache honors `.htaccess` and the four denial probes above.
The root rules deny application/configuration/database/source directories, dotfiles,
logs, documentation, archives, Composer files, and backups, then route through the
root compatibility front controller. If `mod_rewrite`/overrides are unavailable or a
sensitive probe returns 200, stop and use Model A with hosting support.

Never keep the uploaded release ZIP, database dump, `.env` backup, or old source tree
under `public_html` after extraction.

## 3. Upload and install

1. Build a release on a trusted checkout with `php bin/build-release`, or download the
   published release, and verify its `.sha256` checksum. Do
   not deploy arbitrary branch archives or an unverified ZIP containing executable
   migrations.
2. Create the database/user and a full account/database backup point.
3. Upload and extract using one of the web-root models. Preserve `.htaccess` files.
4. Set permissions as described below. Initially the PHP user must be able to create
   the release-root `.env`; after installation remove that broad write permission.
5. Browse to the final HTTPS domain. An uninstalled instance redirects to `/install`.
6. **Environment:** resolve every PHP extension/writable-path failure.
7. **Database:** enter the exact DirectAdmin database host, port, database, username,
   and password. The wizard accepts only an empty database and never cleans unrelated
   or existing application tables.
8. **Administrator:** create the first administrator with a unique password of at
   least 12 characters. The installer installs the baseline plus all migrations,
   writes `.env` atomically with mode 0600, creates `storage/installed.lock`, and hides
   `/install` after durable database installation state exists.
9. Inspect `.env` outside the web root where possible. Replace/generated values as
   needed, then run the deployment check from SSH or DirectAdmin Terminal:

```bash
/path/to/php /home/USER/apps/seo-tracker/current/bin/console deploy:check
/path/to/php /home/USER/apps/seo-tracker/current/bin/console app:check
/path/to/php /home/USER/apps/seo-tracker/current/bin/console update:status
```

`deploy:check` prints names/status only and never prints secret values. Resolve every
failed `error` check before opening traffic. A memory recommendation may be a warning.

## 4. Environment and secrets

Use a non-public `.env` linked into the release, or the installer-created mode-0600
file when Model B is unavoidable. Never paste secrets into Git, module settings, URLs,
cron command arguments, screenshots, support tickets, or web-accessible `.user.ini`.

Required production values include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tracker.example
APP_KEY=<at-least-32-random-characters>
APP_TRUSTED_HOSTS=tracker.example
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<directadmin-database-name>
DB_USERNAME=<directadmin-database-user>
DB_PASSWORD=<unique-database-password>
DB_CHARSET=utf8mb4
SESSION_SECURE=true
SESSION_SAME_SITE=Lax
```

Generate an application key without placing the value in command arguments or normal
shell output, then copy the file's value through DirectAdmin's protected editor:

```bash
umask 077
php -r 'file_put_contents(getenv("HOME")."/.seo-app-key.tmp", "base64:".base64_encode(random_bytes(32)).PHP_EOL);'
```

Delete the temporary file after updating `.env`. Back up the key securely: changing it
invalidates security-derived identifiers.

Search Console is optional. Leave its values empty and disable the module when unused.
When enabled, store the Google client ID/secret and exact HTTPS callback URI only in
`.env`. Generate `SEARCH_CONSOLE_ENCRYPTION_KEY` as base64 of exactly 32 random bytes
using the same protected-file pattern (without the `base64:` prefix); loss of this key
makes stored OAuth tokens unreadable. Register the exact callback:

```text
https://tracker.example/websites/search-console/callback
```

The Google password is never collected. Do not confuse an API key with OAuth client
credentials.

## 5. Permissions

Typical same-account DirectAdmin permissions:

- directories: `0750` (or `0755` only where Apache traversal requires it);
- source/configuration files: `0640` or `0644` when Apache/PHP runs as the account;
- `.env`: `0600`, or `0640` only with a dedicated trusted web-process group;
- `storage/`, its framework/log subdirectories, and `bootstrap/cache`: writable by the
  PHP/cron identity, normally `0750` or `0770` with a controlled group;
- release source: read-only after activation; `0550`/`0640` where host ownership allows;
- `bin/console`: `0750` is sufficient, or invoke it explicitly through PHP.

Never recursively apply `0777`, never make `.env` world-readable, and never run cron as
another account that creates files the web PHP identity cannot read/write.

## 6. Cron and database-backed queues

The queue is the database and remains authoritative. DirectAdmin cron merely starts
short, bounded workers; jobs retain pending/running/retry/failed state, transactional
claims, leases, capped retry, and error records. Cron does not replace queue semantics,
and normal web requests do not execute slow provider work.

Create these **once per minute** in **Advanced Features → Cron Jobs**, using absolute
paths and the same PHP version as the website:

```cron
* * * * * /usr/local/php82/bin/php /home/USER/apps/seo-tracker/current/bin/console rank:work --limit=10 >> /home/USER/apps/seo-tracker/shared/storage/logs/cron-rank.log 2>&1
* * * * * /usr/local/php82/bin/php /home/USER/apps/seo-tracker/current/bin/console search-console:work --limit=3 >> /home/USER/apps/seo-tracker/shared/storage/logs/cron-search-console.log 2>&1
```

Do not install the rank cron while `RANK_ADAPTER` is blank. Do not install the Search
Console cron while that optional module is disabled. Database compare-and-update claims
and leases protect jobs if invocations overlap; if the host provides `flock`, an outer
nonblocking lock may reduce redundant PHP starts but is not the correctness mechanism.
If cron is missed, jobs remain pending until the next run. A one-minute minimum means
shared hosting provides minute-scale, not real-time, queue latency. Configure panel log
rotation/retention and alert on repeated failed jobs or a growing pending queue.

## 7. TLS and OAuth

Issue a valid certificate in **Account Manager → SSL Certificates** (for example via
Let's Encrypt), force HTTP to HTTPS at the domain level, then set `APP_URL` and OAuth
redirects to HTTPS. Keep `SESSION_SECURE=true`; never work around callback/cookie issues
by disabling Secure cookies. Verify certificate renewal and ensure a reverse proxy, if
present, conveys HTTPS accurately to PHP. HSTS is emitted only for requests observed as
HTTPS.

## 8. Backups and restore

Back up together:

1. the complete MySQL/MariaDB database;
2. `.env`, especially `APP_KEY`, database credentials, OAuth client secret, and Search
   Console encryption key, into an encrypted access-controlled secret backup;
3. shared `storage/`, including installation lock, protected logs needed for audit, and
   any future private exports/uploads;
4. the active release identifier/checksum and cron definitions.

Do not place backups under the web root. Test restoration to a private staging domain:
restore database and matching secrets/storage, deploy a compatible release, update
`APP_URL`/trusted host/OAuth callback for staging, and run checks. A database backup
without the encryption/app keys is not a complete recoverable backup.

## 9. Source replacement and update

1. Read release notes and verify checksum, PHP/extensions, and schema upgrade path.
2. Pause the two cron entries and avoid submitting new jobs. Allow bounded workers to
   finish; do not kill them during database writes.
3. Back up and verify the database, `.env`, shared storage, cron definitions, and active
   release checksum.
4. Extract the new release into a new private directory. Never overwrite the running
   tree file-by-file when release/symlink deployment is available.
5. Link/copy the same protected `.env` and shared storage; apply safe permissions.
6. Run `deploy:check` and `update:status`. For CLI update, run `update:run --force` only
   after confirming the backup. In browser-compatible hosting, opening `/` detects the
   existing database and redirects to `/update`; authenticate as an administrator and
   run the displayed forward migration plan.
7. Run `app:check`, `update:status`, route/module smoke checks, login, Website/Keyword
   reads, and queue status checks. The installed schema/source markers must match.
8. Atomically switch `current` or perform the documented maintenance rename, re-enable
   cron, submit one controlled job when adapters are configured, and monitor logs.

The installer must never be used for an existing database. Source downgrade is refused.
Code rollback is safe only if the old source declares compatibility with the migrated
schema; otherwise restore the database, secrets/storage, and old release together.

Detailed backup commands, a step-by-step restore rehearsal, log retention, performance
findings, and the non-destructive rollback decision are in
[`PRODUCTION_READINESS.md`](PRODUCTION_READINESS.md). A backup is not accepted as a
release gate until it has been restored and checked on a private staging target.

## 10. Host-specific validation still required

Local validation proves PHP/config rules, CLI preflight behavior, rewrite-file presence,
test coverage, and denial-rule contents. It cannot prove a particular DirectAdmin host's
DocumentRoot controls, `AllowOverride`, enabled Apache modules, symlink policy, CLI PHP
path/version, outbound HTTPS policy, cron reliability, MySQL/MariaDB version/privileges,
TLS termination, backup restoration, or filesystem ownership. Validate those items on
the target account before production traffic.
