# Development workflow

Every change to Orbit follows one short flow. A Linear issue defines it, a
fresh planner maps implementation before coding, one agent implements it in a
worktree, a reviewer re-proves it, and a merge agent merges and cleans up.
Governed by [ADR 0007](../decisions/0007-nine-step-feature-flow.md) and
[ADR 0010](../decisions/0010-record-decisions-before-implementation-issues.md).

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
verifier cannot safely hard-cutover first. Put all independent roots whose
prerequisites are already on `main` in `Todo` together. Keep dependents in
`Backlog`; after a prerequisite merges, recheck the dependent issue against
the new `main` before admitting it to `Todo`.

`Backlog` means the issue is recorded but is not ready. `Todo` means the
implementation contract is complete, proof-feasible, and claimable. `Blocked`
is reserved for claimed work that cannot continue. Issue creation always sets
`Backlog` or `Todo` explicitly. This refinement shapes Linear work but does not
create `.orbit/plan.md`; that temporary implementation map begins after claim.

## Feature flow

1. **Issue.** Linear: explicit state, outcome, readiness when backlogged,
   scope, acceptance criteria, components, governing ADRs, real dependencies,
   and `Proof: incus` when a real machine is needed.
   A Linear issue with status `Todo` is ready for implementation. See [creating-issues](../../.agents/skills/creating-issues/SKILL.md).
2. **Worktree and preflight.** `bin/worktree-create <ISSUE> slug` creates and
   bootstraps the worktree and initializes gitignored `.orbit/plan.md`. A fresh
   planner follows [planning-features](../../.agents/skills/planning-features/SKILL.md),
   mapping every criterion to code boundaries and focused proof without
   changing code. A fresh independent reviewer follows
   [reviewing-feature-plans](../../.agents/skills/reviewing-feature-plans/SKILL.md)
   and records `PASS`, `FIX`, or `BLOCK`. Every `FIX` starts a fresh correction
   planner and then a fresh independent reviewer. Repeat until `PASS` or
   `BLOCK`; only `BLOCK` stops preflight. Issue → In Progress only after `PASS`.
   `PASS` is the normal result. `FIX` means the issue remains implementable but
   its temporary plan needs correction, so it stays `Todo`. `BLOCK` means the issue was not ready; move claimed work to `Blocked`, repair the issue or
   dependency graph, return it to `Todo`, and start a fresh preflight. Unless
   `main` drifted after refinement, a block is an issue-creation failure.
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
8. **Review.** A fresh reviewer merges `main`, re-proves with the same plan,
   reads the code, and reports all blocking findings in one pass. Findings must
   identify a defect against the issue, ADR, existing invariant/test, or repo
   rule. New requirements become separate Linear work. Approval is exactly
   `Approved.` and the proved topology stays alive.
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
