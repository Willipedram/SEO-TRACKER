# Phase 19 Security Review

**Status:** complete for the repository implementation reviewed on 2026-08-25. No
known exploitable critical or high-risk issue remains in the audited code. This is a
point-in-time application review, not a guarantee about host configuration or future
provider behavior.

## Scope and method

The review traced authentication and password reset, account-state enforcement,
sessions, all router registrations and state-changing actions, RBAC and owner-scoped
queries, HTML/attribute/URL rendering, SQL construction, HTTP clients, OAuth state and
tokens, installer/update filesystem paths, logging, stored network metadata, error
rendering, Apache deny rules, Composer metadata, and migrations. Repository and Git
history were scanned for common credential patterns without displaying any candidate
value. No upload feature or user-controlled download path exists in this release.

## Findings remediated

1. **Incomplete structured-log redaction (medium).** Exact-name matching could retain
   composite keys such as refresh tokens, encrypted credential envelopes, or session
   identifiers. Recursive key matching and bearer/message redaction now cover these
   credential classes. Regression tests prove sentinel values do not reach logs.
2. **OAuth redirect prefix validation (medium, defense in depth).** A textual prefix
   check did not itself establish an origin boundary. Redirects now require parsed
   HTTPS scheme, exact `accounts.google.com` host, approved OAuth path, no userinfo,
   allowed port, bounded length, and no control characters. The gateway continues to
   generate a fixed endpoint and disable redirects for back-channel requests.
3. **Production debug configuration footgun (medium).** Production mode now suppresses
   exception details regardless of an accidentally true debug flag. Correlation IDs
   remain visible and details remain in protected logs.
4. **Expired/malformed session cleanup (low).** Invalid authentication state now
   invalidates the complete session rather than deleting one key. Login already
   regenerates the session ID and now also rotates the CSRF token.
5. **Header/configuration defense in depth (low).** CSP has explicit script/style/
   image/connect/object policies, CORP is same-origin, and HSTS is emitted only over
   HTTPS. Session lifetime and SameSite settings now fail closed when unsafe.
6. **Redirect header injection defense (low).** Response redirects reject control
   characters and unreasonable length before constructing a Location header.

## Verified existing controls

- Passwords use the platform password API (Argon2id when available), verification is
  timing-resistant at the API boundary, unknown accounts use a dummy hash, and hashes
  are upgraded after successful verification. Login throttling combines HMAC-derived
  account/network buckets and returns generic failures for missing/disabled accounts.
- Reset secrets are random, stored as hashes, expiring, and single-use. Authentication
  checks active account state on every session lookup.
- Browser mutations are covered by the central CSRF gate. Service-layer permissions
  and owner joins provide defense against direct endpoint access and IDOR/BOLA.
- Database values use prepared statements. Dynamic identifiers and sort fragments are
  fixed by code or allowlists; no user-controlled SQL identifier was found.
- Search Console HTTP destinations are constants, redirects are disabled, tokens use
  authenticated encryption at rest, OAuth state is hashed, expiring, single-use and
  user-bound, and disconnect clears local credentials even if revocation fails.
- There is no file upload surface. Installer and update paths are application-owned,
  canonical/basename constrained where relevant, and web-server rules deny source,
  configuration, storage, migrations and dotfiles.
- Rank and sync execution have bounded queue/rate/retry policies. Raw IP storage is
  disabled by default; login audit networks are HMAC-derived and untrusted forwarding
  headers are not accepted as client identity.

## Dependencies and residual risk

The lock file contains no third-party packages, so there was no package advisory
surface for Composer to report. PHP, OpenSSL, PDO/database server, Apache, and the host
OS remain deployment dependencies and must receive vendor security updates. HSTS only
helps after a secure visit; production must enforce HTTPS at the proxy/web server and
set `SESSION_SECURE=true`. A reverse proxy must not rewrite an untrusted client header
into `REMOTE_ADDR`. Rate-limit tables require operational pruning/monitoring at scale.
Live Google OAuth/API behavior was not exercised without deployment credentials and
interactive consent; mocked integration tests validate application boundaries only.
No automated SAST engine is bundled, so syntax checks, focused source searches, tests,
Composer validation/audit, and manual data-flow review form this phase's evidence.
