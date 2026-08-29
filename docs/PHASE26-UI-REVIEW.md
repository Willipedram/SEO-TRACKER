# Phase 26 rendered UI and terminology review

## Method and environment

The extracted-release E2E host was opened through PHP's built-in HTTP server with a
fresh schema-14 SQLite installation and `APP_LOCALE=fa`. Rendered HTML was decoded and
reviewed for Persian copy, `lang=fa`, structural `dir=rtl`, preserved raw technical
values, and expected empty/error states. Desktop and mobile CSS rules, tooltip markup,
keyboard focus behavior, and touch/click JavaScript were inspected. This is not a claim
of visual testing on physical devices or every browser engine.

## Screens reviewed

| Screen | Result |
|---|---|
| Installer environment/database/admin/completion and existing-database warnings | Persian-first; destructive warnings retain explicit preservation language |
| Login, failed login, account and logout | Persian; email remains isolated LTR |
| Users, user editing, roles, role assignment and permissions | Persian labels/descriptions; permission keys such as `rank_tracking.run` remain unchanged and LTR |
| Website list/create/dashboard/settings/archive | Persian; domain and URL values remain LTR |
| Keyword list/create/edit/status and validation | Consistent «کلیدواژه»; tracked query and target URL are not normalized |
| Rank check, failure, history, dashboard and SVG chart labels | Persian; exact tracker metric uses «رتبه ثبت‌شده توسط ردیاب» |
| Search Console settings, connection/error states, dashboard, filters and sync history | Persian; canonical Google metrics retain separate terminology/tooltips |
| Combined Rank/Search Console view | Source labels remain distinct; no metric merge or causal claim |
| Reports, filters, classifications, tables and CSV labels | Persian UI; source-specific position headers stay distinct |
| Module management, system/module/user settings and feature flags | Persian; internal module/setting IDs and enum values remain stable |
| Update/migration authorization, success and failure copy | Persian copy supplied through centralized UI catalog; versions and migration IDs remain LTR/raw |
| 404, CSRF, host, authorization, empty and unavailable states | Safe localized copy; no technical exception or secret exposure |

There is no sidebar, modal, confirmation-dialog framework, or client-rendered component
in the current server-rendered UI. Their absence was recorded rather than treated as an
unreviewed screen.

## Tooltip audit

The central terminology list was reviewed for Rank Tracking, Search Console, OAuth,
RBAC, modules/settings, queue/jobs, migrations, devices, statuses, and rate limiting.
Ordinary actions such as ذخیره، حذف، لغو and بازگشت do not receive tooltips. Raw URLs,
emails, IP addresses, IDs, versions, tokens, and hashes do not receive terminology
tooltips. English tooltip content is short, canonical, LTR-isolated, and supplemental;
the visible Persian label retains the critical meaning.

## Missing translation result

No structured translation key is expected to leak: known keys have Persian entries and
unknown keys remain detectable in tests. English remains intentionally visible only for
the SEO Tracker and Google product names, URL/API/OAuth/CSV and standards, raw technical
values, module/permission identifiers, and user-provided Latin text. Provider-originated
free-form diagnostics are shown only through safe predefined presentation states.

## Remaining visual limitation

Hover/focus/click/tap behavior is covered structurally and by automated markup/CSS/JS
contracts. A physical touch-device, screen-reader matrix, and cross-browser screenshot
comparison were unavailable; deployment acceptance should include those environment-
specific checks without changing canonical terminology.
