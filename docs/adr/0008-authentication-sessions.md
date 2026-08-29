# ADR 0008: Server sessions and database-backed login throttling

- Status: Accepted
- Date: 2026-08-23

## Context

The application needs identity proof on ordinary PHP hosting before RBAC exists.
Persistent remember tokens would require secure device rotation and revocation,
while IP-only blocking would harm users behind shared hosting or office networks.

## Decision

Use strict, cookie-only server-side PHP sessions with ID rotation at login, idle and
absolute authentication timeouts, database account-state checks, and destruction at
logout. Store only user ID and timestamps in authenticated session state. Throttle
with short-lived HMAC account and direct-network buckets, using a substantially
higher network threshold. Use Argon2id when available and PHP's default otherwise,
with verification-time rehashing. Do not implement remember-me until a rotatable,
hashed device-token design and revocation UI exist.

## Consequences

Authentication works without RBAC or client-side identity tokens and stolen old
session IDs are invalid after login rotation. File sessions require shared storage
if the application later runs on multiple web nodes. Every protected request reads
the user record to enforce disablement; caching must not weaken that invariant.
Network throttling is defense in depth rather than the primary account threshold.
