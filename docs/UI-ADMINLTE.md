# Phase 27 — Persian RTL AdminLTE UI

## Stack and delivery

The presentation shell uses the official AdminLTE distribution, pinned to
`4.0.0-rc4`, with Bootstrap `5.3.3` (AdminLTE's UI runtime) and Bootstrap Icons
`1.11.3`. All three are delivered over HTTPS by jsDelivr. AdminLTE and Bootstrap
are MIT licensed; Bootstrap Icons is MIT licensed. No jQuery, tracker, advertising
script, second CSS framework, table framework, or demo bundle is loaded.

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

The shell covers the implemented login/account, installer, update, website list/create/
edit/dashboard/settings, keyword list/create/edit, rank run/history/dashboard/chart,
Search Console configuration/connection/property/sync/dashboard/comparison, reports,
users/roles/permissions, user/system/module settings and localized error responses.
Operational pages not backed by real routes (a general log viewer, cron status UI and
global notification center) are intentionally absent rather than represented by fake
menus.

## Acceptance limits

Automated contracts cover RTL markup, permission-filtered navigation, pinned CDN URLs,
responsive CSS, tooltip behavior and absence of demo UI. Cross-browser physical-device
and production-CDN acceptance remains a deployment check; canonical timestamps and all
backend security/business behavior are unchanged.
