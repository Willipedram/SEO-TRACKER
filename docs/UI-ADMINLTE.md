# Phase 27 — Persian RTL AdminLTE UI

## Stack and delivery

The presentation shell uses the official stable AdminLTE distribution, pinned to
`4.9.1`, with Bootstrap `5.3.8` (compatible with AdminLTE's declared `^5.3.8`
requirement) and Bootstrap Icons
`1.11.3`. All three are delivered over HTTPS by jsDelivr. AdminLTE and Bootstrap
are MIT licensed; Bootstrap Icons is MIT licensed. No jQuery, tracker, advertising
script, second CSS framework, table framework, or demo bundle is loaded.

The versions were re-verified for the Phase 27 closure pass on 2026-08-29. This
replaces the earlier `4.0.0-rc4`/Bootstrap `5.3.3` pairing. The application uses the
current AdminLTE 4 `app-wrapper`, `app-header`, `app-sidebar`, `sidebar-menu`,
`data-lte-toggle="sidebar"` and `data-lte-toggle="treeview"` contracts. Bootstrap is
loaded once; no independent Bootstrap stylesheet is loaded because AdminLTE supplies
the compatible presentation layer. Its JavaScript bundle supplies real Bootstrap
dropdown, collapse, modal, popover and tooltip primitives when a page uses them.

AdminLTE is the primary shell. `public/assets/phase27.css` contains only product RTL,
responsive, branding, form, table, empty-state and chart adjustments. The local CSS
and tooltip JavaScript are inlined by the server because common nginx/DirectAdmin
setups do not publish the repository's `public/assets` directory. Exact CSP hashes
authorize those blocks; `unsafe-inline` and `unsafe-eval` are not enabled. A CDN
failure leaves usable server-rendered forms, tables and navigation, although enhanced
sidebar behavior and vendor styling are unavailable.

## Layout architecture

`AdminLayout` is the single layout integration point. It converts existing
server-rendered controller output into either a standalone login/installer/updater
card or the authenticated AdminLTE app wrapper. The authenticated wrapper owns the
navbar, product branding, off-canvas sidebar, content header and version footer.
Existing controller forms, tables, alerts, rank SVGs and workflows remain intact.

Persian remains the default locale and the document uses `lang="fa" dir="rtl"`.
Logical CSS properties place the sidebar correctly; URLs, email addresses, code,
versions and chart mathematics remain LTR. Phase 26 terminology buttons remain
keyboard/click accessible and visually inherit Bootstrap/AdminLTE controls.

## Navigation and authorization

Sidebar entries map only to registered application routes. `Application` resolves the
authenticated user's effective permission keys from the existing RBAC tables and the
layout omits entries without the corresponding permission. Search Console navigation
requires its real sync permission. This is presentation filtering only: controllers
continue to enforce authorization directly.

There are no demo messages, notifications, ecommerce figures, avatars, global search,
mailbox, calendar, fake metrics, placeholder links or sample credentials. Screens with
no business data retain their real server-generated empty states.

## Adding or updating UI

1. Add a real route and keep authorization in its controller/application service.
2. Render semantic headings, forms, tables and empty/error states; `AdminLayout`
   applies shared components automatically.
3. Add a sidebar item only when the route exists, including its permission key.
4. Add Persian/English strings to the translation catalogs and technical terminology
   to `Terminology`; never translate stored identifiers or user data.
5. Put product styles in `phase27.css`, not AdminLTE vendor files. Pin and document any
   new dependency and update CSP deliberately.
6. To update AdminLTE, verify the official release, change the constant in
   `AdminLayout`, review Bootstrap compatibility/license/CSP, then run all UI,
   responsive, RTL and security tests.

## Route/page inventory

All HTML is localized before it enters the shared layout, so the rows marked verified
below share the same Persian, RTL and responsive contracts. POST rows use the same
layout for validation/error states and preserve CSRF plus controller authorization.

