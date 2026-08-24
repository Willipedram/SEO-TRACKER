# Search Console Dashboard

## Scope

Phase 15 adds a website-scoped dashboard over rows persisted by the Phase 14 synchronization engine. It does not call Google, synthesize missing dates, or combine Search Console metrics with Rank Tracker observations.

## Components and states

The dashboard shows connection/property state, the latest synchronization status, summary cards, equal-period changes, daily trends, and top query, page, and device tables. Explicit states cover a disabled module, no connection, expired authorization, never synchronized, a valid empty result, and available data. Previously synchronized rows remain available after a sync failure, while an expired connection asks the user to reconnect through the existing OAuth flow.

Filters support UTC date range, query substring, exact page URL, device, ISO alpha-3 country, and Search Console search type. The default period is the last 28 complete days and the maximum historical window is 16 months.

## Metric semantics

- Clicks and impressions are summed.
- CTR is `sum(clicks) / sum(impressions)`; stored row CTR values are not averaged.
- Average position is impression-weighted: `sum(average_position * impressions) / sum(impressions)`.
- Query and page totals use distinct values in the filtered result.
- Comparisons use the immediately preceding period of equal length. A smaller average-position value is an improvement.

Search Console average position is labeled explicitly and must not be presented as an exact Rank Tracker check.

## Performance and security

Aggregates and breakdowns execute in the database. The UI does not load all raw rows, top tables are capped, and schema version 12 adds property/date, composite filter, and latest-sync indexes. Every request requires `search_console.sync`, resolves the website through its owner, validates all filters, uses bound parameters, and escapes database/API-originated values. The optional module route disappears when disabled; Core and other modules remain independent.

All labels come from English/Persian catalogs, pages set `lang` and `dir`, and tables scroll on narrow screens.
