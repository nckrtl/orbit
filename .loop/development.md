# Implementation correction record

Issue: ORB-234
Flow: discovery
Candidate: `e31b418e98f4b5c9ff22a35de89e848347a33115`
Reviewed predecessor: `4b9b1c691113d0543440180dae19b18b4bcf1778`
Approved plan artifact: `e71919d0ab4ae4912cf9f82af5d8af258b78babf`
Plan documentation commit: `d3c751f62124dc4c09999d74c4c726b1778dfb9e`

## Formal review corrections

1. Signal-killed child: `ScenarioPestProcess` catches Symfony's `ProcessSignaledException`, retains partial standard output and error output, appends the exact signal diagnostic, and returns exit code `128 + signal`. The suite therefore performs parent-side exact recovery, continues later selected flows, writes all results, and persists the complete aggregate.
2. Real-adapter coverage: `ScenarioWrapperTest.php` now runs a real `ScenarioPestProcess` against a PHP child that records its invocation and kills itself with `SIGKILL`. The test verifies the signal diagnostic, exact attempt recovery, retained recovery command, second-flow invocation, and persisted two-result aggregate. The earlier exit-143 fake interruption test was replaced.
3. Construction cleanup evidence: `ColdTopologyConstructor` reports its internal cleanup result and timing through an optional observer while preserving its existing exception behavior. `ScenarioColdExecutor` retains that exact result instead of recording a second no-op cleanup. Successful construction rollback now lists every removed VM and network.
4. Cleanup coverage: `ColdTopologyConstructorTest.php` verifies the original construction diagnostic still propagates and the cleanup observer receives the exact reverse-order removed inventory. `ColdTopologyAcceptanceTest.php` verifies the retained result lists that same exact removed inventory.
5. `blocked` accuracy: `docs/reference/incus-topologies.md` now states that `blocked` is reserved in the result schema for an unavailable required run-scoped checkpoint and that no current committed cold flow produces it. Checkpoint-dependent flows remain outside ORB-234 scope.
6. Fingerprint citation: acceptance evidence cites `ScenarioDefinitionTest.php` for input and action fingerprint invalidation and `ScenarioResultTest.php` for complete result serialization and round trips.

## Acceptance evidence

1. Exact candidate and filters: `apps/e2e/tests/Feature/Commands/ScenarioWrapperTest.php` and `apps/e2e/tests/Unit/E2E/ScenarioDefinitionTest.php` cover exact clean `HEAD`, repeated filters, unknown and duplicate IDs, invalid IDs, invalid input paths and fingerprints, invalid recipes, and missing deadlines before execution.
2. Complete retained results: `apps/e2e/tests/Unit/E2E/ScenarioResultTest.php` covers identity, normalized fingerprints, inputs, actions, phase timing, verification, diagnostics, cleanup, result round trips, and atomic retained state. `apps/e2e/tests/Unit/E2E/ScenarioDefinitionTest.php` verifies that declared-input and bounded-action changes invalidate the definition fingerprint. The exact-candidate aggregates contain complete per-flow results.
3. Independent serial cold flows: the real signal-killed process case in `ScenarioWrapperTest.php` verifies recovery and continuation to a second flow. Exact-candidate Incus run `b68d1e2060e8c0c1464a63f4120611d9` ran `cold-four-node` and `cold-construction-cleanup` on separate exact inventories; both passed.
4. Outcome and aggregate behavior: `ScenarioWrapperTest.php` and `ScenarioResultTest.php` verify `passed`, `failed`, and `infrastructure-error` execution and the complete aggregate-before-exit contract. `blocked` is represented and round-tripped in the result schema; no current cold definition has the run-scoped checkpoint dependency that would produce it.
5. Exact cleanup and recovery: `ScenarioColdExecutorTest.php` covers `SIGINT`, `SIGTERM`, and dual-failure diagnostic preservation. The real-adapter case in `ScenarioWrapperTest.php` covers parent recovery after `SIGKILL`, continuation, and the aggregate. `ColdTopologyConstructorTest.php` covers construction rollback, actual removed inventory, ownership refusal, reverse exact cleanup, leftovers, and preservation of the primary exception. Exact-candidate Incus cleanup run `0f6b4a6dc7bbab21a1d18416bcde9b8b` passed and retained every removed resource.
6. Delivery and snapshot separation: `ScenarioWrapperTest.php` and `ComposerConfigurationTest.php` keep the supported scenario entry point outside ordinary test and delivery paths. The unused `test:scenario-cold` alias remains absent. Both exact-candidate real scenario observations left the promoted manifest unchanged and created no proof or promotion state.
7. Documentation: `docs/reference/incus-topologies.md` documents commands, filters, produced and reserved outcomes, retained results, exact cleanup recovery, and delivery boundaries. Documentation build and lint passed with generated context current.
8. Harness checks: `cd apps/e2e && composer check` passed. The exact-candidate Builder gate passed across all five projects.

