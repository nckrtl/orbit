# Feature plan

Plan format: 1
Issue: ORB-230
Flow: discovery
Review verdict: PENDING

## Outcome

When an issue selects proof delivery, Orbit captures successful proof before interactive review, retains every proof Node through review and refresh, and releases the exact topology only through the approved replacement, abandonment, or closeout path.

## Code boundaries

In:
- `apps/e2e/app/E2E/IssueState.php`, `ProofEvidence.php`, and new typed capture, review-record, review-evaluation, and closeout services and values persist exact issue, candidate, attempt, topology, action, result, finding, and lifecycle identities as private atomic JSON.
- `apps/e2e/app/E2E/TopologyAcquirer.php`, `ProofEquivalenceEvaluator.php`, `TopologyReleaser.php`, `TopologySnapshotPromoter.php`, and `TopologySnapshotRefresher.php` enforce capture-before-access, fresh proof after a reviewed code or configuration fix, reviewer-modified-state refusal, and exact retained-topology cleanup after refresh.
- `apps/e2e/app/Console/Commands/Topology/**`, `apps/e2e/app/Providers/AppServiceProvider.php`, and `bin/e2e-topology` expose capture, recorded proof shell and exec access, review evaluation, guarded replacement or abandonment, status, and verified closeout.
- `bin/worktree-remove`, `AGENTS.md`, `.agents/skills/developing-features/SKILL.md`, `.agents/skills/reviewing-pull-requests/SKILL.md`, and `.agents/skills/merging-pull-requests/SKILL.md` release idle discovery before proof-flow review, retain captured successful proof through review, and keep refresh and cleanup order aligned with that lifecycle.
- `apps/e2e/tests/Unit/E2E/**` and `apps/e2e/tests/Feature/**` cover the state values, services, commands, proof-flow isolation, guidance, and failure paths, including the issue-named existing suites.

Out:
- Keep the registered three-Node topology and the existing optional `app-prod-2` extension; add no topology shape.
- Keep `apps/e2e/tests/Scenario/**`, cold and snapshot scenario authority, and scenario scheduling unchanged.
- Keep production release, credentials, resources, execution, verification, and journaling separate and unchanged.
- Keep discovery delivery free of proof, reproof, retention, equivalence, snapshot-refresh, and closeout gates.
- Keep CLI, Gateway, PHP SDK, and other product feature behavior unchanged.

## Documentation

The issue-scoped audit used `apps/e2e` and the `Proof topology` concept. It found no report-only drift, fixed the retained-proof review lifecycle in commit `9f49d819`, and linked the governing ADR while restoring idle-discovery release guidance in commit `8a006b2c`:

- `docs/concepts.md` defines a Proof topology as machines whose captured result stays immutable while separate interactive review may change live state, and links ADR 0056.
- `docs/reference/implementation-loop.md` states the proof-only capture, interactive review, fresh-proof-after-fix, refresh, and release order, including release of idle discovery before review, while preserving discovery exemptions and linking ADR 0056.
- `docs/reference/incus-topologies.md` defines the capture, review, status, release, and closeout command contracts for standard and extended attempts and links ADR 0056.
- `docs/reference/proof-plans.md` separates captured proof from review records, states the replacement, abandonment, refresh, and cleanup rules, and links ADR 0056.
- `docs/reference/topology-snapshot.md` refuses promotion from reviewer-accessed live state and keeps retained machines when refresh fails.
- `docs/generated/context.json` contains the rebuilt documentation context for these changes.

Audit reported: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Complete capture gates successful-proof review access | Capture service, `ProofEvidence`, `IssueState`, capture command, and `TopologyAcquirer` | `apps/e2e/tests/Unit/E2E/TopologyProofRunnerTest.php`, new capture-service tests, command tests, and discovery observation `proof-review-capture` |
| 2. Shell and exec reach every retained standard or extended proof Node with ordinary guest privileges | `TopologyAcquirer`, proof review service, `ShellCommand`, `ExecCommand`, and physical Node-key validation | `apps/e2e/tests/Feature/Commands/TopologyCommandsTest.php`, focused acquirer and review-service tests, and discovery observation `proof-review-standard-and-extended` |
| 3. Interactive changes cannot alter captured bytes or hashes, and the separate review record is complete and bound | Review record values and archive, `IssueState`, capture archive, shell and exec command recording | New review-record tests, state and command tests, and discovery observation `proof-review-evidence-isolation` |
| 4. Required failures or incomplete required records block approval while exploratory failures stay distinct | Review evaluator and review/status commands | New review-evaluation tests and focused command tests |
| 5. A reviewed code or configuration fix requires fresh proof from candidate inputs | `ProofEquivalenceEvaluator`, review record lookup, and proof replacement guard | `apps/e2e/tests/Unit/E2E/ProofEquivalenceEvaluatorTest.php`, focused replacement tests, and discovery observation `proof-review-fix-reproof` |
| 6. Promotion rejects reviewer-modified live state; verified merge closeout refreshes before exact release | `TopologySnapshotPromoter`, closeout service and command, `TopologySnapshotRefresher`, and `TopologyReleaser` | `apps/e2e/tests/Feature/Commands/TopologySnapshotCommandsTest.php`, focused promoter/closeout/releaser tests, and discovery observation `proof-review-closeout` |
| 7. Failed refresh retains the whole topology and evidence; replacement or abandonment cleans the exact obsolete attempt | Closeout state, capture and review archives, `TopologyReleaser`, release command, and `bin/worktree-remove` | Focused closeout and releaser failure/retry tests plus discovery observation `proof-review-retention-and-replacement` |
| 8. Only the issue worktree with proof selected gains this lifecycle; proof-flow review releases idle discovery but retains successful proof, while discovery delivery and failed-proof diagnosis stay unchanged | `DeliveryFlow`, all new commands and services, existing diagnosis paths, release sequencing, and cleanup scripts | `apps/e2e/tests/Unit/E2E/LoopFlowTest.php`, `TopologyCommandsTest.php`, `TopologyReleaserTest.php`, and the harness lifecycle suites |
| 9. Contributor, reviewer, closeout, and proof references agree with the commands and distinguish pre-review idle-discovery release from successful-proof retention | Five changed maintained pages, generated context, `AGENTS.md`, three agent-role skills, wrapper usage, and guidance tests | `apps/e2e/tests/Feature/Configuration/AgentRoleContractTest.php` and `composer docs-lint` |
| 10. Harness quality checks pass | All changed `apps/e2e` and `bin/e2e-*` boundaries | `cd apps/e2e && composer check` |

