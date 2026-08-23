# Authentication Architecture

## Implemented Phase 05 flows

`GET /login` displays the sign-in form. `POST /login` is CSRF-protected, applies
account/network throttling, verifies a normalized email and password, rejects
disabled accounts with the same public error as invalid credentials, regenerates
the session identifier, and stores only the user ID plus authentication/activity
times. `GET /account` demonstrates an authenticated boundary without making any
authorization decision. `POST /logout` is CSRF-protected, clears server-side session
state, expires the cookie, destroys the session, and redirects to login.

Authentication proves identity only. Roles are not loaded into the session and the
authentication layer exposes no permission API. Phase 06 must implement policies
and RBAC independently while consuming the authenticated user identity.

## Passwords and account state

New and rehashed passwords use Argon2id when available, falling back to the PHP
runtime's `PASSWORD_DEFAULT`. Verification uses PHP's password API and successful
login opportunistically rehashes an outdated encoding. Password values are marked
as sensitive parameters and never included in logs, session data, or responses.

`users.disabled_at` is the Phase 05 account-state boundary. Disabled users cannot
log in; a user disabled after login loses authenticated state on the next protected
request. Missing, invalid-password, and disabled-account login responses remain
generic to avoid account enumeration.

## Sessions and throttling

Authentication uses the existing private file-backed PHP session with strict mode,
cookie-only IDs, HttpOnly, configured SameSite, and HTTPS-aware Secure cookies.
Login regenerates and deletes the old session ID. Authenticated sessions have a
30-minute default inactivity timeout and 12-hour absolute timeout. Logout destroys
the server session and expires its cookie.

Failures are stored as HMAC-derived account and direct-network keys. An account is
temporarily limited after five failures in 15 minutes; a network receives a higher
threshold to reduce shared-network denial of service. Success clears only the
account bucket. Old attempts are bounded and cleaned opportunistically. Proxy
addresses are deliberately not trusted until explicit trusted-proxy support exists.

## Password reset foundation

`PasswordResetTokens` issues a 128-bit selector and independent 256-bit secret. The
database stores only the selector and SHA-256 secret digest with user, creation,
expiry, and single-use timestamps. Token comparison is constant-time and atomic
consumption succeeds only once. Issuing a new token revokes prior user tokens.

Email delivery, request/confirm pages, and the final password-changing use case are
not implemented because no mail subsystem exists. A later phase must always return
a generic reset-request response, deliver the raw secret only through an approved
channel, update the password through `PasswordHasher`, and invalidate existing
authenticated sessions.

## Deliberate exclusions

- Remember-me tokens are not implemented: the current schema has no device-token
  rotation/revocation model, and extending session lifetime would be unsafe.
- Authentication does not implement roles, permissions, gates, or RBAC.
- MFA, email verification workflows, session inventories, and global logout remain
  later security capabilities.