## Check receipts

- `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php tests/Feature/Configuration/ComposerConfigurationTest.php tests/Unit/E2E/ScenarioColdExecutorTest.php tests/Unit/E2E/ScenarioDefinitionTest.php tests/Unit/E2E/ScenarioResultTest.php tests/Unit/E2E/ColdTopologyConstructorTest.php tests/Unit/E2E/TopologyTargetTest.php tests/Unit/E2E/Value/TopologyRecipeTest.php`: passed, 79 tests and 275 assertions.
- `cd apps/e2e && composer check`: passed; guidance 4 tests and 22 assertions, Rector, Pint, and PHPStan level 6 all passed.
- `composer docs-build && composer docs-lint`: passed with 0 issues, errors, or warnings; generated context is current.
- `cd apps/e2e && vendor/bin/pint --dirty --format agent`: passed after applying its required formatting fixes, then passed unchanged before the final project check.
- Builder gate: passed (`/home/nckrtl/orbit/.git/orbit-checks/e31b418e98f4b5c9ff22a35de89e848347a33115/review-xhfpf0na/result.json`). The receipt records `role: builder`, candidate `e31b418e98f4b5c9ff22a35de89e848347a33115`, tree `622fdaf03facff5881a281ed7d2bdab43f0d0023`, `passed: true`, and `unchanged: true`; all 15 project validate, check, and affected-test commands exited 0.
- No startup quality check was rerun or claimed.

## Exact-candidate discovery observations

- Discovery attempt `18bbce957a0f3b8e1be2e3535c0459ac` remains available for reviewer inspection.
- `bin/e2e-scenarios cold e31b418e98f4b5c9ff22a35de89e848347a33115`: passed as run `b68d1e2060e8c0c1464a63f4120611d9`; aggregate counts were 2 passed, 0 failed, 0 blocked, and 0 infrastructure-error.
- `bin/e2e-scenarios cold e31b418e98f4b5c9ff22a35de89e848347a33115 --scenario=cold-construction-cleanup`: passed as run `0f6b4a6dc7bbab21a1d18416bcde9b8b`; aggregate counts were 1 passed, 0 failed, 0 blocked, and 0 infrastructure-error.
- The cleanup result for each successful flow lists four VMs in reverse Node order followed by its exact network under `removed`; `absent`, `remaining`, and `refused` are empty.
- Every instance and network carrying either run ID is absent after cleanup.
- Detailed aggregates: `/home/nckrtl/orbit/.e2e/scenarios/runs/b68d1e2060e8c0c1464a63f4120611d9/aggregate.json` and `/home/nckrtl/orbit/.e2e/scenarios/runs/0f6b4a6dc7bbab21a1d18416bcde9b8b/aggregate.json`.
- Discovery development only; isolated acceptance proof not run.

## Documentation, deviations, helpers, and limitations

- Documentation changed: `docs/reference/incus-topologies.md`, first in planning commit `d3c751f62124dc4c09999d74c4c726b1778dfb9e`, now corrected to distinguish the reserved `blocked` schema status from outcomes produced by the current cold catalog.
- Documentation audit: no reported follow-up findings or owners.
- Deviations: none. The correction does not add checkpoint support or change the approved plan.
- Helpers: two bounded advisory helpers inspected the signal boundary and cleanup evidence path. They made no edits and supplied no approval. Both finished before handoff.
- Limitations: `blocked` has no current producer because checkpoint-dependent flows are outside this issue. Formal independent rereview remains pending.
