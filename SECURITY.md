# Security Foundations

This is the Phase 01 threat-control baseline, not a claim that controls are already
implemented. Security-sensitive functionality requires focused review and tests in
its implementation phase.

## Trust boundaries and threats

Untrusted inputs include HTTP requests, uploaded/imported data, rank-provider and
Google responses, OAuth redirects, webhooks, queue payloads, environment values,
and update packages. Valuable assets include user identities, authorization data,
site/keyword business data, OAuth tokens, application encryption key, database,
backups, logs, exports, and update trust keys. Primary threats are account takeover,
broken object authorization, injection, XSS/CSRF/SSRF, credential leakage, OAuth
mix-up/state replay, malicious updates, provider abuse, and tenant data crossover.

## Baseline controls

- Enforce HTTPS, secure/HTTP-only/SameSite cookies, session rotation at privilege
  changes, inactivity/absolute expiry, CSRF tokens for state-changing browser
  requests, and origin checks where appropriate.
- Hash passwords with the framework's adaptive Argon2id/bcrypt configuration;
  never encrypt or log passwords. Rate-limit login, recovery, OAuth initiation,
  expensive reports, rank checks, installer, and updater independently.
- Apply deny-by-default authorization in policies/use cases and scope every object
  lookup to the current actor/account. UI hiding is not authorization. Privileged
  actions require recent authentication where risk warrants it.
- Validate shape and business rules with allow-lists; use parameterized Eloquent/
  query-builder operations. Escape Blade output by default and sanitize any
  explicitly supported rich content. Establish a restrictive, tested CSP and
  standard security headers without assuming headers alone prevent XSS.
- Treat provider URLs and result URLs as data. Any server-side fetch uses allowed
  schemes/hosts, DNS/IP checks resistant to rebinding, redirect limits, private/
  link-local/metadata address denial, timeouts, response-size limits, and no
  ambient credentials.
- Use least-privilege DB/provider scopes, per-environment credentials, explicit
  timeouts/retries/circuit limits, dependency lock files and automated audits.
- Production errors are generic and carry correlation IDs. Logs and audits use
  allow-listed metadata and redact authorization headers, cookies, tokens,
  passwords, database URLs, keys, and sensitive query data.

## OAuth and integration credentials

Search Console is optional and cannot weaken Core boot. Later OAuth implementation
must use authorization code flow, exact registered redirect URIs, cryptographically
random single-use expiring `state` bound to initiating session/user, PKCE where
supported, issuer/provider validation, minimal scopes, and explicit account link
confirmation. Token refresh must be concurrency-safe. Revocation/disconnect clears
credentials and queued access.

Refresh/access tokens are envelope-encrypted at rest with authenticated encryption;
the master key is host-managed outside source and database. Records retain key
version and minimal expiry/scope metadata. Tokens never enter URLs, queues, client
storage, audit metadata, exception messages, or ordinary logs.

## Installer, updater, and filesystem

Only `public/` is web-accessible. `.env`, source, storage, VCS metadata, dependency
manifests, logs, exports, backups, and installer state are outside it or explicitly
denied. Use least permissions and never `0777`. Installer lock and database state
prevent reinstall. Updates accept only authenticated signed releases, run under an
exclusive lock, audit outcome, and follow `UPDATE.md` recovery rules.

Uploads, if later introduced, require authorization, size/type/content validation,
random server names, non-executable private storage, and controlled download
responses. CSV exports require spreadsheet-formula injection protection.

## Data protection and operations

Collect the minimum data needed, document purpose and retention before collection,
and support deletion/anonymization consistent with audit/legal requirements.
Encrypt transport and backups, restrict backup/log/export access, test restores,
rotate credentials, patch dependencies, monitor authentication/authorization and
update events, and maintain an incident procedure. Avoid raw IP/user-agent retention
unless justified; apply truncation/hashing and retention where sufficient.

Security tests must cover cross-user object access, permission denial, CSRF, XSS
escaping, SQL injection resistance, SSRF controls, session lifecycle, rate limits,
OAuth state/replay and token redaction, installer lockout, update signature failure,
and accidental public access to sensitive paths.

## Implemented Phase 02 controls

