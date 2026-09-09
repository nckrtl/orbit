# Feature plan

Issue: ORB-166
Review verdict: Not independently reviewed; direct implementation authorized.

## Outcome

Cluster changes that do not alter Route placement inputs bypass reconciliation. Placement changes hydrate and validate only Routes that depend on the affected Nodes or Clusters while preserving global hostname ownership for the operation.

## Code boundaries

In:
- `apps/gateway/app/Domain/Routes/RouteMutationReconciler.php`
- `apps/gateway/app/Actions/Clusters/UpdateClusterAction.php`
- `apps/gateway/tests/Feature/Domain/RouteMutationReconciliationTest.php`
- `apps/gateway/tests/Feature/Api/AppInstancesTest.php`
- `docs/reference/routes.md`

Out:
- Route architecture or private Route semantic changes
- Cross-operation caches or concurrency ownership changes
- Incus harness implementation and proof resources
- Unrelated Gateway, CLI, SDK, and documentation behavior

## Documentation

## Documentation audit

Scope: ORB-166 and `docs/reference/routes.md`, the maintained page that owns Route mutation reconciliation behavior.

Fixed:
- `docs/reference/routes.md`: the later-mutation text implied validation across all retained Routes -> it now describes affected dependency closure, operation-local global hostname ownership, and unchanged unrelated Routes.

Reported:
- None.

Verification: `composer docs-build` and `composer docs-lint` passed.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Name-only and unchanged placement-input Cluster patches ignore unrelated failed checkout state and preserve unrelated Routes. | `UpdateClusterAction`, workset selection | Domain reconciliation and AppInstances API regressions |
| Workset covers direct/Cluster scope, multi-target and targetless Routes, retained generation bases, and provisioning baseline overrides. | `RouteMutationReconciler` dependency query | Focused domain reconciliation matrix |
| Global hostname collisions, active mutation refusal, migration-required names, and atomic updates remain intact. | Operation-local hostname owner index and existing proposal validation | Focused collision and preservation regressions plus existing reconciliation tests |
| Unrelated graph growth does not increase affected Route hydration. | Workset query and eager loading | Model hydration-count regression |
| Gateway project checks pass. | `apps/gateway` | `composer check` |
| Repository suites pass. | monorepo | Deferred to root's serialized final verification window as dispatched |

## Implementation order

1. Audit the Route reference and correct the affected behavior statement.
2. Add focused failing regressions for bypass, dependency closure, global ownership, and bounded hydration.
3. Add operation-local Route ownership indexing and affected-workset querying.
4. Bypass reconciliation when a Cluster patch leaves TLD and state unchanged.
5. Run focused tests, documentation checks, and the Gateway project check.
6. Commit a clean product/docs checkpoint and stop for root's final integration and root-suite window.

## Must preserve

- Global hostname collision ownership, including unaffected Route owners
- Stable Route ordering and target ordering
- Explicit hostnames and migration-required generated hostnames
- Refusal before writes for active Route changes
- Transaction rollback and unrelated Route state
- Complete dependency closure for Node/Cluster overrides and provisioning baselines
- No cache between reconciliation operations

## Open questions

- None.

## Deviations

- None.

## Review findings

- None; independent review belongs to the root orchestrator after this checkpoint.

## Verification results

- `vendor/bin/pest tests/Feature/Domain/RouteMutationReconciliationTest.php`: 16 tests, 76 assertions passed.
- Focused acceptance and Cluster API files: 78 tests, 667 assertions passed.
- `PHPRC=/dev/null composer check` in `apps/gateway`: 2,662 tests, 14,997 assertions passed; Rector, formatting, and Mago passed.
- `composer docs-build` and `composer docs-lint`: passed.
