# Reporting

Phase 17 provides Website, Keyword, Ranking, Search Console, Improvement, Dropped Keyword, Top 10, Top 3, and #1 Keyword reports.

## Calculations and sources

- Website reports count configured Keywords and immutable Rank Tracker observations inside the selected range.
- Keyword reports show configuration plus the latest actual Rank Tracker observation in the range.
- Ranking reports list immutable Rank Tracker results; they do not infer missing observations.
- Improvement and dropped reports compare the latest two actual numeric positions per Keyword in the range. Change is `previous - current`, so positive is improvement and negative is a drop.
- Top 10, Top 3, and #1 reports classify the latest actual numeric Rank Tracker position per Keyword. A not-found result has no numeric position and is not classified.
- Search Console reports sum clicks and impressions, calculate CTR as `sum(clicks) / sum(impressions)`, and calculate impression-weighted Search Console average position. It is always labeled as Search Console data and never substituted for Rank Tracker position.

## Filters, pagination, and export

Reports accept an owner-scoped Website, owner-scoped Keyword, a validated range of at most 367 inclusive days, and device. Search Console additionally accepts query substring, exact page, ISO alpha-3 country, and search type. Web pages default to 50 rows and allow at most 100 rows per page.

CSV export is UTF-8 with a BOM for Persian spreadsheet compatibility. It retrieves at most 100 rows at a time and caps an export at 10,000 rows. User/API-controlled string cells whose first meaningful character is `=`, `+`, `-`, or `@` are prefixed with an apostrophe to prevent spreadsheet formula execution. PDF is intentionally deferred.

## Security and availability

All reports require `reports.view`. Website and Keyword filters are resolved under the authenticated owner before reporting. SQL values are bound and HTML output is escaped. Search Console disabled/no-data states are isolated: all Rank Tracker reports remain operational.

Movement classifications use a database window query over the bounded date range, while Search Console aggregation and pagination happen in SQL. Existing indexes support these queries; Phase 17 adds no schema migration. Production deployments must use MySQL 8+ because movement reports use `ROW_NUMBER()`.
