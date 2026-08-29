# Phase 22 Fresh Install Validation

## Status and exact environment

Validated on 2026-08-27 in a clean temporary extraction using Linux, PHP 8.5.7-dev
CLI/built-in server, PDO SQLite, `zip`/`unzip`, and loopback HTTP. Required PHP runtime
extensions were present. There was no pre-existing `.env`, database, install lock,
session, generated cache, or application log in the extracted release.

A MySQL/MariaDB server was not installed. Installing MariaDB was attempted, but the
environment's package proxy returned HTTP 403 for Ubuntu packages. Consequently, the
production MySQL web-wizard database submission and MySQL DDL path are **BLOCKED in
this environment**, not reported as successful. Existing isolated migration tests and
the SQLite fresh-schema probe do not substitute for that missing live MySQL check.

## Exact scenario performed

1. Built a new distributable ZIP and SHA-256 file from the source using
   `php bin/build-release /tmp/.../seo-tracker.zip`.
2. Extracted it into a new random temporary release directory, not the working tree.
3. Verified required hidden/runtime/front-controller/migration files and confirmed
   `.env`, `.git`, `dist`, and generated logs were absent.
4. Started PHP's HTTP server against the extracted `public/` entry point. Verified `/`
   redirected to `/install`, the installer Environment Check rendered, all environment
   checks passed, and the database step became available.
5. In a separate clean PHP process, created a new empty SQLite database solely because
   MySQL was unavailable; ran `SchemaInstaller` and every forward migration to schema
   13; created the first administrator; and wrote installation state/lock.
6. Exercised `EnvironmentWriter` independently with validated MySQL-shaped settings and
   verified it generated a mode-0600 file containing a random application key, then
   discarded that probe file. The extracted runtime used a new mode-0600 local SQLite
   `.env`; this is test-only and is not claimed as completion of the MySQL wizard.
7. Booted the application from the extracted source, loaded all configured modules,
   verified administrator/RBAC state, authenticated, and queried an empty dashboard.
8. Started a second loopback HTTP host against the installed extracted release. Through
   real GET/POST requests with cookies and CSRF tokens, logged in, loaded the account,
   created a Website, loaded its dashboard, created a Mobile Keyword, and listed it.
9. Submitted a Rank Check through HTTP. The release correctly returned the explicit
   `No approved Rank Tracking adapter is configured` error because no Phase 09-approved
   provider/local agent is installed. Verified the empty Rank Dashboard remained usable.
10. Verified Search Console was disabled after fresh migration and its route degraded
    without a Core failure. Verified `/install` returned 404 after installation.
11. Wrote a protected log event containing a sentinel refresh token and verified the
    sentinel was redacted.
12. Removed the entire temporary release, database, configuration, sessions, archives,
    and logs after assertions.

The repeatable implementation of this scenario is
`tests/E2E/FreshInstallE2ETest.php`; it deliberately executes the extracted release in
a child process and over loopback HTTP rather than reusing development database state.

## Defects found and fixed

1. **Guest login sessions were destroyed before CSRF persistence.**
   `Authenticator::user()` treated the normal absence of an `auth` key as malformed
   authentication and invalidated the entire new session. Login forms therefore issued
   a CSRF value that could not survive to POST, causing HTTP 419. Guest lookup now
   returns `null` without invalidation; malformed/expired authentication arrays still
   invalidate. A focused regression test preserves guest form state.
2. **The country allowlist was corrupted/truncated.**
   The deployed configuration contained literal truncation text, produced invalid
   two-byte entries, and omitted `US`, causing valid fresh Keyword creation to fail.
   It is replaced with the complete 249-entry ISO 3166-1 alpha-2 allowlist. Existing
   keyword validation continues to require allowlist membership.
3. **Keyword edit links emitted a raw query separator in HTML.**
   The generated link now uses `&amp;`, and the E2E browser response assertion validates
   the resulting link before editing/submitting the Rank Check.
4. **No reproducible distributable builder existed.**
   `bin/build-release` now builds a ZIP plus SHA-256, preserves required hidden/runtime
   placeholders and executable modes, and excludes `.env`, Git metadata, prior `dist`,
   generated logs/sessions/cache/views, and other private runtime state.

## External integration truth

- **Rank execution:** live execution is BLOCKED. No approved provider, browser extension,
  or local agent and no provider credentials are available. Only the real application's
  explicit no-adapter behavior and empty dashboards were E2E verified. Automated
  adapter fixtures elsewhere remain non-live tests.
- **Google OAuth/Search Console:** live connection and sync are BLOCKED because no Google
  OAuth credentials or interactive consent/browser account are available. Fresh-install
  optional-module disabled behavior was verified; no live Google claim is made.
- **DirectAdmin/Apache/MySQL:** DirectAdmin and Apache are absent, and MariaDB package
  download was blocked. PHP front-controller HTTP behavior was verified locally;
  `.htaccess`, panel permissions/cron/TLS, and MySQL must still be executed on staging.
