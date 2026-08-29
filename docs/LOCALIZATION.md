# Localization architecture

Persian (`fa`) is the application default and English (`en`) is the required fallback.
`APP_LOCALE` may select either locale without changing database values, permission keys,
module identifiers, job states, API fields, or routes.

## Presentation pipeline

Module-specific catalogs remain under `lang/{locale}/`. Cross-application legacy UI
copy is mapped through structured keys in `lang/{locale}/ui.php` by `UiLocalizer` at the
HTTP response boundary. This permits installer, authentication, administration, update,
and older module controllers to use the same fallback and RTL policy while controllers
are incrementally moved to direct catalog keys. Missing locale entries fall back to the
English catalog; an unknown key remains visible as its key so automated tests can detect
it rather than silently showing an empty label.

Technical terminology is independent of normal messages. `lang/fa/terms.php` stores a
preferred Persian label, canonical English term, and at most one short clarification.
`UiLocalizer` adds a semantic button and `role=tooltip` only to recognized UI labels.
The English content is isolated with `dir=ltr`. Hover, keyboard focus, click/tap, Escape,
and outside-trigger interaction are supported by the shared stylesheet and external
`tooltips.js`; no inline script is needed.

## RTL and mixed-direction values

Persian pages emit `lang=fa` and `dir=rtl`. Layout uses CSS grid/flex and logical inline
properties rather than globally forcing text alignment. URLs, emails, numeric inputs,
code, versions, IDs, and detected technical values receive isolated LTR direction.
Raw values never receive terminology tooltips.

## Normalization

`PersianNormalizer` is intentionally limited to authored translation catalog strings:
Arabic `ي/ى/ك` become Persian `ی/ی/ک`. It is never applied to passwords, tokens,
cryptographic values, URLs, domains, email addresses, IDs, API credentials, or stored
keywords. A tracked keyword is preserved byte-for-byte after its existing domain
validation; localization must not change the query sent to a rank provider.

## Date, number, and timezone policy

Database and API timestamps remain canonical UTC-compatible Gregorian values. Current
forms display ISO/Gregorian dates and ASCII technical numbers. No Jalali conversion is
performed in this phase. A future calendar formatter must remain presentation-only and
must not change stored timestamps, versions, positions, IP addresses, IDs, or URLs.
