---
title: "Feature delivery"
description: "How a change reaches main: review evidence, merge, CI, local checks, worktrees, and the shared main caches."
covers:
  - bin/{review-check,test,tia-cache,worktree-cache,worktree-create,worktree-remove}
  - .github/workflows/ci.yml
  - apps/gateway/tests/Support/TestDatabase{Environment,Guard}.php
---

# Feature delivery

A feature reaches main as one complete pull request with its code, tests, documentation, and any in-progress ADRs. The [contributor guide](/contributor-guide) describes the steps. Orbit builds its own features as [task groups](/reference/tasks): the first Task writes the documentation, each Task handoff runs the Project task check, and the group ends in a pull request. This page covers review evidence, merge, CI, the local checks, and the main caches that make those checks fast.

## Review on Incus

Orbit's independent reviewer checks the complete change and reproduces the feature on an [Incus topology](/reference/incus-topologies) before merge. Use the machines allocated to the review, and verify which source commit runs there. Exercise the feature and its important failure cases, then inspect the resulting state changes.

The review records enough evidence for another maintainer to assess the result.

| Evidence | Content |
| --- | --- |
| Revision | The exact pull request head and the source revision that ran on the machines |
| Environment | Node roles, operating system, configuration, and resource identity |
| Acceptance | Actions, expected and observed outcomes, exit codes, and state changes |
| CI | Passing required checks for the reviewed change |
| Limitations | Unverified behavior, failed checks, and open findings |
| Verdict | Whether the complete feature is ready for maintainer approval |

Store detailed logs in CI artifacts or in shared evidence outside the checkout, and link them from the review. Share only sanitized records. When no machines are available, the reviewer returns code findings and marks the Incus review as pending. For documentation and tooling changes, verify the behavior that the change affects.

## Merge and cleanup

Address the blocking findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. The final review must cover the commit proposed for merge.

A merge needs a complete feature, passing CI, a successful independent code and Incus review, resolved blocking findings, and maintainer approval. Merge the approved commit and verify the result on GitHub. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

After the merge, keep the review evidence and release the resources allocated to the feature. For a local worktree, run `bin/worktree-remove ISSUE`.

## CI

GitHub CI runs on every pull request, on every push to `main`, and on manual dispatch. It has these jobs.

| Job | Checks |
| --- | --- |
| One job per Composer project: CLI, Docs, Gateway, E2E, PHP SDK | `composer validate --strict`, `composer check`, the classification-fakes check, and the tests |
| API reference | `bin/docs-openapi --check` and `bin/mcp-tools --check` |
| Web | Generated API types, formatting, lint, types, tests, and build |
| Pi server, Agent annotation | Formatting, lint, tests, and build |
| Rust agent | `cargo fmt`, `cargo clippy`, tests, and static builds for x86_64 and aarch64 |
| Required checks | Passes only when every other job passes |

