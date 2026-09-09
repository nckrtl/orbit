# Feature plan

Issue: ORB-187

## Outcome

Standalone Route target and removal commands protect the one-Route association of every active AppInstance. Exact target-set and target-clear retries remain no-op successes.

## Code boundaries

In:
- `apps/gateway/app/Actions/Routes`: order association validation before reconciliation and mutation.
- `apps/gateway/app/Domain/Routes`: report deterministic association conflicts.
- `apps/gateway/tests/Feature/Api/RoutesTest.php`: prove API errors and complete persisted-state preservation.
- `apps/gateway/tests/Feature/Domain/RouteMutationReconciliationTest.php`: prove invariant precedence and retained convergence refusals.
- `docs/reference/routes.md`: state standalone conflicts, no-op behavior, and coordinated removal.

Out:
- Coordinated Route swaps, AppInstance removal, target-set balancing, and infrastructure convergence.
- CLI, PHP SDK, database schema, and E2E harness implementation.

## Documentation

## Documentation audit

Scope: ORB-187 and the pages returned for `apps/gateway`, `Route`, and `AppInstance`.

Fixed:
- `docs/reference/routes.md`: standalone association violations were described as temporary convergence refusals -> the page states `route.target_conflict`, exact no-op behavior, and the coordinated-removal boundary.

Reported:
- None.

Verification: `composer docs-build` passed; `composer docs-lint` passed with 0 issues.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Active target replacement, clearing, and targeted Route removal refuse before mutation | Route association guard and standalone Route actions | `apps/gateway/tests/Feature/Api/RoutesTest.php`; Incus `route-target-invariant-refusals` |
| A target already owned by another Route refuses with both Routes unchanged | Route association guard before reconciliation and persistence | `apps/gateway/tests/Feature/Api/RoutesTest.php` |
| Existing target and already-empty clear are no-op successes | Early returns in target set and clear actions | `apps/gateway/tests/Feature/Api/RoutesTest.php` |
| Association conflicts precede temporary reconciliation; other active Route changes retain reconciliation refusal | Guard ordering in standalone actions | `apps/gateway/tests/Feature/Domain/RouteMutationReconciliationTest.php` |
| Maintained Route documentation and generated context are current | `docs/reference/routes.md`, `docs/generated/context.json` | `composer docs-build`; `composer docs-lint` |
| Gateway and repository checks pass | Gateway and root projects | `cd apps/gateway && composer check`; `PHPRC=/dev/null ORBIT_TEST_PROCESSES=80 bin/test` |

## Implementation order

1. Write focused failing API and domain regressions.
2. Add a narrow Route association guard and call it before reconciliation or mutation.
3. Update the maintained Route contract and generated documentation context.
4. Run focused tests, Gateway checks, documentation checks, and discovery diagnostics.
5. Coordinate current-main integration, root suites, and immutable Incus proof with the root orchestrator.

## Must preserve

- `route.reconciliation_required` for active Route changes that need unimplemented convergence.
- Existing validation precedence outside the association conflicts named by ORB-187.
- Coordinated AppInstance removal, production target sets, and all infrastructure projection behavior.
- Product branches do not change E2E harness implementation.

## Open questions

- None.

## Proof decision

- The Incus action exercises the Gateway's native API behavior on an isolated topology.
- The final proof plan is broad static API evidence and cannot support complete PHP input observations, so `observed_inputs` is false.

## Deviations

- None.

## Review findings

- None.

## Verification results

- `apps/gateway/vendor/bin/pest tests/Feature/Api/RoutesTest.php`: 8 passed, 95 assertions.
- `apps/gateway/vendor/bin/pest tests/Feature/Domain/RouteMutationReconciliationTest.php`: 12 passed, 69 assertions.
- `apps/gateway/vendor/bin/pest tests/Feature/Domain/RouteRemovalGuardTest.php`: 8 passed, 33 assertions.
- Focused AppInstance removal retry: 1 passed, 28 assertions.
- `composer docs-build`: passed with no tracked generated-context change.
- `composer docs-lint`: passed with 0 issues.
- `cd apps/gateway && composer check`: passed; 2,660 tests and 15,011 assertions, with Rector and Mago passing.
- Root `bin/test` and immutable Incus proof remain assigned to the serialized final proof window.
