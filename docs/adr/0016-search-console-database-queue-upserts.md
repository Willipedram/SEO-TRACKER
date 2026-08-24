# ADR 0016: Database-queued Search Console sync with dimension upserts

- Status: Accepted
- Date: 2026-08-24

## Context

Search Analytics responses are paginated, revisable, and too slow for normal shared-
hosting web requests. Repeating the same range must not duplicate data. Google outages,
rate limits, and revoked tokens must not affect Core.

## Decision

Queue manual sync requests in module-owned database tables and process them from a
bounded CLI cron worker with conditional claims, leases, three attempts, and exponential
retry for only transient classes. Store append-only lifecycle logs with safe messages.
Use the Phase 13 refresh boundary and fixed Search Analytics endpoint.

Request dimensions `date,query,page,device,country`. Derive a SHA-256 key from selected
property plus all dimensions and search type; enforce uniqueness and upsert revisable
metrics. Validate every provider row before a page transaction. Cap ranges, pages, and
total rows. Never persist raw provider bodies or tokens.

## Consequences

Repeated ranges are deterministic and shared hosting needs only cron, but a process can
run until its current API page completes. Leases are extended between phases and expired
work is recoverable. Automated tests use a fake provider; live Google behavior and
MySQL-specific upsert execution require deployment verification. Search Console data
remains separate from rank history and Core remains independent.
