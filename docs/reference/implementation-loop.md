---
title: "Feature delivery"
description: "Prepare architecture and documentation, implement a complete PR, and verify it through CI and Orbit review."
---

# Feature delivery

The [contributor guide](/contributor-guide) explains architecture, documentation, implementation, and PR submission. This reference covers review evidence, merge responsibilities, and local verification tools. [ADR 0076](/decisions/0076-deliver-features-through-complete-pull-requests) records the delivery decision. Task completion gates are defined by [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review). [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) proposes judging those gates as separate checks.

## CI and local verification

GitHub CI runs on pull requests, including drafts, and pushes to main. The quality workflow validates Composer metadata, project quality checks, and impacted tests in all five projects. The Docs job also validates the documentation corpus and proposed ADRs. The aggregate `Required checks` job passes only when every project succeeds.

Each project job checks out the triggering branch by name with full history so Pest can write its TIA graph. Detached HEAD and shallow clones skip that write. After Composer install, the job restores that project's portable PHPStan result cache at `vendor/phpstan/cache/resultCache.php` from GitHub Actions cache, then saves it only after `composer check` succeeds. It does not cache PHPStan's compiled container or Larastan's migration cache.

The job restores that project's `.orbit-tia` directory from GitHub Actions cache, runs impacted tests with `ORBIT_TIA_DIRECTORY=.orbit-tia`, and saves the graph only after Pest succeeds. Guidance checks still record into `vendor/.orbit-guidance-tia` and are not cached. Hosted CI does not call `bin/tia-cache`. Incus acceptance remains the independent review proof.

When a push or pull request targets `main`, a separate `Orbit CLI Binary` workflow builds the linux-x64 toolbox binary on hosted GitHub Actions. macos-arm64 builds on mini when the runner variable is set. Its artifacts, dest paths, and hosts are the [CLI binaries](/reference/cli-binaries) contract. That workflow is not part of `Required checks`.

Lint establishes document structure, language, links, and generated context consistency. Independent review assesses the architectural proposal and checks that code implements the documented behavior.

Run checks in each changed project during development. Root `composer check` runs checks across all projects on a clean commit and saves logs under `orbit-checks` in the Git common directory.

The repository maintainer configures branch protection to require `Required checks` and maintainer review. Workflow files define the checks; GitHub repository settings enforce them.

## Orbit task completion evidence

The Gateway task workflow uses the AgentThread transcript and typed review comments. Jev answers whether `composer check` was invoked, whether it passed, and whether that output is for the current tree. A passing implementer rubric sets the task to `reviewing`. The implementer does not post a `ready_for_review` comment. Reviewers post `changes_requested` or `approved`, and the Gateway preserves the full comment body and attempt metadata while it relays findings or checks the commit and pull request.

Assistance is part of the same task history. An `assistance_requested` comment flags the task and parent group without releasing its capacity. A non-empty `resolution` comment clears the flag and continues the AgentThread idempotently. Delivery failures preserve the blocked state and the resolution for retry. These comments and transcript entries are the evidence; the workflow has no separate validation-evidence API.

## Orbit review on Incus

Orbit's independent reviewer checks the complete change and reproduces the feature on Incus before merge. Use machines allocated to the review and verify which source commit is running there.

The [Incus topology reference](/reference/incus-topologies) describes commands for harness-managed machines. Exercise the feature and its important failure cases, then inspect the resulting state changes.

The review records enough evidence for another maintainer to assess the result.

| Evidence | Content |
| --- | --- |
| Revision | Exact PR head and actual source revision used for machine checks |
| Environment | Relevant Node roles, operating system, configuration, and resource identity |
| Acceptance | Actions, expected and observed outcomes, exit codes, and affected state changes |
| CI | Passing required checks for the reviewed change |
| Limitations | Unverified behavior, failed checks, and remaining findings |
| Verdict | Whether the complete feature is ready for maintainer approval |

