# Database Architecture

## Baseline

The target relational store is MySQL/MariaDB using InnoDB, foreign keys,
`utf8mb4`, strict mode, and UTC. Exact supported engine versions are deliberately
not claimed until Phase 02 establishes a CI compatibility matrix. Use `BIGINT
UNSIGNED` internal keys consistently unless measurement supports UUID/ULID keys;
public URLs should use non-sequential opaque identifiers where enumeration is a
risk. Monetary/rank numeric data uses exact integer/decimal types, never floats
without an explicit analytical reason.

All tables have explicit indexes based on access paths. Foreign-key delete
behavior is declared, not left to engine defaults. Mutable records normally have
`created_at`/`updated_at`; optional soft deletion is used only where restoration
or retention requires it. Store instants in UTC and retain user/site timezone as
separate configuration. JSON is reserved for genuinely variable provider payload
fragments, not core fields that need constraints or indexes.

## Logical schema

Names and columns below are an architectural starting point; migrations remain
the schema authority.

Phase 03 installs baseline tables for `users`, `roles`, `user_roles`, `settings`,
`modules`, `migrations`, and `app_installations`. The last table carries the stable
application identifier and schema version used to distinguish an installation
from unrelated data. Permissions and all business/optional module tables remain
owned by their later implementation migrations.

Phase 04 schema version 2 adds `app_installations.source_version` and successful
migration status/start/completion timestamps. `migration_failures` records the
failed migration ID, intended schema version, exception class, redacted diagnostic,
and failure time independently of the successful migration ledger. A successful
retry deletes the corresponding failure state. Neither the tracking migration nor
the runner deletes domain or user data.

Phase 05 schema version 3 adds nullable `users.disabled_at`, bounded
`auth_login_attempts` keyed by HMAC identifiers rather than plaintext email/IP, and
`password_reset_tokens` containing a public selector, secret digest, expiry, and
single-use timestamp. Raw reset secrets, passwords, and session IDs are never stored
in these tables.

Phase 06 schema version 4 adds `permissions`, `role_permissions`, and append-only-
intent `audit_logs`; the pre-existing `roles` and `user_roles` tables already model
multiple roles per user. Unique keys and composite primary keys prevent duplicate
definitions/assignments, while foreign keys clean up role/permission joins. The
administrator role is idempotently assigned every initial permission.

Phase 07 schema version 5 adds `websites`. Each row has a random opaque public ID,
immutable owner, normalized domain, canonical HTTP(S) origin, protocol, description,
IANA timezone, lifecycle status, and archive timestamp. `(owner_user_id,
normalized_domain)` and public IDs are unique; owner/status is indexed.

### Identity and access

- `users`: login/profile state, verified/disabled timestamps, password hash where
  local authentication is enabled; email has a normalized unique constraint.
- `roles`: stable key and display metadata; role keys are unique.
- `permissions`: namespaced immutable key and description; key is unique.
- `user_roles`: unique `(user_id, role_id)` association, assignment metadata.
- `role_permissions`: unique `(role_id, permission_id)` association.

Authentication sessions, password-reset tokens, OAuth state, and remember tokens
use framework-supported stores and expiry/indexing. Provider access/refresh tokens
are never stored in plaintext.

### Websites and keywords

- `websites`: immutable owner reference, canonical origin, normalized host, timezone,
  lifecycle state, and opaque public ID. Phase 07 selects owner-scoped domain
  uniqueness and owner-only access; see `WEBSITES.md`.
- `website_user` only if shared website membership is required; otherwise the
  explicit owner reference is sufficient. Do not create speculative join tables.
- `keywords`: website reference, normalized query and original display query,
  locale/country, search engine, active state, and stable identity.
- `keyword_targets`: optional future table only if multiple device/location target
  combinations per keyword are confirmed. Device must be part of the observation
  key even if configuration initially supports desktop/mobile only.

### Rank tracking

- `rank_checks`: one scheduled/executed collection attempt with provider, target,
  status, requested/start/finish timestamps, retry/idempotency key, and sanitized
  error classification. Index scheduling/status fields.
- `rank_results`: immutable observation per check, keyword, device, location,
  search engine, observed timestamp, rank (nullable when not found), result URL,
  SERP feature metadata, and provider provenance. Enforce uniqueness for the
  check/keyword/target identity to make retries safe.

`rank_results` is the canonical history; a duplicate `rank_history` table is not
planned. Dashboard summaries may later use rebuildable aggregate/projection
tables. Partitioning/archive policy is deferred until measured volume justifies
it. Raw provider responses are not retained by default; if required for diagnosis,
store them encrypted or in protected object storage with short retention.

### Optional Search Console

- `search_console_connections`: user/account reference, provider subject,
  encrypted credential envelope, granted scopes, expiry/status timestamps.
- `search_console_properties`: connection and optional website references,
  provider property identifier/type and permission state.
- `search_console_syncs`: property, date range, cursor/checkpoint, status,
  idempotency key, counts and sanitized failure classification.
- `search_console_data`: property, date, dimensions (query/page/country/device/
  appearance as supported), clicks, impressions, exact average-position and CTR
  inputs. Define a deterministic dimension hash/unique key for idempotent upsert.

These tables are owned and migrated only by the optional module. Core tables do
not reference them. Connection deletion/revocation must schedule credential and
retained-data cleanup according to policy.

### Platform data

- `settings`: scope, namespaced key, typed value, and version; unique by scope/key.
  Secrets do not belong here unless values are explicitly envelope-encrypted.
- `modules`: module key, installed code/schema version, enabled state, and lifecycle
  timestamps. Filesystem manifests remain the boot-time source of available code.
- `migrations`: framework migration ledger; migration files are authoritative.
- `audit_logs`: append-only-intent actor/type/id, action, target type/opaque ID,
  outcome, correlation ID, IP/user-agent minimization fields, structured redacted
  metadata, and timestamp. No foreign key should make audit history disappear.
- Queue, failed job, cache, lock, and session tables exist only when their database
  drivers are selected and are infrastructure-owned.

## Integrity, privacy, and lifecycle

Database access uses a least-privilege application account; installation/update
may use a separately authorized migration account where hosting permits. Backups
are encrypted, access-controlled, retention-tested, and restored in drills.
Sensitive encrypted columns carry a key version so rotation is possible.

Deletion is a use case, not cascading guesswork: define ownership, legal/audit
retention, anonymization, and provider revocation before implementing user/site
deletion. Analytics exports and logs follow independent documented retention.
Audit metadata is allow-listed to prevent token/password leakage.

## Migration rules

Migrations are forward-only, ordered, transactional when the engine operation
allows, and safe to retry. They must not depend on HTTP state or uncontrolled
network access. Large/destructive changes use expand/migrate/contract across
compatible releases, with backfills in resumable bounded jobs. Every migration is
tested on an empty database and an upgrade fixture for each supported engine.
Rollback means restore the pre-update database and compatible release unless a
specific migration has a proven safe `down`; deploy tooling must never imply that
all DDL is reversible.
