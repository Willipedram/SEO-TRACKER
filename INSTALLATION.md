# Installation Wizard and Hosting Contract

Application **2.4.0** targets database schema **14** and supports PHP 8.2+ with
MySQL/MariaDB. The complete DirectAdmin upload, web-root, PHP, Apache, permissions,
secrets, TLS, cron, backup, and update runbook is `DEPLOYMENT_DIRECTADMIN.md`.

## Supported installation modes

### Web wizard (standard shared-host path)

Open the final HTTPS domain after deploying a verified release. An uninstalled
instance redirects to `/install`:

1. **Environment check** verifies PHP 8.2+, `json`, `mbstring`, `openssl`, `pdo`,
   `pdo_mysql`, `session`, writable runtime paths, and ability to create `.env`.
2. **Database** accepts the DirectAdmin MySQL/MariaDB host, port, exact panel-prefixed
   database/user names, and password. It proceeds only with an empty database. An
   unknown database or an existing SEO Tracker installation is not modified.
3. **Administrator** creates the first administrator and application name. Passwords
   require at least 12 characters.

The wizard installs the baseline, runs every migration through schema 14, writes `.env`
atomically with mode 0600, creates `storage/installed.lock`, and regenerates the
installation session. Once durable database state or the lock exists, `/install`
returns 404. Deleting a browser cookie does not re-enable installation.

The application does not support table prefixes. Use a dedicated empty database. The
installer never drops unrelated tables and never displays/logs the database password.

### CLI-assisted deployment

SSH/DirectAdmin Terminal is recommended for preflight and updates but the first-user
schema installer is currently the web wizard. After installation run:

```bash
php bin/console deploy:check
php bin/console app:check
php bin/console update:status
```

The deployment check validates production configuration without printing values. Do
not use `update:run --force` for a clean database; it is for an installed application
after a verified backup.

## Web-root choices

The preferred DocumentRoot is the release's `public/` directory, normally exposed in
DirectAdmin by a custom DocumentRoot or a `public_html` symlink. Only `public/index.php`,
`public/.htaccess`, and public assets are web-visible.

If the host only allows full ZIP extraction into `public_html`, the tracked root
`index.php` and `.htaccess` provide a compatibility layout. That layout is supported
only when Apache honors `.htaccess`, `mod_rewrite` is enabled, and live HTTP probes
confirm that `.env`, `app/`, `config/`, `database/`, `storage/`, tests, Composer files,
archives, logs, and backups return 403/404. The wizard's environment check cannot prove
web-server deny rules.

## Filesystem state

Only these runtime locations need PHP/cron write access:

- `storage/logs`
- `storage/framework/cache`
- `storage/framework/sessions`
- `storage/framework/views`
- `bootstrap/cache`
- release root temporarily while the wizard creates `.env`

Use 0750/0770 directories with controlled ownership, ordinary read-only source files,
and `.env` mode 0600 or carefully grouped 0640. Never use blanket 0777. After install,
remove release-root write access where the hosting layout permits it. Persist and back
up `.env`, `storage/`, `storage/installed.lock`, and the database across releases.

## Queue activation

Do not configure Rank Tracking cron until an approved `RANK_ADAPTER` exists. Do not
configure Search Console cron until the optional module and OAuth secrets are ready.
DirectAdmin cron runs bounded database-backed workers; it is not a substitute for queue
state/leases/retries. Exact commands and operational limitations are in
`DEPLOYMENT_DIRECTADMIN.md`.

## Completion criteria

Installation is complete only when:

- HTTPS and Secure cookies are active;
- `deploy:check`, `app:check`, and `update:status` pass;
- the install URL is hidden and source/secret HTTP probes are denied;
- login and owner-scoped Website/Keyword reads work;
- database and protected secret/storage backups are captured and restore-tested;
- enabled queue cron entries use the same PHP version and absolute release paths.
