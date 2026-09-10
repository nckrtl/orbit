# Implementation loop

This page is for contributors who prepare a candidate for review. It describes delivery flow selection, local checks, and the Git references that hold plans and development evidence. [ADR 0049](../decisions/0049-keep-delivery-artifacts-off-the-merge-head.md) governs artifact storage; [proof plans](proof-plans.md) describes Incus evidence.

## Select a flow

Discovery is the built-in default for new clones and unselected worktrees. Proof requires an explicit selection. A worktree uses either `discovery` or `proof`, as governed by [ADR 0051](../decisions/0051-select-discovery-only-feature-delivery.md). The selected flow lives in ignored `.loop/flow.json` and travels with the candidate-bound artifact ref. Each plan, implementation, review, and closeout handoff names the flow. The reviewer verifies the published selection matches the handoff and local selection.

| Command | Result |
| --- | --- |
| `bin/loop-flow default --flow=discovery` | Selects discovery-only delivery for new worktrees in this repository |
| `bin/loop-flow default --flow=proof` | Selects the proof flow for new worktrees |
| `bin/loop-flow default` | Prints the repository default; an unset default is `discovery` |
| `bin/worktree-create ISSUE --flow=discovery` | Creates and bootstraps a worktree with discovery-only delivery |
| `bin/worktree-create ISSUE --flow=proof` | Creates and bootstraps a worktree with the proof flow |
| `bin/worktree-create ISSUE` | Creates a worktree using the repository default; keeps an existing worktree's selection |
| `bin/loop-flow init` | Initializes a manually created worktree from the repository default without replacing an existing selection |
| `bin/loop-flow status` | Prints the current worktree's flow; an unselected worktree is `discovery` |
| `bin/loop-flow select --flow=discovery` | Explicitly changes the current worktree's flow |
| `bin/loop-flow select --flow=proof` | Explicitly restores the current worktree's proof flow |

The default uses repository-local Git configuration `orbit.loopFlow`, shared across linked worktrees. It does not change selections already saved in worktrees. Selection commands also accept `--worktree=PATH`. A malformed selection fails instead of falling back. A flow switch changes reviewed artifacts: update the plan, publish for a new candidate, and review under the new flow. Switching flows never creates or deletes topology resources.

## Discovery-only delivery

The discovery flow follows this order.

1. Run `bin/worktree-create` to update clean primary main and bootstrap the selected flow using compatible caches. Cache maintenance runs in the background.
2. Run preflight through `planning-features`, including the documentation audit and acceptance map.
3. Obtain an independent preflight review through `reviewing-feature-plans`.
4. Acquire discovery with `bin/e2e-topology acquire ISSUE WORKTREE` after preflight passes.
5. Implement using `shell`, `exec`, `sync`, and `verify` on discovery as development tools. Run focused local tests and project checks.
6. Commit and push the candidate, publish the artifacts, and obtain independent code review with a passing local review gate.
7. Merge the approved candidate, then release discovery and remove the worktree.

The issue's acceptance outcomes stay required. Existing Incus `Proof:` venues map to reproducible discovery observations, focused tests, and the local review gate. The handoff identifies each actual check and says `Discovery development only; isolated acceptance proof not run`. It does not claim immutable acceptance proof. A proof plan, proof fixtures, observations manifest, equivalence report, candidate-convergence attempt, or snapshot refresh is not required. The harness refuses `prove`, `equivalence`, and `candidate` for a worktree selected as `discovery`.

Discovery uses the existing isolated topology machinery and mounts the changing worktree. Acquisition validates the saved snapshot against its recorded generation and checks cold-base compatibility, ownership, capacity, and readiness. It does not require that snapshot to match current main. An incompatible cold base or absent snapshot still needs an explicit infrastructure repair. Optional extended discovery reuses the existing extension declaration format described in [Incus topologies](incus-topologies.md); its actions do not run as acceptance proof.

An advance of main alone does not require integration, another approval, proof, or local checks on a replacement candidate. The orchestrator merges when GitHub reports the approved candidate can merge and the reviewer has run the local gate successfully on that candidate. If actual conflicts block merging, the implementer fetches main, merges it into the branch, resolves conflicts, runs affected checks, publishes artifacts for the new head, and pushes again. The reviewer checks the resolution changes and affected acceptance items on that head; preflight does not restart.

