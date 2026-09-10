# ORB-235 development record

Flow: discovery
Candidate: `06091a514d4e3a8d178403ea8fe06e7f08d6f64f`
Candidate tree: `c82b43e5e669c7f06b8ecba37c07c8a47b31c97c`
Branch: `orb-235`
Included main: `07ca3f48fd422324faf53042f4c0b9463e043e4a`
Approved plan artifact: `4faccbb8d0701411e2939ddae3069c16cddfa511`
Reviewed predecessor: `840e3d07a7cd2430f2144bbdaf5652320be9c8ad`
Reviewed predecessor artifact: `5185452405bc49456e1d0dae104e8e477a8d9add`

## Outcome

Operators can run the committed snapshot lane with `bin/e2e-scenarios snapshot` or the `scenario:snapshot` Composer and Artisan surface. Each selected flow resolves one verified promoted generation, constructs a fresh attempt-scoped clone, synchronizes and confirms the exact candidate, converges and verifies the topology before exercise, records a complete result, and cleans only its recorded resources.

The implementation adds the `snapshot-lifecycle`, `snapshot-isolation`, and `snapshot-extension` scenarios. It also introduces one `PromotedTopologySnapshotResolver` shared by discovery acquisition and snapshot scenarios. Discovery sync keeps its existing cold-base compatibility rule, and `IssueTopologyConstructor` remains the final locked manifest-equality guard.

## Conflict correction

The published prior candidate had actual conflicts with main. Candidate `840e3d07a7cd2430f2144bbdaf5652320be9c8ad` merges main `07ca3f48fd422324faf53042f4c0b9463e043e4a` without restarting preflight or changing `.loop/plan.md`.

The merge resolution changed five overlapping files:

- `TopologyAcquirer.php` keeps `PromotedTopologySnapshotResolver` as the only complete generation-validation path and removes main's duplicated private validation method.
- `AppServiceProvider.php` registers and injects that resolver. Its `TopologySnapshotAvailability` dependency includes main's active snapshot-replacement recovery guard.
- `TopologyAcquirerTest.php` routes main's new conflicting-replacement refusal case through the shared resolver and proves it fails before Incus mutation.
- `docs/reference/incus-topologies.md` keeps the approved contributor, agent, and operator audience and both scenario lanes while incorporating main's declared cold replacement contract.
- `docs/reference/topology-snapshot.md` states that ordinary discovery and proof plus snapshot-lane scenarios use the persistent generation, while retaining main's declared replacement exception.

`git show --remerge-diff 840e3d07a7cd2430f2144bbdaf5652320be9c8ad` shows the exact conflict resolution for independent review.

## Review finding correction

The independent review of `840e3d07a7cd2430f2144bbdaf5652320be9c8ad` requested one Acceptance 3 correction. Candidate `06091a514d4e3a8d178403ea8fe06e7f08d6f64f` adds only test evidence in `SnapshotScenarioRunnerTest.php`; production and documentation files are byte-identical to the reviewed predecessor.

- A pre-existing target network now runs through `executeFromEnvironment()` and the real `ScenarioRecovery`. The result is a setup-only `infrastructure-error`; no network create, VM init, snapshot copy, or delete command is attempted; cleanup refuses the foreign network and records it as remaining.
- A pre-existing `app-prod-2` VM with foreign ownership metadata now follows the same complete runner and recovery path. The result is a setup-only `infrastructure-error`; no construction or deletion mutation is attempted; cleanup refuses the VM and retains the exact remaining inventory.
- The extension physical-identity refusal test now supplies a present VM whose operation metadata differs from the attempt. It reaches the metadata-mismatch branch instead of the absent-instance branch.

This closes the approved plan's foreign-resource refusal coverage with no plan deviation. The original review handoff remains unchanged in `.loop/runtime/`.

## Infrastructure resolution history

Implementation initially stopped because the promoted generation was stale and discovery failed at `metrics.publication`. The adopted resolver proposal was applied with its exact binding:

- Primary main `d5983f5bd21b4efed8e07abbc2a4cfc3fe71c50d` refreshed successfully.
- Promoted generation `d5983f5bd21b-a2f0796e31c5` is stopped and available with prepared fingerprint `a2f0796e31c57821e7244bf434f327bf1c02dc5072c11b8b6b301b6a2bd67920`.
- Discovery acquisition succeeded, including `metrics.publication`.
- The approved plan artifact still verifies after conflict correction.

