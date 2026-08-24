# ADR 0017: Database-aggregated Search Console dashboard

- Status: Accepted
- Date: 2026-08-24

## Context

Search Console sync data can be large. Loading raw rows into PHP would waste memory, create latency, and make metric calculations inconsistent. Search Console average position also has different semantics from an exact Rank Tracker observation.

## Decision

The optional Search Console module owns a website-scoped dashboard service. SQL computes sums, distinct counts, impression-weighted average position, daily trends, and bounded dimension breakdowns. CTR is recomputed from aggregate clicks and impressions. Comparisons use the preceding equal-length UTC period. Schema version 12 supplies indexes aligned with ownership, property, date, common filters, and latest-sync lookup.

The service exposes explicit connection, empty, and failure states. It never contacts Google, fabricates data, or joins Rank Tracker analytics. Controllers translate labels, escape output, and preserve RTL rendering.

## Consequences

Memory use is bounded and requests avoid an N+1 pattern. Query-substring filtering is portable but may need a dedicated search index at very large scale. Exact page filtering remains index-limited because page URLs are long. Database-engine execution plans must be monitored on production-sized datasets.