Store detailed logs in CI artifacts or shared project evidence outside the checkout and link them from the PR review. Share sanitized records.

If machines are unavailable, the reviewer can return code findings while Incus review remains pending. For documentation and tooling changes, verify the behavior those changes affect.

## Corrections, merge, and cleanup

Address blocking findings. Review the fixes and repeat affected checks on the updated PR. Confirm that the final review covers the commit proposed for merge.

Merge requires a complete feature, passing CI, successful independent code and Incus review, resolved blocking findings, and maintainer approval. Merge the approved commit and verify the result on GitHub.

After merge, preserve the review evidence and clean up resources allocated to the feature. For internal worktrees, `bin/worktree-remove ISSUE` verifies the merge and performs cleanup. Follow the resource-specific cleanup rules for retained Incus machines.

## Local checks

Run `composer test:affected` for the affected behavior and failure modes, then run the changed project's `composer check`. The check runs the project's dedicated guidance configuration with fresh TIA so its contracts execute deterministically. `guidance:check` sets `ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia`, so that fresh run records into the project's `vendor/.orbit-guidance-tia` directory and leaves the affected-test graph that `test:affected` reads in place. The check then runs Rector, Pint formatting and syntax checks, and static analysis; it does not run the full project test configuration. Run `composer docs-lint` when documentation changes.

CI checks all five projects with impacted TIA. A later run on the same branch restores a compatible graph when one exists. Root `composer check` is an optional local check across projects on a clean commit. Root `bin/test` and project `composer test` also use TIA. Incus acceptance remains required for independent review.

Use the Composer test commands to select affected tests. Pest disables test impact analysis when given a path, filter, group, or suite, even with `--tia`. Confirm that the feature tests ran.

### Gateway test databases

The Gateway test bootstrap keeps supported local test commands out of caller databases. It applies the same test values to the process environment and PHP environment and server variables before Laravel loads configuration. It then checks Laravel's effective connection, including a connection URL or cached configuration, before service providers and database refresh hooks run.

| Input | Test behavior |
| --- | --- |
| No explicit test database | Uses in-memory SQLite |
| Inherited `DB_DATABASE`, `DB_URL`, or `DB_CONNECTION` | Replaces the inherited value with the safe test value |
| `ORBIT_TEST_DATABASE=/tmp/.../orbit-gateway-test-*.sqlite` | Uses the explicitly allocated disposable SQLite file; parallel tests use their worker-specific copies |
| Any other effective driver, path, URL, or application environment | Exits nonzero before migrations with a database safety refusal |

Allocate a new temporary file for each run that needs a file-backed fixture. Never set `ORBIT_TEST_DATABASE` to a Gateway runtime database, another application's database, or a retained backup.

An unexpected refusal commonly means that Laravel loaded stale cached configuration. Run the recovery commands from `apps/gateway`, then rerun the same supported Composer or Pest command. Do not disable or bypass the test guard.

| Command | Result |
| --- | --- |
| `unset APP_CONFIG_CACHE` | Stops selecting a custom cached configuration path in the current shell |
| `php artisan config:clear` | Removes the default cached configuration |

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

`bin/bootstrap` seeds missing quality caches after installing dependencies, so `bin/worktree-create` gives new worktrees a warm starting point. It prefers compatible successful main publications of Pint and PHPStan result caches, then falls back to the clean primary main checkout. Feature worktrees are not seed sources. Compatibility requires identical project Composer lock files and that tool's configuration; main publications also bind the PHP minor version, checksum, and commit ancestry. Existing destination caches are preserved, and copied caches are independent files. Run `bin/worktree-cache` to seed an existing checkout after installing dependencies.

Pint stores its cache in `vendor/pint.cache`. PHPStan stores analysis results in `vendor/phpstan/cache/resultCache.php`. Both tools validate cached results and recheck changed inputs. PHPStan's path-specific compiled container and Larastan's migration cache stay local and rebuild when needed; bootstrap copies only portable result caches. A missing or incompatible source cache falls back to a normal first run.

