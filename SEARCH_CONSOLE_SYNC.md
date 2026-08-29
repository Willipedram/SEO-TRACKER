# Search Console synchronization engine

## Scope and execution

Phase 14 adds manual, asynchronous Search Analytics synchronization inside the
optional `SearchConsole` module. A normal HTTP request validates and queues work; it
never waits for Google. Shared-host cron runs `php bin/console search-console:work
--limit=3`. The database queue requires no daemon and is independent of RankTracking.

Submission requires `search_console.sync`, an enabled module, an owner-scoped active
website, and its selected connected property. Date ranges are strict UTC `Y-m-d`,
ordered, no later than yesterday, within Google's approximately 16-month history, and
at most the configured 31 days. Supported search types are `web`, `image`, `video`,
`news`, `discover`, and `googleNews`. Ten submissions per user/hour limit abuse. An
identical active request returns the existing job; a completed range may be refreshed.

## Queue and lifecycle

`search_console_syncs` holds pending work and reports `pending`, `running`,
`retry_wait`, `completed`, or `failed`. A conditional claim creates a bounded lease
and increments the attempt. `search_console_sync_logs` records fixed safe transitions:
started, fetching, processing, saving, completed, or failed. Expired leases are reaped.
At most three attempts use exponential delays for rate limiting, provider/network
unavailability, refresh failure, and expired leases. Invalid data, ordinary API errors,
oversized results, disabled modules, and revoked authorization do not retry.

The CLI worker refreshes encrypted credentials through the Phase 13 service, pages the
fixed Search Analytics endpoint in 25,000-row pages, and accepts at most 250,000 rows
per job by default. A revoked authorization clears the token envelope and property
mapping. Exceptions cross the module boundary only as allow-listed error codes; logs
receive sync IDs, code, retry state, and exception class—not tokens or provider bodies.
Technical failures use the dedicated `storage/logs/search-console.log` channel, while
the database lifecycle log remains suitable for the authorized status UI.

## Stored dimensions and metrics

Each `search_console_data` row belongs to a website, selected Search Console property,
and last successful sync. It stores date, query, page, device, country, search type,
clicks, impressions, CTR, and average position. API rows must contain the requested
five dimensions in that order. Dates must belong to the requested range; UTF-8 query
text is bounded and rejects controls; pages must be bounded HTTP(S) URLs without
credentials/fragments; devices and countries use strict formats; metrics must be
finite and within valid ranges. Data is never treated as executable HTML.

## Idempotency and updates

A SHA-256 dimension key covers property, date, query, page, device, country, and search
type. Its unique constraint is the duplicate barrier. MySQL uses `ON DUPLICATE KEY
UPDATE`; SQLite uses `ON CONFLICT DO UPDATE`. Repeating a range updates metrics and
last-sync provenance rather than appending duplicates. Different dates, properties,
devices, countries, pages, queries, or search types remain independent.

Fetched pages first enter a per-sync staging table. Only after every page validates does
one database transaction promote staged rows, mark the job complete, and clear staging.
A failed/retried job clears staging, so partially fetched pages are not published.

## Failure isolation and limitations

Google 429 and selected 5xx responses are retryable. HTTP 401 is authorization revoked;
other rejected requests fail safely. Failed jobs retain no fabricated analytics rows.
Core, authentication, websites, keywords, rank checks, and dashboards continue without
the worker or when Google fails. Phase 14 does not schedule automatic recurring syncs,
render Search Console analytics dashboards, or merge Search Console average position
with rank-tracking observations.
