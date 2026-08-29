# ADR 0003: Selective repository interfaces

- Status: Accepted
- Date: 2026-08-21

## Context

Laravel's Eloquent already provides persistence mapping. A repository per table
would duplicate it, obscure useful query capabilities, and produce generic CRUD
interfaces. Domain aggregates and vendor boundaries can nevertheless benefit from
storage-independent contracts.

## Decision

Use Eloquent/query builder directly inside module Infrastructure for simple
module-local writes and dedicated read query objects for projections. Introduce a
narrow repository interface only for aggregate persistence, meaningful alternate
implementations, or deterministic domain testing. Contracts face inward and never
return Eloquent models across modules.

## Consequences

There is less ceremony and read-heavy rank/report queries remain optimizable.
Application services must still avoid ad-hoc multi-module database access.
Reviewers decide repository use by behavior and boundary value, not naming rules.
