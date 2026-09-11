# Feature plan

Plan format: 1
Issue: ORB-236
Flow: discovery
Review verdict: PASS

## Outcome

An operator can run selected cold and snapshot scenarios concurrently within an explicit worker limit and the live Incus VM budget, while receiving a complete result for every selected flow after failures, cleanup refusals, or interruption.

## Code boundaries

In:
- Implement the issue's single `In` bullet in `bin/e2e-scenarios`; a thin combined scenario command under `apps/e2e/app/Console/Commands/Scenario/`; `apps/e2e/app/E2E/ScenarioCatalog.php`, `ScenarioSuiteRunner.php`, `ScenarioPestProcess.php`, `ScenarioRecovery.php`, a focused `ScenarioScheduler.php`, and only the supporting scenario state and value objects under `apps/e2e/app/E2E/State/` and `apps/e2e/app/E2E/Value/` needed to represent queued, active, interrupted, and completed workers. Update bindings in `apps/e2e/app/Providers/AppServiceProvider.php`, the scenario Composer surface in `apps/e2e/composer.json`, `apps/e2e/tests/Pest.php` only if the real-Incus scheduler acceptance file needs the existing Scenario bootstrap, the named `apps/e2e/tests/Unit/E2E/ScenarioSchedulerTest.php`, and focused wrapper, process, state, result, capacity, and real-Incus scheduler tests. Reuse `HostCapacity`, `OperationLock`, `ColdTopologyConstructor`, and `IssueTopologyConstructor` as the atomic admission boundary: each child creates its own attempt under the shared `topology-create` lock, counts its recipe's actual VM size against every live harness topology, selects a free network slot, and releases the lock after its network and VM inventory exist so later guest work can overlap.

Out:
- Add no new lane, scenario definition, product scenario, shared run checkpoint, nightly schedule, pull-request trigger, affected-flow selector, continuous-integration hook, or feature-delivery gate. Do not change scenario setup, exercise, assertion, convergence, verification, or PCOV behavior; do not change product behavior in `apps/cli`, `apps/gateway`, or `packages/php-sdk`; do not connect scenario results to discovery, issue proof, review, merge, promotion, or production release; and do not refresh, replace, promote, or mutate the persistent topology snapshot. Keep existing lane-specific commands and exact cleanup recovery available, and do not change `apps/e2e` outside the scenario scheduling, process, state, capacity-admission, and test seams named above.

## Documentation

