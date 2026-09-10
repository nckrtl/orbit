# Implementation loop

This page is for contributors who prepare a candidate for review. It describes delivery flow selection, local checks, and the Git references that hold plans and development evidence. [ADR 0049](../decisions/0049-keep-delivery-artifacts-off-the-merge-head.md) governs artifact storage; [proof plans](proof-plans.md) describes Incus evidence.

## Select a flow

Discovery is the built-in default for new clones and unselected worktrees. Proof requires an explicit selection. A worktree uses either `discovery` or `proof`, as governed by [ADR 0051](../decisions/0051-select-discovery-only-feature-delivery.md). The selected flow lives in ignored `.loop/flow.json` and travels with the candidate-bound artifact ref. Each plan, implementation, review, and closeout handoff names the flow. The reviewer verifies the published selection matches the handoff and local selection.

| Command | Result |
| --- | --- |
| `bin/loop-flow default --flow=discovery` | Selects discovery-only delivery for new worktrees in this repository |
| `bin/loop-flow default --flow=proof` | Selects the proof flow for new worktrees |
| `bin/loop-flow default` | Prints the repository default; an unset default is `discovery` |
| `bin/worktree-create ISSUE slug --flow=discovery` | Creates and bootstraps a worktree with discovery-only delivery |
| `bin/worktree-create ISSUE slug --flow=proof` | Creates and bootstraps a worktree with the proof flow |
| `bin/worktree-create ISSUE slug` | Creates a worktree using the repository default; keeps an existing worktree's selection |
| `bin/loop-flow init` | Initializes a manually created worktree from the repository default without replacing an existing selection |
| `bin/loop-flow status` | Prints the current worktree's flow; an unselected worktree is `discovery` |
| `bin/loop-flow select --flow=discovery` | Explicitly changes the current worktree's flow |
| `bin/loop-flow select --flow=proof` | Explicitly restores the current worktree's proof flow |

The default uses repository-local Git configuration `orbit.loopFlow`, shared across linked worktrees. It does not change selections already saved in worktrees. Selection commands also accept `--worktree=PATH`. A malformed selection fails instead of falling back. A flow switch changes reviewed artifacts: update the plan, publish for a new candidate, and review under the new flow. Switching flows never creates or deletes topology resources.

## Discovery-only delivery

The discovery flow follows this order.

1. Create and bootstrap the issue worktree with its selected flow.
2. Run preflight through `planning-features`, including the documentation audit and acceptance map.
3. Obtain an independent preflight review through `reviewing-feature-plans`.
4. Acquire discovery with `bin/e2e-topology acquire ISSUE WORKTREE` after preflight passes.
5. Implement using `shell`, `exec`, `sync`, and `verify` on discovery as development tools. Run focused local tests and project checks.
6. Commit and push the candidate, publish the artifacts, and obtain independent code review with green CI.
7. Merge the approved candidate, then release discovery and remove the worktree.

The issue's acceptance outcomes stay required. Existing Incus `Proof:` venues map to reproducible discovery observations, focused tests, and CI. The handoff identifies each actual check and says `Discovery development only; isolated acceptance proof not run`. It does not claim immutable acceptance proof. A proof plan, proof fixtures, observations manifest, equivalence report, candidate-convergence attempt, or snapshot refresh is not required. The harness refuses `prove`, `equivalence`, and `candidate` for a worktree selected as `discovery`.

Discovery uses the existing isolated topology machinery and mounts the changing worktree. Acquisition validates the saved snapshot against its recorded generation and checks cold-base compatibility, ownership, capacity, and readiness. It does not require that snapshot to match current main. An incompatible cold base or absent snapshot still needs an explicit infrastructure repair. Optional extended discovery reuses the existing extension declaration format described in [Incus topologies](incus-topologies.md); its actions do not run as acceptance proof.