GitHub CI is disabled and no GitHub status check is required for merge. [ADR 0053](../decisions/0053-use-local-review-checks-for-feature-landing.md) governs the local review gate. Conflict-free candidates can merge after exact-candidate review without including newer main.

Closeout verifies authoritative GitHub merge state and runs `bin/loop-flow verify-merge --candidate=SHA --merge=SHA`. The command requires the approved candidate as the exact second parent and the conflict-free merge tree of the recorded parents. That tree can differ from the candidate when main has advanced. Closeout advances the primary checkout, releases discovery, and runs `bin/worktree-remove ISSUE`. Snapshot promotion and refresh are separate infrastructure operations in this flow.

## Proof delivery

The proof flow uses the same worktree, preflight, documentation, review, artifacts, and local checks. Issues with `proof:incus` also require the isolated acceptance proof and captured evidence described in [Proof plans](proof-plans.md). Candidate preparation includes current main; review and closeout enforce that binding. A later candidate uses the retained-proof evaluation and main-delta review rules in the existing skills. Successful proof resources are captured and released before review, and closeout refreshes the shared snapshot from merged main. `verify-merge` requires the feature to include the merged base and the merge tree to equal the approved candidate's tree.

## Local checks

Run focused Pest tests for the affected behavior and failure modes, then run the changed project's `composer check`. The check runs guidance, Rector, Pint formatting and syntax checks, and static analysis; it does not run a full test suite. Run `composer docs-lint` when documentation changes. The independent reviewer runs root `composer check` on the clean submitted candidate before approval. This local gate covers all five projects with test impact analysis (TIA). Root `bin/test` and project `composer test` remain available for an explicit full local run or failure diagnosis.

Each project keeps its formatter configuration in `pint.json` and its analysis configuration in `phpstan.neon`. `composer format` applies Pint's Laravel preset. `composer format:check` checks without editing, and `composer lint` is an alias for that check. `composer analyse` runs PHPStan with Larastan in the applications and PHPStan directly in the framework-neutral SDK.

Every project runs analysis at level 6. The configured paths keep the existing analysis scopes. Tests remain covered by Pint and Pest.

| Project | Analysis level | Analyzed paths |
| --- | --- | --- |
| CLI | 6 | `app` |
| Gateway | 6 | `app`, `routes`, `database/migrations` |
| Docs | 6 | `app`, `config` |
| E2E | 6 | `app` |
| PHP SDK | 6 | `src` |

CLI and E2E have counted exceptions for Larastan findings on inherited command helpers. The exceptions match exact command names, inputs, and files. Unmatched exceptions and new findings fail analysis.

`bin/bootstrap` seeds missing quality caches after installing dependencies, so `bin/worktree-create` gives new worktrees a warm starting point. It prefers compatible successful main publications of Pint and PHPStan result caches, then falls back to compatible registered worktrees. Compatibility requires identical project Composer lock files and that tool's configuration; main publications also bind the PHP minor version, checksum, and commit ancestry. Existing destination caches are preserved, and copied caches are independent files. Run `bin/worktree-cache` to seed an existing checkout after installing dependencies.

Pint stores its cache in `vendor/pint.cache`. PHPStan stores analysis results in `vendor/phpstan/cache/resultCache.php`. Both tools validate cached results and recheck changed inputs. PHPStan's path-specific compiled container and Larastan's migration cache stay local and rebuild when needed; bootstrap copies only portable result caches. A missing or incompatible source cache falls back to a normal first run.

`bin/worktree-create ORB-217` creates `/fast/worktrees/orbit/orb-217` on branch `orb-217`. The issue ID determines both names; no slug is needed. Set a different absolute base path with `git config orbit.worktreeRoot /path/to/worktrees/orbit`. The base must be outside the primary checkout, which prevents an enclosing ignore rule from hiding TIA inputs. Git stores this setting locally and shares it among linked worktrees.

Creation and `bin/worktree-remove ORB-217` also resolve an existing branch with an issue-ID prefix and legacy slug. Multiple matching branches are refused before worktree changes. Creation reuses an already registered branch at its current path. Existing worktrees can finish in their original locations. Discovery commands locate registered worktrees by issue branch or directory name; `--worktree=PATH` resolves an ambiguity. Cleanup also follows the registered branch, including after a worktree moves. The legacy `.worktrees` directory can be removed after its remaining worktrees have closed out.

