# ADR 0002: Optional module boundaries

- Status: Accepted
- Date: 2026-08-21

## Context

Search Console must be optional while dashboards and reports may later consume its
data. Direct table/code dependencies would make Core boot and upgrades fragile.

## Decision

Modules own code, schema, provider, migrations, jobs, routes, and UI contributions.
Dependencies point to Core or another module's explicit public contract and remain
acyclic. Optional providers register only from validated boot configuration.
Optional data is exposed through capability/query contracts; Core has no Search
Console type or foreign-key dependency. Disable, uninstall, and data deletion are
separate lifecycle operations.

## Consequences

Core works without optional source/tables and optional failures can be isolated.
Cross-module DTOs/events and compatibility manifests require governance. Some
queries cannot use convenient cross-module joins and should instead use projections
or composed query results. Architecture tests will prevent forbidden namespaces.
