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

This map includes implemented and planned module boundaries; each phase documents
which providers are enabled:

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

Installer, account, administration, and website management pages use escaped
server-rendered HTML with no JavaScript dependency. Later phases may introduce
progressive enhancement and a pinned asset pipeline. Node, if adopted, is build-time
only. A SPA remains unjustified; charting should be adapter-wrapped and loaded only
where needed.

Phase 07 enables `Websites`. Its normalization value object, lifecycle service,
presentation controller, and factory live under `app/Modules/Websites`; centrally
discovered release migrations remain under `database/migrations`. Its domain and
application layers do not depend on Keywords, Rank Tracking, or Search Console.

Phase 08 enables `Keywords`, depending only on the `Websites` module plus Core
identity/access infrastructure. The module owns validation, lifecycle operations,
presentation, and route registration. Search engines and devices are configurable
keys; it does not reference a rank-tracking provider or observation schema.

Phase 09 keeps `RankTracking` unimplemented while accepting its architecture. The
future module owns request/attempt/result contracts and execution-adapter ports.
`local_agent`, `provider_api`, and approved `server_adapter` implementations remain
replaceable infrastructure; Core, Websites, and Keywords do not depend on them.
Optional Search Console remains unrelated to rank execution.
