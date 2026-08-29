# Architecture

## Status and repository audit

This document is the Phase 01 architecture baseline. The repository at commit
`3640541` contains only an empty `.gitkeep`; it has no application code,
framework, PHP or Composer constraint, frontend toolchain, routes, database
layer, authentication, tests, configuration, CI, web-server configuration, or
deployment scripts. The current branch is `work`, has no configured remote, and
was clean before these documents were added. Consequently, everything below is
a target design, not a description of implemented behavior.

No runtime framework was inherited. Phase 02 verified that installing Laravel is
not possible in the build environment and found that the foundation needs only a
small HTTP/CLI kernel. The implemented target is therefore a Composer PSR-4
**framework-light modular monolith** requiring PHP 8.1, JSON, and PDO. This avoids
shipping unaudited vendored code and keeps ordinary hosting requirements small.
ADR 0006 supersedes ADR 0001; adopting a framework later requires another ADR and
an incremental migration rather than parallel kernels.

## Architectural goals

- Deploy through Composer to ordinary Apache/DirectAdmin hosting while exposing
  only the web root.
- Keep one transactional application and database until measured scale warrants
  extraction; isolate capabilities through modules rather than services.
- Keep optional integrations, especially Search Console, outside Core dependency
  paths. Disabling or omitting them must not break authentication or rank tracking.
- Make HTTP, console, scheduler, and queued jobs thin delivery mechanisms around
  application services.
- Prefer secure defaults, repeatable migrations, observable failures, and
  replaceable external providers.

## Target directory layout

The implemented Composer layout defines these boundaries:

```text
app/
  Core/                    # shared contracts and deliberately small primitives
  Modules/<Module>/
    Application/           # use cases, commands/queries, DTOs, ports
    Domain/                # entities/value objects/policies/domain events
    Infrastructure/        # PDO adapters, providers, external clients
    Presentation/          # HTTP controllers/requests/resources and console UI
bootstrap/                 # autoload and application bootstrap
config/                    # non-secret, environment-resolved configuration
database/migrations/       # ordered core and module schema migrations
public/                    # the only Apache document root; front controller/assets
routes/                    # composition-level routes only
storage/                   # runtime persistent data, never shipped as source
tests/{Unit,Feature,Architecture}/
docs/adr/
```

Module-owned routes, views, translations, migrations, and configuration may live
under their module when Laravel's service-provider APIs can load them. A module
must not reach into another module's Infrastructure namespace or tables. Shared
concepts move to `app/Core` only after more than one stable consumer exists.

## Runtime and request flow

Apache directs non-file requests to `public/index.php`. The application kernel
establishes trusted-host rules, correlation ID, sessions, CSRF protection for all
unsafe browser methods, and security headers. Authentication, authorization,
proxy trust, and endpoint-specific rate limiting remain later-phase concerns.
Controllers validate transport input and call an Application use case. The use
case coordinates Domain behavior and transaction boundaries through explicit
ports. Infrastructure adapters use PDO and future queues, cache, mail,
HTTP clients, and provider APIs. Responses are mapped by Presentation resources;
domain objects are not serialized directly.

Console commands, scheduled work, and queue jobs call the same use cases. Jobs
carry stable scalar identifiers, are idempotent where retryable, define timeout,
retry/backoff, and failure handling, and never store access tokens in payloads.

## Service-layer boundaries

Application services represent business use cases (for example, later,
`RecordRankCheck`), own authorization-independent orchestration and transaction
boundaries, and depend inward on domain types and contracts. HTTP authorization
is enforced at the edge and invariant-sensitive permission checks are repeated in
the use case. Domain services contain business rules that do not naturally
belong to one entity. Infrastructure services implement storage or vendor ports.

Avoid generic `*Service` bags, global helpers, service locators, and controllers
calling Eloquent models for multi-step workflows. Cross-module work uses public
application interfaces and immutable events. In-process synchronous calls are the
default when an immediate result is required; durable queued listeners are used
for slow or retryable side effects. Event schemas require compatibility care.

## Dependency injection

Use constructor injection and explicit bootstrap composition. Each module exposes
one provider implementing the Core `Module` contract and registers only its public
resources. Bind interfaces at volatility boundaries (external APIs,
clock/ID generation where deterministic tests matter, and repositories described
below), not around every class. Runtime module enablement is resolved during
bootstrap from cached, database-independent configuration; business code must not
query a module table on every request.

## Repository pattern decision

Do not create generic CRUD repositories. Prepared PDO statements are appropriate
inside module-local persistence adapters and read projections. Define a
narrow domain repository interface only for an aggregate whose invariants need
storage-independent tests, for a provider boundary, or when two implementations
are credible. Interfaces belong to Application/Domain; PDO implementations
belong to Infrastructure. Reporting and chart queries use dedicated query objects
returning DTOs and may optimize SQL without hydrating aggregates. See
`docs/adr/0003-selective-repositories.md`.

## Configuration management

