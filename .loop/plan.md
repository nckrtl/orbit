# Feature plan

Plan format: 1
Issue: ORB-225
Flow: discovery
Review verdict: PASS

## Outcome

Production preparation installs one independent stopped AppInstance copy of every production-applicable App process and Schedule definition without using candidate overrides or running application code.

## Code boundaries

In:
- A new migration under `apps/gateway/database/migrations/` and `apps/gateway/app/Models/{AppInstance,Process,Schedule}.php`: record one durable definition-capture boundary on each target, retain the source definition UUID on each independent copy without a foreign-key lifecycle, and add uniqueness that prevents duplicate copies while leaving operator-created records distinct.
- `apps/gateway/app/Actions/AppInstances/InstantiateAppRuntimeDefinitionsAction.php` and narrow supporting values under `apps/gateway/app/Domain/AppInstances/`: lock one production target, capture all currently production-applicable Process and Schedule specifications before remote mutation, create target-owned copy records in a deterministic order, and resume only unfinished installations from the captured rows.
- Existing seams under `apps/gateway/app/Actions/Processes/`, `apps/gateway/app/Actions/Schedules/`, `apps/gateway/app/Domain/Processes/`, and `apps/gateway/app/Domain/Schedules/`: reuse the accepted specification normalization, target-derived identity, lifecycle status, exact runtime ownership, and rollback behavior for internal stopped Process installation and disabled Schedule installation, including a production home with no `current` release.
- `apps/gateway/tests/Feature/Domain/InstantiateAppRuntimeDefinitionsTest.php`: cover selection, both Process backends, Schedule copies, independent IDs and artifacts, no application execution, capture and retry, operator changes after completion, record and artifact conflicts, and removal through the existing cascades.

Out:
- The production clone HTTP endpoint, its request and response contract, candidate eligibility, source transfer, environment and SQLite copying, Route preparation, and clone orchestration stay unchanged; do not change `apps/gateway/app/Http/**` or `apps/gateway/routes/**`.
- Candidate-specific Process and Schedule records remain source-instance state and are never selected, copied, changed, stopped, or removed by target definition preparation.
- App definition mutations do not synchronize existing copies, and target-copy mutations do not change App definitions; add no automatic reconciliation or bulk update operation.
- Process placement remains on the target AppInstance's recorded Node; add no cross-Node worker placement, placement selector, queue agent, or asynchronous installer.
- `packages/php-sdk/**`, `apps/cli/**`, `apps/e2e/**`, and `bin/e2e-*` stay unchanged. The existing topology commands provide discovery observations without harness or public-client changes.

## Documentation

Audit scope: ORB-225; the pages selected by `composer docs-context -- --component=apps/gateway --concept=App --concept=AppInstance --concept=Schedule`, with the App runtime-definition reference required by the `docs` label.

Changed:
- `docs/reference/app-processes-and-schedules.md`: now describes production-only definition selection, exclusion of candidate overrides, target-owned stopped Process and disabled Schedule copies, operation without a first release or application execution, one-time capture, incomplete-installation retry, preservation of later operator state, conflict refusal, and removal through existing cascades.
- `docs/generated/context.json`: `composer docs-build` confirmed that generated context is current; its bytes did not change.

Reported: none. The audit found no other issue-scoped drift or follow-up owner.

