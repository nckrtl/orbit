# Feature plan

Plan format: 1
Issue: ORB-234
Flow: discovery
Review verdict: PASS

## Outcome

An operator can run selected committed cold scenarios serially for one exact candidate and receive complete per-flow Pest and JSON results plus an aggregate after every runnable flow finishes.

## Code boundaries

In:
- Implement the issue's single `In` bullet in `bin/e2e-scenarios`; `apps/e2e/app/Console/Commands/Scenario/`; new flat `Scenario*` orchestration services under `apps/e2e/app/E2E/`; scenario state under the existing `apps/e2e/app/E2E/State/`; scenario definition, identity, status, result, and aggregate values under the existing `apps/e2e/app/E2E/Value/`; and the related bindings in `apps/e2e/app/Providers/AppServiceProvider.php`. Extend `apps/e2e/app/E2E/ColdTopologyConstructor.php`, `apps/e2e/app/E2E/Value/ColdTopologyCleanupResult.php`, `apps/e2e/app/E2E/Value/TopologyRecipe.php`, and `apps/e2e/app/E2E/Value/TopologyTarget.php` to bind a scenario run and attempt to normalized construction inputs, phase evidence, and exact recovery. Update `apps/e2e/composer.json`, `apps/e2e/tests/Pest.php`, `apps/e2e/tests/Feature/Commands/ScenarioWrapperTest.php`, `apps/e2e/tests/Feature/Configuration/ComposerConfigurationTest.php`, `apps/e2e/tests/Scenario/ColdTopologyAcceptanceTest.php`, `apps/e2e/tests/Unit/E2E/ColdTopologyConstructorTest.php`, `apps/e2e/tests/Unit/E2E/ScenarioDefinitionTest.php`, `apps/e2e/tests/Unit/E2E/ScenarioResultTest.php`, `apps/e2e/tests/Unit/E2E/TopologyTargetTest.php`, and `apps/e2e/tests/Unit/E2E/Value/TopologyRecipeTest.php` for focused Pest coverage and the two committed cold flows.

Out:
- Keep snapshot-lane scenarios and every `TopologySnapshot*` service unchanged; keep execution serial and add no worker count, worker pool, or parallel capacity scheduler; add no nightly, pull-request, CI, or affected-flow trigger or selector; add no PCOV observation path; change no product behavior in `apps/cli`, `apps/gateway`, or `packages/php-sdk`; do not connect scenarios to discovery acquisition, issue proof, review, merge, or any feature-proof gate; and do not replace, refresh, promote, or write receipts for the persistent topology snapshot. Do not change `apps/e2e` harness infrastructure outside the scenario runner and the existing disposable cold-construction seams named above.

## Documentation

