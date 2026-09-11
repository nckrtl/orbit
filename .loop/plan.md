# Feature plan

Plan format: 1
Issue: ORB-237
Flow: discovery
Review verdict: PASS

## Outcome

Keep the loop launcher forwarding test generic while preserving its exact argument-forwarding contract.

## Code boundaries

In:
- `apps/e2e/tests/Unit/E2E/LoopLauncherTest.php`: replace the issue-specific sample identifier with a neutral valid identifier.

Out:
- `apps/e2e/tests/Unit/E2E/ProofFixtureContractTest.php`: keep the permanent fixture guard unchanged.
- Product and controller behavior: no runtime behavior changes.
- Unrelated tests and maintenance cleanup: no changes.

## Documentation

none: the issue changes test fixture data only and does not change documented product or operator behavior. The issue-scoped `apps/e2e` documentation audit found no drift caused by this repair.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| The configured driver receives the issue argument unchanged when called outside the repository. | `apps/e2e/tests/Unit/E2E/LoopLauncherTest.php` | `vendor/bin/pest --no-tia --compact tests/Unit/E2E/LoopLauncherTest.php` |
| The permanent fixture contract stays strict and passes. | `apps/e2e/tests/Unit/E2E/LoopLauncherTest.php`; preserve `ProofFixtureContractTest.php` | `vendor/bin/pest --no-tia --compact tests/Unit/E2E/ProofFixtureContractTest.php` |
| Focused tests and the E2E project quality checks pass. | `apps/e2e` | `vendor/bin/pest --no-tia --compact tests/Unit/E2E/LoopLauncherTest.php tests/Unit/E2E/ProofFixtureContractTest.php` and `composer check` from `apps/e2e` |

## Implementation order

1. Replace the issue-specific launcher fixture with a neutral syntactically valid issue identifier.
2. Run Pint, both focused contract tests, the E2E project check, and the exact-candidate Builder gate.

## Must preserve

- The launcher passes the caller's issue identifier to the configured controller without changing it.
- Permanent E2E tests contain no individual `ORB-*` or `NCK-*` issue artifacts.
- Incus is not required; the repair has no topology or user-visible surface.

## Open questions

none

## Deviations

none

## Review findings