## Incus observations

Incus: required by the issue label. After independent plan review passes, acquire ORB-230 discovery and use the existing `app-prod` extension declaration when the `app-prod-2` observation is needed. Run the seven named `proof-review-*` observations against real standard and extended Nodes, record the exact commands, JSON results, inventories, file hashes, and cleanup state in the development record, and keep the final discovery attempt for code review. These are reproducible development observations only. Proof instrumentation, a proof attempt, observed-input collection, candidate convergence, and snapshot operations are not required in this discovery flow.

## Implementation order

1. Add typed capture, review action, review evaluation, and closeout records with state and archive tests that prove atomic persistence, exact identities, redaction, and immutable captured bytes.
2. Extract capture from release into its own guarded command and service, require a complete capture before successful-proof access, and keep failed-proof diagnosis access unchanged.
3. Extend proof `exec` and `shell` to target every recorded standard or extended Node, record required or exploratory actions separately, complete interactive records through the review command, and expose evaluation through review and status.
4. Make reviewed code or configuration changes require replacement proof, reject promotion after reviewer access, and add guarded replacement, abandonment, and verified closeout paths that preserve archives and release only the exact recorded inventory after refresh succeeds.
5. Update the command wrapper, worktree cleanup, repository guidance, role skills, and their contract tests to release idle discovery before proof-flow review, retain captured successful proof through review, then use closeout and exact cleanup in the required order.
6. Run focused state, service, command, flow, guidance, and snapshot tests; run the seven discovery observations on real Nodes; then run `cd apps/e2e && composer check` and `composer docs-lint`.

## Must preserve

- ADR 0006: discovery remains separate and mutable; proof still synchronizes one exact Git candidate, runs every declared action with exit `0`, records complete results, keeps diagnosis one-way and explicitly inspectable, cleans exact owned inventories only, and never reuses production resources.
- ADR 0015: captured proof retains its normalized plan and input-manifest fingerprints, exact or equivalent candidate reports remain explainable and fail closed, review stays bound to one exact candidate, relevant runtime drift requires complete proof, and production never depends on disposable Incus or PCOV.
- ADR 0015 as superseded by ADR 0056: no equivalence report or candidate-convergence result may replace fresh proof after a code or configuration fix found during interactive review.
- ADR 0040: an extension remains exactly one declared `app-prod-2` Node in the same attempt, with complete construction inputs, capacity, inventory, ownership, verification, recovery, and exact cleanup; it never enters the shared snapshot or becomes promotable by deleting the extra Node.
- ADR 0050 as retained by ADR 0056: capture includes the successful result, topology identity, complete zero-exit action evidence, and input manifest; evaluation rejects incomplete or mismatched evidence; failed proof retains its diagnosis path; refresh failure retains evidence for retry.
- ADR 0050 as retained by ADR 0056: proof delivery releases idle discovery resources before handing the candidate to review while retaining the captured successful proof topology through review and closeout.
- ADR 0051: the repository owner selects the flow per worktree and candidate artifact; discovery keeps acceptance, focused tests, project checks, the local review gate, and independent review, but gains no proof, equivalence, freshness, retention, promotion, or snapshot-refresh gate.
- ADR 0056: this lifecycle applies only to an issue with proof selected, capture completes before access, and every standard or declared extended proof Node remains retained through review.
- ADR 0056: reviewers receive ordinary interactive access and may change live application or machine state, while the original captured proof remains immutable.
- ADR 0056: review actions and findings stay in a separate record bound to issue, candidate, and attempt; required failures prevent approval.
- ADR 0056: every code or configuration fix is reproducible in the candidate and declared inputs and receives fresh proof before approval.
- ADR 0056: reviewer-modified live state is neither unchanged proof input nor a promoted shared snapshot.
- ADR 0056: closeout releases the retained successful topology only after verified merge and successful snapshot refresh; obsolete replacement or explicit abandonment may release earlier while preserving proof and review evidence.
- Existing exact-ID validation, private atomic JSON writes, secret redaction, operation locks, physical Node-key selection, guest privilege boundaries, capacity accounting, ownership checks, and orphan-network exclusions remain intact.

## Open questions

- none

## Deviations

none

## Review findings

- addressed: Acceptance 8 and 9 boundaries and focused proof, implementation order, and `Must preserve` now require future proof-flow deliveries to release idle discovery before review while retaining captured successful proof. The implementation-loop documentation states the same order. ORB-230 remains in the discovery flow, so its final discovery attempt stays available through code review.
- addressed: `docs/concepts.md`, `docs/reference/implementation-loop.md`, `docs/reference/incus-topologies.md`, and `docs/reference/proof-plans.md` now link ADR 0056 where they describe its lifecycle. Commit `8a006b2c` contains the links and rebuilt `docs/generated/context.json`; `composer docs-lint` passes.
