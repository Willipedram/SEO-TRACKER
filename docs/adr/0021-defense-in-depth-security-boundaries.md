# ADR 0021: Enforce security boundaries centrally and fail closed

- Status: Accepted
- Date: 2026-08-25

## Decision

Core owns scheme-aware security headers, production error disclosure, redirect header
validation, session configuration validation, CSRF lifecycle, and recursive log
redaction. Provider modules add stricter origin/path allowlists for their external
redirects and cannot opt out of the Core controls.

Invalid or expired authentication state invalidates the whole session. Production
never displays exception detail even when a debug flag is accidentally enabled.
Unsafe session configuration fails during boot/use instead of silently weakening the
cookie. HSTS is emitted only when the application observed HTTPS.

## Consequences

Optional modules receive uniform protections, and common configuration errors fail
closed. Deployments behind TLS termination must accurately convey HTTPS to the
application. New external redirect destinations need a purpose-specific parsed URL
allowlist rather than a prefix comparison.
