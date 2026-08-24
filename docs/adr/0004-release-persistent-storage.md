# ADR 0004: Separate immutable releases from persistent data

- Status: Accepted
- Date: 2026-08-21

## Context

DirectAdmin deployments often use `public_html`; updates must not overwrite user
data, expose source, or leave half-updated files.

## Decision

Keep versioned immutable releases separate from shared `.env`, runtime storage,
uploads/exports, backups, and database. Expose only a release's `public` directory,
prefer an atomic `current` symlink, and link explicit shared paths. If host limits
prevent symlinks, use a maintenance-protected staged rename and public-only web
root layout. Updates are CLI-first, signed/checksummed, migration-locked, backed
up, health-checked, and compatibility-aware.

## Consequences

Rollback and source integrity improve, and persistent data survives replacement.
Shared hosting needs documented fallbacks and may incur downtime. Operators must
manage permissions, backups, and symlinks/panel settings correctly.
