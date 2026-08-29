# Changelog

All notable changes to SEO Tracker are documented here. The project uses semantic
application versions and an independently monotonic database schema version.

## Unreleased — Phase 26

- Persian is now the default locale across fresh installation and all HTTP UI flows.
- Added centralized English-fallback UI localization, Persian terminology metadata,
  accessible English technical tooltips, structural RTL handling, and mixed-direction
  isolation for technical values.
- Added a formal Persian glossary and documented safe Persian normalization and
  canonical date/number policies.

## [2.4.0] - 2026-08-29

### Added

- WordPress-like browser installation wizard with environment checks, empty-database
  validation, administrator creation, protected environment generation, and a durable
  installation marker.
- Forward-only source/database updater with migration planning, locking, failure
  diagnostics, administrator authorization, no-op detection, and tested source
  replacement without database data loss.
- Authentication, session lifecycle controls, login throttling, password reset token
  primitives, data-driven roles/permissions, ownership checks, and audit records.
- Website and keyword management with normalized origins, per-website ownership,
  desktop/mobile tracking configurations, validation, and archival behavior.
- Database-backed Rank Tracking request, attempt, result, retry, lease, and immutable
  history model; provider-neutral adapter boundary; dashboard metrics and responsive
  server-rendered SVG ranking charts.
- Optional Google Search Console module with OAuth state and PKCE, encrypted token
  envelopes, property selection, bounded synchronization jobs, staged upserts,
  dashboards, filters, history, and semantically distinct combined views.
- Website, keyword, ranking, movement, top-position, and Search Console reports with
  pagination, bounded UTF-8 CSV export, and spreadsheet-formula protection.
- Typed system/user/module settings, locked foundational modules, optional-module
  lifecycle management, feature flags, and significant-change auditing.
- English and Persian catalogs, RTL-aware and responsive presentation styles,
  DirectAdmin/Apache deployment tooling, checksummed release ZIP generation, and
  complete install/update/backup/restore operational documentation.

### Changed

- Final release version is `2.4.0`; the database target remains schema `14`.
- Production archives exclude tests, fixture credentials, VCS/editor metadata,
  private environment files, generated runtime data, logs, backups, and nested
  archives.
- Production query indexes cover website/date ranking reports, date-window movement
  reports, and per-user Search Console synchronization throttling.

### Security

- Production mode suppresses exception details while retaining request-correlated,
  redacted operational diagnostics.
- State-changing browser routes require CSRF tokens; sessions use strict cookie-only
  behavior with validated Secure, HttpOnly, SameSite, and lifetime settings.
- Passwords use PHP's supported password API; OAuth credentials are stored as
  AES-256-GCM envelopes whose key remains outside the database.
- Host validation, safe redirects, output escaping, parameterized SQL, RBAC and
  website-level access controls, rate limits, security headers, and sensitive-path
  Apache denial rules are covered by regression tests.

### Database

- Fresh installations apply migrations 1 through 14.
- Schema 14 adds only the evidence-based production query indexes; it does not modify
  or delete business, rank-history, Search Console, OAuth, module, or settings data.
- Migrations are forward-only. Database rollback requires restoration of the verified
  pre-deployment database and matching source/secrets; automatic destructive down
  migrations are not promised.

### Known limitations

- No production rank provider is bundled. Rank execution remains unavailable until an
  approved adapter/client is configured; automated fixtures do not prove live SERP
  collection.
- Live Google OAuth and Search Console synchronization require operator-supplied Google
  credentials and outbound HTTPS. Automated fake-gateway tests are not live Google
  verification.
- MySQL/MariaDB, DirectAdmin/Apache, TLS, cron reliability, and restore timing must be
  validated on the target hosting account; local E2E validation uses SQLite and PHP's
  built-in server.
- The repository has no JavaScript build pipeline. UI validation covers server-rendered
  markup, responsive CSS contracts, RTL selectors, localization parity, and HTTP flows,
  but not a device-browser visual-regression matrix.

## [2.3.0] - 2026-08-27

### Added

- Production-readiness audit, schema 14 query indexes, release-content checks, and a
  destructive local backup/restore rehearsal.

## [2.2.0] - 2026-08-26

### Added

- Genuine two-source update validation preserving representative application data.

## [2.1.0] - 2026-08-25

### Added

- Extracted-release fresh-install validation and deterministic release construction.