Committed `.env.example` documents required variables with safe placeholders.
`.env` is host-managed, unreadable from the web, untracked, and never logged.
PHP configuration files are the only place that reads environment variables;
application code uses the immutable configuration repository. Configuration
is divided into:

1. immutable deployment configuration (environment, database, cache, queue,
   mail, trusted proxies/hosts, encryption key) in environment variables;
2. non-secret administrator preferences in a typed, cached settings store;
3. encrypted integration credentials in the database, protected by a key that
   remains outside both source and database.

Changing an encryption key requires an explicit rotation procedure. Secret
values are redacted from exceptions, audit records, health output, and logs.

## Persistence and transactions

MySQL/MariaDB with InnoDB, `utf8mb4`, UTC timestamps, foreign keys, and strict SQL
mode is the system of record. Exact MySQL and MariaDB minimum versions must be
selected with the first schema migration, when an engine compatibility test can
substantiate the claim; Phase 02 has no schema and therefore makes none. The
foundation exposes only parameterized `execute`, `fetchOne`, `fetchAll`,
and transaction operations over PDO; modules do not receive raw credentials.
Transactions live in application use cases; external network calls do not occur
while database locks are held. Queue jobs use after-commit dispatch where they
depend on committed data.

Cache, queue, and session drivers must be selectable for shared hosting. Database
drivers provide the portable baseline; Redis is an optional optimization. The
scheduler is triggered by one cron entry every minute. Long-running queue workers
may be replaced by bounded cron-driven queue processing on hosts without a
process supervisor, with reduced throughput documented.

Phase 09 selects a database-backed, lease-based queue as the initial Rank Tracking
control plane so Apache/DirectAdmin does not require a resident worker. A minute cron
invokes a bounded CLI worker; enrolled agents may outbound-poll and atomically lease
eligible work. Attempts, executor provenance, modeled/native device semantics, and
network context are immutable. Provider/server execution never claims the user's IP,
and fallback never silently changes execution source. Phase 10 implements this queue
and adapter boundary, while live adapters remain disabled pending ADR 0012 approval.
See `RANK_TRACKING.md` and ADR 0012.

## Logging, errors, and observability

Use the foundation JSON-lines logger with UTC time, environment,
severity, correlation ID, actor ID where known, module, event name, and job/check
identifier. Never log credentials, tokens, session IDs, raw authorization
headers, or unnecessary personal/search data. The foundation writes a protected
JSON-lines file; production must configure host `logrotate` until selectable sinks
are introduced. Log retention and permissions are deployment settings; audit logs
are separate immutable-intent database records with their own retention policy.

Expected domain/application failures use typed exceptions or result types and map
to stable, non-sensitive HTTP responses. Validation is 422, unauthenticated is
401 (or login redirect for browser flows), unauthorized is 403, missing is 404,
conflict is 409, and rate limiting is 429. Unexpected failures receive a
correlation ID, are reported once centrally, return a generic production message,
and preserve details only in protected logs. CLI/jobs exit or fail explicitly so
monitoring can detect them.

## Deployment constraints

- Preferred: point the domain document root to `<release>/public`.
- If DirectAdmin fixes the root at `public_html`, make `public_html` a symlink to
  the release's `public` directory or deploy only public assets/front controller
  there with host-specific bootstrap paths. Never copy `.env`, `vendor` internals,
  source, storage, backups, or Composer metadata under a web-accessible root.
- Apache requires `mod_rewrite` and `AllowOverride` when `.htaccess` is used;
  equivalent vhost rules are preferable when available. Disable directory listing
  and deny sensitive dotfiles at server level as defense in depth.
- Required PHP extensions and resource limits will be derived and documented when
  dependencies are pinned. CLI and Apache must run compatible PHP versions.
- The PHP/web and CLI users need read access to a release and write access only to
  `storage`, `bootstrap/cache`, and explicitly configured upload/export paths.
  Never use mode `0777`.
- Persistent paths, database, `.env`, and user-generated data survive releases.
  Releases are replaceable and read-only after build. See `INSTALLATION.md` and
  `UPDATE.md`.

## Quality gates for implementation phases

Executable changes require unit, feature, integration, and architecture tests as
appropriate; static analysis, formatting, dependency/security audit, migration
tests against supported databases, and a production build. Phase 02 provides a
dependency-free test runner until a test framework is deliberately adopted.

Phase 19 makes security boundary validation executable: deployment mode gates debug
rendering, session configuration is fail-closed, OAuth redirects are parsed rather
than prefix-matched, sensitive logs are recursively redacted, and transport/browser
headers are scheme-aware. These controls remain in Core so optional modules cannot
weaken the common HTTP, session, redirect, or logging boundaries.

Phase 20 formalizes independently runnable unit, integration, end-to-end, feature,
and architecture suites. Integration/E2E tests use isolated SQLite databases and
deterministic provider fakes; the runner refuses an externally supplied non-SQLite
test DSN. Xdebug line coverage is supported when available, but release confidence is
based on the critical-path matrix and assertions documented in `TESTING.md`, not a
percentage target. Live Google and rank-provider verification remain separate staging
activities and are never inferred from fixture results.
