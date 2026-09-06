# Feature plan

Issue: ORB-128
Review verdict: FIX

## Outcome

Keep Laravel sample convergence operational while App list responses migrate from `main_branch` to `default_branch`.

## Code boundaries

In:
- `apps/e2e/resources/guest/converge-sample-app.sh`: change only the matched typed App response validation so a present `default_branch` must be a nonempty string, while an absent `default_branch` permits a nonempty legacy `main_branch`; preserve the remaining App, AppInstance, and Route validation and mutation order.
- `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php`: extend the typed sample fixture and focused cases for both accepted branch-field shapes, precedence and invalid values, pre-mutation refusal, retained identity checks, and second-run reuse.

Out:
- Product API aliases and response contracts stay unchanged; do not change `apps/cli/`, `apps/gateway/`, or `packages/php-sdk/`.
- Product source identity and branch-selection rules stay unchanged; ORB-125 owns the product field migration.
- Laravel provisioning and setup commands stay unchanged; do not change the `hydrate` behavior in `apps/e2e/resources/guest/converge-sample-app.sh`.
- The topology roles, resources, and convergence sequence stay unchanged; do not change `apps/e2e/app/E2E/TopologyConverger.php` or its topology definitions.

## Documentation

none: ORB-128 preserves the documented sample-convergence contract while adapting internal harness validation; the issue-scoped audit found no drift and no maintained page needs a change.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Both branch-field response shapes are accepted with `default_branch` preferred | `apps/e2e/resources/guest/converge-sample-app.sh`; `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/ConvergenceGuestScriptsTest.php --colors=never` |
| Invalid or missing branch fields fail closed before App or AppInstance creation | `apps/e2e/resources/guest/converge-sample-app.sh`; `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/ConvergenceGuestScriptsTest.php --colors=never` |
| Existing App identity, duplicate, and AppInstance ownership guards remain exact for both shapes | `apps/e2e/resources/guest/converge-sample-app.sh`; `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/ConvergenceGuestScriptsTest.php --colors=never` |
| A second installed-product convergence reuses the sample App, AppInstance, source, and Route | `.loop/proof/ORB-128.json` action `sample-branch-field-compatibility` | `bin/e2e-topology prove ORB-128`, action `sample-branch-field-compatibility` |
| AppInstance creation remains before sample hydration | Preserved sequence in `apps/e2e/app/E2E/TopologyConverger.php` and unchanged `hydrate` boundary in `apps/e2e/resources/guest/converge-sample-app.sh` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/TopologyConvergerTest.php tests/Unit/E2E/ConvergenceGuestScriptsTest.php --colors=never` |
| E2E project checks pass | All in-scope `apps/e2e` boundaries | `cd apps/e2e && composer check` |
| Repository test suites pass | All in-scope boundaries | `bin/test` |

## Implementation order

1. Extend the typed sample fixture so tests can supply exact App objects with either branch field, both fields, or invalid and missing values without weakening its existing identity and mutation assertions.
2. Add the response-shape matrix and verify every refusal occurs before `app:new` or `instance:new`, while the accepted cases still reuse the same App, AppInstance, checkout, and Route on a second run.
3. Update the typed App validator to distinguish an absent `default_branch` from a present invalid value, accept only the two contracted shapes, and leave every other field and ownership check intact.
4. Add `.loop/proof/ORB-128.json` with the `sample-branch-field-compatibility` action on `app-dev`; after standard proof convergence, invoke the installed `converge-sample-app.sh create-resources app-dev app-prod 0000000000000000000000000000000000000000` again so its typed path validates and reuses the existing sample identities. The final argument satisfies the command's legacy input shape and is not consumed by the typed path.
5. Run the focused test files, `cd apps/e2e && composer check`, and `bin/test`, then run the fresh Incus proof plan for the exact candidate.

## Must preserve

- The legacy `instances` convergence path and nullable legacy records remain unchanged.
- A present `default_branch` is authoritative: an invalid value fails closed even when `main_branch` is valid, and fallback to `main_branch` occurs only when the `default_branch` key is absent.
- Repository URL, slug, name, root, integer App ID, duplicate detection, AppInstance ownership, checkout identity, source evidence, and explicit private Route validation keep their current fail-closed checks and ordering.
- ADR 0005, **Use one complete profile**: Incus proof stays within the issue-specific `gateway_app-dev_app-prod` topology and keeps its existing roles, identities, and isolation.
- ADR 0005, **Treat snapshots as an acceleration cache**: proof synchronizes and runs the exact clean candidate; host Git remains the source authority.
- ADR 0015, **Preserve the complete proof lifecycle**: full topology convergence still creates or reconciles sample resources before issue acceptance actions, and the extra action supplies issue acceptance evidence rather than replacing general convergence or verification.
- ADR 0015, **Preserve exact review, merge, and evidence gates**: the proof plan and successful zero-exit action evidence stay bound to the exact proved candidate and immutable proof state.
- ADR 0015, **Preserve the production boundary**: disposable Incus convergence remains development evidence and does not become production state or release authority.
- ADR 0030: Orbit completes and activates the AppInstance and Route without application-health or dependency gates, and explicitly configured application setup remains after provisioning; sample hydration must not move before AppInstance creation.

## Open questions

none.

## Deviations

none.

## Review findings

- `FIX` — Acceptance item 4 requires the second installed-product convergence to reuse the same App and AppInstance and retain the existing source and Route identities. The planned `sample-branch-field-compatibility` action only invokes `converge-sample-app.sh create-resources` again. That command can still exit `0` after creating a missing App, AppInstance, or Route, and its typed state neither records nor compares the AppInstance ID, Route ID, selected branch, or starting commit before and after the rerun. Add a self-checking proof boundary that captures the exact App, AppInstance, source, and sole Route identities after standard convergence, reruns the installed script, reads them again, and exits nonzero unless every identity is unchanged and no replacement or duplicate was created. Name the proof fixture or exact argv that performs those checks and bind it to the focused fixture coverage.