The earlier plan review, implementation stops, delegated resolution proposals, prior implementation handoff, and prior receipt remain unchanged under `.loop/runtime/`.

## Acceptance evidence

| Acceptance | Implementation and corrected-candidate evidence |
| --- | --- |
| 1. Record and clone one promoted generation, synchronize and converge the exact candidate before exercise, and fail closed as infrastructure error when the generation cannot be verified. | `PromotedTopologySnapshotResolver` provides the shared complete validation path used by `TopologyAcquirer` and `SnapshotScenarioRunner`, including main's replacement-recovery availability guard. Focused coverage passed in `SnapshotScenarioRunnerTest.php`, `PromotedTopologySnapshotResolverTest.php`, `TopologyAcquirerTest.php`, and `TopologySnapshotAvailabilityTest.php`. Real-machine `snapshot-lifecycle` run `3862c30ab63171b2c3e03882ff379328`, attempt `af8da2d6be8df0edd2602879a774241f`, passed from generation `d5983f5bd21b-a2f0796e31c5` on reviewed predecessor `840e3d07a7cd2430f2144bbdaf5652320be9c8ad`; the correction changes tests only. Aggregate: `/home/nckrtl/orbit/.e2e/scenarios/runs/3862c30ab63171b2c3e03882ff379328/aggregate.json`. |
| 2. Repeat from a fresh isolated clone with no prior application or filesystem mutations. | Attempt-scoped construction, state roots, inventories, networks, and cleanup are covered by `SnapshotScenarioRunnerTest.php`. Reviewed-predecessor `snapshot-isolation` runs `6f3307ae4670db0d6b63deb2da05fdca`, attempt `280648af38212318c686d8680302e8d9`, and `b10a723accec25f19a5a4f6090c8fefd`, attempt `5dbc0aa8c133b0df5102f97fad286c87`, both recorded `fresh clone did not contain the prior attempt marker` and `cleanup.remaining: []`. Aggregates: `/home/nckrtl/orbit/.e2e/scenarios/runs/6f3307ae4670db0d6b63deb2da05fdca/aggregate.json` and `/home/nckrtl/orbit/.e2e/scenarios/runs/b10a723accec25f19a5a4f6090c8fefd/aggregate.json`. The current correction changes tests only. |
| 3. Record a declared extra Node's base fingerprint, physical identity, capacity, and exact cleanup; refuse undeclared or foreign resources. | Current-candidate `SnapshotScenarioRunnerTest.php` coverage runs the full entry point with real cleanup for a foreign target network and foreign `app-prod-2` VM. Both are setup-only infrastructure errors, attempt no create/copy/init/delete mutation, and retain refused resources. The extension identity test now reaches the present-VM metadata-mismatch branch. The reviewed-predecessor `snapshot-extension` run `6aba9695057936a0345c9e9dd049d126`, attempt `936fb4238abd192bcbd81bb336ee690d`, recorded extension `app-prod`, slot `2`, image fingerprint `4e02d6ce34f5320e7e6cae69d82b018aaeaa52b1f5bcd60117ca7497d5688f0c`, and physical Node `orbit-e2e-scn-6aba9695-198286-936fb423-app-prod-2`; cleanup removed the exact four VMs and network. Aggregate: `/home/nckrtl/orbit/.e2e/scenarios/runs/6aba9695057936a0345c9e9dd049d126/aggregate.json`. |
| 4. Skip exercise after failed preparation, keep diagnostics and cleanup, continue other selected flows, and use the cold aggregate contract. | Lane-aware catalog, suite, Pest process, result, and wrapper coverage passed. The corrected focused set included `ScenarioWrapperTest.php`, `SnapshotScenarioRunnerTest.php`, `ScenarioCatalogTest.php`, `ScenarioPestProcessTest.php`, and `ScenarioResultTest.php`. |
| 5. Preserve the promoted generation, its VMs and manifest, and other attempts; grant no proof or promotion authority. | Every reviewed-predecessor observation ended with `cleanup.remaining: []`. Snapshot status afterward reports the same stopped promoted generation `d5983f5bd21b-a2f0796e31c5`. Snapshot scenarios remain operator-invoked and outside proof, acquisition, promotion, release, and CI commands. The test-only correction does not change those authority or cleanup paths. |
| 6. Maintain snapshot input and failure guidance with current generated context. | The two approved pages retain the complete scenario contract and now coexist with main's declared cold replacement contract. `composer docs-build` and `composer docs-lint` passed; generated context stayed current. |
| 7. Pass harness checks. | `cd apps/e2e && composer check` passed after the test correction. Root `composer check` passed across all five projects on the exact clean candidate. |