The foundation validates the request host against deployment configuration,
generates bounded correlation IDs, attaches baseline browser security headers,
uses secure/HTTP-only/SameSite session cookie defaults, and rejects unsafe HTTP
methods without a constant-time validated CSRF token. It provides a single HTML
escaping primitive for later presentation code. PDO operations are parameterized,
configuration rejects malformed DSN components, structured log context redacts
secret keys and common embedded credential forms, and production exception
responses contain no technical detail. Proxy trust, CSP nonces, and route-specific CSRF exemptions remain deferred to
their owning phases; authentication and authorization controls are described below.

The Phase 03 web installer is available only while the configured database lacks
the verified application marker. All installer writes use the global CSRF guard;
database and administrator inputs are allow-listed and bounded. Database passwords
are held only in the server-side installation session, written to a mode `0600`
environment file after schema success, cleared from the session, marked as a
sensitive parameter, and never included in user-facing connection errors or logs.
Only a demonstrably empty database can receive the baseline schema. Application
databases are directed to the upgrade path and unknown databases are never changed.

The Phase 04 browser updater is available only for a verified installation with a
pending or incompatible version state. Execution requires CSRF protection plus
fresh verification of an administrator email/password hash from the persistent
database; credentials are not stored or logged, and repeated failures are
temporarily throttled per updater session. CLI execution requires explicit
`--force` and shell access. Migration discovery uses only the fixed release
directory and rejects malformed names/contracts, while update requests cannot
supply code, paths, SQL, versions, or migration identifiers. Technical failures are
redacted in both the protected log and database failure record.

Phase 05 authentication uses adaptive Argon2id/PHP-default password hashes with
verification-time rehashing, generic login failures, disabled-account checks,
account-plus-network temporary throttling, CSRF on login/logout, session ID rotation
on login, idle/absolute expiry, and complete session destruction on logout. Auth
logs contain user IDs or HMAC-derived correlation keys, never passwords, raw email/
network values, reset secrets, or session IDs. Password reset tokens use independent
high-entropy selector/secret values, store only the secret digest, expire, and are
atomically single-use. See `AUTHENTICATION.md` for boundaries and exclusions.

Phase 06 authorization resolves effective permissions from persistent role joins on
every protected operation and excludes disabled users. Management controllers and
their underlying services both enforce capability checks; submitted IDs are bounded,
validated, and resolved server-side. User creation cannot mass-assign roles, and
only `roles.manage` can alter assignments. Self/last-administrator and mandatory
administrator-management invariants prevent common lockout/escalation paths. Every
mutation uses CSRF and records a transactional, allow-listed audit event. See
`RBAC.md`.

Phase 07 website operations require both their data-driven `websites.*` permission
and ownership of the selected row. Random public IDs reduce enumeration and every
lookup still scopes by authenticated owner to prevent IDOR. Origins are normalized
without network access and reject credentials, ports, paths, queries, fragments,
invalid/non-ASCII hosts, and unsupported protocols. Mutations use CSRF-protected
POST requests, explicit fields, parameterized queries, escaped output, transactions,
and allow-listed audit metadata. Archive replaces destructive deletion. See
`WEBSITES.md`.

Phase 08 scopes every keyword lookup through a website selected by opaque ID and
authenticated owner, then requires the corresponding `keywords.*` permission. This
prevents changing keyword or website IDs to cross ownership boundaries. Forms use
the global CSRF guard, explicit field mapping, bounded validation, parameterized SQL,
escaped HTML, fixed local redirects, transactions, and metadata-minimized audits.
Optional target URLs reject non-HTTP(S) schemes, credentials, and fragments and are
never fetched. See `KEYWORDS.md`.

Phase 09 treats rank executors and provider responses as untrusted. Browser page
JavaScript is not a scraper, direct server requests never claim user-IP semantics,
and an agent may receive only short-lived job-scoped capabilities for allowlisted
adapters. Never collect Google passwords, provider cookies, general browsing history,
or expose a generic URL-fetch/proxy interface. Enrollment credentials are revocable;
jobs/results require replay protection, bounded leases, validation, provenance, and
kill switches. See ADR 0012 and `RANK_TRACKING.md`.

IP addresses are personal data in many contexts and are minimized by default. Rank
attempts store network-context classification and a rotating keyed HMAC of the
control-plane-observed agent connection only when needed for abuse correlation. Raw
IP, unbounded user-agent strings, response pages, vendor secrets, and executor tokens
must not enter ordinary logs, queue payloads, URLs, or exports. Exact IP retention is
off by default and requires a documented purpose, legal basis/notice, encryption,
strict access, short retention, and audit. Agent execution is described as its
observed network path, not proof of residential address or physical location.
