# Development workflow

Every change to Orbit follows one short flow. A Linear issue defines it, a
fresh planner maps implementation before coding, one agent implements it in a
worktree, a reviewer re-proves it, and a merge agent merges and cleans up.
Governed by [ADR 0007](../decisions/0007-nine-step-feature-flow.md),
[ADR 0010](../decisions/0010-record-decisions-before-implementation-issues.md),
[ADR 0011](../decisions/0011-linear-lifecycle-states.md), and
[ADR 0014](../decisions/0014-reconcile-technical-preflight-blocks.md).

## Decision and issue refinement

Feature discussion can begin without a Linear issue. When it introduces or
changes a choice of architectural significance, stop issue creation. Draft a
`Proposed` ADR, revise its exact text with the user, mark the approved decision
`Accepted`, and put it on `origin/main` before implementation issues are
derived. The approved ADR may be an ADR-only direct commit when the user
approved its exact final text, the commit contains only that ADR, local `main`
matches the current remote base, and unrelated work is preserved. A pull
request remains optional.

After the decision lands, reconcile affected open work and refine the complete
issue set against current `main`. Inspect relevant product, migration, proof,
and harness code; close lifecycle, ownership, migration, compatibility,
failure, rollback, and removal decisions; and give every acceptance criterion
one available proof action.

Dependencies must be explicit and acyclic and represent only real
prerequisites. Start a compatibility bridge when the current product and its
verifier cannot safely hard-cutover first. Put every fully refined issue in
`Todo`; a dependent remains in that ordered queue but is ineligible until all
of its prerequisites are `Done` and present on current `origin/main`.

`Backlog` means the issue is rough and still needs refinement. `Todo` is the
ordered queue of refined, proof-feasible issues. `In Progress` begins before
Tom creates or resumes any worktree, Herdr worker, or preflight turn. `Blocked`
parks started work and retains its artifacts without consuming execution
concurrency. `In Review` begins before independent PR review. Issue creation
always sets `Backlog` or `Todo` explicitly. Refinement does not create
`.orbit/plan.md`; that temporary implementation map begins after claim.

Execution concurrency is the live number of `In Progress` plus `In Review`
issues, with a maximum of three. When capacity exists, Tom scans Todo in its
established order, skips every issue with a prerequisite not yet `Done` on
current `origin/main`, and selects the first eligible issue.

## Feature flow

1. **Issue.** Linear: explicit state, outcome, readiness when backlogged,
   scope, acceptance criteria, components, governing ADRs, real dependencies,
   and `Proof: incus` when a real machine is needed.
   A Linear issue with status `Todo` is refined and queued; it is eligible only
   when every declared prerequisite is `Done` on current `origin/main`. See
   [creating-issues](../../.agents/skills/creating-issues/SKILL.md).
2. **Claim, worktree, and preflight.** Tom moves the selected issue to
   `In Progress` and reads it back before doing anything in Herdr.
   `bin/worktree-create <ISSUE> slug` creates and
   bootstraps the worktree and initializes gitignored `.orbit/plan.md`. A fresh
   planner follows [planning-features](../../.agents/skills/planning-features/SKILL.md),
   mapping every criterion to code boundaries and focused proof without
   changing code. A fresh independent reviewer follows
   [reviewing-feature-plans](../../.agents/skills/reviewing-feature-plans/SKILL.md)
   and records `PASS`, `FIX`, or `BLOCK`. Every `FIX` starts a fresh correction
   planner and then a fresh independent reviewer. `PASS` is the normal result and
   continues into implementation while the issue stays `In Progress`. `FIX` means the issue remains implementable but only its temporary plan needs
   correction; keep it `In Progress`.

   `BLOCK` stops implementation but does not immediately change Linear state.
   The reviewer includes the exact blocker, evidence, smallest safe recommended
   resolution, and apparent decision boundary. Tom exits that reviewer and
   starts a fresh Codex `gpt-5.6-sol` xhigh reconciler following
   [reconciling-feature-blocks](../../.agents/skills/reconciling-feature-blocks/SKILL.md).
   The reconciler independently checks the finding and searches for a simpler,
   narrower, more elegant, or less invasive resolution.

   - `TECHNICAL_RESOLUTION`: keep the issue `In Progress`. Internal harness
     behavior, test mechanics, proof technique, sequencing, implementation
     boundaries, or narrow scope may change when necessary to satisfy the
     already accepted outcome without changing product behavior or weakening
     proof. If only the plan changes, Tom starts a fresh correction planner. If
     Linear or a relation needs a durable mutation, Tom routes the exact
     proposal to Anna; Anna verifies delegated technical authority, applies and
     reads back the change, and signals Tom to continue. Tom then starts a
     wholly fresh planner and reviewer. The fresh reviewer's `PASS` is agreement
     with the resolution.
   - `HUMAN_DECISION_REQUIRED`: move the issue to `Blocked`, park its worktree,
     Herdr workspace, branch, and plan, and record the bounded decision,
     evidence, owner, retained artifacts, and restart condition in Linear. Use
     this only for product-visible behavior, conflicting requirements, weakened
     outcomes, ownership, migration, compatibility, security, privacy, data
     integrity, rollback, material irreversible risk, any new or changed ADR,
     or unguided architectural direction. Internal technical or harness choices
     are not enough. Anna prepares the bounded ADR proposal; Nick's exact-text
     approval remains required before acceptance.

   Tom remains routing only: he never judges the recommendation or edits the
   issue contract or relations. Repeated technical `BLOCK` results remain active
   preflight non-convergence for Tom and Anna to diagnose; they are not
   misrepresented as Nick-owned product decisions.
