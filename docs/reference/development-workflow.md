# Development workflow

Every change to Orbit follows one short flow. A Linear issue defines it, an
agent implements it in a worktree, a reviewer re-proves it, a merge agent
merges and cleans up. Governed by
[ADR 0006](../decisions/0006-topology-led-feature-development.md).

## Feature flow

1. **Issue.** Linear (team `NCK`): outcome, scope, acceptance criteria,
   components, ADR. `Proof: incus` when a real machine is needed. Ready when
   complete. See [creating-orbit-issues](../../.agents/skills/creating-orbit-issues/SKILL.md).
2. **Worktree.** `bin/worktree-create NCK-123 slug`, then `bin/bootstrap` in it.
   Issue → In Progress.
3. **Fresh topology.** `bin/e2e-topology acquire NCK-123 <worktree>`: three VMs
   cloned from the standby snapshot (~20 s), worktree mounted at
   `/home/orbit/orbit` on `gateway` and `app-dev`.
4. **Get it right.** `bin/e2e-topology shell NCK-123 <role>` opens a shell as
   `orbit`. Edit, run, repeat. `exec` for scripted checks.
5. **Codify.** Manual steps become product code with tests. `composer check`
   in each changed project, root `bin/test`, commit.
6. **Prove fresh.** `git merge main`, `bin/e2e-topology release NCK-123`,
   `bin/e2e-topology prove NCK-123` with `proofs/NCK-123.json`. New VMs, exact
   commit, full convergence; every acceptance action exits 0.
7. **Pull request.** Short and human: what changed, "Proved with
   `proofs/NCK-123.json` at `<sha>`".
8. **Review.** The reviewer merges `main`, re-proves with the same plan, reads
   the code, posts `Approved.`, keeps the proved topology alive.
9. **Merge.** `gh pr merge --merge`; `bin/e2e-standby promote NCK-123` makes
   the reviewer's topology the standby generation (fallback `refresh`);
   `bin/worktree-remove NCK-123 slug` releases and deletes; close the issue.

Issues without `Proof: incus` run steps 1, 2, 5, 7, 8 (CI green instead of a
proof), 9 (no promote).

## Harness flow

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except
`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. Changes to it are
their own issues with `apps/e2e` in Components. No discovery topology. The proof is
`bin/e2e-live <sha>`: build a standby from the candidate in the validation
clone and run the feature flow once end to end, promote included. The reviewer
runs it too; the merge agent promotes the standby it built.

Feature branches never modify the harness. A harness gap found during a
feature is reported, fixed in its own issue, and the feature resumes on the
new `main`.

## Where things live

| What | Where | Lifetime |
|---|---|---|
| Proof plan and fixtures | `proofs/<ISSUE>.json`, `proofs/<ISSUE>/` | committed with the PR |
| Attempt, lease, last proof, log | `<worktree>/.e2e/` | dies with the worktree |
| Promoted standby generation | `<primary checkout>/.e2e/standby/` | until the next promote |
| Topologies | Incus, `orbit-e2e-<issue>-<attempt8>-<role>` on `oe-<id>` | until `release` |

Details of the commands: [incus-topologies](incus-topologies.md).
