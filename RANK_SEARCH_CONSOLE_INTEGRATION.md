# Rank Tracker and Search Console Integration

## Semantic boundary

The two position values are deliberately separate:

- **Rank Tracker observed position** is the position returned by one accepted Rank Tracking execution at a specific timestamp and device.
- **Google Search Console average position** is Google's impression-based aggregate for Search Console rows over a date or date range.

The integration never overwrites either source, labels them as one generic position, fills missing dates, or asserts that movement in one metric caused movement in another.

## Mapping strategy

A comparison is scoped to one owner-accessible Website and one Keyword. Search Console rows are included only when all of these conditions hold:

1. the optional Search Console module is enabled and the Website has a selected, connected property owned by the actor;
2. the Keyword search engine is Google;
3. `query_text` is an exact, case-sensitive match for the configured Keyword text;
4. the Search Console and Keyword device categories are identical (`desktop` or `mobile`);
5. the Search Console search type is `web`; and
6. the stored row falls inside the selected 7, 30, or 90 UTC-day window.

Rank Tracker observations are selected by Keyword, matching requested device, and the same UTC window. When multiple observations occur on one UTC day, the latest immutable observation for that day is displayed. Search Console daily position is impression-weighted. The timeline is the union of actual dates from both sources: absent values stay absent.

Country is not used for alignment because Keywords currently use ISO alpha-2 while Search Console sync rows use alpha-3. Search Console comparison values therefore aggregate matching query/device rows across countries. Language cannot be reliably mapped because Search Console does not provide a language dimension in this model. These limitations are disclosed in the view.

## Availability and security

The combined route requires both `rank_tracking.view` and `search_console.sync`, then performs an owner-scoped Website/Keyword lookup. A disabled, disconnected, or unavailable Search Console module produces a clear partial state; it never affects the standalone Rank Tracker routes or stored history.

The service performs one bounded Rank Tracker query and one bounded Search Console aggregate query. It does not join the two history tables or create an uncontrolled cross join. Existing keyword/time and Search Console property/date/filter indexes support the lookups, so Phase 16 requires no schema migration.