| Route | Feature | Layout | Menu entry | Permission | Module | RTL | Responsive | Status |
|---|---|---|---|---|---|---|---|---|
| `/login` | Sign in/errors | Standalone | No | Guest | Auth | Verified | Verified | Covered |
| `/account`, `/logout` | Real dashboard/account | App | Dashboard | Authenticated | Auth | Verified | Verified | Covered |
| `/install` GET/POST | Environment, database, admin, completion | Standalone wizard | No | Fresh install only | Core | Verified | Verified | Covered |
| `/update` GET/POST | Migration plan/authorization/result | Standalone updater | No | Update workflow | Core | Verified | Verified | Covered |
| `/websites` | Website list/empty state | App | Websites | `websites.view` | `websites` | Verified | Verified | Covered |
| `/websites/create` GET/POST | Create website | App | Via list action | `websites.create` | `websites` | Verified | Verified | Covered |
| `/websites/dashboard` | Website detail/dashboard | App | Via real row action | `websites.view` | `websites` | Verified | Verified | Covered |
| `/websites/edit` GET/POST | Edit website | App | Via real row action | `websites.edit` | `websites` | Verified | Verified | Covered |
| `/websites/settings` GET/POST | Website settings | App | Via real row action | `websites.edit` | `websites` | Verified | Verified | Covered |
| `/websites/archive` POST | Archive website | App result | Real form action | `websites.delete` | `websites` | Verified | Verified | Covered |
| `/keywords` | Keyword list/empty state | App | Keywords | `keywords.view` | `keywords` | Verified | Verified | Covered |
| `/keywords/create` GET/POST | Create keyword | App | Via list action | `keywords.create` | `keywords` | Verified | Verified | Covered |
| `/keywords/edit` GET/POST | Edit keyword | App | Via real row action | `keywords.edit` | `keywords` | Verified | Verified | Covered |
| `/keywords/status`, `/keywords/delete` POST | Status/delete actions | App result | Real form actions | `keywords.edit` / `keywords.delete` | `keywords` | Verified | Verified | Covered |
| `/rank-checks` POST | Queue rank check | App result | Keyword action | `rank_tracking.run` | `rank_tracking` | Verified | Verified | Covered |
| `/rank-checks/status` | Job status | App | Via submitted job | `rank_tracking.view` | `rank_tracking` | Verified | Verified | Covered |
| `/rank-checks/history` | Immutable rank history | App | Via keyword action | `rank_tracking.view` | `rank_tracking` | Verified | Verified | Covered |
| `/rank-dashboard` | Rank overview/filters | App | Rank Tracking | `rank_tracking.view` | `rank_tracking` | Verified | Verified | Covered |
| `/rank-dashboard/chart` | Responsive rank chart/history | App | Via dashboard action | `rank_tracking.view` | `rank_tracking` | Verified | Verified | Covered |
| `/websites/search-console` | Connection/property state | App | Search Console | `search_console.connect` | `search_console` | Verified | Verified | Covered |
| `/websites/search-console/*` | Connect, property, disconnect, sync/status | App/result | Real page actions | Connect/sync permissions | `search_console` | Verified | Verified | Covered |
| `/websites/search-console/dashboard` | GSC metrics/filter/trends | App | Search Console | `search_console.sync` | `search_console` | Verified | Verified | Covered |
| `/websites/search-console/combined` | Tracker/GSC comparison | App | Via dashboard action | View/sync permissions | `search_console` | Verified | Verified | Covered |
| `/admin/modules/search-console` GET/POST | Optional module state | App | Settings/modules | `settings.manage` | `search_console` | Verified | Verified | Covered |
| `/reports` | Real filtered reports/empty states | App | Reports | `reports.view` | `reports` | Verified | Verified | Covered |
| `/reports/export.csv` | CSV download | Non-HTML response | Via reports action | `reports.view` | `reports` | N/A | N/A | Covered |
| `/admin/users*` | Users/create/edit/status/delete/roles | App | Users | Users permissions | Core RBAC | Verified | Verified | Covered |
| `/admin/roles*` | Roles/permission assignment | App | Roles & permissions | `roles.manage` | Core RBAC | Verified | Verified | Covered |
| `/settings` GET/POST | User settings | App | User settings | Authenticated | `settings` | Verified | Verified | Covered |
| `/admin/settings`, `/admin/modules` | System/module settings | App | Settings & modules | `settings.manage` | `settings` | Verified | Verified | Covered |
| Localized 403/404/500 responses | Safe error states | Standalone or app context | No dead link | Contextual | Core | Verified | Verified | Covered |

`/health`, `/internal/permissions`, OAuth callback, CSS/JS compatibility endpoints and
CSV export are machine/callback/download routes, not navigation screens.

### Installation wizard database decisions

The standalone installer now uses a four-step, WordPress-inspired interaction model:
environment readiness, database credentials, database-state detection, and initial
administrator creation. Database fields use a compact responsive grid with familiar
host/name/user/password/port guidance, while credentials remain POST-only, protected by
CSRF, excluded from logs, and retained only in the installer session until activation.

After a successful connection the installer distinguishes three states before making
changes:

* **Empty:** offers a clean installation and explicitly confirms that no table will be
  deleted.
* **Existing SEO Tracker:** recommends preserving all users, websites, keywords,
  credentials and reports, activates the submitted protected connection configuration,
  then redirects to the existing authenticated migration/update workflow.
* **Unknown data:** refuses to modify or delete anything and asks for a separate empty
  database.

The “clean installation” choice shown for an existing application never truncates or
drops that database. It returns to database selection so the operator must provide a
different empty database. This deliberately avoids a one-click destructive reset while
still presenting the requested update-versus-clean decision.

An existing `.env` no longer blocks an explicitly selected installation mode. A clean
installation atomically replaces stale configuration only after schema installation
succeeds. An existing-system update merges only URL/database connection keys into the
current file and preserves `APP_KEY`, OAuth values and unknown application settings so
encrypted data remains usable.

