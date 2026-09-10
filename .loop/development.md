# Implementation and main-conflict correction record

Issue: ORB-234
Flow: discovery
Candidate: `4b9b1c691113d0543440180dae19b18b4bcf1778`
Candidate parents: prior corrected candidate `1e7e522588f8cd9f508f4e5a2383e21f32f06e4a` and merged `origin/main` `15e09d0bd5e73c0f655b2f2682270dbe4ee73d92`
Approved plan artifact: `e71919d0ab4ae4912cf9f82af5d8af258b78babf`
Plan documentation commit: `d3c751f62124dc4c09999d74c4c726b1778dfb9e`

## Main-conflict correction

- Merged `origin/main` only because the published candidate had an actual merge conflict.
- Resolved the only conflict in `apps/e2e/app/Providers/AppServiceProvider.php` by retaining main's `ProofCaptureService`, `ProofCloseoutService`, and `ProofReviewService` imports and bindings together with ORB-234's `ScenarioPestProcess` import and singleton.
- No ORB-234 source, test, scenario, or plan behavior changed. Main's other changes merged without manual resolution.
- Preflight was not restarted. The approved plan and earlier acceptance evidence remain retained.

## Review corrections retained

1. Interruption evidence: `apps/e2e/tests/Unit/E2E/ScenarioColdExecutorTest.php` sends both `SIGINT` and `SIGTERM` through the installed handlers and verifies the retained diagnostic. `apps/e2e/tests/Feature/Commands/ScenarioWrapperTest.php` simulates an interrupted child with no result, then verifies exact attempt recovery, retained cleanup, the recovery command, and aggregate creation.
2. Dual construction and cleanup failure: `ScenarioColdExecutor` unwraps the original construction exception, uses it for the action evidence and diagnostic, retains the cleanup result embedded by `ColdTopologyCleanupException`, preserves the original primary outcome, reports the effective result as `infrastructure-error`, and adds the exact recovery command. `ScenarioColdExecutorTest.php` verifies the original diagnostic and every retained cleanup field.
3. Composer entry point: the unused `test:scenario-cold` script is absent. `ComposerConfigurationTest.php` requires it to stay absent and retains `scenario:cold` as the supported operator entry point used by `bin/e2e-scenarios`.
4. Evidence citations: the acceptance rows and commands below cite the files and commands actually run. Acceptance item 5 cites both interruption venues instead of attributing interruption behavior to `ColdTopologyConstructorTest.php` alone.

## Acceptance evidence

1. Exact candidate and filters: `apps/e2e/tests/Feature/Commands/ScenarioWrapperTest.php` and `apps/e2e/tests/Unit/E2E/ScenarioDefinitionTest.php` cover exact clean `HEAD`, repeated filters, unknown and duplicate IDs, invalid IDs, invalid input paths and fingerprints, invalid recipes, and missing deadlines before execution.
2. Complete retained results: `apps/e2e/tests/Unit/E2E/ScenarioResultTest.php` covers identity, normalized fingerprints, inputs, actions, phase timing, verification, diagnostics, cleanup, result round trips, and the first atomic retained phase. The retained aggregates contain complete per-flow results.
3. Independent serial cold flows: retained real Incus run `17072b86176cfc48546d93bb6bf770cc` ran `cold-four-node` and `cold-construction-cleanup` on separate exact inventories. Both passed. The fake-process suite case in `ScenarioWrapperTest.php` verifies that the second flow runs after the first fails.
4. Outcome and aggregate behavior: `ScenarioWrapperTest.php` and `ScenarioResultTest.php` cover `passed`, `failed`, `blocked`, and `infrastructure-error`, continuation after failure, nonzero process results, and aggregate creation after all results.
5. Exact cleanup and recovery: `ScenarioColdExecutorTest.php` covers `SIGINT`, `SIGTERM`, and preservation of the original diagnostic plus embedded cleanup details. `ScenarioWrapperTest.php` covers recovery after an interrupted or reporting-failed child. `ColdTopologyConstructorTest.php` covers construction rollback, ownership refusal, reverse exact cleanup, leftovers, and preservation of the primary exception. Retained real Incus run `8dc80f67f5ec917438ccc5e5b53a854b` ran only `cold-construction-cleanup` and passed.
6. Delivery and snapshot separation: `ScenarioWrapperTest.php` and `ComposerConfigurationTest.php` keep the supported scenario entry point outside ordinary test and delivery paths. The unused `test:scenario-cold` alias is absent. Both retained real scenario observations compared the promoted manifest before and after the flow. No scenario changed it or created proof or promotion state.
7. Documentation: `docs/reference/incus-topologies.md` was written in planning commit `d3c751f62124dc4c09999d74c4c726b1778dfb9e`. `composer docs-build && composer docs-lint` passed on the merged candidate; generated context stayed current.
8. Harness checks: `cd apps/e2e && composer check` passed on the merged candidate.