3. **Fresh topology.** `bin/e2e-topology acquire <ISSUE> <worktree>`: three VMs
   cloned from the standby snapshot (~20 s), worktree mounted at
   `/home/orbit/orbit` on `gateway` and `app-dev`.
4. **Get it right.** `bin/e2e-topology shell <ISSUE> <role>` opens a shell as
   `orbit`. The same implementer follows the approved implementation order,
   edits, runs, and repeats. It may use bounded subagents, but not one agent per
   increment. `exec` is available for scripted checks.
5. **Codify.** Manual steps become product code with tests. Run mapped focused
   proof as increments complete, each changed project's `composer check`, and
   root `bin/test`. Separate commits per increment are optional.
6. **Prove fresh.** `git merge main`, `bin/e2e-topology release <ISSUE>`,
   `bin/e2e-topology prove <ISSUE>` with `proofs/<ISSUE>.json`. New VMs, exact
   commit, full convergence; every acceptance action exits 0.
7. **Pull request.** Short and human: what changed, "Proved with
   `proofs/<ISSUE>.json` at `<sha>`".
8. **Review.** Tom moves the issue to `In Review` and reads it back before a
   fresh reviewer merges `main`, re-proves with the same plan,
   reads the code, and reports all blocking findings in one pass. Findings must
   identify a defect against the issue, ADR, existing invariant/test, or repo
   rule. New requirements become separate Linear work. Approval is exactly
   `Approved.` and the proved topology stays alive. Actionable findings move
   the issue back to `In Progress` before the retained implementer resumes.
9. **Merge.** `gh pr merge --merge`; `bin/e2e-standby promote <ISSUE>` makes
   the reviewer's topology the standby generation (fallback `refresh`);
   `bin/worktree-remove <ISSUE> slug` releases and deletes; close the issue.

Issues without `Proof: incus` run steps 1, 2, 5, 7, 8 (CI green instead of a
proof), and 9 without promote.

## Correction loop

The same implementer handles every valid requested change. Every pushed head
gets a fresh PR reviewer. Review does not widen the feature contract. If more
than two PR review rounds are needed, Tom pauses that issue and diagnoses
whether the issue contract, preflight, repository guidance, or automated proof
is missing a durable guard before continuing.

## Harness flow

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except
`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. Changes to it are
their own issues with `apps/e2e` in Components. Preflight still maps the
intended harness boundary, but there is no discovery topology. The proof is
`bin/e2e-live <sha>`: build a standby from the candidate in the validation
clone and run the feature flow once end to end, promote included. The reviewer
runs it too; the merge agent promotes the standby it built.

Feature branches never modify the harness. A harness gap found during a
feature is reported, fixed in its own issue, and the feature resumes on the
new `main`.

## Where things live

| What | Where | Lifetime |
|---|---|---|
| Implementation preflight | `<worktree>/.orbit/plan.md` | gitignored; dies with the worktree |
| Proof plan and fixtures | `proofs/<ISSUE>.json`, `proofs/<ISSUE>/` | committed with the PR |
| Attempt, lease, last proof, log | `<worktree>/.e2e/` | dies with the worktree |
| Promoted standby generation | `<primary checkout>/.e2e/standby/` | until the next promote |
| Topologies | Incus, `orbit-e2e-<issue>-<attempt8>-<role>` on `oe-<id>` | until `release` |

Details of the commands: [incus-topologies](incus-topologies.md).