Each feature worker uses one whole-repository worktree. Run Composer and Pest from the affected project directory, such as `apps/gateway` or `packages/php-sdk`. Projects keep separate dependencies, test configurations, and TIA baselines. Run checks in each project that a change affects.

Worktree bootstrap installs all five projects. Their Composer hooks apply the pinned Pest monorepo and consumer-autoloader fixes before generating autoloaders. This also runs on a direct `composer install` or `composer dump-autoload` in a project. Each worktree has its own installed package; setup needs no external local fork or shared vendor symlink. A modified or unsupported Pest build fails setup. Installations without development dependencies skip Pest setup.

Use these commands from the affected project directory.

| Command | Result |
| --- | --- |
| `vendor/bin/pest --compact tests/Unit/ExampleTest.php` | Runs the selected file directly; an explicit path or filter bypasses TIA |
| `composer test:affected` | Runs TIA with two parallel workers; selects affected tests when a valid baseline exists |
| `composer check` | Runs project quality checks without the full test suite |
| `composer test` | Runs the full project suite with TIA disabled |

TIA requires PCOV or Xdebug to record dependencies. The first run, or a run without a usable baseline, can execute the full project suite. Later runs reuse the baseline and select tests affected by changes. Run `test:affected` in each affected project when this broader local feedback is useful; focused acceptance tests and the local review gate remain required. A TIA skip or zero selected tests is not new acceptance evidence.

Baselines stay separate between projects. Bootstrap seeds absent worktree caches from a compatible successful main baseline. A missing or incompatible publication still needs an initial recording run. Discovery and proof flow selection do not change test-runner setup.

## Local review gate

The reviewer runs root `composer check` in a clean review worktree at the submitted candidate. The command first seeds absent TIA caches, then runs strict Composer validation, project `composer check`, and `composer test:affected` in each project, sequentially. Project quality checks use the project's configured tools, including Rector in dry-run mode. A failure in any project prevents approval.

The gate writes command logs and `result.json` under the Git common directory at `orbit-checks/<candidate>/review-*/`. The receipt records the exact candidate and tree, each command, exit code, duration, and log path. It reports success only when every check passes and the candidate remains clean and unchanged. The reviewer retains the receipt with the acceptance assessment; the orchestrator verifies that its candidate matches the approved and merged head. A later candidate needs a new gate and approval.

GitHub's workflow is available only for manual diagnostics and remains disabled in the repository settings. It does not run automatically on pushes or pull requests. Focused acceptance tests remain required; TIA selection alone does not establish acceptance. Missing or incompatible caches can cause the local gate to record a full project suite. Root `bin/test` remains available for an explicit full run without TIA.

## Main test baselines

[ADR 0052](../decisions/0052-seed-worktrees-from-successful-main-test-baselines.md) governs baseline ownership. Each repository stores one successful publication per Composer project in its Git common directory under `orbit-tia/v1/published`. Linked worktrees share these publications and keep their writable Pest caches separate. Other repositories and separate clones need their own initial refresh.

Worktree creation requires clean primary main, fetches origin, fast-forwards main, and queues background maintenance without waiting for newer caches. It then calls bootstrap, which installs the patched Pest runner and copies a compatible main dependency graph into each absent private cache. Bootstrap preserves an existing cache and reports a cache miss without running tests. Manually created worktrees get the same setup through `bin/bootstrap`.

The repository commands manage this lifecycle.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches in the current worktree |
| `bin/tia-cache refresh --background` | Records a durable request, starts one worker when needed, and returns without waiting for checks |
| `bin/tia-cache refresh` | Requests refresh and waits for the repository worker; exits nonzero when a requested project fails |
| `bin/tia-cache status` | Prints test publications and maintenance state, including pending requests, current publications, failures, and log paths |
| `bin/tia-cache status --json --remote` | Reads authoritative remote main and returns maintenance state as JSON without changing the checkout or queue |
| `bin/worktree-remove ISSUE` | Verifies the feature merged, queues background refresh, then releases resources and removes the worktree |

Cache commands accept `--repository=PATH`. Seed and refresh accept repeatable `--project=apps/docs` options; their default covers all five Composer projects. Maintenance status covers the whole monorepo. Seed reports missing or incompatible graphs and leaves those projects cold; a cache miss does not fail setup.