`bin/worktree-create ORB-217` creates `/fast/worktrees/orbit/orb-217` on branch `orb-217`. The issue ID determines both names. Set a different absolute base path with `git config orbit.worktreeRoot /path/to/worktrees/orbit`. The base must be outside the primary checkout, which prevents an enclosing ignore rule from hiding TIA inputs. Git stores this setting locally and shares it among linked worktrees.

Creation and `bin/worktree-remove ORB-217` also resolve an existing branch with an issue-ID prefix and legacy slug. Multiple matching branches are refused before worktree changes. Creation reuses an already registered branch at its current path. Existing worktrees can finish in their original locations. Discovery commands locate registered worktrees by issue branch or directory name; `--worktree=PATH` resolves an ambiguity. Cleanup also follows the registered branch, including after a worktree moves. The legacy `.worktrees` directory can be removed after its remaining worktrees have closed out.

Run project checks from their directory, such as `apps/gateway` or `packages/php-sdk`. Projects keep separate dependencies, test configurations, and TIA baselines.

Worktree bootstrap installs all five projects. Each project requires the published `nckrtl/pestphp-monorepo` package, which provides Pest with the monorepo and consumer-autoloader fixes. Composer installs the release recorded in that project's lock file. Setup needs no local Pest checkout, vendor symlink, or patching hook. The official Pest plugins remain installed alongside the fork.

