# Implementation record

Issue: ORB-234
Flow: discovery
Candidate: `68fb2bb9949f1b8c93574928a2292bb2c1937762`
Approved plan candidate: `d3c751f62124dc4c09999d74c4c726b1778dfb9e`
Approved plan artifact: `227ec438edbf63b42848e400e69a8c35635a6b61`

## Acceptance evidence

1. Exact candidate and filters: `apps/e2e/tests/Feature/Commands/ScenarioWrapperTest.php` and `apps/e2e/tests/Unit/E2E/ScenarioDefinitionTest.php` cover exact clean `HEAD`, repeated filters, unknown and duplicate IDs, invalid IDs, invalid input paths and fingerprints, invalid recipes, and missing deadlines before execution.
2. Complete retained results: `apps/e2e/tests/Unit/E2E/ScenarioResultTest.php` covers identity, normalized definition and recipe fingerprints, declared inputs, action and phase timing, verification, diagnostics, cleanup, atomic attempt state, and result round trips.
3. Independent serial cold flows: real Incus run `14764bdd3eeb819aadb3981c7ba5418a` ran `cold-four-node` and `cold-construction-cleanup` on separate exact inventories. Both passed. The fake-process suite test confirms the second flow runs after the first fails.
4. Outcome and aggregate behavior: wrapper and result tests cover `passed`, `failed`, `blocked`, and `infrastructure-error`, continuation after failure, nonzero process results, and aggregate creation after all results.
5. Exact cleanup and recovery: constructor and wrapper tests cover ownership refusal, reverse exact cleanup, remaining resources, reporting failure, and the exact recovery command. Real Incus run `46dd14b859dd161652c566065d330f73` ran only `cold-construction-cleanup` and passed.
6. Delivery and snapshot separation: wrapper and Composer configuration tests keep scenarios outside ordinary test and delivery paths. Both real scenario tests compared the promoted manifest before and after the flow. No scenario changed it or created proof or promotion state.
7. Documentation: `docs/reference/incus-topologies.md` was written in planning commit `d3c751f62124dc4c09999d74c4c726b1778dfb9e`. `composer docs-build && composer docs-lint` passed; generated context stayed current.
8. Harness checks: `cd apps/e2e && composer check` passed.

## Focused checks

- `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php tests/Feature/Configuration/ComposerConfigurationTest.php tests/Unit/E2E/ScenarioDefinitionTest.php tests/Unit/E2E/ScenarioResultTest.php tests/Unit/E2E/ColdTopologyConstructorTest.php tests/Unit/E2E/TopologyTargetTest.php tests/Unit/E2E/Value/TopologyRecipeTest.php`: passed, 75 tests and 246 assertions.
- `cd apps/e2e && composer check`: passed; guidance 4 tests and 22 assertions, Rector, Pint, and PHPStan level 6 all passed.
- `composer docs-build && composer docs-lint`: passed with 0 issues, errors, or warnings.
- Independent root `composer check`: pending reviewer.

## Discovery observations

- Discovery acquisition: attempt `18bbce957a0f3b8e1be2e3535c0459ac` remains available for reviewer inspection.
- `bin/e2e-scenarios cold 68fb2bb9949f1b8c93574928a2292bb2c1937762`: passed as run `14764bdd3eeb819aadb3981c7ba5418a`; aggregate counts were 2 passed, 0 failed, 0 blocked, and 0 infrastructure-error.
- `bin/e2e-scenarios cold 68fb2bb9949f1b8c93574928a2292bb2c1937762 --scenario=cold-construction-cleanup`: passed as run `46dd14b859dd161652c566065d330f73`; aggregate counts were 1 passed, 0 failed, 0 blocked, and 0 infrastructure-error.
- Resource absence: all 12 expected VM identities and all 3 expected network identities from the successful runs were absent after cleanup. Every retained cleanup result had empty `remaining` and `refused` lists.
- Detailed JSON: `/home/nckrtl/orbit/.e2e/scenarios/runs/14764bdd3eeb819aadb3981c7ba5418a/aggregate.json` and `/home/nckrtl/orbit/.e2e/scenarios/runs/46dd14b859dd161652c566065d330f73/aggregate.json`.
- Discovery development only; isolated acceptance proof not run.

## Diagnostic observations

- One invocation supplied a mistyped expanded SHA and stopped at the wrapper's exact-HEAD check before state or Incus mutation.
- Run `33578384bf140a4818263337b02878ba` exposed the Scenario test-state isolation defect. Both flows were recorded as infrastructure-error and exact cleanup found no resources.
- Run `46d29f4f4b282127a1cc5f514894d47a` exposed empty-object phase-state handling before Incus mutation. Both flows were recorded as infrastructure-error and exact cleanup found no resources.
- Run `748bbc9662119245f060c53289153417` constructed both inventories. The cleanup flow passed; the four-node flow reported the roleless VM as a registry peer. Both exact inventories cleaned successfully. The final definition now declares the roleless VM only in the physical recipe, not in the expected Orbit registry state.

## Documentation and deviations

- Documentation changed: `docs/reference/incus-topologies.md` defines the command, filters, serial execution, results, cleanup, and delivery boundaries.
- Documentation audit findings: fixed in the approved planning commit; no reported follow-up findings or owners.
- Deviations: none. The roleless physical VM remains in the four-node construction and cleanup inventory and remains absent from the expected Orbit Node registry, consistent with ADR 0019 and the approved acceptance meaning.
- Limitations: none for implementation. The independent root gate and code review belong to the reviewer.
