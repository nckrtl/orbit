# Feature plan

Issue: ORB-155
Review verdict: NOT REVIEWED

## Outcome

Candidate convergence reads and validates its retained proof, equivalence report, and linked proof-input manifest only after it holds the existing issue topology lock. A concurrent proof release or evidence change therefore fails before a candidate lease or Incus resource can be created.

## Code boundaries

In:
- `apps/e2e/app/E2E/TopologyProofRunner.php`
- `apps/e2e/tests/Unit/E2E/TopologyProofRunnerTest.php`
- Existing proof-release behavior in `apps/e2e/tests/Unit/E2E/TopologyReleaserTest.php`

Out:
- New locks, lock recursion, or a generic lock framework
- Candidate convergence, release, promotion, and equivalence behavior outside authorization timing
- Product components outside `apps/e2e`

## Documentation

Audit scope:
- `docs/reference/incus-topologies.md`
- `docs/reference/proof-plans.md`
- Accepted ADRs returned by the filtered `apps/e2e` documentation context

Fixed:
- None.

Reported:
- None.

Documentation is unchanged. The issue has no `docs` label, and the scoped references already state that harness commands use the issue lock and candidate convergence requires retained proof plus linked equivalence evidence. Lock-internal read ordering is an implementation safety property covered by tests and proof.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Proof lease removed during lock contention rejects convergence without candidate state or resources | `TopologyProofRunner::convergeCandidate` and `IssueState` proof lease | `rejects candidate convergence when proof release completes during issue-lock contention`; existing `TopologyReleaserTest` retained-proof release coverage |
| Equivalence pointer or linked manifest changed during contention rejects before resources | Candidate authorization reads inside the issue lock | `rejects candidate convergence when the equivalence pointer changes during issue-lock contention`; `rejects candidate convergence when the linked manifest changes during issue-lock contention` |
| Valid unchanged evidence authorizes convergence; failed validation releases the lock and preserves retained proof | Candidate authorization validation and `finally` lock release | `converges and verifies an authorized exact candidate without rerunning acceptance actions`; pointer-contention test reacquires the lock and compares the unchanged proof record |
| Real OS lock boundary passes on isolated topology | App-dev VM filesystem locking and PCNTL alarm interruption | Incus action `candidate-authorization-contention` |
| Owning-project checks pass | `apps/e2e` | `cd apps/e2e && composer check` |
| Repository suites pass | Monorepo | `PHPRC=/dev/null bin/test` in the reserved final evidence window |

## Implementation order

1. Move all retained candidate-authorization reads and validation under the current issue lock.
2. Add real file-lock contention regressions for proof release, pointer replacement, and linked-manifest replacement.
3. Preserve and exercise the unchanged valid candidate path and proof-release behavior.
4. Run focused and owning-project checks, then validate the Incus action on discovery.
5. Integrate current main and run root tests plus immutable proof in the reserved final evidence window.

## Must preserve

- Repository identity and clean-worktree preflight remain outside the issue lock.
- Candidate-attempt refusal remains under the same issue lock.
- Every existing authorization diagnostic and fail-closed validation remains unchanged.
- Failed authorization creates no candidate lease, topology record, network, or VM.
- The issue lock releases through `finally`, including validation failures.
- Retained proof state is not rewritten by failed candidate authorization.
- No recursive issue-lock acquisition is introduced.

## Proof decisions

- The proof plan has one action for the one Incus acceptance criterion.
- The action runs the contracted candidate and release regressions on a real app-dev VM, using the VM's filesystem lock and PCNTL signal boundary.
- `observed_inputs` is false. The changed code is the host-side Incus harness, while complete PCOV requires app-dev CLI, gateway CLI, and gateway FPM observations. Manufacturing gateway runtime requests would not observe this host-only authorization boundary and would add unrelated behavior.
- The action is read-only outside temporary test directories, so `mutates` is false.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
