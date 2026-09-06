# Feature plan

Issue: ORB-128
Review verdict: PASS

## Outcome

Keep Laravel sample convergence operational while App list responses migrate from `main_branch` to `default_branch`.

## Code boundaries

In:
- `apps/e2e/resources/guest/converge-sample-app.sh`: change only the matched typed App response validation so a present `default_branch` must be a nonempty string, while an absent `default_branch` permits a nonempty legacy `main_branch`; preserve the remaining App, AppInstance, and Route validation and mutation order.
- `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php`: extend the typed sample fixture and focused cases for both accepted branch-field shapes, precedence and invalid values, pre-mutation refusal, and second-run reuse; execute the issue proof fixture against unchanged, replacement, and duplicate identity responses.

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
| A second installed-product convergence reuses the sample App, AppInstance, source, and Route | `.loop/proof/ORB-128.json`; `.loop/proof/sample-branch-field-compatibility.sh` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/ConvergenceGuestScriptsTest.php --filter='self-checking sample branch compatibility proof' --colors=never`; then `bin/e2e-topology prove ORB-128`, action `sample-branch-field-compatibility` with argv `["bash", "/var/lib/orbit-e2e/proof/sample-branch-field-compatibility.sh"]` |
| AppInstance creation remains before sample hydration | Preserved sequence in `apps/e2e/app/E2E/TopologyConverger.php` and unchanged `hydrate` boundary in `apps/e2e/resources/guest/converge-sample-app.sh` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/TopologyConvergerTest.php tests/Unit/E2E/ConvergenceGuestScriptsTest.php --colors=never` |
| E2E project checks pass | All in-scope `apps/e2e` boundaries | `cd apps/e2e && composer check` |
| Repository test suites pass | All in-scope boundaries | `bin/test` |

## Implementation order

1. Extend the typed sample fixture so tests can supply exact App objects with either branch field, both fields, or invalid and missing values without weakening its existing identity and mutation assertions.
2. Add the response-shape matrix and verify every refusal occurs before `app:new` or `instance:new`, while the accepted cases still reuse the same App, AppInstance, checkout, and Route on a second run. Add a focused test for `.loop/proof/sample-branch-field-compatibility.sh` that proves unchanged identities pass and changes to the App ID, AppInstance ID, selected branch, starting commit, or Route ID, plus a duplicate App, AppInstance, or matching Route, each fail.
3. Update the typed App validator to distinguish an absent `default_branch` from a present invalid value, accept only the two contracted shapes, and leave every other field and ownership check intact.
4. Add `.loop/proof/sample-branch-field-compatibility.sh`. Run as the `orbit` user with the installed CLI environment. Before the rerun, read `app:list`, `instance:list`, and `route:list`; fail unless there is exactly one `laravel-typed` App, exactly one `e2e-dev` AppInstance owned by it, and exactly one Route associated with that AppInstance. Normalize a snapshot containing the App ID and exact repository, slug, name, root, and effective branch-field identity; the AppInstance ID, App and Node ownership, checkout path, selected branch, starting commit, effective root, and status; and the Route ID, App and scope identities, hostname, provenance, publication, status, and complete target identity. Invoke the installed `converge-sample-app.sh create-resources app-dev app-prod 0000000000000000000000000000000000000000`, read and validate the three responses again, and exit nonzero unless the canonical before and after snapshots are byte-for-byte equal. Exact cardinality checks before and after make replacement plus duplication fail even when the rerun exits `0`.
5. Add `.loop/proof/ORB-128.json` with the `sample-branch-field-compatibility` action on `app-dev` and exact argv `["bash", "/var/lib/orbit-e2e/proof/sample-branch-field-compatibility.sh"]`, so the harness stages the named fixture and runs its self-check after standard proof convergence.
6. Run the focused test files, `cd apps/e2e && composer check`, and `bin/test`, then run the fresh Incus proof plan for the exact candidate.

## Must preserve

- The legacy `instances` convergence path and nullable legacy records remain unchanged.
- A present `default_branch` is authoritative: an invalid value fails closed even when `main_branch` is valid, and fallback to `main_branch` occurs only when the `default_branch` key is absent.
- Repository URL, slug, name, root, integer App ID, duplicate detection, AppInstance ownership, checkout identity, source evidence, and explicit private Route validation keep their current fail-closed checks and ordering.
- The Incus acceptance action is self-checking: success requires one unchanged sample App ID, AppInstance ID and source, and Route ID before and after the rerun; the installed convergence command's exit code alone is not acceptance evidence.
- ADR 0005, **Use one complete profile**: Incus proof stays within the issue-specific `gateway_app-dev_app-prod` topology and keeps its existing roles, identities, and isolation.
- ADR 0005, **Treat snapshots as an acceleration cache**: proof synchronizes and runs the exact clean candidate; host Git remains the source authority.
- ADR 0015, **Preserve the complete proof lifecycle**: full topology convergence still creates or reconciles sample resources before issue acceptance actions, and the extra action supplies issue acceptance evidence rather than replacing general convergence or verification.
- ADR 0015, **Preserve exact review, merge, and evidence gates**: the proof plan and successful zero-exit action evidence stay bound to the exact proved candidate and immutable proof state.
- ADR 0015, **Preserve the production boundary**: disposable Incus convergence remains development evidence and does not become production state or release authority.
- ADR 0030: Orbit completes and activates the AppInstance and Route without application-health or dependency gates, and explicitly configured application setup remains after provisioning; sample hydration must not move before AppInstance creation.

## Open questions

none.

## Deviations

- The durable E2E suite no longer executes the issue-specific proof fixture. ADR 0022 requires durable fixture checks to apply generically to the current branch rather than name one `.loop` fixture, and `.loop` must be deleted after workspace approval. `ProofFixtureShellContractTest.php` keeps the generic fixture contract; the fresh Incus action remains the acceptance evidence for installed-product identity reuse.

## Review findings
