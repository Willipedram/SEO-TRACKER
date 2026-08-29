# ADR 0010: Owner-scoped canonical websites

- Status: Accepted
- Date: 2026-08-23

## Context

Website access needs a concrete scope before later domain data can reference it.
Formatting variants must not create duplicate sites, and shared membership has not
been designed.

## Decision

Give each website one immutable owner and require both an RBAC capability and owner
match. Use random 128-bit public identifiers in routes. Normalize input to an HTTP(S)
origin and enforce uniqueness by owner plus normalized domain, irrespective of
protocol. Archive instead of deleting. Do not perform network access.

## Consequences

Two owners may register the same domain, while one owner cannot create variants based
on case, trailing slash, trailing dot, or protocol. Global website permissions do
not grant access to another owner's records. Sharing or support access requires a
later explicit membership/delegation design and migration.
