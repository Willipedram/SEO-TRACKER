# ADR 0015: Google OAuth with PKCE and authenticated token envelopes

- Status: Accepted
- Date: 2026-08-23

## Context

Search Console needs delegated Google access on conventional PHP hosting. Callback
requests can be forged or replayed, database disclosure must not reveal bearer tokens,
and disconnect must not damage website or rank data. Interactive Google access and
production credentials are unavailable in the build environment.

## Decision

Use Google's authorization-code flow with a 256-bit single-use state and S256 PKCE.
Bind pending state to the authenticated local user and opaque website in the server-side
session for ten minutes; store only the state hash. Use only fixed Google OAuth and
Search Console endpoints with redirects disabled for back-channel calls.

Encrypt access/refresh token JSON using OpenSSL AES-256-GCM with a random 96-bit nonce,
128-bit tag, and fixed additional authenticated data. Load a base64 32-byte key and
version from the environment, never the database. Property discovery occurs after
exchange, but mapping always requires explicit user selection and repeated ownership
checks. Disconnect best-effort revokes at Google then irreversibly clears local tokens
and mapping, even if Google is unavailable.

## Consequences

The application requires `ext-openssl` and secure server-side sessions. Database-only
compromise does not directly disclose tokens, but application-host/key compromise still
does; key access, backup policy, and rotation remain operational responsibilities.
Automated tests use a fake gateway and do not prove live Google behavior. Phase 14 may
use the refresh boundary but cannot bypass ownership or token-vault controls.
