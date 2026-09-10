# Feature plan

Issue: ORB-212
Review verdict: IMPLEMENTATION COMPLETE; independent review pending

## Outcome

An authorized operator can synchronize one AppInstance's complete stored environment configuration to its recorded workload `.env` through the Gateway. The response and retained activity expose only bounded operation metadata. Synchronization is safe before application dependencies, caches, services, processes, or a database exist.

## Code boundaries

In:

- Gateway `POST /api/v1/instances/{instance}/environment/sync` request, controller, authorization, and result.
- Consistent encrypted storage size and decrypted configuration snapshots.
- Recorded placement and Route placeholder resolution.
- Deterministic dotenv rendering and the existing protected remote writer.
- One bounded per-AppInstance operation lock shared by environment operations, removal, and Route mutations.
- Gateway feature tests and `docs/reference/environment-variables.md`.

Out:

- SDK and CLI synchronization commands.
- Framework cache updates, service or process restarts, database setup, deployment, cloning, release layouts, and automatic synchronization during Route mutations.
- New environment storage or changed import, update, preflight, and remote writer contracts.
- Harness changes.

## Documentation

- `docs/reference/environment-variables.md`: documents the synchronization endpoint, selectors, recorded locations, placeholders, preflight-before-decryption, overwrite direction, concurrency, recovery, idempotency, and separate cache or process steps.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. The sync endpoint accepts exactly an empty JSON object, reuses selectors and owning-Node authorization, rejects malformed input before remote work, and rejects missing stored configuration without changing the file. | Route, Form Request, controller, action, storage snapshot. | `tests/Feature/Api/AppInstanceEnvironmentTest.php`; `tests/Feature/Configuration/NodeAccessRouteScopeTest.php`. |
| 2. Trusted SSH, execution identity, containment, write and replacement permissions, and conservative capacity are checked before decryption; placement is recorded and independent of web root. | Context resolver, raw encrypted-size capacity read, existing preflight, synchronization action. | `tests/Feature/Infrastructure/AppInstanceOperationPreflightTest.php`; Incus `environment-sync-placement-and-preflight`. |
| 3. Hostname and environment placeholders resolve from the current sole Route and recorded environment; literals remain literal and unavailable references stop replacement. | Context resolver and environment renderer. | `tests/Feature/Domain/AppInstanceEnvironmentRenderingTest.php`. |
| 4. Rendering has stable key order and valid dotenv escaping for accepted whitespace, newlines, quotes, dollar signs, backslashes, empty strings, and substitutions. | Environment renderer. | `tests/Feature/Domain/AppInstanceEnvironmentRenderingTest.php`, including supported dotenv loader round trips. |
| 5. The public operation installs a complete missing or existing `.env` through the reusable writer with runtime ownership and mode 0600; stored `APP_KEY` remains and local-only content disappears without storage adoption. | Synchronization action and existing protected writer. | Incus `environment-sync-complete-file`. |
| 6. Confirmed failures preserve the old destination and expose no secrets; an unconfirmed result returns `env.sync_unconfirmed` without an unchanged-file claim. | Preflight, snapshot, renderer, writer, action failure mapping, activity sanitization. | `tests/Feature/Infrastructure/AppInstanceEnvironmentWriterTest.php`; `tests/Feature/Api/AppInstanceEnvironmentTest.php`; `tests/Feature/Api/CommandActivityTest.php`. |
| 7. Import, update, sync, removal, and Route transitions cannot use stale ownership or a mixed snapshot; competitors wait or return a bounded conflict, and post-sync updates remain stored-only. | Per-AppInstance native operation lock integrated before existing source or projection locks, with owner revalidation. | `tests/Feature/Domain/AppInstanceEnvironmentConcurrencyTest.php`; `tests/Feature/Infrastructure/AppInstanceEnvironmentOperationLockTest.php`; `tests/Feature/Domain/AppInstanceRemovalCoordinatorTest.php`. |
| 8. Retry resolves current placement and storage again; matching protected files return `changed: false`, while changed content or protection returns `changed: true`. | Synchronization action and existing idempotent writer. | `tests/Feature/Api/AppInstanceEnvironmentTest.php`; Incus `environment-sync-retry-and-repeat`. |
| 9. Development and production sync change only `.env` and work without application bootstrap state. | Recorded placement, preflight, and protected writer only. | Incus `environment-sync-without-application-bootstrap`. |
| 10. Success returns only AppInstance ID, `operation: sync`, boolean `changed`, bounded `key_count`, and request metadata; all other surfaces remain value-free and success claims only file synchronization. | Environment result, controller response, exception mapping, activity sanitizer. | `tests/Feature/Api/AppInstanceEnvironmentTest.php`; `tests/Feature/Api/CommandActivityTest.php`. |
| 11. Maintained reference text covers the complete synchronization contract and all required repository checks pass. | `docs/reference/environment-variables.md` and project verification. | `composer docs-lint`; `cd apps/gateway && composer check`; root `bin/test`. |

## Implementation order

1. Add the strict API request and owning-Node authorization boundary.
2. Add raw encrypted-size capacity inspection and a consistent decrypted snapshot.
3. Add placeholder resolution and deterministic dotenv rendering.
4. Compose context, preflight, snapshot, rendering, writer, and narrow response in the synchronization action.
5. Add the bounded per-AppInstance native operation lock and integrate every competing mutation in sorted lock order.
6. Add focused API, rendering, concurrency, lock, activity, authorization, and removal tests.
7. Update the maintained environment reference.
8. Commit product, tests, and documentation; then add exact Incus proof artifacts and prove the immutable candidate.

## Must preserve

- Import and update remain stored-only operations.
- The reusable preflight and writer retain their existing contracts.
- Stored values, rendered bytes, protected input, and raw remote output never enter responses, activity, logs, diagnostics, or model serialization.
- The writer remains the only remote mutation boundary and installs one complete protected file.
- AppInstance environment locks are acquired in sorted owner order before existing source or projection locks.
- Product code does not change the E2E harness.
- `.loop` remains ignored and outside the product candidate; publish its complete workspace on the candidate-bound artifact reference before review.

## Open questions

None. The issue contract and accepted ADRs settle all material behavior.

## Deviations

None.

## Review findings

None recorded yet.