The fork is maintained at [nckrtl/pestphp-monorepo](https://github.com/nckrtl/pestphp-monorepo). Its managed local checkout lives at `/fast/packages/pestphp-monorepo`. Releases use explicit version tags; a push to `main` runs checks but does not publish a stable version. Update the dependency and lock file in each Orbit project when adopting a new release, then run the project checks.

Use these commands from the affected project directory.

| Command | Result |
| --- | --- |
| `composer test:affected` | Runs TIA with two parallel workers; selects affected tests when a valid baseline exists |
| `composer check` | Runs project quality checks without the full test suite |
| `composer test` | Runs the project suite through TIA in parallel |

TIA requires PCOV or Xdebug to record dependencies. The first run, or a run without a usable baseline, can execute the full project suite. Later runs reuse the baseline and select tests affected by changes. Run `test:affected` in each affected project for development feedback; acceptance evidence and CI remain required. A TIA skip or zero selected tests is not new acceptance evidence.

Baselines stay separate between projects. Bootstrap seeds absent worktree caches from a compatible successful main baseline. A missing or incompatible publication still needs an initial recording run. Every `tests/Pest.php` honors `ORBIT_TIA_DIRECTORY`; only the guidance check sets it, so a fresh guidance run never replaces the seeded baseline.

## Main test baselines

[ADR 0052](/decisions/0052-seed-worktrees-from-successful-main-test-baselines) governs baseline ownership. Each repository stores one successful publication per Composer project in its Git common directory under `orbit-tia/v1/published`. Linked worktrees share these publications. Each project keeps writable Pest history in its checkout-local `.orbit-tia` directory unless `ORBIT_TIA_DIRECTORY` overrides it. The task provisioner transfers main publications to remote Orbit clones. Other repositories need their own initial refresh.

Worktree creation requires clean primary main, fetches origin, fast-forwards main, and queues background maintenance without waiting for newer caches. It then calls bootstrap, which installs the patched Pest runner and copies a compatible main dependency graph into each absent private cache. Bootstrap validates guidance, runs `composer test:affected`, and runs `composer check` in each project. A compatible history selects affected tests; a cache miss may need a full run. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` explicitly requests installation, seeding, and guidance validation only. Task preparation uses the default checks. Manually created worktrees get the same setup through `bin/bootstrap`.

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

### Maintenance environment

The background worker removes worktree-setup settings before it starts, and every project command applies the same boundary.

| Boundary | Environment variables |
| --- | --- |
| Orbit, application, and database settings | Names that start with `ORBIT_`, `APP_`, or `DB_`, plus `DATABASE_URL`, `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION` |
| Setup temporary directories | `TMPDIR`, `TMP`, and `TEMP` |
| Retained process access | Other settings, including `PATH`, the user home and shell, tool configuration, and dependency authentication |

Each project loads its own environment and test configuration after this filter. The filter proves only that setup settings cannot select a project runtime; it does not prove that a queued background check ran or passed. Inspect a failed run with `bin/tia-cache status --json --remote`, then read the per-check `log` paths in `results` and `correctness_failures`; use `refresh_log` for worker launch or setup failures.

One worker holds the repository refresh lock. Requests live in `orbit-tia/v1/requests.json` and remain pending until a worker records their outcome. Repeated requests for the same target combine; requests arriving during a run remain pending when they name newer work. Each batch fetches newest main. An interrupted worker leaves recoverable requests. Failed checks retain their logs and previous successful publications without an automatic retry loop. Background workers have reduced CPU priority and keep their runner and logs in the Git common directory so worktree removal cannot interrupt them. When closeout does not use `bin/worktree-remove`, the orchestrator queues refresh explicitly after verifying the merge.

Publication replaces one complete snapshot atomically after testing succeeds on clean main. The snapshot contains only the portable dependency graph and its metadata. Seed checks the project, locked Composer dependencies and test configuration, Pest fingerprint including dependencies and PHP minor version, checksum, and commit ancestry. It does not copy affected-test lists, worker partials, coverage reports, or download state. A no-affected-tests run can publish a newer tested main commit while retaining an older graph anchor; both commits are recorded.

New worktrees may use the previous successful compatible publication while a refresh runs. Failed refreshes retain that publication and report the failure in `refresh.log`; retry with the refresh command after resolving the failure. Background baseline maintenance does not delay merge or resource cleanup. New worktree setup uses a compatible successful publication immediately after pulling main; missing or incompatible caches use cold checks. TIA remains optional local feedback; a warm cache or zero selected tests does not establish acceptance evidence.

## Maintenance recovery

[ADR 0054](/decisions/0054-maintain-main-caches-asynchronously) governs asynchronous maintenance. The orchestrator inspects `bin/tia-cache status --json --remote` through its existing watchdog. The result names remote main, whether a worker holds the lock, pending project requests, publication freshness, per-project command results, retained correctness failures, and log paths. A remote-read failure is an inspection error. A successful status command reports observed state; it does not mean checks passed.

When publications lag main and no worker is active, the orchestrator queues refresh. This also recovers merges outside its closeout flow and requests left after interruption. A reported failure is an owned recovery task. Cache transport, installation, and publication failures can use prior compatible caches or cold checks.

A nonzero test, formatting, or analysis command is a correctness signal requiring diagnosis and a hold on unrelated feature merges. Later infrastructure failures retain the earlier correctness failure. An open TIA failure makes recovery run the unfiltered affected-test command with `--fresh` on checked clean main. Maintenance accepts successful recovery only when the resulting graph records that checked commit.

A cached or zero-execution result that leaves an older graph anchor reports `recovery: not_executed` in the project result and retains the original failed commit, tool, and diagnostic log. An executed successful recovery reports `recovery: executed` and clears only that project's TIA failure. Failed or interrupted recovery keeps the preceding successful publication and unresolved signal.

Status and retained command logs distinguish the unresolved correctness failure from the latest recovery result. These records prove native maintenance execution and cache publication only. The orchestrator separately admits a reviewed repair or revert through the merge hold and clears that hold only after verification on main containing the repair.

One maintenance owner covers all five projects. Routine warming uses scripts; the maintainer assigns investigation of failures. The agent diagnoses the exact failed commit, preserves evidence, and performs source repairs in a separate worktree. It returns verification to the orchestrator instead of approving its own change or mutating primary main. Feature development can continue during a correctness hold. Cache freshness alone never holds creation, merge, or cleanup.