- `docs/reference/incus-topologies.md`: now defines the cold command and repeatable scenario filter, pre-mutation validation, serial independent execution, retained per-flow and aggregate JSON, four result meanings, nonzero aggregate exit, exact cleanup recovery, and the boundaries from feature proof and topology-snapshot state.
- `docs/generated/context.json`: `composer docs-build` confirmed the generated context is current; its bytes did not change.
- Documentation audit fixed the former single-flow guidance and its statement that selection and aggregation were separate work. No other page in the issue-scoped `apps/e2e` context drifted, and there are no reported findings or follow-up owners.
- Documentation commit: `d3c751f62124dc4c09999d74c4c726b1778dfb9e` (`docs: define cold scenario suite behavior`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Resolve the exact candidate and optional filter; reject all invalid suite inputs before Incus mutation. | `bin/e2e-scenarios`; scenario commands, catalog, and `ScenarioDefinition`; existing recipe and Git resolution values. | `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php tests/Unit/E2E/ScenarioDefinitionTest.php` |
| 2. Record complete identities, fingerprints, inputs, timings, action outcomes, verification, diagnostics, and cleanup; invalidate changed definitions or inputs. | Scenario definition normalization, declared-input hashing, `ScenarioResult`, aggregate values, and atomic scenario state. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/ScenarioResultTest.php` |
| 3. Give two selected cold flows separate exact inventories, stop one failed flow, clean it, and continue the other. | Serial scenario suite runner, Pest process adapter, cold flow executor, scenario state, `ColdTopologyConstructor`, and scenario acceptance tests. | `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Scenario/ColdTopologyAcceptanceTest.php --filter=cold-scenario-suite` as a reproducible discovery observation, plus the focused fake-process cases in `tests/Feature/Commands/ScenarioWrapperTest.php`. |
| 4. Distinguish passed, failed, blocked, and infrastructure-error in JSON and Pest, then exit nonzero only after the complete aggregate is written. | Scenario status/result/aggregate values, Pest process classification, report writer, and wrapper exit handling. | `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php` |
| 5. Attempt exact cleanup after success, action failure, reporting failure, and interruption; retain primary outcome, exact leftovers, and recovery after cleanup failure. | Scenario executor/state and cleanup command around `ColdTopologyConstructor` and `ColdTopologyCleanupResult`. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/ColdTopologyConstructorTest.php` and `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Scenario/ColdTopologyAcceptanceTest.php --filter=cold-scenario-suite-cleanup` as a reproducible discovery observation. |
| 6. Leave the promoted generation and manifest unchanged, create no proof or promotion receipt, and stay outside ordinary delivery commands. | Scenario command/catalog/state namespace, cold executor, wrapper, Composer scenario script, and scenario acceptance tests. | `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php` and `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Scenario/ColdTopologyAcceptanceTest.php --filter=cold-scenario-suite` as a reproducible discovery observation. |
| 7. Describe commands, filters, outcomes, and exact cleanup with current generated context. | `docs/reference/incus-topologies.md`; generated documentation context. | `composer docs-build && composer docs-lint` |
| 8. Pass harness checks. | All changed `apps/e2e` PHP, tests, Composer scripts, and command wrapper integration. | `cd apps/e2e && composer check` |

## Incus observations

- Incus required: the issue's `incus` label records the real-machine requirement independently of its selected `discovery` flow, as current `origin/main` clarifies in ADR 0058.
- After independent plan review, acquire ORB-234 discovery for development and run the `cold-scenario-suite` and `cold-scenario-suite-cleanup` commands from the acceptance map as the required real-machine observations. These disposable cold runs are development observations, not issue proof.
- Proof instrumentation, a proof topology, observed-input collection, exact-commit proof, main-freshness checks, and snapshot closeout are not applicable; discovery flow.

## Implementation order

1. Add strict scenario identifiers, statuses, normalized definitions, declared-input digests, results, and aggregate values. Reuse `TopologyRecipe`, exact Git commit resolution, and canonical JSON hashing. Validate the whole catalog and selection before creating run state or calling Incus.
2. Add private atomic scenario-run state under `<primary>/.e2e/scenarios/runs/<run-id>/`. Record each attempt's exact target, operation, inventory, primary outcome, cleanup outcome, and supported recovery command before and after every mutation boundary.
3. Wrap the existing `ColdTopologyConstructor` in one cold-flow executor that records phase timings and action/verification evidence, stops after the first failed required step, and runs exact cleanup in `finally` for success, failure, report-write failure, and interruption. Keep persistent cold construction byte-for-byte compatible.
4. Add the serial suite runner and thin scenario commands. Run each selected definition as one isolated Pest test, translate the test result into the four scenario outcomes, continue independent flows, write one complete aggregate, and return nonzero after the final write when any result is not passed.
5. Extend `bin/e2e-scenarios` and the Composer scenario entry point for optional repeated `--scenario` filters and exact `cleanup RUN_ID SCENARIO_ID ATTEMPT_ID` recovery while preserving clean-HEAD candidate checks and exclusion from default suites.
6. Convert the two existing cold acceptance flows into committed definitions and extend focused unit, wrapper, result, constructor, and real-Incus acceptance coverage for validation-before-mutation, separate inventories, continuation, complete reporting, snapshot immutability, and exact recovery.
7. Run all acceptance-map focused tests, `cd apps/e2e && composer check`, and the two named discovery observations. Confirm the default root and project test paths still do not invoke the scenario suite.

## Must preserve

- ADR 0019: scenario runs are disposable regression evidence for one exact commit and never issue acceptance, feature-proof, topology-snapshot promotion, or production-release authority.
- ADR 0019: every committed scenario has a unique ID, cold lane, ordered recipe, bounded setup/exercise/assertion actions, declared non-PHP inputs, expected end state, optional observation declaration, and a normalized fingerprint in its result.
- ADR 0019: faithful cold scenarios start from the unchanged `orbit-base-ubuntu-26.04-runtime` image, synchronize the exact candidate, and never read or mutate the persistent topology snapshot.
- ADR 0019: persistent snapshot construction and disposable cold scenarios continue to share the typed cold constructor, while persistent fixed identities, manifests, corruption/recovery checks, and promotion rules remain unchanged and no `live` snapshot namespace returns.
- ADR 0019: physical Node keys stay separate from assigned roles; identifiers remain bounded and deterministic from run, scenario, attempt, and Node key; a scenario never adopts an unrecorded VM.
- ADR 0019: one independent flow is one Pest test; a required-step failure stops that flow, cleanup still runs, and other selected flows continue on separate mutable state. An unavailable required checkpoint reports `blocked`, not a product failure.
- ADR 0019: every selected flow reports passed, failed, blocked, or infrastructure-error with complete identity, action, timing, verification, diagnostic, and cleanup data, and the process decides its exit only after writing the aggregate.
- ADR 0019: faithful cold execution adds no pre-construction PCOV instrumentation; observed-input and affected-flow selection remain outside this issue.
- ADR 0019: every exit path attempts cleanup from the exact recorded inventory; ownership is revalidated before deletion; cleanup failure retains the primary outcome, remaining resources, and one exact recovery action without broader deletion.
- ADR 0019: the runner stays operator-invoked and serial; feature delivery, scheduling, pull-request triggers, snapshot scenarios, and worker budgeting do not invoke or depend on it.
- ADR 0058 on current `origin/main`: the `incus` label requires real-machine discovery observations but does not change the selected discovery flow or authorize a proof topology.
- Preserve `TopologyRecipeTest`, `TopologyTargetTest`, `ColdTopologyPlanTest`, `TopologySnapshotBuilderTest`, and `TopologySnapshotRefresherTest` invariants for the canonical three-Node profile, persistent names, fixed slot, image fingerprint refusals, and snapshot manifests.
- Preserve the existing command and state rules: exact validated identifiers, safe paths, secret redaction, private atomic JSON, no HTTP/database/queue state, thin commands, and no deletion by prefix, glob, age, or unresolved value.
- Preserve `ScenarioWrapperTest` and Composer configuration coverage that keeps real scenario tests outside `bin/test`, TIA, feature acquisition/proof, review checks, and ordinary Composer suites.

## Open questions

- none: the issue, accepted ADR 0019, current disposable constructor, and existing cold acceptance flows determine the implementation without a new product decision.

## Deviations

- none: the issue already names focused tests, the `incus` requirement, and the current changed-project check. Its Incus harness acceptance venues map to the two reproducible discovery observations above; isolated proof, proof inputs, candidate freshness, and snapshot closeout do not apply in discovery flow. No issue-text or label correction is required.

## Review findings

- none
