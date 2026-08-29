# ADR 0009: Database-driven union-of-roles authorization

- Status: Accepted
- Date: 2026-08-23

## Context

Future modules need extensible capabilities without embedding role names throughout
business code. Users may need multiple organizational roles, while authentication
must remain separate from authorization.

## Decision

Represent permission definitions and role assignments in normalized database
tables. Effective capabilities are the distinct union of permissions attached to
all roles of an active authenticated user. Require namespaced permission keys at
both controller and application-service boundaries. Seed definitions from an
idempotent catalog migration, but resolve decisions from database data. Reserve
permission-definition creation for module migrations because unenforced arbitrary
keys have no security meaning.

## Consequences

Modules can introduce stable permission keys without changing the authorization
algorithm, and multiple roles compose naturally. Authorization reads add database
work and must never be replaced by UI visibility checks. Assignment operations need
strict ID validation, lockout invariants, transactions, and audit records. Phase 06
does not infer resource ownership; later modules must combine capability checks with
server-side object scoping to prevent IDOR/BOLA.
