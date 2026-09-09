# Implementation loop

This page is for contributors who prepare a candidate for review. It describes local checks and the Git references that hold plans and proof fixtures. [ADR 0049](../decisions/0049-keep-delivery-artifacts-off-the-merge-head.md) governs artifact storage; [proof plans](proof-plans.md) describes Incus evidence.

## Local checks

Run focused Pest tests for the affected behavior and failure modes, then run the changed project's `composer check`. The check runs guidance, Rector, formatting, lint, and analysis; it does not run a full test suite. Run `composer docs-lint` when documentation changes. Continuous integration (CI) runs all five full suites without test impact analysis. The submitted candidate requires green CI. Root `bin/test` and project `composer test` remain available for an explicit full local run or failure diagnosis.

## Artifact references

The local `.loop/` directory is ignored. It holds the plan, plan review, proof plan, and fixtures for one issue. The product candidate contains no `.loop/` paths. The commands use a temporary Git index and leave the feature head and its real index unchanged.

| Command | Result |
| --- | --- |
| `bin/loop-artifacts save ISSUE` | Saves the local workspace at `refs/orbit/loop/<issue-lowercase>/draft` for planning and plan review |
| `bin/loop-artifacts publish ISSUE` | Creates and pushes `refs/tags/loop/<issue-lowercase>/<candidate-sha>`; prints the candidate, ref, and artifact SHA |
| `bin/loop-artifacts fetch ISSUE --candidate=SHA` | Fetches that candidate's artifact ref and prints its binding |
| `git show <artifact-sha>:.loop/plan.md` | Reads the exact plan from the submitted snapshot |
| `git diff <candidate-sha> <artifact-sha> -- .loop/` | Shows the plan and every fixture for independent review |

Each published artifact commit has the candidate as its only parent. Its tree adds only `.loop/` paths. Repeating publication with identical contents succeeds. Different artifacts for an already published candidate require a new candidate commit. A symlink or special file causes publication to fail. Proof reads the committed artifact snapshot and refuses a working plan that differs from it.

The developer includes the artifact ref and SHA in the pull request body. The reviewer fetches that ref, verifies its SHA and candidate binding, and reads the plan and every fixture. Approval binds both SHAs. The orchestrator merges that exact candidate after approval and CI. Main integration requires a new candidate, artifact publication, evidence decision, and approval.

## Existing worktrees

Preserve the local workspace when converting an existing feature. Run `git rm -r --cached .loop`, commit that candidate change, then publish its artifacts before the next review. The harness can read existing tracked proof inputs for retained evidence. Feature closeout requires the separate artifact binding and a candidate without `.loop/`.

Artifact refs remain after feature branch and worktree cleanup. They retain the exact candidate as their parent and permit later inspection of the reviewed inputs.
