# ADR 0019: Bounded source-specific reports and safe CSV

- Status: Accepted
- Date: 2026-08-24

## Decision

Reports are a first-class module depending on Websites, Keywords, and Rank Tracking, but not on Search Console source code. Search Console storage is read only when its persisted module state is enabled. Every report is owner-scoped, date-bounded, paginated, and labeled by source.

Latest/two-latest Rank Tracker classification uses SQL window functions. Search Console aggregation remains in SQL. CSV is emitted in paginated batches, capped at 10,000 rows, encoded as UTF-8 with BOM, and neutralizes formula-leading controlled strings.

## Consequences

Rank Tracker continues when Search Console is absent or disabled. Exact Rank Tracker positions and Search Console averages cannot be confused. MySQL 8 or a compatible window-function database is required for movement reports. PDF remains outside this phase.
