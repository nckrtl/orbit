# ORB-235 development record

Flow: discovery
Candidate: `e624474a3813317b900382ccf11f0624ca572823`
Candidate tree: `dd973d043ef91571b9e3f4f2918738e144397162`
Branch: `orb-235`
Branch base: `0c9dc6b7e9addc6d8994c1f18239f2324d510e76`
Approved plan artifact: `4faccbb8d0701411e2939ddae3069c16cddfa511`

## Outcome

Operators can run the committed snapshot lane with `bin/e2e-scenarios snapshot` or the `scenario:snapshot` Composer and Artisan surface. Each selected flow resolves one verified promoted generation, constructs a fresh attempt-scoped clone, synchronizes and confirms the exact candidate, converges and verifies the topology before exercise, records a complete result, and cleans only its recorded resources.

The implementation adds the `snapshot-lifecycle`, `snapshot-isolation`, and `snapshot-extension` scenarios. It also introduces one `PromotedTopologySnapshotResolver` shared by discovery acquisition and snapshot scenarios. Discovery sync keeps its existing cold-base compatibility rule, and `IssueTopologyConstructor` remains the final locked manifest-equality guard.

## Resolution history

Implementation initially stopped because the promoted generation was stale and discovery failed at `metrics.publication`. The adopted resolver proposal was applied with its exact binding:

- Primary main `d5983f5bd21b4efed8e07abbc2a4cfc3fe71c50d` refreshed successfully.
- Promoted generation `d5983f5bd21b-a2f0796e31c5` is stopped and available with prepared fingerprint `a2f0796e31c57821e7244bf434f327bf1c02dc5072c11b8b6b301b6a2bd67920`.
- Discovery acquisition succeeded at the unchanged approved documentation head, including `metrics.publication`.
- The approved plan artifact still verifies.

The earlier plan review, implementation stops, and delegated resolution proposals remain unchanged under `.loop/runtime/`.

## Acceptance evidence

| Acceptance | Implementation and evidence |
| --- | --- |
| 1. Record and clone one promoted generation, synchronize and converge the exact candidate before exercise, and fail closed as infrastructure error when the generation cannot be verified. | `PromotedTopologySnapshotResolver` provides the shared complete validation path used by `TopologyAcquirer` and `SnapshotScenarioRunner`. The runner records the source generation and candidate synchronization before convergence and exercise. Focused coverage: `SnapshotScenarioRunnerTest.php`, `PromotedTopologySnapshotResolverTest.php`, and `TopologyAcquirerTest.php`. Real-machine `snapshot-lifecycle` run `e78a08da175ae0f75c38de39b343cdad`, attempt `143fb7a30e73c73d631fee83dfb7888b`, passed from generation `d5983f5bd21b-a2f0796e31c5` on candidate `e624474a3813317b900382ccf11f0624ca572823`; aggregate: `/home/nckrtl/orbit/.e2e/scenarios/runs/e78a08da175ae0f75c38de39b343cdad/aggregate.json`. |
| 2. Repeat from a fresh isolated clone with no prior application or filesystem mutations. | Attempt-scoped construction, state roots, inventories, networks, and cleanup are covered by `SnapshotScenarioRunnerTest.php`. Two real-machine `snapshot-isolation` runs passed independently: run `d5bde596233a97bd42ad4725c8a8564f`, attempt `67a74f741e79d59b36207ab79bdee32a`; and run `eb3934f3ce105abf9f5f96a9757c7bdd`, attempt `b4435e26da1269d4a85828bd618f3db9`. Both recorded `fresh clone did not contain the prior attempt marker` and `cleanup.remaining: []`. Aggregates: `/home/nckrtl/orbit/.e2e/scenarios/runs/d5bde596233a97bd42ad4725c8a8564f/aggregate.json` and `/home/nckrtl/orbit/.e2e/scenarios/runs/eb3934f3ce105abf9f5f96a9757c7bdd/aggregate.json`. |
| 3. Record a declared extra Node's base fingerprint, physical identity, capacity, and exact cleanup; refuse undeclared or foreign resources. | `SnapshotScenarioRunnerTest.php`, scenario definition validation, `HostCapacity`, and locked construction cover the extension and refusal rules. Real-machine `snapshot-extension` run `6c99832596bc92752d3ceb67e395b705`, attempt `734791b55a9a35819d4ef7d541e8f8f4`, recorded extension `app-prod`, slot `2`, image fingerprint `4e02d6ce34f5320e7e6cae69d82b018aaeaa52b1f5bcd60117ca7497d5688f0c`, and deterministic physical Node `orbit-e2e-scn-6c998325-198286-734791b5-app-prod-2`. Cleanup removed the exact four VMs and network with no remaining resources. Aggregate: `/home/nckrtl/orbit/.e2e/scenarios/runs/6c99832596bc92752d3ceb67e395b705/aggregate.json`. |
| 4. Skip exercise after failed preparation, keep diagnostics and cleanup, continue other selected flows, and use the cold aggregate contract. | Lane-aware `ScenarioCatalog`, `ScenarioSuiteRunner`, `ScenarioPestProcess`, `ScenarioResult`, and the wrapper retain independent flow continuation and complete aggregate output. Covered by `ScenarioWrapperTest.php`, `SnapshotScenarioRunnerTest.php`, `ScenarioCatalogTest.php`, `ScenarioPestProcessTest.php`, and `ScenarioResultTest.php`. |
| 5. Preserve the promoted generation, its VMs and manifest, and other attempts; grant no proof or promotion authority. | The runner reads the promoted generation and deletes only its attempt inventory after ownership revalidation. Both isolation observations and all other scenario observations ended with `cleanup.remaining: []`. Snapshot status after the observations still reports the same stopped promoted generation `d5983f5bd21b-a2f0796e31c5`. Snapshot scenarios remain operator-invoked and outside proof, acquisition, promotion, release, and CI commands. |
| 6. Maintain snapshot input and failure guidance with current generated context. | `docs/reference/incus-topologies.md` describes the operator command, lane inputs, generation validation, lifecycle, failures, extension recording, isolation, cleanup, aggregate results, and authority limits. `docs/reference/topology-snapshot.md` identifies disposable snapshot-lane readers and links to the detailed contract. `composer docs-build` and `composer docs-lint` passed; generated context remained byte-identical. |
| 7. Pass harness checks. | `cd apps/e2e && composer check` passed. The exact-candidate root `composer check` also passed across all five projects with its retained Builder receipt. |