Refresh fetches current main and advances an owned clean maintenance checkout under `orbit-tia/v1/checkout`. Its separate Git directory gives Pest an isolated cache while preserving the repository's remote URL. The command leaves the user's primary checkout and feature checkouts untouched. Closeout still advances a clean primary main when possible. A dirty maintenance checkout causes refresh to fail and retain its files for inspection.

Maintenance installs dependencies and runs `composer test:affected`, `composer format:check`, and `composer analyse` one project at a time, with two test workers and PCOV or Xdebug coverage enabled. It starts from compatible successful results and records again when tooling changes. TIA graphs and each quality tool publish independently after their checks succeed. Quality publications live under `orbit-tia/v1/quality` and contain portable results with dependency, configuration, runtime, checksum, and main-commit bindings. Complete publications at the same main commit and worker version avoid repeated installation and checks.

One worker holds the repository refresh lock. Requests live in `orbit-tia/v1/requests.json` and remain pending until a worker records their outcome. Repeated requests for the same target combine; requests arriving during a run remain pending when they name newer work. Each batch fetches newest main. An interrupted worker leaves recoverable requests. Failed checks retain their logs and previous successful publications without an automatic retry loop. Background workers have reduced CPU priority and keep their runner and logs in the Git common directory so worktree removal cannot interrupt them. When closeout does not use `bin/worktree-remove`, the orchestrator queues refresh explicitly after verifying the merge.

Publication replaces one complete snapshot atomically after testing succeeds on clean main. The snapshot contains only the portable dependency graph and its metadata. Seed checks the project, Pest patch and test configuration, Pest fingerprint including dependencies and PHP minor version, checksum, and commit ancestry. It does not copy affected-test lists, worker partials, coverage reports, or download state. A no-affected-tests run can publish a newer tested main commit while retaining an older graph anchor; both commits are recorded.

New worktrees may use the previous successful compatible publication while a refresh runs. Failed refreshes retain that publication and report the failure in `refresh.log`; retry with the refresh command after resolving the failure. Background baseline maintenance does not delay merge or resource cleanup. New worktree setup uses a compatible successful publication immediately after pulling main; missing or incompatible caches use cold checks. TIA remains optional local feedback; a warm cache or zero selected tests does not establish acceptance evidence.

## Maintenance recovery

[ADR 0054](../decisions/0054-maintain-main-caches-asynchronously.md) governs asynchronous maintenance. The orchestrator inspects `bin/tia-cache status --json --remote` through its existing watchdog. The result names remote main, whether a worker holds the lock, pending project requests, publication freshness, per-project command results, retained correctness failures, and log paths. A remote-read failure is an inspection error. A successful status command reports observed state; it does not mean checks passed.

When publications lag main and no worker is active, the orchestrator queues refresh. This also recovers merges outside its closeout flow and requests left after interruption. A reported failure is an owned recovery task. Cache transport, installation, and publication failures can use prior compatible caches or cold checks.

A nonzero test, formatting, or analysis command is a correctness signal requiring diagnosis and a hold on unrelated feature merges. Later infrastructure failures retain the earlier correctness failure. The failed tool must pass again before its retained failure clears. The orchestrator permits a reviewed repair or revert through that hold and clears it only after verification on main containing the repair.

One maintenance owner covers all five projects. Routine warming uses scripts; failures needing investigation use [maintaining-monorepo](../../.agents/skills/maintaining-monorepo/SKILL.md). The agent diagnoses the exact failed commit, preserves evidence, and performs source repairs in a separate worktree. It returns verification to the orchestrator instead of approving its own change or mutating primary main. Feature development can continue during a correctness hold. Cache freshness alone never holds creation, merge, or cleanup.

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

The developer includes the artifact ref and SHA in the pull request body. The reviewer fetches that ref, verifies its SHA and candidate binding, and reads the plan and every fixture. Approval binds both SHAs. The orchestrator merges that exact candidate after approval and the local review gate. Main integration creates a new candidate and requires artifact publication, local review checks, and approval under the selected flow. Discovery-only delivery does not integrate main solely because it advanced.

## Existing worktrees

Preserve the local workspace when converting an existing feature. Run `git rm -r --cached .loop`, commit that candidate change, then publish its artifacts before the next review. The harness can read existing tracked proof inputs for retained evidence. Feature closeout requires the separate artifact binding and a candidate without `.loop/`.

Artifact refs remain after feature branch and worktree cleanup. They retain the exact candidate as their parent and permit later inspection of the reviewed inputs.
