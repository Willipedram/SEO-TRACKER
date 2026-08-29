# ADR 0018: Explicit Rank Tracker/Search Console alignment

- Status: Accepted
- Date: 2026-08-24

## Decision

Combined analysis belongs to the optional Search Console module, which may depend on Rank Tracking; Rank Tracking must never depend on Search Console. A comparison is limited to a single owned Website and Keyword, an exact case-sensitive query, identical device, Google/web data, and a bounded UTC range.

Rank Tracker contributes its latest actual observation per UTC day. Search Console contributes impression-weighted daily aggregates. Application code aligns the two bounded series by the union of dates and retains nulls instead of manufacturing observations. Source-specific labels and visual cards are mandatory.

## Consequences

The design prevents large history cross joins and preserves module independence. It intentionally rejects fuzzy query matching and device substitution. Country and language alignment remain unavailable with the current schemas. Side-by-side trends are descriptive, not causal.
