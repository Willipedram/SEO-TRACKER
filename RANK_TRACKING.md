# Rank Tracking Architecture

Phase 16 comparison views live entirely in the optional Search Console module. Rank Tracker observed positions remain independent, immutable observations and are never overwritten by or relabeled as Search Console average position. See `RANK_SEARCH_CONSOLE_INTEGRATION.md`.

## Implemented Phase 10 boundary

Phase 10 implements the control plane, database queue/leases, adapter contract,
request/status/history UI, immutable results, safe retry, and bounded CLI worker. It
does **not** implement a scraper, local agent, browser extension, proxy, or live
provider adapter. Production adapter registration remains empty and `RANK_ADAPTER`
defaults to blank because no adapter has passed ADR 0012's approval gate. Submitting
a check without an approved registered adapter fails explicitly; it never fabricates
a ranking. Automated success/not-found tests use an in-process test adapter and are
not evidence of external provider behavior.

## Execution strategy and client/server model

The PHP application is the control plane. It authenticates and authorizes requests,
creates immutable check requests, selects an eligible execution source, leases work,
validates structured results, and persists attempts/results. Executors are adapters:

- `provider_api`: default approved third-party or official structured provider;
- `local_agent`: opt-in enrolled desktop agent using its current network;
- `browser_extension`: reserved future capability, never inferred from page JS;
- `server_adapter`: only an explicitly approved structured server integration.

`execution_source` is immutable per attempt. Fallback creates a new attempt and never
rewrites the source of an old one. Page JavaScript may submit, cancel where safe, and
poll status; it does not scrape results. Agents outbound-poll HTTPS so DirectAdmin
needs no inbound port and home networks need no port forwarding.

### Agent trust boundary

Future enrollment issues a random device ID and revocable credential bound to the
user. Store only a verifier or encrypted credential envelope, not a reusable secret
in logs. Jobs use short-lived, audience-bound, single-job capabilities and signed
responses including request, attempt, lease, agent, adapter, and payload digest.
Executors receive only the keyword/configuration fields necessary for that attempt.
They may contact only adapter allowlisted destinations and expose no generic fetch,
shell, proxy, browser-cookie, or Google-password interface. Signed releases,
automatic security updates, sandboxing, minimum versions, revocation, and replay
protection are mandatory before agent availability.

## Device model

Keep three separate facts:

1. `requested_device`: keyword configuration (`desktop` or `mobile` initially).
2. `execution_device`: `desktop_native`, `mobile_native`, `desktop_emulated_mobile`,
   `provider_mode`, or another versioned adapter capability.
3. `user_agent_profile`: normalized adapter/profile ID and version, not an
   unbounded raw browser string.

A provider “mobile” parameter and a desktop agent using mobile emulation are recorded
as modeled/emulated mobile, never physical mobile. Physical mobile requires a native
mobile executor and records `mobile_native`. Results remain comparable only within
compatible engine, location, language, device semantics, adapter, and adapter version.

## IP handling model

Server and provider execution do not use the user's network. `X-Forwarded-For`, a
submitted IP, or copying an address into an outbound header does not change egress.
Only an agent/extension request can originate from the user's current network path.
VPN, carrier NAT, Apple/enterprise relay, corporate proxy, or provider routing can
still make observed egress differ from the user's physical or subscriber address.

Store `network_context` (`agent_observed`, `provider_egress`, `server_egress`,
`unknown`) on an attempt. Default persistence is a keyed, rotating HMAC of the
control-plane-observed agent connection address plus address-family and coarse region
when required; it supports abuse correlation without retaining a reusable address.
Do not accept an agent-asserted address as verified. Exact IP storage is off by
default and requires a documented legal purpose, consent/notice where required,
short retention, restricted access, encryption, and audit. Never place raw IPs in
queue payloads, ordinary logs, exports, URLs, or third-party metadata unnecessarily.

## Authorization and request context

Creating/running checks requires `rank_tracking.run`; reading requests, attempts, or
results requires `rank_tracking.view`. Resolve the keyword through its owned website,
as Phase 08 does, before creating a request. An agent is enrolled to one user and can
lease only attempts authorized for that user/device. Server-side authorization is
rechecked at submission, lease, result acceptance, cancellation, and read time.

Logical `rank_check_requests` fields:

- opaque `request_id` (UUIDv7/ULID or 128-bit random; never sequential externally);
- initiating `user_id`, `website_id`, and `keyword_id` internal references;
- immutable engine, country, language, requested device, target URL snapshot;
- requested source policy, priority class, request/correlation ID;
- state, created/eligible/deadline/cancelled/completed timestamps;
- idempotency key scoped to actor and normalized check input.

Do not persist a raw user agent or IP on the request. Network/executor observations
belong to attempts because fallback attempts can have different contexts.

## Queue and lease model

Use a database-backed queue initially because persistent workers are not guaranteed
on Apache/DirectAdmin. One cron entry invokes a bounded CLI worker every minute;
interactive agent polling may claim its eligible jobs immediately. The same queue
contract can later gain Redis or managed-queue adapters.

Claims use an atomic compare-and-set from `queued|retry_wait|awaiting_agent` to
`leased`, with opaque `attempt_id`, random `lease_token` verifier, `leased_by`, lease
expiry, heartbeat deadline, attempt number, and row-version. A unique request/source/
attempt-number constraint and idempotent result key prevent double finalization.
Expired leases are reaped in bounded batches. Database/server time is authoritative.
Agent long polling is bounded; no request depends on a PHP process remaining open.

Suggested states:

`queued -> awaiting_agent -> leased -> running -> succeeded`

