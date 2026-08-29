# Rank Dashboard and Charts

## Scope

Phase 11 reads only accepted immutable `rank_results`. It does not execute checks,
change observations, infer missing ranks, or treat failed attempts as history. The
dashboard is available per owner-scoped website to users with `rank_tracking.view`.

## Calculations

For each keyword configuration, observations inside the selected date range are
ordered by `observed_at`, then result ID. Current and previous mean the latest two
accepted observations in that order. A ranked observation has a numeric position;
`not_found` has no position and remains unavailable rather than becoming 101 or the
checked depth.

Position change is `previous - current`: positive is improvement, negative is a
drop, zero is unchanged, and null means either current or previous is unavailable.
Best is the minimum ranked position and worst is the maximum ranked position in the
filter range. Ranking URL and last-checked time come from the current observation.

Desktop and mobile are separate keyword configurations. Rows retain their configured
device while paired desktop/mobile columns show the latest available observation for
the same normalized term, engine, country, and language. Values are never averaged or
merged.

## Filters and query plan

Dashboard filters support website (required/owner scoped), optional keyword, device
(`all|desktop|mobile`), and date range (`7|30|90|365|all`). The dashboard uses three
fixed queries—website authorization, keyword configurations, and all applicable
results—then aggregates in memory, avoiding a query per keyword. A chart similarly
uses fixed website, selected/sibling keyword, and result queries.

Schema version 8 adds `(website_id, requested_device, observed_at)` and
`(keyword_id, requested_device, observed_at)` indexes. The migration is idempotent
for SQLite and explicitly checks MySQL/MariaDB metadata before index creation.

## Chart

Each keyword chart is a responsive, server-rendered SVG with separate desktop and
mobile series. Points are chronological. A `not_found` observation creates a gap; no
point is manufactured. Rank coordinates are inverted: position 1 maps to the top and
the maximum displayed position maps to the bottom. Reference lines mark #1 and Top
3, 5, 10, 20, 50, and 100. Point titles expose time and rank to pointer/assistive
technology users, and a tabular history accompanies the visual.

## Localization and direction

Dashboard text comes from `lang/<locale>/rank_dashboard.php` through the small
`Translator` boundary. English and Persian catalogs ship in Phase 11. `APP_LOCALE`
selects the catalog; Persian renders `lang="fa" dir="rtl"`. CSS uses logical spacing,
RTL table alignment, horizontal table scrolling, responsive filters, and a mobile
card layout. Missing translation keys fall back to English.

## Security

Both dashboard and chart resolve the website using opaque public ID plus authenticated
owner and require `rank_tracking.view`. Keyword filters are resolved inside that
website. Filter values are strict allowlists/bounded opaque IDs, SQL is parameterized,
and every stored label, URL, timestamp, and localized string is HTML-escaped. The
read-only GET endpoints do not weaken CSRF protection for mutations.