Branch protection requires `Required checks` and maintainer review. The workflow files define the checks, and the GitHub repository settings enforce them. The [contributor guide](/contributor-guide#3-implement-and-verify) describes how pull requests and pushes select tests.

Each Composer project job checks out the branch by name with full history, so Pest can write its test-impact graph. On a detached HEAD, Pest does not save the graph. The Docs job's `composer check` also runs `composer docs-lint`. The E2E job runs `bin/bootstrap --skip-checks` to install every project, because its integration tests use the other projects. The Gateway job installs the Linux tools that the Gateway tests need and creates the `caddy` user.

Each Composer project job caches three sets of files in GitHub Actions cache.

| Cache | Key inputs |
| --- | --- |
| PHPStan result cache, `vendor/phpstan/cache/resultCache.php` | `composer.lock`, `phpstan.neon` |
| Pint and Rector caches, `vendor/pint.cache` and `vendor/rector/cache` | `composer.lock`, `pint.json`, `rector.php` |
| Test-impact graph, `.orbit-tia` | `composer.lock`, `tests/Pest.php`, `phpunit.xml` |

A job restores the newest cache for its branch, then for `main`, then any cache for the project. It saves each cache only after its checks succeed. These caches are separate from the [main caches](#main-caches), and CI never calls `bin/tia-cache`.

The separate `Orbit CLI Binary` workflow builds the toolbox binaries. It is not part of `Required checks`. See [CLI binaries](/reference/cli-binaries).

## Local checks

Run these commands in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. Each project keeps its own dependencies, test configuration, and test-impact graph.

| Command | Result |
| --- | --- |
| `composer test:affected` | Runs the affected tests with test-impact analysis (TIA) and two parallel workers |
| `composer check` | Runs `guidance:check`, Rector, the Pint check, and PHPStan. It does not run the test suite. |
| `composer test` | Runs the project suite with TIA in parallel |
| `composer format` | Applies Pint's Laravel preset |
| `composer analyse` | Runs PHPStan. The applications use Larastan. |

In `apps/docs`, `composer check` also runs the documentation lint and the API fixture check. `guidance:check` runs the project's guidance tests with a fresh graph in `vendor/.orbit-guidance-tia`, so it never replaces the graph that `test:affected` reads. The [contributor guide](/contributor-guide#static-analysis) sets the PHPStan level and the rules for fixing findings.

TIA needs PCOV or Xdebug. The first run, or a run without a usable graph, runs the full suite. Later runs select the tests that the changes affect. An explicit path or a partial-selection option such as `--filter`, `--group`, or `--testsuite` turns TIA off for that run. Confirm that the tests of the feature ran. Zero selected tests is not acceptance evidence. Every `tests/Pest.php` stores its graph in the directory that `ORBIT_TIA_DIRECTORY` names, and in `.orbit-tia` by default.

Each project requires the `nckrtl/pestphp-monorepo` Pest fork from its lock file. The fork adds the monorepo and test-impact fixes. To adopt a new release, update the dependency and the lock file in every project, then run the project checks. A runner change invalidates the published graphs, so the first run after it records a fresh graph.

Root `bin/test` runs all five project suites in parallel and splits the available processors between them.

### The candidate gate

Root `composer check` runs `bin/review-check`. It checks the working tree as it is, including uncommitted and untracked files. The Orbit Project uses it as its task check, so it also runs at every Task handoff. It works in this order.

1. It runs `bin/docs-impact --gate` against the merge base with `origin/main`. Without a merge base, this check fails.
2. It seeds absent quality and test caches with `bin/worktree-cache` and `bin/tia-cache seed`.
3. In each of the five Composer projects, it runs `composer validate --strict`, `composer check`, and `composer test:affected`. Each affected-test run records into its own copy of the project graph.
4. In each Composer project that the candidate changes, it runs the project's architecture tests.
5. When the candidate changes a test file that TIA did not select, it lists that file's tests and runs the file without TIA. A file without tests fails.
6. When the candidate changes test sources, it runs `bin/check-classification-fakes` on them.
7. When the candidate changes `apps/web`, it runs the web checks and the build. When it changes only `docs/openapi.json`, it checks the generated API types. When it changes `apps/pi-server`, it runs the Pi server checks.

The gate writes a receipt, `result.json`, and one log per command under `orbit-checks/<HEAD>/` in the Git common directory. The receipt passes only when every command passed and the commit and the working tree did not change during the run. It records a warning when `test:affected` selected no tests in a project that the candidate changes.

### Gateway test databases

The Gateway test bootstrap keeps tests away from real databases. Before Laravel loads its configuration, it sets `APP_ENV=testing`, the SQLite driver, and the test database in the process environment and in the PHP environment and server variables. After Laravel loads its configuration, a guard checks the effective connection, including a connection URL and cached configuration.

| Input | Test behavior |
| --- | --- |
| No `ORBIT_TEST_DATABASE` | In-memory SQLite |
| Inherited `DB_DATABASE`, `DB_URL`, or `DB_CONNECTION` | The test value replaces it |
| `ORBIT_TEST_DATABASE` set to an absolute path of an `orbit-gateway-test-*` file in the system temporary directory | That disposable SQLite file |
| Any other driver, database, or application environment | The run stops before migrations with a database safety refusal |

Allocate a new temporary file for each run that needs a file-backed database. Never point `ORBIT_TEST_DATABASE` at a Gateway runtime database, another application's database, or a backup.

An unexpected refusal usually means that Laravel loaded a stale cached configuration. In `apps/gateway`, run `unset APP_CONFIG_CACHE` and `php artisan config:clear`, then run the same command again. Do not disable the guard.

## Worktrees

`bin/worktree-create ISSUE` creates a linked worktree for a local feature. For `ORB-217`, it creates branch `orb-217` at `/fast/worktrees/orbit/orb-217`, based on the freshly fetched `origin/main`. It does not change the primary checkout. Set another absolute base directory with `git config orbit.worktreeRoot PATH`. The base must be outside the primary checkout. When the branch already has a clean registered worktree, the command reuses it. Then it runs `bin/bootstrap` in the worktree.

`bin/bootstrap` installs all five Composer projects, seeds the caches, and validates each project's guidance. Then it runs `composer test:affected` and `composer check` in each project. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` stops after the guidance validation.

`bin/worktree-remove ISSUE` refuses a dirty worktree and a branch that `origin/main` does not contain. It queues a background cache refresh, releases the worktree's discovery topology, and removes the worktree. Then it deletes the merged branch.

## Main caches

Main caches let a new checkout start with warm test-impact graphs and warm Pint and PHPStan result caches. Each repository keeps one store in its Git common directory under `orbit-tia/v1`. Linked worktrees share it.

| Path under `orbit-tia/v1` | Content |
| --- | --- |
| `published/<project>.json` | One test-impact graph per Composer project |
| `quality/<project>/<tool>.json` | One Pint or PHPStan result cache per project |
| `requests.json` | Pending refresh requests, the last result per project, and open correctness failures |
| `refresh.log`, `run-*/` | The worker log and the per-check logs |
| `checkout/` | The private maintenance checkout of `main` |

Only a successful run on a clean `main` publishes. Each publication records the tested commit, a checksum, and the inputs it is valid for. A new publication must descend from the one it replaces, so older results never replace newer ones.

### Seed a checkout

Seeding copies a publication into an absent private cache and never replaces an existing one. `bin/tia-cache seed` copies test-impact graphs. `bin/worktree-cache` copies Pint and PHPStan caches. Bootstrap and the candidate gate run both. A missing or incompatible publication leaves that project cold, and seeding does not fail.

| Cache | Compatible when |
| --- | --- |
| Test-impact graph | The project's `composer.lock`, `tests/Pest.php`, and Pest fingerprint match, the checksum matches, and the tested commit is an ancestor of `HEAD` |
| Pint or PHPStan cache | The project's `composer.lock`, the tool's configuration, and the PHP minor version match, the checksum matches, and the tested commit is an ancestor of `HEAD` |

When no quality publication fits, `bin/worktree-cache` copies the cache from the primary checkout when that checkout is clean on `main` and has the same lock file and configuration. Feature worktrees are never a source.

A checkout seeds from the first of these stores that has publications:

1. The store in `ORBIT_MAIN_CACHE_STORE`. Bootstrap uses it only while seeding, and never publishes into it.
2. The checkout's own store.
3. The store registered for the checkout's origin on the same machine.

The registration is a link at `$XDG_STATE_HOME/orbit/main-cache-stores/<key>`, and `$XDG_STATE_HOME` defaults to `~/.local/state`. The key comes from the origin URL, so the HTTPS and SSH URLs of one repository share it. Each publication registers its store unless another live store already holds the registration. `bin/tia-cache register` takes the registration for the current repository.

Task workspaces are independent clones, so they start without publications of their own. `bin/bootstrap`, which the Orbit Project runs as a setup step, and `bin/review-check`, its task check, seed them from the registered store. When the registered store lags the clone's fetched `main`, seeding queues a background refresh of that store for the lagging projects. It does not queue a project whose last refresh failed at that commit or at a later one.

### Publish from bootstrap

After all checks pass, bootstrap publishes its caches when the checkout is clean, `HEAD` equals the fetched `origin/main`, `ORBIT_MAIN_CACHE_STORE` is unset, and the default TIA directory is in use. It turns the branch's Pest results into a main graph and keeps the graph's recorded commit. It skips a project with an open correctness failure, a graph with working-edit history, or a graph with results from other branches. When a refresh worker is running, the caches stay private. A publication error is reported, and bootstrap still succeeds.

### Refresh in the background

`bin/tia-cache refresh --background` records a request and starts one worker when none runs. It returns at once. `bin/worktree-remove` queues it after each merge. Cache freshness never holds worktree creation, a merge, or cleanup.

The worker takes the repository refresh lock, fetches `main`, and fast-forwards the private maintenance checkout. It works through one project at a time. It skips a project whose publications are current at that commit. Otherwise it installs the dependencies and runs `composer test:affected`, `composer format:check`, and `composer analyse`. Each tool publishes after its own check succeeds, and a failure keeps the previous publication. The test run needs PCOV or Xdebug, and each command stops after 30 minutes.

Requests stay in `requests.json` until the worker records their outcome, so an interrupted worker leaves them pending. Repeated requests for the same target combine. The worker copies its own script into the store and runs at reduced CPU priority, so removing the calling worktree does not stop it. After each batch, it deletes the run logs that no recorded result names.

The worker removes these variables from its environment and from every command, so setup settings cannot select a project runtime:

- names that start with `ORBIT_`, `APP_`, or `DB_`;
- `DATABASE_URL`, `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION`;
- `TMPDIR`, `TMP`, and `TEMP`.

It sets `PAO_DISABLE=1`, so the commands print their normal output even when an agent session queued the refresh.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches |
| `bin/tia-cache refresh --background` | Queues a refresh and starts a worker when none runs |
| `bin/tia-cache refresh` | Queues a refresh, waits for the worker, and exits nonzero when a requested project fails |
| `bin/tia-cache status` | Prints the publications and the maintenance state |
| `bin/tia-cache status --json --remote` | Reads `main` from the remote and prints the maintenance state as JSON, without changing anything |
| `bin/tia-cache register` | Registers this repository's store for its origin |

Every cache command accepts `--repository=PATH`. `seed` and `refresh` accept repeatable `--project=apps/docs` options, and they cover all five projects by default.

### Recover a failed refresh

`bin/tia-cache status --json --remote` reports the remote `main`, whether a worker holds the lock, the pending requests, which projects are current, each project's command results with log paths, the open correctness failures, and `refresh_log`. A successful status command reports state. It does not mean that the checks passed.

The worker sorts failures into two kinds:

- A **check failure** is a nonzero exit of the tests, Pint, or PHPStan. It is a correctness signal on main. It stays open until that tool passes on a later refresh, and an infrastructure failure never clears it.
- A **maintenance failure** is a failed install, fetch, or setup. Checkouts keep using the previous publications or run cold.

While a test-impact failure is open, the next refresh runs `composer test:affected -- --fresh` on the checked commit. The failure clears only when the new graph records that commit, and the project result then shows `recovery: executed`. A run that executed no tests shows `recovery: not_executed` and keeps the failure. Diagnose a check failure on the exact failed commit, and fix it in a separate worktree through a reviewed pull request.

## Why it works this way

### Main caches come only from clean main

A feature graph can hold unmerged code, failed tests, or working edits, so it cannot prove the state of main. Only a clean run on main publishes, and each checkout writes to its own private copy. One writable graph shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results. Recording every new worktree from scratch is also rejected, because unchanged projects would repeat the full run.

### Maintenance runs in the background

Waiting for every refresh before a new worktree or a merge would serialize delivery behind maintenance. A checkout starts from the newest compatible publication while the worker catches up. The cost is that a failure on main can surface after another feature merges. One worker per repository covers all five projects, because separate owners would compete for the same checkout and processors. A cache failure is not a source regression, so only check failures count as correctness signals.

### Bootstrap publishes, the primary checkout stays put

A clean bootstrap on main already ran every check, so its results can warm later worktrees without another run. Advancing the primary checkout to warm caches is a rejected alternative, because that checkout can run the Gateway, and its updates belong to deployment. A separate cache service is also rejected, because linked worktrees already share one store.

### CI caches stay separate

Hosted CI keeps its caches in GitHub Actions cache, keyed by branch and inputs. Each run starts from a fresh runner, so it cannot read the repository store. The Rector cache is shared only in CI, because Rector keys its cache on absolute file paths, and CI always uses the same checkout path.

### Findings are fixed, not silenced

A PHPStan ignore, a baseline, or a silencing cast keeps the wrong type in the code, and new code can repeat it. Fixing the value at its source lets CI catch these errors before review, so a reviewer spends the review on behavior.
