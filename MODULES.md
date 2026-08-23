# Module Architecture

## Principles

Modules are compile-time code boundaries within one deployable PHP
application. A module owns its use cases, domain rules, schema, adapters, routes,
tests, and public contracts. It may depend on Core and explicitly declared public
contracts, never on another module's controllers, models, migrations, or internal
tables. Cycles are forbidden and will be guarded by architecture tests.

`Core` is not a miscellaneous module. It contains framework-agnostic primitives
such as IDs, clock contracts, pagination types, and shared tenancy/actor concepts
only when stable. Framework conveniences remain in normal Laravel locations.

## Planned module map

This is a dependency plan, not implemented functionality:

| Module | Responsibility | Allowed direct dependencies |
|---|---|---|
| Identity | users, authentication identities, account lifecycle | Core |
| Access | roles, permissions, authorization policy data | Core, Identity public contract |
| Websites | tracked sites and ownership/access association | Core, Identity/Access contracts |
| Keywords | keywords, locale/device tracking configuration | Core, Websites contract |
| RankTracking | check scheduling, provider ports, observations/history | Core, Websites/Keywords contracts |
| Dashboard | read-only projections and charts | public query contracts from relevant modules |
| Reporting | report definitions, generation, delivery | public query contracts; Identity actor contract |
| SearchConsole | optional OAuth connections, properties, sync/data | Core, Websites contract |
| Audit | security/business audit event persistence | Core, Identity actor contract |
| Settings | typed non-secret administrator preferences | Core |
| ModuleManagement | installed/enabled module metadata and compatibility | Core |

Installer, updater, logging, and generic infrastructure are platform capabilities,
not business modules. Search Console is optional: no Core interface references its
types, its provider/routes/jobs/listeners register only when installed and enabled,
and its tables can remain absent. Dashboard/Reporting discover optional datasets
through capability/query contracts rather than conditional SQL joins.

## Module lifecycle

Each module supplies `module.json` with a stable name, code version, provider
class, dependency list, and later an application compatibility range/migrations. Manifests
are source-controlled and contain no secrets. Installation verifies code and
dependencies, runs migrations through the central migrator, then records the
installed version. Enable/disable controls provider activation but is rejected
when dependants or scheduled/running work make the transition unsafe.

The loader validates names, semantic versions, provider contracts, missing
dependencies, and dependency cycles, then topologically orders enabled providers.
It reads only source-controlled manifests and never discovers modules from request
input or the database during boot.

Core modules required for boot cannot be disabled. Disabling an optional module
stops new routes, scheduling, jobs, and UI integration but does not silently drop
data. Uninstall/data deletion is a distinct, explicit, authorized workflow and is
not part of ordinary updates.

## Communication and presentation

Public contracts use DTOs/value objects rather than Eloquent models. Synchronous
contract calls serve immediate consistency. Domain/application events notify
other modules without coupling the publisher to listeners; critical delivery
uses a transactional outbox if later requirements demonstrate that ordinary
after-commit queued events are insufficient.

Module HTTP endpoints use module-prefixed route names and middleware, form
requests for validation, policies/gates for authorization, and API resources for
serialization. Shared navigation uses contribution contracts so optional module
views are never referenced directly by Core.

## Frontend decision

No frontend is implemented. A later phase may introduce escaped server-rendered
templates with progressively enhanced JavaScript and a pinned asset pipeline.
Node, if adopted, is build-time only. A SPA remains unjustified by current
requirements; charting should be adapter-wrapped and loaded only where needed.
