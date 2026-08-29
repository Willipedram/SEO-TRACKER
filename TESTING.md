# Testing

The E2E suite includes both a clean extracted-release installation and a real
two-source replacement update. Run the latter with
`php tests/run.php --suite=e2e --filter=SourceReplacement`; it uses an isolated
SQLite database outside both release trees. See
[`UPDATE_VALIDATION.md`](UPDATE_VALIDATION.md) for its precise boundaries.

## Test contract

Phase 20 separates fast behavioral tests, database/service integration tests,
extracted-release end-to-end tests, HTTP/controller feature tests, and architecture
boundary tests. The runner sets
`APP_ENV=testing`, refuses a non-SQLite `SEO_TRACKER_TEST_DSN`, and every database test
uses either `sqlite::memory:` or a unique temporary SQLite file. It never reads the
production database configuration as test storage.

```bash
composer test
composer test:unit
composer test:integration
composer test:e2e
composer test:feature
composer test:architecture
php tests/run.php --filter=OAuth
```

Tests may be filtered by case-insensitive class/method text. A filter matching no test
is an error rather than a misleading green run. Suite output includes per-category and
total counts, failures, and elapsed time.

## Coverage

When Xdebug coverage mode is available, `composer coverage` writes application line
coverage to `storage/logs/coverage.txt`. Coverage counts executable `app/` lines only;
configuration, migrations, tests and generated/vendor code are excluded. Coverage is
evidence for locating gaps, not a release target or substitute for meaningful checks.
The report is a local build artifact and is ignored with the other log files.

## Coverage matrix

| Area | Primary evidence |
| --- | --- |
| Authentication, reset, throttling, session state | Unit plus HTTP/bootstrap feature tests |
| RBAC, permissions, privilege/IDOR boundaries | Unit and direct-endpoint feature tests |
| Installer, updater, migrations | Unit, integration database setup, update feature test |
| Websites and keywords | Domain/service unit tests and controller feature tests |
| Rank queue, retry, rate limits, history, desktop/mobile | Unit plus migrated-database workflow integration |
| Search Console module/OAuth/sync | Integration tests with deterministic Google gateway fakes |
| Extracted release/fresh state and HTTP smoke flows | End-to-end suite |
| Dashboard, reports and localization | Unit/service rendering tests |
| Modules/settings and security boundaries | Unit, feature and architecture suites |

The Search Console tests use fake Google responses and do **not** verify live OAuth or
live API synchronization. Rank adapter fixtures verify queue/result contracts and do
**not** prove live SERP execution, provider correctness, or user-IP semantics.

## Remaining gaps

- Live Google OAuth/Search Console and a production rank adapter require credentialed,
  interactive staging verification outside automated CI.
- MySQL migration/query compatibility still needs a disposable MySQL CI service; the
  repository suite deliberately refuses production-style database targets.
- Browser accessibility, visual regression, and real RTL layout require browser tooling.
- Concurrency/lease behavior is transaction-tested but should receive multi-process
  stress testing on the production database engine.