Terminal alternatives are `failed`, `expired`, `cancelled`, and `superseded`.
Retryable failures move through `retry_wait` and create a new attempt after delay.
Requests complete only after a validated result is committed.

## Attempt, result, and provenance model

Logical `rank_execution_attempts` fields:

- opaque attempt/request IDs, attempt number, execution source, adapter key/version;
- agent/provider credential reference (never secret), lease metadata, timestamps;
- requested and execution device semantics, normalized user-agent profile;
- network context, rotating IP HMAC/key version, optional coarse region;
- status, provider request ID where safe, result digest/signature/attestation state;
- normalized error code, retryability, safe detail, and diagnostic correlation ID.

Logical immutable `rank_results` fields:

- opaque result/request/attempt IDs and keyword/website snapshot references;
- engine, country, language, requested/execution device and source provenance;
- observed timestamp, rank nullable for `not_found`, result URL when observed;
- checked depth, result type (`ranked`, `not_found`, `challenge`, `unsupported`);
- adapter key/version and normalized evidence metadata needed for audit/replay;
- created timestamp and unique accepted attempt/result identity.

Never turn a challenge, consent page, blocked request, parse error, missing response,
or provider failure into rank “not found.” `not_found` is valid only when the adapter
successfully inspected its declared depth and can support that conclusion. Results
are never silently overwritten; corrections append a superseding record with reason.
Raw provider/browser pages are not stored by default.

## Error model

Errors use stable categories and safe public messages:

- `configuration_invalid`, `adapter_unsupported`, `agent_unavailable`;
- `authentication_failed` (executor/vendor credential, never user Google password);
- `quota_exceeded`, `rate_limited`, `provider_unavailable`, `network_timeout`;
- `challenge_presented`, `consent_required`, `response_invalid`, `parse_failed`;
- `lease_expired`, `result_rejected`, `cancelled`, `internal_error`.

Each error records stage, adapter/version, retryable flag, safe code, occurrence time,
and correlation ID. Secrets, raw IPs, response bodies, proxy/vendor credentials, and
stack traces stay out of public messages and queue payloads. Protected technical logs
use existing redaction and retention controls.

## Retry and idempotency model

Retry only explicitly transient categories. Use capped exponential backoff with full
jitter, provider `Retry-After` when present, and separate maximums per adapter/error.
Do not automatically retry invalid configuration, unsupported adapter, revoked agent,
credential rejection, consent/challenge, result-validation failure, or terms/policy
disablement. A retry is a new immutable attempt under the same request. Late results
from expired/superseded leases are rejected and audited. Manual retry creates a new
request unless recovering the same idempotent submission.

## Rate limiting and scheduling

Enforce layered token buckets/quotas per user, website, keyword configuration,
agent/device, adapter/vendor account, provider/engine, and server egress. Cap global
concurrency and per-cron runtime. Add randomized schedule jitter to avoid synchronized
bursts and coalesce duplicate checks with idempotency keys. Agent limits protect the
user's network; provider limits obey contract quotas and cost budgets. A circuit
breaker pauses unhealthy adapters. Rate-limit responses are errors, never rankings.

Phase 10 implements a bounded per-user submission window, bounded worker batch, lease
duration, and capped attempts. Provider-account/engine quotas and circuit breaking
must be supplied by a future approved adapter because no vendor account exists; an
adapter cannot be registered until those controls are reviewed. The current request
count limiter is a shared-database control, not a promise of exact distributed quota
accounting under untested MySQL concurrency.

## Privacy, retention, and transparency

Before execution, disclose source and truthful IP/device semantics. Let users choose
an available agent or provider path and show when user-network execution is not
available. Provide agent/device revocation and deletion. Define retention separately
for requests, attempts, IP HMACs/coarse region, results, errors, and audit logs. Data
sent to a vendor is documented in the privacy notice/DPA and minimized to query,
market/device configuration, and necessary correlation—not account email or raw IP.

Exact location and raw network identifiers are not prerequisites for rank history.
Access is least privilege, exports preserve provenance, and deletion/anonymization
must retain only what contractual/legal integrity requires.

## Provider/terms gate

Every adapter is disabled by default until it has:

1. a named owner and threat/data-flow review;
2. a current provider/vendor terms review and recorded permitted use;
3. credential, quota, cost, retention, and incident-response configuration;
4. conformance fixtures for success, not-found, challenge, malformed, and rate-limit;
5. truthful device/location/IP labels and monitored accuracy;
6. a kill switch and versioned compatibility declaration.

Terms are external and can change. Re-review dates and policy URLs are operational
configuration, not assumptions embedded in code. If permission is unclear, the
adapter remains disabled.

## Live adapter activation criteria

The engine is deliberately adapter-incomplete until one structured provider passes
the terms, security, privacy, cost, and accuracy gate above. An implementation must
register a `RankAdapter`, report a semantic version and truthful execution source,
map desktop/mobile into materially compatible execution-device modes, convert only
approved transient failures into retryable codes, and pass conformance plus live
integration tests. A local agent, extension, scraper, proxy, or vendor integration
requires its own scoped review.

## Implemented operational commands and status

`php bin/console rank:work --limit=10` claims a bounded number of eligible database
jobs. Configure DirectAdmin cron once per minute after an adapter is approved. Public
job states are `pending`, `running`, `completed`, and `failed`; `retry_wait` is a
pending retry. Results append to history and have a unique attempt identity. Worker
crashes leave leases that a later invocation expires and safely retries within the
configured maximum.

Phase 11 dashboard calculations and chart behavior are documented separately in
`RANK_DASHBOARD.md`. They read only accepted immutable results and do not alter the
engine, adapter approval status, or external-verification limitations.