Verification: `composer docs-build` passed and `composer docs-lint` passed. Documentation commit: `4b560b80` (`docs: describe production runtime copies`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Target preparation selects the App's production worker and production Schedules, excluding a development-only Vite definition and a candidate-specific worker override. | Definition capture in `InstantiateAppRuntimeDefinitionsAction`; target-owned Process and Schedule rows with retained definition provenance. | `apps/gateway/tests/Feature/Domain/InstantiateAppRuntimeDefinitionsTest.php` through `cd apps/gateway && composer test:affected`; discovery observation `production-definition-selection` creates the three source choices, invokes target preparation, and inspects the target and candidate records and artifacts separately. |
| 2. Every copy has a new target-owned identity and supported runtime specification, while Processes stay stopped and Schedule timers stay disabled and stopped without a selected release or application execution. | Captured-copy models and migration; reusable Process and Schedule target resolution and runtime installation seams. | The same focused test covers systemd and Docker specifications, generated Process and Schedule identities, a missing `current`, stopped services and containers, disabled and stopped timers, and an untouched execution sentinel; discovery observation `production-definition-stopped-copies` inspects the target database and exact-owned systemd, Docker, and timer artifacts on `app-prod`. |
| 3. One durable selection survives interruption and later definition edits or deletion; retry resumes only incomplete installation and preserves completed copy specifications, operator changes, and explicit starts. | Target capture marker, immutable source-definition identity on copies, per-copy lifecycle checkpoints, and retry loop in the instantiation action. | The same focused test injects a later-copy installation failure, changes and deletes App definitions, changes or starts a completed copy, retries, and asserts only unfinished captured rows converge; discovery observation `production-definition-retry` repeats that sequence around a removable exact-owned conflict. |
| 4. Name and runtime-artifact conflicts are refused without adoption, while AppInstance removal deletes only target copies and their artifacts and retains App definitions and source-instance runtimes. | Transactional copy admission, source-definition provenance checks, existing exact runtime ownership checks, and existing Process and Schedule AppInstance-removal cascades. | The same focused test covers an operator-created same-name row, foreign systemd, Docker, and Schedule artifacts, resumable refusal, and removal isolation; discovery observation `production-definition-removal` repairs its deliberate conflict, removes the target, and verifies target artifacts and rows are gone while App definitions and candidate state remain. |
| 5. Maintained documentation describes selection, stopped installation, and retry behavior with current generated context. | Pages listed in Documentation. | `composer docs-build` and `composer docs-lint` (passed during planning). |
| 6. Focused tests, Gateway checks, and the all-project candidate gate pass under the current TIA policy. | All Gateway boundaries above and repository quality tooling. | During implementation run `cd apps/gateway && composer test:affected`, `cd apps/gateway && composer check`, and `git diff --check`; after the clean candidate commit, the Builder runs root `composer check` with TIA across all five projects and retains its exact-candidate receipt. |

## Incus observations

- Incus required: the issue's `incus` label records the real-machine requirement independently of the selected `discovery` flow.
- After independent plan review passes, acquire ORB-225 discovery with `bin/e2e-topology acquire ORB-225 /fast/worktrees/orbit/orb-225`. Run the four named `production-definition-*` observations through the mounted Gateway and inspect the target runtime on `app-prod`. Use a systemd command, Docker command, and Schedule command that would each write a distinct sentinel if application code ran; require every sentinel to remain absent after preparation.
- Run `production-definition-selection` and `production-definition-stopped-copies` on the initial captured target. For `production-definition-retry`, interrupt a later copy with an exact removable conflict after at least one copy completes, change or remove the App definitions and alter or start the completed copy, repair only the conflict, and confirm retry installs only the unfinished captured copy. Run `production-definition-removal` last and confirm target cleanup leaves the candidate runtime and App definitions unchanged. Finish with zero-exit `bin/e2e-topology sync ORB-225` and `bin/e2e-topology verify ORB-225`.
- Retain the exact guest commands and results in the implementation handoff. These are reproducible development observations only. Proof instrumentation, a proof plan, proof fixtures, observed-input collection, an isolated proof topology, candidate convergence, main-freshness checks, and snapshot closeout are not applicable in this discovery flow. Discovery development only; isolated acceptance proof not run.

## Implementation order

1. After Tom receives an independent `PASS` for this plan, acquire the required discovery topology. Add focused failing cases for production selection, copy identity and specification, stopped installation without `current`, no execution, durable retry, conflicts, and cascade isolation.
2. Add the target capture marker and copy provenance fields and indexes. Keep provenance as retained scalar identity rather than a live definition relationship so later definition replacement or deletion cannot change or remove a captured copy.
3. Extract the narrow reusable Process and Schedule preparation seams needed to reserve a specific independent copy and converge it stopped or disabled. Preserve public add idempotency, existing desired state on retry, target-derived Node, user, home and artifacts, sensitive command handling, exact ownership refusal, rollback, and failure status.
4. Implement one locked instantiation action. On the first call, validate the production target placement, select only definitions containing `production`, reject any same-name operator record, copy every selected specification and source UUID in one database transaction, and record capture even when the selection is empty. Make no remote call until that transaction commits.
5. Install captured Process and Schedule rows in deterministic order. Skip completed rows, retry failed or provisioning rows through their existing exact-owned runtime paths, persist each completion before continuing, and never reread App definitions or candidate Process and Schedule rows after capture.
6. Exercise existing AppInstance removal with instantiated copies and assert that its source preflight, Process and Schedule cleanup order, exact artifact ownership, resumable failures, App-definition retention, and unrelated source-instance isolation remain intact.
7. Run the focused TIA tests after each coherent change, then run Gateway `composer check`, `git diff --check`, all four discovery observations, and the final discovery `sync` and `verify`. Commit the clean candidate before the Builder's root `composer check` gate.

## Must preserve

- ADR 0048: production preparation selects only production-applicable App definitions and never uses candidate-specific Process or Schedule overrides.
- ADR 0048: every selected definition becomes an independent AppInstance-owned record whose execution placement and identity come from the target, not the definition or caller.
- ADR 0048: App definition changes never reconcile existing copies, copy changes never update definitions, and an identical retry preserves completed copies and their desired runtime state.
- ADR 0048: a copied Schedule may finish installation disabled and stopped; later activation is explicit and remains separate from manual execution.
- ADR 0047: target Processes and Schedules remain stopped until the operating agent starts them, production preparation excludes development-only definitions, leaves candidate queues, Processes, and Schedules unchanged, and does not run deployment steps or application code.
- ADR 0047: repeated target preparation retains completed target state; generated dependencies, logs, caches, local PHP-FPM tuning, database preparation, and external storage stay outside definition copying.
- ADR 0038: authorized AppInstance removal applies source preflight first, prevents new child attachment after acceptance, removes every owned Process and Schedule record and persistent exact-owned artifact, and reports no success while cleanup remains incomplete.
- ADR 0038: cascade retry leaves Node-owned Schedules, other AppInstances' children, source-instance runtimes, App definitions, and unrecognized artifacts unchanged, and an active Schedule command never delays removal or recreates deleted state.
- Existing `ProcessActionsTest`, `ScheduleLifecycleTest`, `AppInstanceProcessCascadeTest`, and `AppInstanceRemovalCoordinatorTest` invariants remain authoritative for public add retry, desired-state preservation, stopped production operation without `current`, exact ownership, removal order, and retry.
- Process and Schedule definitions keep their validated closed specifications and sensitive command handling. Copying adds no caller command, target, start-state, host-identity, or cross-Node input.
- The action remains synchronous and idempotent. It adds no queue, Agent, background worker, generic executor, or public endpoint.

## Open questions

none

## Deviations

- The issue's Incus `Proof:` actions map to the four same-outcome discovery observations above after independent plan review because the selected flow is `discovery`. Proof inputs, isolated proof, immutable capture, candidate convergence, main freshness, and snapshot closeout are not required. No acceptance outcome or `incus` classification changes.
- The final issue criterion's stale request for all five full no-TIA CI suites maps to focused Gateway TIA checks plus the Builder's exact-clean-candidate root `composer check`, which runs TIA across all five projects. GitHub CI is disabled. Product acceptance is unchanged; the orchestrator should update the issue proof text to name the current gate.

## Review findings
