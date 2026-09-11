# ORB-236 development record

Flow: discovery
Candidate: `c5b61f9f8ced297919d14b918e08773d5477d2c3`
Base planning commit: `77361bf00922a935bec89f257f07c71e61f3153f`
Incus discovery: `fad44bc0ea746d3a62453e25ef7dbf2f` retained for review

## Acceptance evidence

1. Invalid counts, worker and VM bounds
   - `ScenarioSchedulerTest.php`, `HostCapacityTest.php`, and `ScenarioWrapperTest.php` passed in the 56-test focused suite.
   - `scenario-worker-capacity` passed on real Incus with 12 assertions at the candidate. Run `67491196ad2af18f361234f66fd3f809` admitted cold, snapshot, and four-VM extension recipes with two workers, observed a peak of two workers, stayed within the configured 24-VM budget, retained requested result order, and produced exact cleanup or recovery data for every result.

2. Worker isolation and post-creation overlap
   - `ScenarioSchedulerTest.php`, `ScenarioPestProcessTest.php`, and `ScenarioResultTest.php` passed in the focused suite.
   - `scenario-worker-isolation` passed on real Incus with 10 assertions. Run `983b7951568f2cce10371df94e9ce64d` recorded separate attempt and operation IDs, state paths, networks, six guest names, and exact candidate syncs. Its durable attempt timings show overlapping post-creation preparation and synchronization.

3. Failure continuation and complete ordered aggregation
   - `ScenarioSchedulerTest.php` proves a product failure and cleanup refusal do not cancel the remaining flow. It asserts all selected primary and effective outcomes, retained recovery state, and stable aggregate order.
   - The capacity observation also completed every selected result after the outer acceptance test deliberately stopped its active disposable workers.

4. Interruption, recovery, queued results, and unrelated topology survival
   - `ScenarioSchedulerTest.php` proves signal delivery, active-worker recovery, explicit queued infrastructure errors, stable ordering, and unrelated-state preservation.
   - `scenario-worker-interruption` passed on real Incus with 7 assertions. Run `06d02387689e621b0d7b8af9f4d6bd4c` stopped with two active workers and one queued flow, wrote all three ordered infrastructure-error results, retained actionable recovery for a partially constructed cold attempt, and left the ORB-236 discovery state and all three discovery guests unchanged. The exact recorded cleanup command then passed and left no scenario VM for that run.

5. Maintained guidance and generated context
   - `composer docs-build` regenerated `docs/generated/context.json` without a tracked change.
   - `composer docs-lint` passed with 0 issues, 0 errors, and 0 warnings.
   - `docs/reference/incus-topologies.md` is the maintained behavior page from the approved planning commit.

6. Harness checks
   - Focused Pest command: 56 tests, 260 assertions, passed.
   - `cd apps/e2e && composer check`: passed.
   - Root `composer check` on the exact clean candidate: passed across all five projects.
   - Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/c5b61f9f8ced297919d14b918e08773d5477d2c3/review-ccll0tx7/result.json`.

## Implementation

- Added the combined `scenario:run` and `bin/e2e-scenarios run` surfaces with required positive worker validation and cross-lane selection.
- Added asynchronous Pest worker handles and a bounded scheduler that keeps active work within the requested limit, refills slots, and restores catalog or requested result order.
- Added parent signal handling, bounded child termination, exact missing-result recovery, explicit queued results, and complete aggregate persistence.
- Preserved the existing lane commands, definitions, fingerprints, result statuses, aggregate schema, capacity lock, snapshot generation, and cleanup command.

## Documentation

- `docs/reference/incus-topologies.md`: documents the combined command, worker validation, atomic capacity admission, isolation, overlap, failure continuation, ordering, interruption, cleanup, and non-delivery boundary.
- `docs/generated/context.json`: rebuilt with no content change.
- Reported documentation findings: none.

## Deviations and limits

- Plan deviations: none.
- The real-Incus capacity and isolation acceptance tests intentionally terminate their inner disposable runs after the required admission or overlap facts are durable. This avoids making scheduler evidence depend on external package-mirror convergence. The tests require the complete non-passing aggregate and exact cleanup or actionable recovery for every worker.
- The interruption observation initially retained one partially constructed cold attempt after exact ownership validation refused deletion. Its recorded cleanup command was run after construction stopped and passed. No scenario inventory remains from the final observations.
- Discovery development only; isolated acceptance proof not run.
- Helpers: none.