## Current-candidate check receipts

- `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php tests/Feature/Configuration/ComposerConfigurationTest.php tests/Unit/E2E/ScenarioColdExecutorTest.php tests/Unit/E2E/ScenarioDefinitionTest.php tests/Unit/E2E/ScenarioResultTest.php tests/Unit/E2E/ColdTopologyConstructorTest.php tests/Unit/E2E/TopologyTargetTest.php tests/Unit/E2E/Value/TopologyRecipeTest.php`: passed, 79 tests and 267 assertions.
- `cd apps/e2e && composer check`: passed; guidance 4 tests and 22 assertions, Rector, Pint, and PHPStan level 6 all passed.
- `composer docs-build && composer docs-lint`: passed with 0 issues, errors, or warnings; `docs/generated/context.json` remained unchanged.
- `cd apps/e2e && vendor/bin/pint --dirty --format agent`: passed before the merge commit; no formatting changes were needed.
- Independent root `composer check`: pending the formal reviewer.
- No startup quality check was rerun or claimed.

## Retained discovery observations

- The successful observations bind the prior corrected candidate `1e7e522588f8cd9f508f4e5a2383e21f32f06e4a`. They were retained because the merge resolution only combines service-provider registrations and the discovery flow does not rerun Incus scenarios or candidate equivalence after conflict resolution.
- Discovery acquisition attempt `18bbce957a0f3b8e1be2e3535c0459ac` remains available for reviewer inspection.
- `bin/e2e-scenarios cold 1e7e522588f8cd9f508f4e5a2383e21f32f06e4a`: passed as run `17072b86176cfc48546d93bb6bf770cc`; aggregate counts were 2 passed, 0 failed, 0 blocked, and 0 infrastructure-error.
- `bin/e2e-scenarios cold 1e7e522588f8cd9f508f4e5a2383e21f32f06e4a --scenario=cold-construction-cleanup`: passed as run `8dc80f67f5ec917438ccc5e5b53a854b`; aggregate counts were 1 passed, 0 failed, 0 blocked, and 0 infrastructure-error.
- Every instance and network carrying either successful run ID was absent after cleanup. Every retained cleanup result had empty `remaining` and `refused` lists.
- Detailed JSON: `/home/nckrtl/orbit/.e2e/scenarios/runs/17072b86176cfc48546d93bb6bf770cc/aggregate.json` and `/home/nckrtl/orbit/.e2e/scenarios/runs/8dc80f67f5ec917438ccc5e5b53a854b/aggregate.json`.
- Discovery development only; isolated acceptance proof not run.

## Documentation, deviations, helpers, and limitations

- Documentation changed in the approved planning commit: `docs/reference/incus-topologies.md` defines the command, filters, serial execution, results, cleanup, and delivery boundaries. Main's later edits to that maintained page merged without conflict and the current documentation checks pass.
- Documentation audit findings: fixed in the approved planning commit; no reported follow-up findings or owners.
- Deviations: none.
- Helpers: none used.
- Limitations: the retained Incus observations bind the pre-merge candidate, as permitted for discovery conflict correction; independent root `composer check` and formal rereview belong to the reviewer.
