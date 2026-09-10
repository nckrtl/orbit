# Feature plan

Plan format: 1
Issue: ORB-235
Flow: discovery
Review verdict: PASS

## Outcome

An operator can run a selected scenario from a fresh isolated clone of one verified promoted generation, converge the exact candidate before exercise, and receive complete attempt-scoped results and cleanup without changing the snapshot or another run.

## Code boundaries

In:
- Implement the issue's single `In` bullet in `bin/e2e-scenarios`; `apps/e2e/app/Console/Commands/Scenario/`; `apps/e2e/app/E2E/ScenarioCatalog.php`, `ScenarioSuiteRunner.php`, `ScenarioPestProcess.php`, `ScenarioRecovery.php`, and a focused snapshot-scenario runner; the related scenario state and value objects under `apps/e2e/app/E2E/State/` and `apps/e2e/app/E2E/Value/`; and bindings in `apps/e2e/app/Providers/AppServiceProvider.php`. Extract the complete promoted-generation validation from `apps/e2e/app/E2E/TopologyAcquirer.php` into a new `apps/e2e/app/E2E/PromotedTopologySnapshotResolver.php` used by both discovery acquisition and the snapshot runner: one resolution path must reject a missing or legacy manifest, invalid generation ID, structural or prepared fingerprint drift, a feature cold-epoch or base-image-alias change, and unavailable owned snapshots. Move the existing sync-time cold-base compatibility check into a separate resolver operation without making sync depend on the current promoted manifest. Keep `apps/e2e/app/E2E/IssueTopologyConstructor.php` as both construction callers' locked changed-generation guard immediately before snapshot copying. Reuse candidate synchronization, convergence, verification, host-capacity, and exact-rollback seams in `TopologySnapshotAvailability.php`, `DiscoveryGuestPreparer.php`, `WorktreeSynchronizer.php`, `TopologyConverger.php`, `TopologyVerifier.php`, `HostCapacity.php`, and `AcquisitionRollback.php`. Update `apps/e2e/composer.json`, `apps/e2e/tests/Pest.php`, the named `apps/e2e/tests/Unit/E2E/SnapshotScenarioRunnerTest.php`, `apps/e2e/tests/Unit/E2E/TopologyAcquirerTest.php`, a focused resolver test, `apps/e2e/tests/Feature/Commands/ScenarioWrapperTest.php`, nearby scenario value/state tests, and a snapshot scenario acceptance file under `apps/e2e/tests/Scenario/` for focused and real-Incus coverage.

Out:
- Keep cold-constructor behavior unchanged; keep scenario execution serial and add no parallel scheduling, worker count, worker pool, run-scoped shared checkpoint, or shared-checkpoint construction. Add no new product behavior in `apps/cli`, `apps/gateway`, or `packages/php-sdk`; do not connect scenarios to discovery acquisition, feature proof, review, merge, promotion, or any feature-proof gate; and do not refresh, replace, promote, or derive promotion evidence from the topology snapshot. Do not change the `apps/e2e` harness outside the existing scenario, promoted-snapshot clone, candidate-convergence, capacity, and exact-cleanup seams named above.

## Documentation

