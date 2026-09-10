# ORB-230 development record

Flow: discovery
Incus: required
Candidate: c448ee9467c815c4ae3d332415ec66671eed94a1
Branch: orb-230
Base branch: main
Candidate merge base: f6a2506b950873192dc8f513ceda6dfc51ba22ab
Observed origin/main: 2f72567f706b71058cc9a3061b2447365802c1f0
Plan SHA: 77145c9aa48dd49c1c1b3ac83162d905220036abb95d8ca3086fe5f328e08d54
Reviewed plan artifact: ec859e2bd864af05cfc3159117d8de2842cbe19b

## Acceptance

| Item | Evidence | Result |
| --- | --- | --- |
| 1. Complete capture gates successful-proof review access | `TopologyProofRunnerTest.php`, `CapturedProofTest.php`, `TopologyCommandsTest.php`; observation `proof-review-capture` | Capture validates the successful result, exact plan, manifest, action list, and topology before review. Discovery refused capture with exit 1. |
| 2. Shell and exec reach every standard or extended retained proof Node as the ordinary guest | `TopologyAcquirerTest.php`, `ProofReviewServiceTest.php`, `TopologyCommandsTest.php`; observations `proof-review-standard-and-extended` and Node identity checks | Unit and feature tests cover all physical Node keys, including `app-prod-2`. Real `id -u` returned `1000` with exit 0 on all four discovery Nodes. |
| 3. Captured evidence stays immutable and review history is separate and bound | `CapturedProofTest.php`, `ProofReviewActionTest.php`, `ProofReviewRecordTest.php`, `IssueStateTest.php`, `ProofReviewServiceTest.php`; observation `proof-review-evidence-isolation` | Capture and review use separate attempt-bound atomic records and primary archives. Review argv, stdin identity, output, and findings are bounded or redacted. Discovery refused equivalence with exit 1. |
| 4. Required review failures or incomplete actions block readiness; exploratory failures stay distinct | `ProofReviewEvaluationTest.php`, `ProofReviewServiceTest.php`, `TopologyCommandsTest.php`; observation `proof-review-evaluation` | Required incomplete or failed actions evaluate as blocked. Exploratory failures are reported separately. Discovery refused review evaluation with exit 1. |
| 5. A reviewed code or configuration fix requires fresh proof | `ProofEquivalenceEvaluatorTest.php`, `TopologyReleaserTest.php`; observation `proof-review-fix-reproof` | Reviewed runtime, configuration, or proof-contract changes become stale. Documentation-only changes remain reusable. Discovery refused proof with exit 1. |
| 6. Reviewer-modified live state cannot be promoted; closeout refreshes before exact release | `TopologySnapshotPromoterTest.php`, `ProofCloseoutServiceTest.php`, `TopologyReleaserTest.php`, `TopologySnapshotCommandsTest.php`; observation `proof-review-closeout` | Promotion rechecks local and primary review history while holding the issue lock. Closeout validates exact merge lineage, refreshes first, and performs retry-safe captured cleanup. Discovery refused closeout with exit 1. |
| 7. Refresh failure retains topology and evidence; explicit replacement or abandonment cleans only the obsolete attempt | `ProofCloseoutRecordTest.php`, `ProofCloseoutServiceTest.php`, `TopologyReleaserTest.php`, `LoopFlowTest.php`; observation `proof-review-retention-and-replacement` | Failed refresh is retryable on newer main. Crash recovery works after resource deletion. Ordinary release and worktree removal protect captured success even if mutable proof state is absent. Discovery had no proof attempt, so `release --proof --replace` refused with exit 1 without changing discovery. |
| 8. Only proof-selected worktrees gain the lifecycle; discovery and failed-proof diagnosis remain unchanged | `LoopFlowTest.php`, `TopologyCommandsTest.php`, `TopologyReleaserTest.php`; observation `proof-review-access-metadata` | Discovery refused proof review metadata with exit 1. Existing discovery remained active and verified. Diagnosis cleanup remains available. |
| 9. Contributor, reviewer, closeout, and reference guidance agree | `AgentRoleContractTest.php`; `composer docs-lint` | Guidance preserves idle-discovery release before proof review, retained captured proof through review, fresh proof after review fixes, and verified closeout. Documentation lint reported 0 issues. |
| 10. Harness quality checks pass | Focused Pest suite and `cd apps/e2e && composer check` | 182 tests passed with 1,527 assertions. Rector, Pint, and PHPStan passed. |

