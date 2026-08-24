# Google Search Console module foundation

Current module version: **1.4.0**. Phase 15 adds a stored-data-only website dashboard documented in `SEARCH_CONSOLE_DASHBOARD.md`; Phase 16 adds explicitly labeled comparison views documented in `RANK_SEARCH_CONSOLE_INTEGRATION.md`.

## Phase 12 scope and lifecycle

`SearchConsole` is an optional module at code version 1.2.0. Its source manifest is
loaded only as an isolated administration shell. Persistent enablement is stored in
the `modules` table and defaults to disabled. Disabled means that no OAuth, provider,
property, sync, navigation contribution, or data-query capability is registered.
The administration shell remains available to an authorized administrator so the
module can be inspected or re-enabled.

If its directory is absent, the optional-aware loader records `unavailable` and
continues loading Core and mandatory modules. If its provider throws during
registration, it records `failed` and continues. This isolation is deliberately not
applied to mandatory modules because silently losing mandatory capabilities is unsafe.

Disable is non-destructive: it updates one lifecycle flag and an audit record. It
does not drop connection rows, website data, rank history, or any other persistent
data. Uninstall/purge is a separate future operation.

## Boundaries

Search Console uses only these Core contracts:

- `Module`, `ModuleContext`, and `Router` for isolated registration;
- `ConnectionFactory`/`Database` for parameterized persistence;
- `Authenticator` and `Authorization` for identity and `settings.manage` checks;
- `AuditRecorder`, `Csrf`, `Html`, `Response`, and `Translator` for safe management UI.

The module owns its manifest, lifecycle service, Google configuration view, connection
records, future OAuth adapter implementing `SearchConsoleGateway`, properties, sync
jobs, and imported data. Core, Websites, Keywords, RankTracking, and the primary
dashboard must never import `App\Modules\SearchConsole` classes or query its tables.
Future consumers must use an optional capability/query contract and work when no
provider is registered.

## Configuration and status

Application OAuth values come only from deployment secrets:

- `GOOGLE_SEARCH_CONSOLE_CLIENT_ID`
- `GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET`
- `GOOGLE_SEARCH_CONSOLE_REDIRECT_URI` (absolute HTTPS)

The status page reports only whether the ID/secret is configured; it never renders
their values. Status is `disabled`, `misconfigured`, or `ready`. `ready` means only
that Phase 12 configuration prerequisites are present—not that Google OAuth or API
access has been verified. Invalid Google scope configuration is rejected.

## Persistence

Schema version 9 creates `search_console_connections` with an opaque public ID,
owner, provider subject, state, granted scopes, optional encrypted credential envelope
metadata, expiry, and sanitized error code. Phase 12 creates no credentials and no
connection workflow. A later OAuth phase must encrypt token material with an external
key, store no raw authorization secret, and implement revocation and rotation.

## Phase 13 OAuth connection

An enabled, correctly configured module exposes a website-scoped Search Console
settings page. `search_console.connect` plus website ownership is required throughout.
Connect generates 256-bit state and a 512-bit PKCE verifier, stores only the state
hash plus verifier/user/website/expiry in the server-side session, and redirects only
to Google's fixed authorization endpoint. State is single-use, user/website bound,
constant-time checked, and expires after ten minutes.

The callback consumes state before handling denial/code input. It exchanges the code
at Google's fixed token endpoint, discovers accessible properties through the fixed
Search Console sites endpoint, and stores tokens only inside an AES-256-GCM envelope.
The 32-byte encryption key and key version come from deployment secrets. The envelope
is authenticated with application-specific additional data and records its key version
for rotation; keys never enter the database. Refresh retains Google's existing refresh
token when a refresh response omits it.

Discovered `sc-domain:` and URL-prefix properties remain candidates until the user
explicitly selects one. The OAuth connection context binds candidates to the initiating
website, and selection rechecks user, website, connection, and property ownership.
Disconnect attempts Google revocation, then always clears the encrypted envelope,
expiry, and website mapping without deleting Core data. When discovery returns no
properties or fails after token exchange, best-effort revocation prevents an unused
credential from being retained.

Safe error classes cover denial, invalid/replayed/expired state, invalid callback,
exchange/discovery failures, Google unavailability, and revoked credentials. Provider
responses and exception details are never rendered or logged. No Google password or
manual API key is requested.

## Phase boundary

Phase 13 performs only OAuth, property discovery/selection, refresh, and
disconnect/revocation. Synchronization is implemented separately below.

## Phase 14 synchronization

Phase 14 adds a database-queued manual Search Analytics sync. The worker refreshes
Phase 13 credentials, requests date/query/page/device/country dimensions from Google's
fixed property endpoint, validates bounded provider data, and upserts clicks,
impressions, CTR, and average position through a unique dimension hash. Lifecycle logs,
leases, bounded retry, and safe errors isolate Google failures. See
`SEARCH_CONSOLE_SYNC.md` and ADR 0016. Automatic schedules and analytics visualization
remain outside this phase.
