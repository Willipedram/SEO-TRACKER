# Phase 09 Architecture Validation Checklist

This checklist is a review artifact, not executable Rank Tracking code.

## Truthfulness

- [x] Server/provider egress is explicitly distinguished from user-network egress.
- [x] Forwarded/submitted IP headers are not treated as egress changes.
- [x] Browser JavaScript is not claimed to be a reliable SERP scraper.
- [x] Modeled/emulated mobile is distinct from physical mobile execution.
- [x] Challenge/error responses cannot become fake `not_found` rankings.
- [x] Phase 09 adds no sample/fake rankings or engine integration.

## Deployment and operations

- [x] Initial queue works with MySQL plus bounded DirectAdmin cron invocations.
- [x] Local agents use outbound polling; no customer inbound port is required.
- [x] Leases, expiry, retries, late results, idempotency, and circuit breaking exist
  in the design.
- [x] No long-running PHP request or supervisor is required for correctness.

## Security and privacy

- [x] Google passwords and browser/provider cookies are prohibited.
- [x] Executor credentials are scoped, revocable, redacted, and replay-resistant.
- [x] Destinations are allowlisted; no generic fetch/proxy/shell interface exists.
- [x] Website/keyword ownership and RBAC are rechecked server-side.
- [x] IP/user-agent collection is minimized, purpose-bound, and retained separately.
- [x] Adapter terms, threat, privacy, accuracy, and commercial review gate enablement.

## Phase 10 control-plane validation

- [x] Desktop and mobile requests retain distinct requested/execution semantics.
- [x] Ranked and not-found results pass strict structural validation.
- [x] History appends and has one accepted result per immutable attempt.
- [x] Pending/running/completed/failed and retry-wait behavior is tested.
- [x] Retry is capped and limited to allow-listed transient error codes.
- [x] RBAC, ownership, active keyword, rate limit, and unknown ID checks are tested.
- [x] Production adapter registration is empty and submission fails explicitly.

## External/live blockers

- [ ] Select and contractually approve one structured adapter.
- [ ] Define supported MySQL/MariaDB compatibility and concurrency test matrix.
- [ ] Approve retention periods and privacy notice/DPA changes.
- [ ] Define cost quotas and operational ownership.
- [ ] Threat-model any agent/extension as a separately signed product.
- [ ] Validate/finalize DDL after MySQL/MariaDB lease/idempotency concurrency tests.