## Checks

- Focused Pest command covering the runner, shared resolver, acquisition regressions, command wrapper, definitions, results, catalog, Pest process, cold preservation, and Composer configuration: 68 tests passed with 291 assertions.
- `vendor/bin/pint --dirty --format agent`: passed before commit.
- `cd apps/e2e && composer check`: passed.
- `composer docs-build`: passed; `docs/generated/context.json` stayed byte-identical.
- `composer docs-lint`: passed with no issues, errors, or warnings.
- `git diff --check`: passed.
- Root `composer check` on clean candidate `e624474a3813317b900382ccf11f0624ca572823`: passed and reported an unchanged candidate.
- Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/e624474a3813317b900382ccf11f0624ca572823/review-75fa1fya/result.json`.

## Discovery

- Retained discovery attempt: `15f9462829a561aa01e0a6227aea0dcb`.
- Discovery source is clean candidate `e624474a3813317b900382ccf11f0624ca572823` on gateway and app-dev.
- Discovery verification passed, including `metrics.publication`.
- Snapshot observation runs: lifecycle `e78a08da175ae0f75c38de39b343cdad`; isolation `d5bde596233a97bd42ad4725c8a8564f` and `eb3934f3ce105abf9f5f96a9757c7bdd`; extension `6c99832596bc92752d3ceb67e395b705`.
- Every aggregate is candidate-bound, passed final verification, and reports no remaining cleanup resources.
- Discovery remains available for independent reviewer inspection. Scenario attempt resources were cleaned. The promoted generation remains stopped and unchanged.

## Documentation

- `docs/reference/incus-topologies.md`: documents the snapshot command and operator audience, lane selection, validation, execution order, failures, extension records, isolation, cleanup, results, and authority boundaries.
- `docs/reference/topology-snapshot.md`: documents disposable snapshot-lane consumption of the persistent promoted generation and routes readers to the scenario contract.
- Planner documentation commits remain `2a17596d8ff6e3e310bdaa670bb830ca3251dff6` and `dba0e35c9a334928e64d5e46dd0e3fd0adbf5a89`.
- Documentation audit findings: all fixed; no reported follow-up findings or owners.

## Deviations and limitations

- Deviations from the approved plan: none.
- Flow limitation: discovery development only. No isolated acceptance proof, proof topology, main-freshness gate, candidate convergence proof, or snapshot closeout was run or required.

## Helper coordination

Three bounded implementation helpers worked on disjoint resolver, command/catalog, and snapshot-runner slices. The Builder inspected and integrated their changes, ran the combined focused and project checks, performed the real-machine observations, committed the candidate, and ran the exact-candidate root gate. All helpers finished before handoff. They supplied no formal approval.
