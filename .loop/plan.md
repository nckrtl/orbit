# Feature plan

Issue: ORB-226
Review verdict: PASS

## Outcome

Provide a reusable Gateway operation that installs one explicitly selected, transactionally consistent live SQLite snapshot on a production target without changing the source AppInstance.

## Code boundaries

In:
- `apps/gateway/app/Domain/AppInstances/Sqlite/**`: define the source and target placement values, the SQLite seeder contract, and bounded confirmed or unconfirmed outcomes needed for safe retry.
- `apps/gateway/app/Infrastructure/AppInstances/RemoteAppInstanceSqliteSeeder.php`: validate the candidate path and both placements, create and verify the live snapshot, coordinate protected transfer, install the target atomically, and bind cleanup and retry to one owned seed attempt.
- `apps/gateway/app/Infrastructure/AppInstances/ProtectedSqliteSnapshotTransfer.php`: stream only the owned snapshot between the recorded Nodes without putting database bytes in command results, logs, or activity data; this remains specific to SQLite seeding.
- `apps/gateway/app/Providers/AppServiceProvider.php`: bind the SQLite seeder contract to its remote implementation.
- `apps/gateway/tests/Feature/Infrastructure/AppInstances/SqliteSeedTest.php`: execute the source and target programs locally and through fakes to cover preflight, live WAL snapshots, atomic installation, protected transfer, interruption, ownership, and identical retry.

Out:
- Database selection remains explicit; no discovery or inferred default database is added.
- MySQL, PostgreSQL, external storage, and a reusable general file-copy API remain unchanged.
- Source queues, processes, schedules, checkpoints, configuration, and database contents remain unchanged; target queue cleanup is not performed.
- Clone request orchestration, target creation, SDK and CLI inputs, deployment, and the E2E harness remain unchanged.

## Documentation

- `docs/reference/appinstance-cloning.md`: added the optional SQLite seed, source-path and capacity requirements, live-snapshot behavior, fixed target installation, retry boundary, and target-only application cleanup.
- `docs/reference/deployments.md`: linked the persistent production database path to the cloning seed contract.
- `docs/README.md`: routed readers to the new AppInstance cloning reference.
- `docs/generated/context.json`: rebuilt the documentation index for the new reference and links.
- Audit scope: ORB-226 with `apps/gateway`; fixed findings: none; reported findings: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Explicit source validation and source/target capacity fail before replacement | SQLite domain placement values; remote seeder preflight; SQLite seed feature test | `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Infrastructure/AppInstances/SqliteSeedTest.php`; after plan review, reproduce `sqlite-seed-preflight` on discovery with invalid path, file, database, source capacity, and target capacity cases and inspect the unchanged target |
| Concurrent WAL writes produce one valid snapshot without source workload mutation | Remote seeder snapshot command; SQLite seed feature test | The focused Pest file runs a WAL writer while snapshotting and verifies integrity and continuing writes; after plan review, reproduce `sqlite-live-snapshot` on discovery while a writer, queue worker, and schedule sentinel continue, and compare source state and issued command inventory |
| Protected transfer installs one atomic target database with target ownership and permissions | SQLite-specific protected transfer; remote seeder installer; SQLite seed feature test | The focused Pest file verifies target bytes, integrity, inode replacement, owner/mode, bounded receipts, protected streams, and redacted diagnostics; after plan review, reproduce `sqlite-seed-install` on discovery and inspect target metadata plus bounded Gateway logs and activity |
| Interrupted and repeated seeds affect only owned incomplete work | Remote seeder attempt state and cleanup; SQLite seed feature test | The focused Pest file injects snapshot, transfer, install, acknowledgement, and cleanup failures, retains unrelated files, and verifies an identical completed retry keeps the target inode; after plan review, reproduce `sqlite-seed-retry` on discovery from an owned interrupted attempt and with an unrelated destination |
| Documentation describes the complete SQLite seed contract | Documentation pages listed above | `composer docs-build`; `composer docs-lint` |
| Changed-project and repository suites pass | All changed Gateway boundaries | Focused Pest file; `cd apps/gateway && composer check`; `composer check` at repository root during independent code review; full no-TIA suites with `composer test` in `apps/cli`, `apps/gateway`, `apps/docs`, `apps/e2e`, and `packages/php-sdk` on the submitted candidate |

Incus observations: not applicable; discovery flow. The named Incus venues are reproduced as discovery observations after independent preflight review and are not immutable acceptance proof.

## Implementation order

1. Add typed source and target SQLite seed placement and result contracts without coupling them to clone API input.
2. Add the SQLite-specific protected snapshot transfer so database bytes use protected streams and never enter a generic command result.
3. Implement source and target preflight, bounded live SQLite backup and integrity validation, atomic target installation, attempt ownership, cleanup, and retry receipts.
4. Bind the operation and add focused tests for every acceptance failure and recovery boundary.
5. Acquire discovery only after independent plan `PASS`, reproduce the four named live observations, then run focused tests, documentation checks, Gateway checks, and the required repository suites.

## Must preserve

- ADR 0047: SQLite seeding remains optional and accepts only one explicit source path; the seed comes from a consistent live snapshot; a clone without a seed creates no database.
- ADR 0047: source code, configuration, data, queues, processes, and schedules remain unchanged; Orbit does not stop the source or perform application cleanup, and the operating agent owns target cleanup before workers start.
- ADR 0047: an identical completed retry retains target data, and external storage and non-SQLite database preparation stay outside Orbit's seed operation.
- ADR 0044: environment storage, reference resolution, synchronization, and atomic `.env` replacement remain separate from database copying; no environment value or database path is read or rewritten by this operation.
- Existing `RemoteAppInstanceEnvironmentAccess` protected-input, bounded-output, placement, and retry behavior remains unchanged and covered by `AppInstanceOperationPreflightTest.php` and `AppInstanceEnvironmentWriterTest.php`.
- Existing production release layout keeps the optional database only at `<production-home>/database.sqlite`, and `ProductionReleaseLayoutTest.php` plus removal-retention tests keep that persistent-content boundary.
- The new transfer is SQLite-seed-specific, so it does not create the general file-copy API excluded by Scope.

## Open questions

- none

## Deviations

- none

## Review findings

- none
