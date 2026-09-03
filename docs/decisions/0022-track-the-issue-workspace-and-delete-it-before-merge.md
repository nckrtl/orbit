# ADR 0022: Track the issue workspace and delete it before merge

In the context of the plan, the proof plan, and the fixtures that describe how one issue is built and proved, facing a plan no reviewer reads from the pull request and twenty-five proof plans that stay on `main` once their issues close, we decided for tracking all of it under `.loop/` on the issue's branch and deleting that directory in one commit before the merge, and against ignoring it or leaving it on `main`, to achieve one reviewable workspace per issue and a `main` that carries none of it, accepting that every issue takes a second approval.

## Status

Accepted on 2026-09-03. Amends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md) where a proof plan declares another issue's fixtures.

Corrected on 2026-09-03: the directory is named `.loop`. The record named it
`.orbit` when it was accepted, before anything used it.

## Context

A fixture is executable code, and a fixture that asserts nothing and exits zero makes a proof pass. A reviewer needs it in the diff, because a fixture reaches hundreds of lines and a pull-request description does not review one. The plan has the same problem from the other side: it is ignored today, so a reviewer reads it only by reaching into the implementer's worktree on the proving host.

After an issue merges, no command reads its plan, its proof plan, or its fixtures again. Twenty-five proof plans are tracked and one belongs to open work. They rot, because a plan that calls a command another issue deletes cannot run and misleads whoever trusts it. They also couple unrelated work: one plan stages another issue's fixtures, and that single reference blocks deleting the product code those fixtures exercise.

## Decision

- `.loop/` holds everything that describes how one issue is built and proved: the plan, the proof plan, and the fixtures. Git tracks it on the issue's branch.
- `main` carries no `.loop` directory.
- The harness reads a proof plan and its fixtures from `.loop/proof/` and reads neither from anywhere else.
- A reviewer approves the head that carries `.loop`, having read the plan, checked that every acceptance criterion proved on Incus has an action, and checked that each fixture asserts what its action claims.
- The implementer then pushes one commit that deletes `.loop` and nothing else.
- The merge gate refuses the merge unless that commit's only difference from the approved head is the removal of `.loop`, and requires an approval of it.
- A proof plan declares no other issue's fixtures. An issue that needs a fixture another issue wrote copies it into its own `.loop/proof/`.
- The fixture checks in `apps/e2e` name no individual fixture. They apply to whatever the branch carries.
- `.e2e/` stays ignored. It holds retained topology state, not a description of the work.

## Rejected alternatives

- Leave the proof plans on `main`: rejected because twenty-four of twenty-five belong to closed work, they rot, and one plan that stages another issue's fixtures already blocks an unrelated deletion.
- Delete them after the merge: rejected because that needs a direct push to a protected branch, or its own pull request and its own approval.
- Keep them ignored and carry the plan in the pull-request description: rejected because a fixture is executable code and a description does not review it.
- Track the proof plan but keep the plan ignored: rejected because it leaves the plan reviewable only on the proving host and needs two removal rules instead of one.

## Consequences

- A reviewer reads the plan, the proof plan, and every fixture in the diff, so plan review no longer depends on reaching the implementer's worktree.
- The removal is one rule a machine checks: the diff deletes `.loop` and touches nothing else.
- Every issue takes two approvals, and the second reviews that removal.
- The plan and the fixtures stay readable in the closed pull request, which is where someone debugging a merged issue looks.
- A fixture two issues need exists once per issue.
- The removal commit cannot stale a retained proof, because the static input policy classifies a proof path as a non-runtime input.
- The plan reviewer writes its verdict and findings into a tracked file, so those edits reach the pull request as commits.
- Branch protection that requires approvals enforces this gate once review runs under an identity other than the pull-request author.

## Affects

- Components: apps/e2e
- ADRs: amends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md) for cross-issue fixture staging
- Detail: docs/reference/proof-plans.md
- Verify: the `apps/e2e` Pest suite applies the fixture checks to whatever fixtures a branch carries; `git diff <approved-sha> <head> --name-only` on a removal head lists only paths under `.loop/`
