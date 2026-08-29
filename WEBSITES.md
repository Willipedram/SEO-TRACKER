# Website Management

## Scope and ownership

Phase 07 provides website lifecycle management only. Every website has one immutable
`owner_user_id`; a caller must both hold the relevant `websites.*` permission and own
the requested row. This conservative scope prevents cross-account IDOR. Sharing and
organization-wide administration require a future explicit membership model.

Browser routes use a random 128-bit hexadecimal `public_id`, never the sequential
database key. Every lookup includes the authenticated owner ID. Archived records are
retained for future data integrity, are read-only, and normal lists omit them.

## Canonical origin rules

The form accepts an absolute HTTP or HTTPS origin. Validation rejects credentials,
ports, paths other than `/`, queries, fragments, single-label hosts, invalid hosts,
and non-ASCII input. International domains must be supplied in Punycode form. The
normalized domain is lowercase without a trailing dot; the canonical URL is
`protocol://normalized-domain` without a trailing slash.

Uniqueness is `(owner_user_id, normalized_domain)`. Protocol and formatting
differences cannot create duplicates for one owner, while two owners may manage the
same domain. The application performs no DNS resolution, HTTP request, redirect
following, or other network access, preserving a boundary for future SSRF controls.

## Data and lifecycle

Stored fields are opaque public ID, owner, site name, normalized domain, canonical
URL, protocol, description, IANA timezone, `active|paused|archived` status, creation/
update timestamps, and archive timestamp. Creation defaults to UTC and active.
Settings permit an IANA timezone and active/paused state. Archive requires
`websites.delete` and replaces destructive deletion.

Creation, update, settings, and archive events use the existing audit log. Audit
metadata is limited to normalized domain, timezone, status, and opaque identifiers.

## Dashboard boundary

`/websites/dashboard?id=<public_id>` is an independent server-rendered dashboard.
It shows website metadata and a static extension point. Phase 07 does not implement
keywords, rank collection/history, Search Console, or their data.