- `docs/reference/incus-topologies.md`: now defines the snapshot command and lane selection, promoted-generation validation, fresh clone and exact-candidate convergence order, failure classification, declared extra-Node inputs, attempt isolation, exact cleanup, and the boundary from proof and promotion.
- `docs/reference/topology-snapshot.md`: now identifies snapshot-lane scenarios as disposable readers of the one persistent promoted generation and routes their behavior to the topology registry.
- `docs/generated/context.json`: `composer docs-build` confirmed the generated context is current; its bytes did not change.
- Documentation audit fixed the stale statement that snapshot scenarios were future work, the snapshot consumer coverage gap, and the topology registry opening that excluded operators and on-demand scenarios from its stated audience and question. No other page in the issue-scoped `apps/e2e` and `Node` context drifted, and there are no reported findings or follow-up owners.
- Documentation commits: `2a17596d8ff6e3e310bdaa670bb830ca3251dff6` (`docs: describe snapshot scenario lifecycle`) and `dba0e35c9a334928e64d5e46dd0e3fd0adbf5a89` (`docs: clarify topology registry audience`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Record and verify one promoted generation, clone it into a fresh attempt, synchronize and converge the exact candidate before exercise, and classify a missing or unverifiable generation as infrastructure error without exercise. | Snapshot scenario definition/catalog selection; shared `PromotedTopologySnapshotResolver`; `TopologyAcquirer`; snapshot runner around `IssueTopologyConstructor`, guest preparation, candidate synchronization, convergence, verification, scenario state, result, and cleanup. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/SnapshotScenarioRunnerTest.php tests/Unit/E2E/PromotedTopologySnapshotResolverTest.php tests/Unit/E2E/TopologyAcquirerTest.php` and reproducible discovery observation `snapshot-scenario-lifecycle`. |
| 2. Repeat the same snapshot scenario from a fresh isolated clone that cannot observe an earlier run's application or filesystem mutations. | Attempt-scoped `TopologyTarget`, scenario run store, snapshot runner, snapshot scenario Pest flow, and exact cleanup/recovery. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/SnapshotScenarioRunnerTest.php` and reproducible discovery observation `snapshot-scenario-isolation`. |
| 3. Give each declared extra Node a recorded base image fingerprint, deterministic physical identity, capacity reservation, and exact cleanup; refuse undeclared or foreign existing resources. | Scenario definition and recipe values; construction inputs; `HostCapacity`; snapshot clone constructor; `AcquisitionRollback`; scenario recovery and state validation. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/SnapshotScenarioRunnerTest.php` and reproducible discovery observation `snapshot-scenario-extension`. |
| 4. Skip exercise after failed preparation, retain diagnostics and cleanup, continue another selected flow, and use the existing complete aggregate result contract. | Lane-aware scenario command/catalog, suite runner, Pest process, shared scenario result/aggregate/state, and wrapper exit handling. | `cd apps/e2e && vendor/bin/pest --compact tests/Feature/Commands/ScenarioWrapperTest.php tests/Unit/E2E/SnapshotScenarioRunnerTest.php`. |
| 5. Preserve the promoted generation, its VMs and manifest, and all other attempts after successful and failed snapshot scenarios; emit no feature-proof or promotion authority. | Read-only promoted-generation resolution, attempt-scoped target and state, snapshot runner cleanup, wrapper/Composer isolation from delivery commands, and snapshot scenario acceptance flows. | `cd apps/e2e && vendor/bin/pest --compact tests/Unit/E2E/SnapshotScenarioRunnerTest.php` and reproducible discovery observation `snapshot-scenario-isolation`. |
| 6. Describe snapshot inputs and failure behavior with current generated context. | `docs/reference/incus-topologies.md`, `docs/reference/topology-snapshot.md`, and generated documentation context. | `composer docs-build && composer docs-lint`. |
| 7. Pass harness checks. | All changed `apps/e2e` PHP, tests, Composer scripts, and `bin/e2e-scenarios`. | `cd apps/e2e && composer check`. |

## Incus observations

- Incus required: the issue's `incus` label records the real-machine requirement independently of its selected `discovery` flow.
- After independent plan review, acquire ORB-235 discovery for development. Run the snapshot scenario acceptance flow under `apps/e2e/tests/Scenario/` with the focused Pest filters `snapshot-scenario-lifecycle`, `snapshot-scenario-isolation`, and `snapshot-scenario-extension`. Each observation must show the selected candidate, source generation, separate attempt inventory, exercise ordering or skip, diagnostics, cleanup, and unchanged promoted resources needed by its acceptance row.
- The three runs are reproducible development observations. Proof instrumentation, a proof topology, observed-input collection, exact-commit proof capture, main-freshness checks, and snapshot closeout are not required in discovery flow. Discovery development only; isolated acceptance proof not run.

## Implementation order

1. Extend scenario definitions and catalog selection to accept exactly `cold` or `snapshot`, bind each definition to its declared recipe or extension and non-PHP inputs, and select only definitions from the requested lane before creating state or reaching Incus. Keep existing cold definitions and fingerprints stable unless the shared normalized schema must explicitly record the new declaration.
2. Add a thin snapshot command and wrapper/Composer entry point that preserves the clean exact-HEAD validation and repeatable filter contract, then pass the selected lane through the shared serial suite runner and Pest process without adding another result or aggregate model.
3. Extract `TopologyAcquirer::promotedGeneration()` and its cold-base comparison into one `PromotedTopologySnapshotResolver`. Make discovery acquisition and the snapshot runner call the same resolution operation for the missing-manifest, legacy-schema, generation-ID, structural/prepared-fingerprint, feature cold-epoch/base-image-alias, and owned-snapshot availability checks; keep discovery sync on the resolver's separate existing-generation cold-base compatibility operation so a later promotion does not invalidate an acquired topology. Keep `IssueTopologyConstructor`'s shared generation lock and promoted-manifest equality check as the final changed-generation refusal before either construction caller copies snapshots. Record the resolved generation before the snapshot runner mutates Incus, validate its complete target inventory, persist enough construction input for exact recovery, reserve capacity for the declared recipe, clone the registered Nodes, construct only declared image-source Nodes, and refuse every pre-existing target resource instead of adopting it.
4. Start and prepare the cloned guests, synchronize the exact candidate, converge every declared Node and product projection, and verify readiness before invoking the scenario exercise. Record each preparation phase, source generation, candidate source state, action, verification, diagnostic, and cleanup in the existing per-attempt and aggregate contract.
5. Put exact rollback and recovery around every preparation and exercise exit. A preparation failure skips exercise and reports `infrastructure-error`; a product assertion can report `failed`; cleanup failure makes the effective result infrastructure-invalid while retaining the primary outcome, exact remaining inventory, and recovery command. Continue each other independently selected scenario after the result is durable.
6. Add unit coverage in `SnapshotScenarioRunnerTest.php` for generation validation, ordering, fresh identities, source recording, extension fingerprint/capacity, foreign-resource refusal, preparation failure, cleanup, aggregate continuation, and snapshot/attempt immutability. Add focused resolver cases for every extracted refusal, and update `TopologyAcquirerTest.php` to prove acquisition and sync retain that shared validation path. Extend wrapper, catalog, state/result, Composer configuration, and scenario-process tests only where the shared lane contract changes.
7. Add the three real-Incus snapshot scenario acceptance filters for lifecycle, repeat isolation, and declared extension behavior. Run every focused acceptance-map command, `cd apps/e2e && composer check`, and the required discovery observations; confirm ordinary test, delivery, proof, and promotion commands still do not invoke or accept scenario results.

## Must preserve

- ADR 0019: a scenario run remains disposable regression evidence for one exact commit and never becomes issue acceptance evidence, feature-proof evidence, topology-snapshot promotion authority, or production-release authority.
- ADR 0019: every scenario declares exactly one cold or snapshot lane, a unique stable ID, an ordered physical-Node recipe, bounded actions, declared non-PHP inputs, expected end state, optional observation choice, and a normalized fingerprint in every result.
- ADR 0019: a snapshot scenario clones a fresh isolated copy of one immutable promoted generation, synchronizes and converges the exact candidate before exercise, and never changes the source generation.
- ADR 0019: persistent snapshot construction and disposable scenario construction share typed construction services while fixed snapshot identity, manifest, corruption/recovery, and promotion rules remain unchanged; the removed `live` snapshot namespace does not return.
- ADR 0019: physical Node identity remains separate from role assignment; every external identifier stays bounded and deterministic from run, scenario, attempt, and Node key; no scenario adopts a pre-existing or unrecorded VM.
- ADR 0019: one independent flow remains one Pest test. A failed required step stops that flow, cleanup still runs, and other selected flows continue with separate networks, inventories, state roots, guest filesystems, and application data.
- ADR 0019: every selected flow retains the shared passed, failed, blocked, or infrastructure-error contract with candidate, run and attempt identities, lane, fingerprints, actions, timings, verification, diagnostics, cleanup, and aggregate completion before process exit.
- ADR 0019: snapshot PCOV observation is optional and remains outside this issue; unknown inputs and incomplete observation cannot claim unaffected behavior. Parallel workers, nightly triggers, pull-request selection, and affected-flow selection also remain outside this issue.
- ADR 0019: every exit path attempts cleanup from the exact recorded inventory; ownership is revalidated before deletion; cleanup failure retains the original outcome, remaining resources, and supported recovery without broader deletion.
- ADR 0019: the runner remains explicitly operator-invoked and outside `bin/test`, discovery acquisition, feature proof, review, merge, topology-snapshot promotion, and continuous integration.
- Preserve ADR 0037 and existing `TopologySnapshotManifestStoreTest`, `TopologySnapshotAvailabilityTest`, `TopologyAcquirerTest`, `TopologySnapshotBuilderTest`, `TopologySnapshotPromoterTest`, and `TopologySnapshotRefresherTest` invariants for the current promoted generation, three stopped source VMs, manifest identity, generation locks, cold replacement, and promotion authority.
- Preserve one fail-closed promoted-generation resolution contract for discovery acquisition and snapshot scenarios; neither may bypass or independently reimplement the shared resolver or the constructor's locked manifest-equality check. Discovery sync retains its existing cold-base compatibility semantics and does not start depending on the current promoted manifest.
- Preserve the existing cold scenario definitions, faithful base-image construction, result meanings, serial continuation, exact cleanup, and `ScenarioWrapperTest`, `ScenarioDefinitionTest`, and `ScenarioResultTest` coverage delivered by ORB-234.
- Preserve the E2E command and state rules: thin console commands, exact validated IDs and safe paths, redacted external failures, private atomic JSON, no HTTP/database/queue state, and no deletion by prefix, glob, age, or unresolved value.

## Open questions

- none: the issue, accepted ADR 0019, completed ORB-234 scenario contract, and existing promoted-snapshot acquisition seams determine the implementation without a new product decision.

## Deviations

- none: the issue's Incus harness acceptance venues map to the three reproducible discovery observations above. The selected flow is `discovery`, so no proof plan, proof fixture, isolated topology proof, candidate-convergence proof attempt, main-freshness gate, or snapshot closeout is required. No issue-text, label, or generic check-policy correction is required.

## Review findings
