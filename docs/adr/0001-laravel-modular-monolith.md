# ADR 0001: Laravel modular monolith

- Status: Superseded by ADR 0006
- Date: 2026-08-21

## Context

The repository is empty and provides no framework constraint. The product has a
large but cohesive transactional domain, must run on conventional PHP hosting,
and needs optional modules without the operational cost of distributed services.

## Decision

Use a supported Laravel release, pinned during implementation after target-host
verification, as one deployable modular monolith. Propose PHP 8.1+ as the baseline.
Preserve Laravel conventions and isolate business capabilities under
`app/Modules`, enforced through public contracts and architecture tests. Use Blade
and progressively enhanced compiled assets initially. Node is build-time only.

## Consequences

Laravel supplies mature HTTP, console, DI, validation, database, queue, migration,
logging, and security primitives and is Composer-deployable. One deployment and
database fit shared hosting and transactional workflows. Module discipline must be
actively tested because PHP namespaces alone do not enforce it. Exact framework,
PHP, Node, extension, and engine versions remain a Phase 02 pinning decision and
must not be inferred from this documentation.

Alternatives rejected: bespoke PHP would recreate security/infrastructure; early
microservices conflict with hosting and add failure modes; a mandatory SPA adds
build/API complexity without a demonstrated interaction need.