An advance of main alone does not require integration, another approval, proof, or CI on a replacement candidate. The orchestrator merges when GitHub reports the approved candidate can merge and its required CI checks are green. If actual conflicts block merging, the implementer fetches main, merges it into the branch, resolves conflicts, runs affected checks, publishes artifacts for the new head, and pushes again. The reviewer checks the resolution changes and affected acceptance items on that head; preflight does not restart.

GitHub requires all five CI jobs with strict branch freshness disabled so conflict-free candidates can merge without including the latest main.

Closeout verifies authoritative GitHub merge state and runs `bin/loop-flow verify-merge --candidate=SHA --merge=SHA`. The command requires the approved candidate as the exact second parent and the conflict-free merge tree of the recorded parents. That tree can differ from the candidate when main has advanced. Closeout advances the primary checkout, releases discovery, and runs `bin/worktree-remove ISSUE slug`. Snapshot promotion and refresh are separate infrastructure operations in this flow.

## Proof delivery

The proof flow uses the same worktree, preflight, documentation, review, artifacts, and local checks. Issues with `proof:incus` also require the isolated acceptance proof and captured evidence described in [Proof plans](proof-plans.md). Candidate preparation includes current main; review and closeout enforce that binding. A later candidate uses the retained-proof evaluation and main-delta review rules in the existing skills. Successful proof resources are captured and released before review, and closeout refreshes the shared snapshot from merged main. `verify-merge` requires the feature to include the merged base and the merge tree to equal the approved candidate's tree.

## Local checks

Run focused Pest tests for the affected behavior and failure modes, then run the changed project's `composer check`. The check runs guidance, Rector, formatting, lint, and analysis; it does not run a full test suite. Run `composer docs-lint` when documentation changes. Continuous integration (CI) runs all five full suites without test impact analysis. The submitted candidate requires green CI. Root `bin/test` and project `composer test` remain available for an explicit full local run or failure diagnosis.

## Artifact references

The local `.loop/` directory is ignored. It holds the flow selection, plan, plan review, development notes, and any proof plan and fixtures for one issue. The product candidate contains no `.loop/` paths. The commands use a temporary Git index and leave the feature head and its real index unchanged.

| Command | Result |
| --- | --- |
| `bin/loop-artifacts save ISSUE` | Saves the local workspace at `refs/orbit/loop/<issue-lowercase>/draft` for planning and plan review |
| `bin/loop-artifacts publish ISSUE` | Creates and pushes `refs/tags/loop/<issue-lowercase>/<candidate-sha>`; prints the candidate, ref, and artifact SHA |
| `bin/loop-artifacts fetch ISSUE --candidate=SHA` | Fetches that candidate's artifact ref and prints its binding |
| `git show <artifact-sha>:.loop/plan.md` | Reads the exact plan from the submitted snapshot |
| `git diff <candidate-sha> <artifact-sha> -- .loop/` | Shows the plan and every fixture for independent review |

Each published artifact commit has the candidate as its only parent. Its tree adds only `.loop/` paths. Repeating publication with identical contents succeeds. Different artifacts for an already published candidate require a new candidate commit. A symlink or special file causes publication to fail. Proof reads the committed artifact snapshot and refuses a working plan that differs from it.

The developer includes the artifact ref and SHA in the pull request body. The reviewer fetches that ref, verifies its SHA and candidate binding, and reads the plan and every fixture. Approval binds both SHAs. The orchestrator merges that exact candidate after approval and CI. Main integration creates a new candidate and requires artifact publication, CI, and approval under the selected flow. Discovery-only delivery does not integrate main solely because it advanced.

## Existing worktrees

Preserve the local workspace when converting an existing feature. Run `git rm -r --cached .loop`, commit that candidate change, then publish its artifacts before the next review. The harness can read existing tracked proof inputs for retained evidence. Feature closeout requires the separate artifact binding and a candidate without `.loop/`.

Artifact refs remain after feature branch and worktree cleanup. They retain the exact candidate as their parent and permit later inspection of the reviewed inputs.
