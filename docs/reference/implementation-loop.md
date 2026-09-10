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

Each feature worker uses one whole-repository worktree. Run Composer and Pest from the affected project directory, such as `apps/gateway` or `packages/php-sdk`. Projects keep separate dependencies, test configurations, and test impact analysis (TIA) baselines. Run checks in each project that a change affects.

Worktree bootstrap installs all five projects. Their Composer hooks apply the pinned Pest monorepo and consumer-autoloader fixes before generating autoloaders. This also runs on a direct `composer install` or `composer dump-autoload` in a project. Each worktree has its own installed package; setup needs no external local fork or shared vendor symlink. A modified or unsupported Pest build fails setup. Installations without development dependencies skip Pest setup.

Use these commands from the affected project directory.

| Command | Result |
| --- | --- |
| `vendor/bin/pest --compact tests/Unit/ExampleTest.php` | Runs the selected file directly; an explicit path or filter bypasses TIA |
| `composer test:affected` | Runs TIA with two parallel workers; selects affected tests when a valid baseline exists |
| `composer check` | Runs project quality checks without the full test suite |
| `composer test` | Runs the full project suite with TIA disabled |

TIA requires PCOV or Xdebug to record dependencies. The first run, or a run without a usable baseline, can execute the full project suite. Later runs reuse the baseline and select tests affected by changes. Run `test:affected` in each affected project when this broader local feedback is useful; focused acceptance tests and full CI remain required. A TIA skip or zero selected tests is not new acceptance evidence.

Baselines stay separate between projects. Bootstrap seeds absent worktree caches from a compatible successful main baseline. A missing or incompatible publication still needs an initial recording run. Discovery and proof flow selection do not change test-runner setup.

## Main test baselines

[ADR 0052](../decisions/0052-seed-worktrees-from-successful-main-test-baselines.md) governs baseline ownership. Each repository stores one successful publication per Composer project in its Git common directory under `orbit-tia/v1/published`. Linked worktrees share these publications and keep their writable Pest caches separate. Other repositories and separate clones need their own initial refresh.

Worktree creation calls bootstrap, which installs the patched Pest runner and copies a compatible main dependency graph into each absent private cache. Bootstrap preserves an existing cache and reports a cache miss without running tests. Manually created worktrees get the same setup through `bin/bootstrap`.

The repository commands manage this lifecycle.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches in the current worktree |
| `bin/tia-cache refresh --background` | Queues a serialized refresh and returns its process ID and log path immediately |
| `bin/tia-cache refresh` | Refreshes in the foreground; exits nonzero if any project fails |
| `bin/tia-cache status` | Prints each publication's tested main commit, graph anchor, compatibility, checksum, and publication time, plus the refresh log path |
| `bin/worktree-remove ISSUE slug` | Verifies the feature merged, queues background refresh, then releases resources and removes the worktree |

Each cache command accepts `--repository=PATH` and repeatable `--project=apps/docs` options. The default covers all five Composer projects. Seed reports missing or incompatible graphs and leaves those projects cold; a cache miss does not fail setup.

Refresh fetches current main and advances an owned clean maintenance checkout under `orbit-tia/v1/checkout`. Its separate Git directory gives Pest an isolated cache while preserving the repository's remote URL. The command leaves the user's primary checkout and feature checkouts untouched. Closeout still advances a clean primary main when possible. A dirty maintenance checkout causes refresh to fail and retain its files for inspection.

Maintenance installs dependencies and runs `composer test:affected` one project at a time, with two test workers and PCOV or Xdebug coverage enabled. Each successful project publishes independently. Refreshes hold one repository lock; a queued refresh fetches the newest main after acquiring it. Background jobs keep their runner and log in the Git common directory so worktree removal cannot interrupt them. When closeout does not use `bin/worktree-remove`, the orchestrator queues refresh explicitly after verifying the merge.

Publication replaces one complete snapshot atomically after testing succeeds on clean main. The snapshot contains only the portable dependency graph and its metadata. Seed checks the project, Pest patch and test configuration, Pest fingerprint including dependencies and PHP minor version, checksum, and commit ancestry. It does not copy affected-test lists, worker partials, coverage reports, or download state. A no-affected-tests run can publish a newer tested main commit while retaining an older graph anchor; both commits are recorded.

New worktrees may use the previous successful compatible publication while a refresh runs. Failed refreshes retain that publication and report the failure in `refresh.log`; retry with the refresh command after resolving the failure. Baseline maintenance does not delay merge, resource cleanup, or the next feature. TIA remains optional local feedback; a warm cache or zero selected tests does not establish acceptance evidence.

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