- `docs/reference/incus-topologies.md`: now defines the combined `run` command, required positive worker count, cross-lane selection and stable aggregate order, separate worker state, atomic recipe-sized capacity admission, overlapping post-creation work, continuation after worker failure or cleanup refusal, interruption cleanup, explicit unstarted results, and the boundary from nightly, pull-request, and affected-flow selection.
- `docs/generated/context.json`: `composer docs-build` confirmed the generated context is current; its bytes did not change.
- Documentation audit fixed the stale serial-execution paragraph and the statement that parallel workers remained separate work. The other pages in the issue-scoped `apps/e2e` context do not own scenario scheduling and had no drift. There are no reported findings or follow-up owners.
- Documentation commit: `77361bf00922a935bec89f257f07c71e61f3153f` (`docs: define bounded scenario workers`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Refuse invalid worker counts before mutation and keep the active worker set and every admitted cold, snapshot, or variable-size recipe within the requested concurrency and live host VM budgets. | Combined wrapper and command validation; catalog-wide selection; `ScenarioScheduler`; existing `HostCapacity`, `OperationLock`, `ColdTopologyConstructor`, and `IssueTopologyConstructor` atomic creation path. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/ScenarioSchedulerTest.php tests/Unit/E2E/HostCapacityTest.php tests/Feature/Commands/ScenarioWrapperTest.php` and reproducible discovery observation `scenario-worker-capacity`. |
| 2. Give concurrent scenarios separate mutable checkout, state root, network, guest, application data, operation, and attempt identities while allowing preparation and exercise to overlap after atomic creation. | Scheduler worker launch context; `ScenarioPestProcess`; `ScenarioRunStore`; scenario identity and target values; existing attempt-scoped cold and snapshot executors and constructors. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/ScenarioSchedulerTest.php tests/Unit/E2E/ScenarioPestProcessTest.php tests/Unit/E2E/ScenarioResultTest.php` and reproducible discovery observation `scenario-worker-isolation`. |
| 3. Continue unrelated runnable flows after a product failure or cleanup refusal and write one ordered aggregate with every selected flow's primary and cleanup outcomes before returning the final exit code. | `ScenarioScheduler`, `ScenarioSuiteRunner`, `ScenarioRecovery`, `ScenarioRunStore`, and scenario result and aggregate values. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/ScenarioSchedulerTest.php tests/Unit/E2E/ScenarioResultTest.php tests/Feature/Commands/ScenarioWrapperTest.php`. |
| 4. On interruption, stop launching work, attempt cleanup for every active worker from its recorded inventory, report every unstarted flow explicitly, preserve an unrelated topology, and retain actionable recovery state. | Parent signal handling and child termination in `ScenarioScheduler` and `ScenarioPestProcess`; exact fallback in `ScenarioRecovery`; attempt, result, and aggregate persistence in `ScenarioRunStore`. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/ScenarioSchedulerTest.php tests/Unit/E2E/ScenarioPestProcessTest.php` and reproducible discovery observation `scenario-worker-interruption`. |
| 5. State worker and capacity behavior in maintained guidance and keep generated context current. | `docs/reference/incus-topologies.md` and generated documentation context. | `composer docs-build && composer docs-lint`. |
| 6. Pass harness checks. | All changed `apps/e2e` PHP, tests, Composer scripts, and `bin/e2e-scenarios`. | `cd apps/e2e && composer check`. |

## Incus observations

- Incus required: the issue's `incus` label records the real-machine requirement independently of its selected `discovery` flow.
- After independent plan review, acquire ORB-236 discovery for development. Run the scheduler acceptance venue under `apps/e2e/tests/Scenario/` with the focused Pest filters `scenario-worker-capacity`, `scenario-worker-isolation`, and `scenario-worker-interruption`.
- `scenario-worker-capacity` must overlap cold, snapshot, and variable-size recipes with the retained ORB-236 discovery and persistent snapshot, record the requested and observed peak worker counts, and show that every atomic admission used the recipe VM count without exceeding the configured host budget. `scenario-worker-isolation` must show distinct attempts, operations, state paths, networks, guests, and application state while preparation or exercise intervals overlap. `scenario-worker-interruption` must interrupt a run with active and queued work, show exact cleanup or retained recovery for every active attempt, record the queued flows as unstarted infrastructure errors in the complete aggregate, and verify the unrelated ORB-236 discovery topology remains unchanged.
- These observations are reproducible development checks. Proof instrumentation, a proof topology, observed-input collection, exact-commit proof capture, main-freshness checks, and snapshot closeout are not required in discovery flow. Discovery development only; isolated acceptance proof not run.

## Implementation order

1. Extend catalog selection with one combined cold-and-snapshot mode while preserving the current cold-only and snapshot-only validation. Add a thin `run` wrapper, Composer/Artisan entry point, repeatable scenario filters, and a required positive decimal `--workers=COUNT`; resolve the exact candidate, complete catalog, full selection, and worker count before creating run state or reaching Incus.
2. Refactor `ScenarioPestProcess` behind an asynchronous worker handle that can start one existing Pest flow, expose completion and retained output, receive graceful interruption, and report signal or forced-stop failure without sharing a mutable checkout or environment between children. Keep one independently meaningful flow in one Pest process and test.
3. Add `ScenarioScheduler` to create distinct attempt and operation identities, launch at most the requested number of workers, refill available slots as workers finish, collect completions out of order, and return results in the original catalog or requested selection order. Keep each attempt on its existing private `ScenarioRunStore` paths and pass only immutable repository objects and snapshot inputs between workers.
4. Integrate the scheduler into `ScenarioSuiteRunner`. Preserve the existing atomic `topology-create` lock around recipe-sized `HostCapacity` admission, network-slot selection, and resource creation in both cold and snapshot constructors. Do not hold that lock during guest preparation, convergence, exercise, verification, reporting, or cleanup, so admitted workers can overlap while another command or live topology still counts against the same Incus inventory.
5. Keep a worker's primary and cleanup outcomes independent. When a child exits without a durable result, run exact parent-side recovery and write an infrastructure-error result. Continue all unrelated queued work after ordinary failures and cleanup refusals, write one aggregate only after every selected flow has a durable result, and retain selection order and the existing four scenario statuses.
6. Install parent interruption handling before workers start. On `SIGINT` or `SIGTERM`, stop dequeuing, ask every active child to terminate so its own cleanup can run, force-stop only after a bounded wait, perform exact recorded recovery for missing results, create explicit infrastructure-error attempt/results for every unstarted definition, write the complete aggregate, and return nonzero. Revalidate owner, run, scenario, attempt, and operation before deletion and never touch unrelated topology state.
7. Add `ScenarioSchedulerTest.php` coverage for malformed, zero, and missing worker counts before state mutation; worker and VM-budget bounds; mixed recipe scheduling; overlap; out-of-order completion with ordered aggregation; product failure; cleanup refusal; signal and forced-stop recovery; explicit unstarted results; and unrelated-state preservation. Extend the wrapper, process, catalog, run-store, result, aggregate, capacity, Composer configuration, and scenario bootstrap tests only where their contracts change.
8. Add the three named real-Incus scheduler acceptance filters without adding product scenario definitions. Run every focused acceptance-map command, `cd apps/e2e && composer check`, and the required discovery observations; confirm ordinary tests and every discovery, proof, review, merge, snapshot, and promotion command still neither invokes nor accepts scenario results.

## Must preserve

- ADR 0019: every scenario run remains disposable regression evidence for one exact repository commit and never becomes issue acceptance evidence, feature-proof evidence, topology-snapshot promotion authority, or production-release authority.
- ADR 0019: every scenario retains exactly one cold or snapshot lane, a unique stable ID, its ordered physical-Node recipe, bounded actions, declared non-PHP inputs, expected end state, optional observation declaration, and normalized fingerprint in every result. The combined command is a selector, not a third lane, and existing definitions and fingerprints remain stable.
- ADR 0019: cold scenarios start from the unchanged generic base and never read or mutate the persistent snapshot; snapshot scenarios clone one immutable promoted generation, synchronize and converge the exact candidate before exercise, and never change the source generation.
- ADR 0019: physical Node identity remains separate from role assignment. Capacity counts each recipe's actual VM size, every external identity stays bounded and deterministic from run, scenario, attempt, and Node key, and no worker adopts a pre-existing or unrecorded VM.
- ADR 0019: one independent flow remains one Pest test. Its first failed required step stops that flow, cleanup still runs, other runnable flows continue, and every selected flow retains the passed, failed, blocked, or infrastructure-error result contract with complete identity, actions, timing, verification, diagnostics, cleanup, and aggregate reporting before process exit.
- ADR 0019: concurrent workers may share immutable repository objects and the promoted snapshot as inputs, but never a mutable checkout, state directory, topology, guest filesystem, application database, attempt, operation, or result. The host creation lock keeps capacity and network-slot admission atomic while later guest work overlaps.
- ADR 0019: faithful cold execution adds no pre-construction PCOV instrumentation. Snapshot observation remains optional, and nightly scheduling, pull-request triggering, and affected-flow selection remain outside this issue.
- ADR 0019: success, failure, interruption, and reporting exits attempt cleanup from exact recorded inventory; ownership is revalidated before deletion; cleanup refusal retains the primary outcome, every remaining exact resource, and the supported recovery command without broader deletion.
- Preserve the current lane-specific `cold`, `snapshot`, and `cleanup` command contracts, exact clean-HEAD checks, repeatable ordered filters, scenario catalog, result meanings, aggregate schema, and `ScenarioWrapperTest`, `ScenarioCatalogTest`, `ScenarioDefinitionTest`, `ScenarioResultTest`, `ScenarioPestProcessTest`, `ScenarioColdExecutorTest`, and `SnapshotScenarioRunnerTest` coverage delivered by ORB-234 and ORB-235.
- Preserve `HostCapacityTest`, `ColdTopologyConstructorTest`, `TopologyAcquirerTest`, and `IssueTopologyConstructor` invariants: the shared VM budget comes from current Incus inventory, the persistent slot is never allocated, variable recipe sizes count exactly, the creation lock covers the capacity read through resource creation, and generation locks and promoted snapshot immutability remain intact.
- Preserve the E2E command and state rules: thin commands, exact validated IDs and safe paths, redacted external failures, private atomic JSON, no HTTP, database, or queue state, and no deletion by prefix, glob, age, or unresolved value.

## Open questions

- none: the issue, accepted ADR 0019, completed ORB-234 and ORB-235 runner contracts, and the existing creation-lock, host-capacity, per-attempt state, process, and exact-recovery seams determine the implementation without a new product or architecture decision.

## Deviations

- none: the issue already uses the current focused-test and changed-project check policy. Its Incus harness acceptance venues map to the three reproducible discovery observations above. The selected flow is `discovery`, so no proof plan, proof fixture, isolated acceptance topology, exact-commit proof, main-freshness gate, or snapshot closeout is required. No issue-text, label, or check-policy correction is required.

## Review findings