## Prior acceptance evidence preserved

The immutable evidence for prior candidate `e624474a3813317b900382ccf11f0624ca572823` remains available and is not claimed as proof for the corrected candidate:

- Lifecycle run `e78a08da175ae0f75c38de39b343cdad`, attempt `143fb7a30e73c73d631fee83dfb7888b`.
- Isolation runs `d5bde596233a97bd42ad4725c8a8564f`, attempt `67a74f741e79d59b36207ab79bdee32a`, and `eb3934f3ce105abf9f5f96a9757c7bdd`, attempt `b4435e26da1269d4a85828bd618f3db9`.
- Extension run `6c99832596bc92752d3ceb67e395b705`, attempt `734791b55a9a35819d4ef7d541e8f8f4`.
- Builder receipt `/home/nckrtl/orbit/.git/orbit-checks/e624474a3813317b900382ccf11f0624ca572823/review-75fa1fya/result.json` and artifact `6d9d0026884e38bdc2acfd2bd0eb481703511bfe`.

## Checks

- Corrected focused Pest set covering the runner, shared resolver, replacement-aware availability, acquisition regressions, command wrapper, definitions, results, catalog, Pest process, cold preservation, and Composer configuration: 77 tests passed with 326 assertions.
- `vendor/bin/pint --dirty --format agent`: passed.
- `cd apps/e2e && composer check`: passed.
- `composer docs-build`: passed and wrote current generated context.
- `composer docs-lint`: passed with no issues, errors, or warnings.
- `git diff --check`: passed.
- Root `composer check` passed across all five projects on unchanged candidate `06091a514d4e3a8d178403ea8fe06e7f08d6f64f`.
- Successful Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/06091a514d4e3a8d178403ea8fe06e7f08d6f64f/review-9pft_qoo/result.json`.
- The reviewed predecessor's successful and superseded failed gate receipts remain preserved in its implementation handoff.

## Discovery

- Retained discovery attempt: `15f9462829a561aa01e0a6227aea0dcb`.
- Discovery sync records clean candidate `06091a514d4e3a8d178403ea8fe06e7f08d6f64f` on gateway and app-dev.
- Discovery verification passed, including `metrics.publication`.
- Reviewed-predecessor observation runs: lifecycle `3862c30ab63171b2c3e03882ff379328`; isolation `6f3307ae4670db0d6b63deb2da05fdca` and `b10a723accec25f19a5a4f6090c8fefd`; extension `6aba9695057936a0345c9e9dd049d126`.
- Every retained aggregate is bound to reviewed predecessor `840e3d07a7cd2430f2144bbdaf5652320be9c8ad`, passed final verification, and reports no remaining cleanup resources. The current correction is test-only, so those accepted observations were not repeated.
- Discovery remains available for independent reviewer inspection. Scenario attempt resources were cleaned. The promoted generation remains stopped and unchanged.

## Documentation

- `docs/reference/incus-topologies.md`: retains the snapshot command and operator audience, lane selection, validation, execution order, failures, extension records, isolation, cleanup, results, and authority boundaries; it also incorporates main's declared cold replacement lifecycle.
- `docs/reference/topology-snapshot.md`: retains disposable snapshot-lane consumption of the persistent promoted generation and incorporates main's declared replacement exception and installation lifecycle.
- Planner documentation commits remain `2a17596d8ff6e3e310bdaa670bb830ca3251dff6` and `dba0e35c9a334928e64d5e46dd0e3fd0adbf5a89` in candidate history.
- Documentation audit findings remain fixed; no reported follow-up finding or owner exists.

## Deviations and limitations

- Deviations from the approved plan: none.
- Conflict resolution integrated actual main conflicts as required. It did not change the issue contract or approved plan and did not restart preflight.
- Flow limitation: discovery development only. No isolated acceptance proof, proof topology, equivalence evaluation, candidate-convergence proof, or snapshot closeout was run or required.

## Helper coordination

The three prior bounded helpers worked on disjoint resolver, command/catalog, and snapshot-runner slices and finished before the first handoff. The retained Builder made this review correction without further delegation, inspected and ran the new refusal tests, synchronized retained discovery, and produced the exact-candidate gate. Helpers supplied no formal approval.
