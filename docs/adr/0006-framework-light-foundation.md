# ADR 0006: Framework-light application foundation

- Status: Accepted; supersedes ADR 0001
- Date: 2026-08-21

## Context

Phase 02 verified the empty repository and attempted dependency resolution. The
build environment cannot reach Packagist, so Laravel cannot be installed or
integrity-locked. Committing an unverified handwritten Laravel skeleton would not
boot; vendoring reconstructed framework code would create a security and update
liability. The current phase requires only configuration, routing, PDO, errors,
logging, independent module registration, security headers, sessions, CSRF
primitives, and CLI checks.

## Decision

Implement a deliberately small PHP 8.1 kernel with Composer PSR-4 metadata and no
third-party runtime package. Keep the inward module layering from ADR 0001. Use
explicit bootstrap composition, prepared native PDO connections, immutable config,
JSON-lines logging, exact-match routing, and module manifests. Keep abstractions
small enough to replace incrementally. Do not emulate Laravel APIs or introduce a
second container/kernel. A later framework adoption requires a superseding ADR,
working dependency resolution, lockfile audit, and passing behavioral tests.

## Consequences

The application boots and tests without network or business modules and has a
small shared-host attack surface. The project owns maintenance of this kernel and
lacks mature framework conveniences. Future phases must not casually expand it
into a bespoke general framework: adopt focused audited dependencies when the
build can resolve them, or revisit Laravel with a controlled migration.