## Discovery observations

Topology attempt: `223101c4841b29cf15471ef00c28d121`
Network: `oe-c384283f8be0`
Inventory: `gateway`, `app-dev`, `app-prod`, `app-prod-2`
Source candidate on mounted Nodes: `c448ee9467c815c4ae3d332415ec66671eed94a1`
Verification: passed, including the declared `app-prod` extension and `app-prod-2` assignment.
Resource state: active and retained for reviewer inspection.

Ordinary-user checks:

| Node | Command | Exit | Output |
| --- | --- | --- | --- |
| `gateway` | `/usr/bin/id -u` | 0 | `1000` |
| `app-dev` | `/usr/bin/id -u` | 0 | `1000` |
| `app-prod` | `/usr/bin/id -u` | 0 | `1000` |
| `app-prod-2` | `/usr/bin/id -u` | 0 | `1000` |

Discovery-flow boundary observations all returned exit 1 and made no proof or snapshot mutation:

- `proof-review-capture`: `bin/e2e-topology capture ORB-230 --json`
- `proof-review-evaluation`: `bin/e2e-topology review ORB-230 --json`
- `proof-review-fix-reproof`: `bin/e2e-topology prove ORB-230 --json`
- `proof-review-evidence-isolation`: `bin/e2e-topology equivalence ORB-230 --json`
- `proof-review-standard-and-extended`: `bin/e2e-topology candidate ORB-230 --json`
- `proof-review-closeout`: `bin/e2e-topology closeout ORB-230 --json`
- `proof-review-access-metadata`: `bin/e2e-topology exec ORB-230 gateway --proof --review-action=inspect --argv='["true"]' --json`
- `proof-review-retention-and-replacement`: `bin/e2e-topology release ORB-230 --proof --replace --json` reported no active proof attempt.

The first seven refusals reported that discovery disables proof, capture, review, equivalence, candidate convergence, and closeout. The plan asks for seven named observations but its acceptance map names six unique observations. This record includes every mapped name plus the review-evaluation and access-metadata checks; this is evidence clarification, not a behavior deviation.

## Checks

- Focused acceptance suite: `vendor/bin/pest --no-tia --compact` over the 17 plan-mapped state, value, service, command, flow, snapshot, and guidance test files: 182 passed, 1,527 assertions.
- `cd apps/e2e && composer check`: passed; guidance tests, Rector, Pint, and PHPStan all passed.
- `composer docs-lint`: passed with 0 issues, errors, or warnings.
- `bash -n bin/e2e-topology bin/worktree-remove`: passed.
- `git diff --check`: passed before commit.
- `bin/e2e-topology sync ORB-230 --json`: exit 0; host and guest SHA both equal the candidate; source clean.
- `bin/e2e-topology verify ORB-230 --json`: exit 0; all probes passed.
- Independent root review gate: pending Tom's reviewer.

## Documentation

Planning commits already supplied the maintained documentation for this behavior:

- `docs/concepts.md`: captured proof and mutable interactive review model.
- `docs/reference/implementation-loop.md`: capture, idle-discovery release, review, reproof, refresh, and cleanup order.
- `docs/reference/incus-topologies.md`: capture, review, status, release, and closeout command contracts.
- `docs/reference/proof-plans.md`: immutable proof versus separate review records and cleanup paths.
- `docs/reference/topology-snapshot.md`: reviewer-modified topology promotion refusal and refresh-failure retention.
- `docs/generated/context.json`: rebuilt generated context.

Audit reported: none. Development made no further maintained documentation edits.

## Deviations and limitations

Deviations: none.

Limitations: discovery observations validate real-machine availability, ordinary UID, topology health, and proof-flow isolation. They are development observations, not immutable acceptance proof. The independent root review gate and approval are pending. No GitHub or pull-request surface was changed.

Discovery development only; isolated acceptance proof not run
