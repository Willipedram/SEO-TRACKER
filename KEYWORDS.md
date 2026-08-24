# Keyword Management

## Model and ownership

Phase 08 adds keyword tracking configuration beneath an owned website. Each keyword
has a random 128-bit public ID and an internal website foreign key. Every operation
requires the corresponding `keywords.*` permission and resolves the parent website
by both opaque ID and authenticated owner before resolving the keyword. An archived
website remains readable but cannot create, edit, activate, deactivate, or delete
keywords.

Stored configuration includes display and normalized keyword text, optional target
URL, search-engine key, ISO-style two-letter country, BCP 47-style language tag,
device key, active state, and timestamps. Deletion is physical in Phase 08 because
no rank observations exist yet; Phase 09 migrations must choose retention behavior
before adding dependent history.

## Search and device configuration

Search engines and devices are validated against source-controlled arrays in
`config/keywords.php`. Initial engines are `google` and `bing`; the model stores an
engine key rather than a Google-specific enum or column. A later provider can add a
stable lowercase key through release configuration and implement tracking behind
its own adapter. Initial devices are `desktop` and `mobile` and use the same key-based
extension model.

Country is canonical uppercase and language is canonical lowercase. Whitespace in
keyword text is trimmed/collapsed and Unicode case-folded with required `mbstring`.
The unique tracking identity is website, normalized keyword, engine, country,
language, and device. This rejects accidental duplicates while allowing the same
term for a different device, market, language, engine, or website.

## Target URLs and network boundary

Target URL is optional. When present it must be an absolute HTTP(S) URL of at most
2048 bytes without credentials or fragments. Paths and queries are retained because
they identify legitimate landing pages. Phase 08 performs no DNS resolution, HTTP
request, redirect following, or rank lookup. Target-host ownership restrictions are
not imposed prematurely; tracking and SSRF-sensitive provider code must define its
own outbound-network policy in Phase 09.

## UI and audit

The website dashboard links to `/keywords?website=<public_id>`. Server-rendered pages
provide list, create, edit, activate/deactivate, and delete operations. All mutations
use POST and the application CSRF guard. Output is escaped and submitted fields are
explicitly mapped into a validated value object.

Creation, update, activation, deactivation, and deletion are recorded in the audit
log without keyword text or target URLs. Phase 08 does not schedule or execute rank
checks and does not create rank history.