The global Persian font stack now starts with locally licensed `IRANSans`, `IRANSansX`
and `IRANSansWeb` names. IranSans is proprietary and is therefore not redistributed by
this repository or fetched from an unofficial CDN; a hosting account with a licensed
local installation uses it automatically, while Tahoma/Segoe UI remain safe fallbacks.

## Not implemented in backend / Future phase candidate

The repository has no user-facing backend workflow for password reset, global search,
notifications/messages, a safe log browser, a general audit-log browser, cron/system
status, or a generic queue administration dashboard. Bulk rank operations beyond the
implemented single job workflow also do not exist. Phase 27 deliberately provides no
dead links, demo dropdowns, fake data or raw-log exposure for these capabilities. They
are future backend candidates and do not block integration of the existing UI.

The login screen intentionally omits “Forgot password” because no password-reset route
exists. Global search and notification controls are likewise intentionally absent.

## Closure validation and audits

The closure suite contains explicit contracts for stable AdminLTE `4.9.1`, one
Bootstrap `5.3.8` runtime, HTTPS-only pinned URLs, absence of pre-release identifiers
and `@latest`, CSP CDN directives plus dynamic SHA-256 hashes, responsive RTL styles,
RBAC/module navigation, Phase 26 terminology, and absence of dead/demo navigation.
The complete PHP suite passed 150 tests on 2026-08-29 (115 unit, 18 integration,
2 end-to-end, 14 feature and 1 architecture).

The source audit found no `href="#"`, `javascript:void(0)`, AdminLTE sample user,
mailbox/ecommerce/sales/order/product/demo notification, sample avatar, fake chart,
placeholder image, `index2`/`index3`, invoice, calendar, kanban, chat or file-manager
UI. References to “AdminLTE” remain only in source documentation, tests and pinned
dependency URLs; visible branding is SEO Tracker.

The CSP permits only `cdn.jsdelivr.net` for the pinned vendor script/style/font files.
Application CSS and tooltip JavaScript remain server-inlined for nginx compatibility;
their exact response-time SHA-256 values are added without `unsafe-inline` or
`unsafe-eval`. `connect-src` also permits jsDelivr so browser developer tools can load
vendor source maps without generating false CSP violations. The kernel overwrites any
older response-level CSP with this authoritative policy and emits
`X-SEO-CSP-Version: phase27-jsdelivr-connect-v2` to make stale PHP/opcache deployments
immediately visible in browser Network tools.

## Deployment activation and installer discovery

`storage/installed.lock` is the deployment acknowledgement for the currently
extracted source tree. A complete clean extraction does not contain this runtime
file, so even when a retained `.env` points to an existing SEO Tracker database the
application opens the installer. After the database connection is tested, the
wizard classifies it as empty, an existing SEO Tracker installation, or unknown and
shows only the safe clean/update choices for that state. Completing a clean install
or explicitly selecting update recreates the lock. Production deployments that are
meant to remain immediately active must preserve `storage/installed.lock` together
with `.env` and other persistent storage.

The standalone login and installer use bounded `calc()` widths, global border-box
sizing, and viewport overflow containment. Authenticated tables are automatically
placed inside `.seo-table-responsive`, so wide tabular data scrolls within its card
instead of widening the dashboard or mobile viewport.

Rank checks are deliberately queued. A production adapter must be registered and
selected with `RANK_ADAPTER`; otherwise the dashboard shows a configuration warning
and does not expose an action that can only fail. Configure DirectAdmin cron to run
`php /absolute/path/bin/console rank:work --limit=10` at a bounded interval. Pending,
running, and retry-wait status pages refresh every five seconds and explicitly show
the worker command, while expired leases are recovered by the next worker run. The
UI never fabricates a rank or runs an unapproved direct SERP scraper.

All shared navbar/sidebar URLs are rendered with the detected physical or virtual mount
prefix before HTML leaves the layout. The response mount pass recognizes already
prefixed URLs, preventing both root-level links such as `/keywords` and accidental
double prefixes such as `/seotrack/seotrack/keywords`. Installer-generated `APP_URL`
also retains the mount prefix for later redirects and OAuth configuration.

## Acceptance limits

Automated contracts cover RTL markup, permission-filtered navigation, pinned stable CDN URLs,
responsive CSS, tooltip behavior and absence of demo UI. Cross-browser physical-device
and production-CDN acceptance remains a deployment check; canonical timestamps and all
backend security/business behavior are unchanged.

No Chromium, Chrome, Firefox, Playwright or Puppeteer executable/package exists in the
execution image. Offline `npx` checks returned `ENOTCACHED`, and outbound CDN probes
were rejected by the environment proxy with HTTP tunnel 403. Consequently real-browser
viewport, console, network and screenshot verification is externally blocked rather
than claimed. This does not represent missing application implementation, but remains a
production acceptance check for the deployment environment.
