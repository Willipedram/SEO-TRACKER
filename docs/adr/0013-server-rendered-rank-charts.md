# ADR 0013: Server-rendered localized rank charts

- Status: Accepted
- Date: 2026-08-23

## Context

Phase 11 needs an accessible, responsive historical chart on shared hosting without
introducing an unpinned JavaScript chart dependency or losing desktop/mobile and
missing-observation semantics. The UI must remain compatible with Persian/RTL.

## Decision

Aggregate immutable observations in a fixed-query application service and render a
server-side SVG plus HTML history table. Invert rank coordinates so #1 is highest,
split series on missing positions, retain separate device series, and render reference
thresholds at 1/3/5/10/20/50/100. Put all Phase 11 labels in locale catalogs and set
document language/direction from configuration.

## Consequences

Charts work without JavaScript or an asset build and cannot leak data through a chart
CDN. SVG is escaped and accessible, but advanced interaction is intentionally limited.
Future progressive enhancement may consume the same view model; it must preserve
axis, gap, provenance, authorization, and localization semantics.
