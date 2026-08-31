# Development workflow

Every change to Orbit follows one short flow. A Linear issue defines it, a
fresh planner maps implementation before coding, one agent implements it in a
worktree, a reviewer re-proves it, and a merge agent merges and cleans up.
Governed by [ADR 0007](../decisions/0007-nine-step-feature-flow.md).

## Feature flow

1. **Issue.** Linear (team `NCK`): outcome, scope, acceptance criteria,
   components, ADR. `Proof: incus` when a real machine is needed. Orbit uses
   Linear `Todo` as its Ready-equivalent; enter Todo only when complete. See [creating-issues](../../.agents/skills/creating-issues/SKILL.md).
2. **Worktree and preflight.** `bin/worktree-create NCK-123 slug` creates and
   bootstraps the worktree and initializes gitignored `.orbit/plan.md`. A fresh
   planner follows [planning-features](../../.agents/skills/planning-features/SKILL.md),
   mapping every criterion to code boundaries and focused proof without
   changing code. A fresh independent reviewer follows
   [reviewing-feature-plans](../../.agents/skills/reviewing-feature-plans/SKILL.md)
   and records `PASS`, `FIX`, or `BLOCK`. Every `FIX` starts a fresh correction
   planner and then a fresh independent reviewer. Repeat until `PASS` or
   `BLOCK`; only `BLOCK` stops preflight. Issue → In Progress only after `PASS`.
3. **Fresh topology.** `bin/e2e-topology acquire NCK-123 <worktree>`: three VMs
   cloned from the standby snapshot (~20 s), worktree mounted at
   `/home/orbit/orbit` on `gateway` and `app-dev`.
4. **Get it right.** `bin/e2e-topology shell NCK-123 <role>` opens a shell as
   `orbit`. The same implementer follows the approved implementation order,
   edits, runs, and repeats. It may use bounded subagents, but not one agent per
   increment. `exec` is available for scripted checks.
5. **Codify.** Manual steps become product code with tests. Run mapped focused
   proof as increments complete, each changed project's `composer check`, and
   root `bin/test`. Separate commits per increment are optional.
6. **Prove fresh.** `git merge main`, `bin/e2e-topology release NCK-123`,
   `bin/e2e-topology prove NCK-123` with `proofs/NCK-123.json`. New VMs, exact
   commit, full convergence; every acceptance action exits 0.
7. **Pull request.** Short and human: what changed, "Proved with
   `proofs/NCK-123.json` at `<sha>`".
8. **Review.** A fresh reviewer merges `main`, re-proves with the same plan,
   reads the code, and reports all blocking findings in one pass. Findings must
   identify a defect against the issue, ADR, existing invariant/test, or repo
   rule. New requirements become separate Linear work. Approval is exactly
   `Approved.` and the proved topology stays alive.
9. **Merge.** `gh pr merge --merge`; `bin/e2e-standby promote NCK-123` makes
   the reviewer's topology the standby generation (fallback `refresh`);
   `bin/worktree-remove NCK-123 slug` releases and deletes; close the issue.

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
