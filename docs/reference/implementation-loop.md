---
title: "Feature delivery"
description: "How a change reaches main: review evidence, merge, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/AdrLifecycle.php
  - bin/{review-check,bug-repro,task-group-check,pr-head-check,deploy-verify,test,tia-cache,worktree-cache,worktree-create,worktree-remove,check-classification-fakes}
  - tools/phpstan/**
  - .github/workflows/ci.yml
  - apps/gateway/tests/Support/TestDatabase{Environment,Guard}.php
---

# Feature delivery

A feature reaches main as one complete pull request with its code, tests, documentation, and any in-progress ADRs. The [contributor guide](/contributor-guide) describes the steps. Orbit builds its own features as [tasks](/reference/tasks): the first subtask writes the documentation, each subtask handoff runs the Project task check, and the task ends in a pull request. This page covers review evidence, merge, CI, the local checks, and the main caches that make those checks fast.

## Review on Incus

Orbit's independent reviewer checks the complete change and reproduces the feature on an [Incus topology](/reference/incus-topologies) before merge. Use the machines allocated to the review, and verify which source commit runs there. Exercise the feature and its important failure cases, then inspect the resulting state changes.

The review records enough evidence for another maintainer to assess the result.

| Evidence | Content |
| --- | --- |
| Revision | The exact pull request head and the source revision that ran on the machines |
| Environment | Node roles, operating system, configuration, and resource identity |
| Acceptance | Actions, expected and observed outcomes, exit codes, and state changes |
| CI | A passing `Required checks` job for the reviewed change |
| Limitations | Unverified behavior, failed checks, and open findings |
| Verdict | Whether the complete feature is ready for maintainer approval |

Store detailed logs in CI artifacts or in shared evidence outside the checkout, and link them from the review. Share only sanitized records. When no machines are available, the reviewer returns code findings and marks the Incus review as pending. For documentation and tooling changes, verify the behavior that the change affects.

## Merge and cleanup

Address the blocking findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. The final review must cover the commit proposed for merge.

A merge needs a complete feature, passing CI, a successful independent code and Incus review, resolved blocking findings, and maintainer approval. Merge the approved commit and verify the result on GitHub. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

### Final review of an Orbit task pull request

When the maintainer delegates final review and merge of a named Orbit task pull request, that delegation is the consent for that work only. The DevOps reviewer uses the maintainer's GitHub CLI profile. The reviewer checks the whole pull request, confirms the independent code and Incus review evidence, and submits a formal GitHub review for the exact head.

The review body names the full head commit SHA, the checks and their results, any remaining limitations, links to the review evidence, and the verdict. A limitation that leaves required behavior unverified prevents approval. A Tasks engine subtask approval does not replace this final review of the whole pull request.

The merge gate requires a formal GitHub `APPROVED` review from the designated final reviewer for the current head. Submit `APPROVE` through the GitHub reviews API from the maintainer profile, with `commit_id` set to the reviewed SHA. A plain comment, including a ready-to-merge verdict, does not satisfy the gate. The pull request author cannot approve their own pull request. The delegation does not authorize an unrelated merge.

Before the immediate merge, read GitHub's review records and verify the final reviewer's identity, the `APPROVED` state, and that `commit_id` matches the reviewed SHA. A dismissed or stale approval, an approval for another head, outstanding requested changes from the final reviewer, the wrong identity, or unreadable review data prevents the merge.

The reviewer also confirms that `Required checks` succeeded on that same head, that blocking findings are resolved, that required verification is complete, and that the pull request head still matches the reviewed SHA. The reviewer then runs `gh pr merge <pr-url> --merge --match-head-commit <reviewed-sha>` from the maintainer profile. If the head changes, stop. Review the new commit, repeat the affected checks, and submit a new formal approval before trying again. A failed, pending, missing, or unreadable required check prevents the merge.

This workflow merges immediately after those checks pass. It does not enable GitHub auto-merge. After the merge, verify the merged state and record the merge commit. The Tasks scheduler observes the merged pull request and completes the task on its next tick.

The maintainer profile is an admin profile. It bypasses GitHub enforcement of the `Required checks` status rule, including on `gh pr merge`. GitHub does not require this approval and does not enforce it for that account, so the reviewer checks the formal approval and the successful `Required checks` result before invoking the merge. This workflow keeps that bypass and does not change the ruleset. It adds no `tasks:merge` command, Gateway merge endpoint, SDK contract, MCP contract, or API contract, and it changes no App permission.

Final DevOps review and its designated reviewer stay outside Orbit. The Tasks scheduler also consumes [trusted GitHub requested changes](/reference/tasks#trusted-github-feedback) as bounded fixup input. The Gateway operator's repository-scoped account allowlist is repair authority only; it does not designate the final reviewer or enforce this merge workflow. `COMMENTED` reviews remain informational, and an observed `APPROVED` review neither completes the task nor authorizes a Gateway merge.

Operators can [inspect durable approval evidence](/reference/tasks#inspect-approval-observations) through the read-only Gateway console report. Its reviewer/review/commit provenance and `current`, `historical`, or `unverified` status describe the stored scan only. It cannot replace this workflow's fresh checks or establish the designated final reviewer's identity or delegated consent.

For blocking findings, submit a formal `REQUEST_CHANGES` review through GitHub's reviews API with `commit_id` set to the full reviewed head SHA. Put the bounded findings in its body and inline review comments, not only in an issue comment or linked evidence. An eligible trusted request creates at most one automatic fixup for that review ID, within the shared [fixup caps](/reference/tasks#fix-a-settling-pull-request). Editing or dismissing a consumed review does not rewrite or cancel that work.

If the request exceeds the retrieval limits or needs a product decision, scope it with the operator instead of assuming Orbit will follow links or implement every instruction in the prose.

A fixup's fresh internal reviewer checks its snapshotted findings and the Project checks. Its push updates the same pull request. It does not submit a GitHub decision, comment, dismissal, or re-review request. The external reviewer must review the new head and submit a new formal decision. An old request or approval is stale, even if the fixup seems small.

Read all effective decisions from the designated final reviewer in submission order, with review ID breaking ties: a later `COMMENTED` review does not erase an approval or requested changes, and dismissal does not revive an older decision. The final reviewer still checks the complete PR, independent evidence, resolved findings, and successful `Required checks` on the exact head before the authorized immediate merge. Orbit observes the merge and completes the task afterward.

After the merge, keep the review evidence and release the resources allocated to the feature. For a local worktree, run `bin/worktree-remove ISSUE`.

[Delivery-line proofs](/reference/delivery-line) are the read-only commands for reproduction on current main, task-group shape, the current pull-request head, and post-merge live state. They do not file a task, merge, deploy, or roll back.

## CI

GitHub CI runs on every pull request, on every push to `main`, and on manual dispatch. It has these jobs.

| Job | Checks |
| --- | --- |
| One job per Composer project: CLI, Docs, Gateway, E2E, PHP SDK | `composer validate --strict`, `composer check`, the classification-fakes check, and the tests |
| API reference | `bin/docs-openapi --check` and `bin/mcp-tools --check` |
| Web | Generated API types, formatting, lint, types, tests, and build |
| Pi server | Formatting, lint, types, tests, and build |
| Agent annotation | Formatting, lint, tests, and build |
| Rust agent | `cargo fmt`, `cargo clippy`, tests, and static builds for x86_64 and aarch64, with Cargo caches. A pull request that changes neither `apps/agent` nor `ci.yml` skips these steps |
| Required checks | Passes only when every other job passes |

On a pull request, each Composer project job runs the TIA-selected tests and the architecture tests. The architecture tests include the contract tests that read the workflow files, `CliBinaryBuildContractTest` and `ComposerConfigurationTest`, because TIA does not link a workflow file to the tests that read it. On a push to `main` or a manual dispatch, it runs the full suite once with `--tia --fresh`, which also records a new TIA graph, and saves that graph to the cache.

On `main`, GitHub enforces three rules. The branch cannot be deleted, and it accepts no force pushes, with no bypass. A change to `main` also needs a passing `Required checks` status from GitHub Actions. The branch does not have to be up to date first, so the merge rules in [Merge and cleanup](#merge-and-cleanup) still check the merged result. GitHub requires no review.

Repository admins bypass the status rule automatically, so the maintainer can push straight to `main`. The bypass also applies to `gh pr merge` from an admin account, with or without `--admin`. An admin who merges must first wait until `Required checks` passes on the pull request's head commit. The [contributor guide](/contributor-guide#3-implement-and-verify) describes how pull requests and pushes select tests.

Each Composer project job checks out the branch by name with full history, so Pest can write its test-impact graph. On a detached HEAD, Pest does not save the graph. The Docs job's `composer check` also runs `composer docs-lint`. The E2E job runs `bin/bootstrap --skip-checks` to install every project, because its integration tests use the other projects. The Gateway job installs the Linux tools that the Gateway tests need and creates the `caddy` user. That step stops after 10 minutes, and apt retries a mirror that does not answer within 30 seconds.

Hosted jobs run on `ubuntu-26.04`, the Ubuntu release that Nodes run, so tests use the same uutils coreutils as a Node.

### Self-hosted Gateway runner

When the repository variable `ORBIT_SABRE_RUNNER` is `true`, the Gateway job runs on the self-hosted runner on Sabre, with the labels `self-hosted` and `sabre`. Pushes, manual dispatches, and pull requests from branches in this repository use it. A pull request from a fork always uses a GitHub-hosted runner, so code from outside the repository never runs on Sabre. Set the variable to anything else to move the job back to GitHub-hosted runners.

On Sabre the job skips the PHP setup, Homebrew, and system package steps, because Sabre already has PHP 8.5 with PCOV, Caddy, `acl`, `attr`, and `wireguard-tools`. Its PHP CLI sets `zend.exception_ignore_args=0` in `99-github-actions.ini`.

The PHP setup step must not run on Sabre: on a self-hosted runner it makes `/usr/local/bin` world-writable, and the program that configures service metrics refuses a Node with such a directory. Every Instance removal reconciles metrics on all Nodes, so each removal would then fail. Its `/usr/bin/composer` is Composer 2.10, installed over the Ubuntu package with a `dpkg-divert`, because Composer 2.9 rejects the GitHub Actions token that the PHP setup step exports. Pest runs 6 processes there instead of 4.

The job installs Node 24 with the Node setup step on Sabre. Tests that start `/usr/local/bin/node` directly, as Process presets do, use Sabre's own `node` shim, which points into the managed user's home. The runner user has traverse-only access to that home (`setfacl -m u:github-runner:x`) so the shim works.

Sabre has no Orbit role and serves no Instance. Three runner services, `sabre-1` to `sabre-3`, run as the `github-runner` user, so several pull request runs and a `main` run do not wait for each other. That user has passwordless `sudo`, because the PHP setup step installs packages. Each runner service mounts its own work directory at `/home/runner/work`, the path that GitHub-hosted runners use. PHPStan and Rector key their caches on absolute paths, so the caches saved by either kind of runner stay valid on the other.

The `github-runner-egress` systemd unit loads an nftables rule that rejects traffic from `github-runner` to private addresses: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, and `100.64.0.0/10`. Orbit trusts WireGuard source addresses, so this rule keeps a job from reaching the Gateway or another Node as Sabre. DNS still works through the local resolver. A command that a job runs with `sudo` runs as root, and the rule does not cover it. So the runner accepts only code from this repository.

Docs-lint also checks the ADR lifecycle. A row in the decisions overview's lower table has no file with its recorded slug, and a redirect exists from that exact path. ADRs from 0180 onward have an `In progress.` Status and a `Principle:` line. A lower number follows those rules only when that lower table does not list its number. The open gaps are 0007 and 0020. The committed allowlist of older live ADRs can only shrink. The [contributor guide](/contributor-guide#checks-that-need-no-network) explains these checks.

Each Composer project job caches three sets of files in GitHub Actions cache.

| Cache | Key inputs |
| --- | --- |
| PHPStan result cache, `vendor/phpstan/cache/resultCache.php` | `composer.lock`, `phpstan.neon` |
| Pint and Rector caches, `vendor/pint.cache` and `vendor/rector/cache` | `composer.lock`, `pint.json`, `rector.php` |
| Test-impact graph, `.orbit-tia` | `composer.lock`, `tests/Pest.php`, `phpunit.xml`, `phpunit.xml.dist` |

A job restores the newest cache for its branch, then for `main`, then any cache for the project. It saves each cache only after its checks succeed. These caches are separate from the [main caches](#main-caches), and CI never calls `bin/tia-cache`.

The separate `Orbit CLI Binary` workflow builds the toolbox binaries. It is not part of `Required checks`. See [CLI binaries](/reference/cli-binaries).

## Local checks

Run these commands in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. Each project keeps its own dependencies, test configuration, and test-impact graph.

| Command | Result |
| --- | --- |
| `composer test:affected` | Runs the affected tests with test-impact analysis (TIA) and two parallel workers; the Gateway, with the largest suite, uses four |
| `composer check` | Runs `guidance:check`, Rector, the Pint check, and PHPStan. It does not run the test suite. |
| `composer test` | Runs the project suite with TIA in parallel |
| `composer format` | Applies Pint's Laravel preset |
| `composer analyse` | Runs PHPStan. The applications use Larastan. |

In `apps/docs`, `composer check` also runs the documentation lint and the API fixture check. `guidance:check` runs the project's guidance tests with a fresh graph separate from the graph that `test:affected` reads. In Gateway, CLI, and E2E, `bin/guidance-check` creates private temporary directories for the graph and Blade views before booting Laravel, then removes them when the command exits. The gate does not depend on `storage/framework/views` existing or on caches written by another user. The check still fails if Boost cannot render guidance or changes the markers required by the hard-stop transformation. The [contributor guide](/contributor-guide#static-analysis) sets the PHPStan level and the rules for fixing findings.

TIA needs PCOV or Xdebug. The first run, or a run without a usable graph, runs the full suite. Later runs select the tests that the changes affect. An explicit path or a partial-selection option such as `--filter`, `--group`, or `--testsuite` turns TIA off for that run. Confirm that the tests of the feature ran. Zero selected tests is not acceptance evidence. Every `tests/Pest.php` stores its graph in the directory that `ORBIT_TIA_DIRECTORY` names, and in `.orbit-tia` by default. E2E scenario runs use the directory in `ORBIT_SCENARIO_TIA_DIRECTORY` instead, which must be under the primary checkout's `.e2e/scenarios/runs/`.

Each project requires the `nckrtl/pestphp-monorepo` Pest fork from its lock file. The fork adds the monorepo and test-impact fixes. To adopt a new release, update the dependency and the lock file in every project, then run the project checks. A runner change invalidates the published graphs, so the first run after it records a fresh graph.

Root `bin/test` runs all five project suites in parallel and splits the available processors between them.

### The candidate gate

Root `composer check` runs `bin/review-check`. It checks the working tree as it is, including uncommitted and untracked files. The Orbit Project uses it as its task check, so it also runs at every subtask handoff. It runs these checks.

1. It runs `bin/docs-impact --gate` against the merge base with `origin/main`. The fallback is the merge base with local `main`. Without a merge base, this check fails.
2. It seeds absent quality and test caches with `bin/worktree-cache` and `bin/tia-cache seed`.
3. It checks each of the five Composer projects in turn, as the next paragraph describes.
4. When the candidate changes `apps/web`, `docs/openapi.json`, or `apps/pi-server`, it adds the matching checks, as [Web and Pi server checks](#web-and-pi-server-checks) describes.
5. Last, when the candidate changes test sources, it runs `bin/check-classification-fakes` on them.

For each Composer project, the gate runs `composer validate --strict`, `composer check`, and `composer test:affected`. The Gateway's `composer check` also runs `bin/annotator-build --check` to verify the distributed injection asset against its package sources. After changing those sources, run `bin/annotator-build` and include the generated Gateway resource files. Each affected-test run records into its own copy of the project graph. That copy keeps only the `main` baseline. The gate selects tests for every change since `main`, whatever local `composer test:affected` runs happened earlier. Local runs are unchanged. Each one still writes its branch baseline into the project's own graph, and the gate leaves that graph unchanged.

When the candidate changes the project, the gate runs that project's architecture tests. When that project has changed and `test:affected` selects no tests, the gate runs its full suite with `--no-tia` in four parallel processes instead of only warning. A project with changed source files but no selected tests is this case. When `test:affected` passed, the gate looks for changed test files that TIA did not select. It lists the tests of each such file and runs the file without TIA. A file without tests fails.

#### Web and Pi server checks

A change under `apps/web` runs `bun install --frozen-lockfile` in `apps/web` and `packages/agent-annotation`, then `bun run check`, `bun run build`, and a generated-types check in `apps/web`. The generated-types check writes `openapi-typescript` output for `docs/openapi.json` to a temporary file and compares it with `src/api/schema.d.ts`. It does not change the working tree. A change to `docs/openapi.json` alone runs the install and the generated-types check. A change under `apps/pi-server` runs `bun install --frozen-lockfile`, `bun run check`, `bun run test`, and `bun run build` there. The Rust agent and agent annotation checks run only in CI.

A command whose program is missing fails with `<program>: required tool not found`. The generated-types check runs in a shell, so a tool that is missing there fails with exit code 127. The gate never skips a selected check.

#### Finding checks

Three checks reject review findings that code can detect. `composer check` runs the first two in every PHP project through the shared rules in `tools/phpstan/`.

| Check | Rejects |
| --- | --- |
| `phpstan-disallowed-calls` | A call to `strtotime()`. Use Carbon parsing. |
| `orbit.inlineVarOverride` | An inline `@var`, `@phpstan-var`, or `@psalm-var` inside a method body. Fix the type at its source. |
| `bin/check-classification-fakes` | A changed test that calls `Classification::fake()` without chaining `preventStrayClassifications()`. |

Each failure names the file and line.

#### Receipt

The gate writes a receipt, `result.json`, and one log per command in a new `review-*` directory under `orbit-checks/<HEAD>/` in the Git common directory. The receipt passes only when at least one command ran, every command passed, and the commit and the working tree did not change during the run. When `test:affected` selects no tests for a changed project, the full suite with `--no-tia` is one of those commands. A warning does not replace that run.

### Gateway test databases

The Gateway test bootstrap keeps tests away from real databases. Before Laravel loads its configuration, it sets `APP_ENV=testing`, the SQLite driver, and the test database in the process environment and in the PHP environment and server variables. After Laravel loads its configuration, a guard checks the effective connection, including a connection URL and cached configuration.

| Input | Test behavior |
| --- | --- |
| No `ORBIT_TEST_DATABASE` | In-memory SQLite |
| Inherited `DB_DATABASE`, `DB_URL`, or `DB_CONNECTION` | The test value replaces it |
| `ORBIT_TEST_DATABASE` set to an absolute path of an `orbit-gateway-test-*` file in the system temporary directory | That disposable SQLite file |
| `ORBIT_TEST_DATABASE` set to any other path, or to a symbolic link | The test bootstrap stops with a message that names the rule |
| Any other driver, database, or application environment | The run stops before migrations with a database safety refusal |

Allocate a new temporary file for each run that needs a file-backed database. Never point `ORBIT_TEST_DATABASE` at a Gateway runtime database, another application's database, or a backup.

An unexpected refusal usually means that Laravel loaded a stale cached configuration. In `apps/gateway`, run `unset APP_CONFIG_CACHE` and `php artisan config:clear`, then run the same command again. Do not disable the guard.

## Worktrees

`bin/worktree-create ISSUE` creates a linked worktree for a local feature. For `ORB-217`, it uses branch `orb-217` and the directory `/fast/worktrees/orbit/orb-217`. It fetches `origin` first and does not change the primary checkout. Set another absolute base directory with `git config orbit.worktreeRoot PATH`. The base must be outside the primary checkout.

The command reuses what already exists.

| State | Result |
| --- | --- |
| The branch has a clean registered worktree | The command reuses that worktree |
| That worktree is dirty or on another branch | The command refuses |
| Only the local branch exists | The command adds a worktree for it |
| Only `origin/orb-217` exists | The command creates a local branch that tracks it |
| No branch exists | The command creates the branch from `origin/main` |

It refuses a path that exists but is not a registered worktree. Then it runs `bin/bootstrap` in the worktree.

`bin/bootstrap` installs all five Composer projects, seeds the caches, and validates each project's guidance. Then it runs `composer test:affected` and `composer check` in each project. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` stops after the guidance validation.

`bin/worktree-remove ISSUE` refuses a dirty worktree, a branch that `origin/main` does not contain, and a path that exists but is not a registered worktree. It queues a background cache refresh, releases the worktree's discovery topology, and removes the worktree. Then it deletes the merged branch.

## Main caches

Main caches let a new checkout start with warm test-impact graphs and warm Pint and PHPStan result caches. Each repository keeps one store in its Git common directory under `orbit-tia/v1`. Linked worktrees share it.

| Path under `orbit-tia/v1` | Content |
| --- | --- |
| `published/<project>.json` | One test-impact graph per Composer project |
| `quality/<project>/<tool>.json` | One Pint or PHPStan result cache per project |
| `requests.json` | Pending refresh requests, the last result per project, and open correctness failures |
| `refresh.log`, `run-*/` | The worker log and the per-check logs |
| `development-instance.json` | The default Instance that owns deployment and cache warm-up |
| `checkout/`, `repository/` | Legacy private maintenance checkout for repositories without a development owner |
| `runners/` | The frozen worker scripts |
| `requests.lock`, `refresh.lock` | The queue lock and the worker lock |

Only a successful run on a clean `main` publishes. Each publication records the tested commit, a checksum, and the inputs it is valid for. A new publication must descend from the one it replaces, so older results never replace newer ones.

### Seed a checkout

Seeding copies a publication into an absent private cache and never replaces an existing one. `bin/tia-cache seed` copies test-impact graphs. `bin/worktree-cache` copies Pint and PHPStan caches. Bootstrap and the candidate gate run both. A missing or incompatible publication leaves that project cold, and seeding does not fail.

| Cache | Compatible when |
| --- | --- |
| Test-impact graph | The project's `composer.lock`, `tests/Pest.php`, and Pest fingerprint match, the checksum matches, and the tested commit is an ancestor of `HEAD` |
| Pint or PHPStan cache | The project's `composer.lock`, the tool's configuration, and the PHP minor version match, the checksum matches, and the tested commit is an ancestor of `HEAD` |

When no quality publication fits, `bin/worktree-cache` copies the cache from the primary checkout when that checkout is clean on `main` and has the same lock file and configuration. Feature worktrees are never a source.

When `ORBIT_MAIN_CACHE_STORE` is set, a checkout seeds only from that store, even when it has no publications, and `bin/worktree-cache` skips the primary-checkout fallback. Bootstrap uses this variable only while seeding, and never publishes into it. When the variable is unset, a checkout seeds from its own store when that store has publications, and otherwise from the store registered for its origin on the same machine.

The registration is a link at `$XDG_STATE_HOME/orbit/main-cache-stores/<key>`, and `$XDG_STATE_HOME` defaults to `~/.local/state`. The key comes from the origin URL, so the HTTPS and SSH URLs of one repository share it. Each publication registers its store unless another live store already holds the registration. `bin/tia-cache register` takes the registration for the current repository.

Orbit task workspaces are linked worktrees of Orbit's `default` repository, starting at its current release commit. They share its main cache store but keep private tool caches. `bin/bootstrap` and `bin/review-check` seed those private caches from the publications. A Project setup step reflinks each dependency tree and private cache from `ORBIT_SEED_PATH` first. When the store lags fetched `main`, seeding queues a background development deployment for the lagging projects. It does not queue a project whose last refresh failed at that commit or at a later one. Projects without a release keep the independent-clone fallback.

### Publish from bootstrap

After all checks pass, bootstrap publishes its caches when the checkout is clean, `HEAD` equals the fetched `origin/main`, `ORBIT_MAIN_CACHE_STORE` is unset, and the default TIA directory is in use. It turns the branch's Pest results into a main graph and keeps the graph's recorded commit. It skips a project with an open correctness failure, a graph with working-edit history, a graph with results from other branches, or a branch result that is not complete at the checked commit. When a refresh worker is running, the caches stay private. A publication error is reported, and bootstrap still succeeds.

### Refresh in the background

`bin/tia-cache refresh --background` records a request and starts one worker when none runs. It returns at once. `bin/worktree-remove` queues it when it removes a merged worktree. A merge without that cleanup, such as a task's pull request, queues nothing. The next seed from the registered store queues the refresh instead. Cache freshness never holds worktree creation, a merge, or cleanup.

For Orbit, the store lives under the stable `default` repository's `.git/orbit-tia/v1` on `/fast`. Register its owner with `bin/tia-cache register --repository=/fast/apps/orbit/default --development-instance=303` after initializing the release layout. The background worker holds the refresh lock and calls `orbit instance:deploy 303 --json`; the managed user's Orbit CLI must have Gateway access. It never fetches, installs, or changes the selected release itself.

The Project's last development deploy step runs `bin/tia-cache warm` in the clean, unselected candidate. Pest does not record detached releases, so warm-up temporarily uses a private candidate branch and folds its successful results into the main publication. A durable, ownership-bound journal records this transition before attachment. Warm-up holds its journal lock through child commands and detaches the candidate before removing the journal. If the process dies, the next deployment validates that journal, Git administration, commit and branch creation receipt before detaching and deleting the private branch. It refuses a live lock or foreign state instead of modifying it.

Warm-up installs dependencies and runs `composer test:affected`, `composer format:check`, and `composer analyse` for each project, publishing each successful tool independently.

PCOV or Xdebug is required and each command stops after 30 minutes. A required warm-up failure retains the live release; a best-effort failure is reported and can still switch it. Bootstrap from a task workspace cannot publish into this deployment-owned store.

An unmanaged repository without a development owner retains the private maintenance-checkout fallback. Its worker fetches `main`, fast-forwards that checkout and runs the same checks. This is not Orbit's deployed cache source. After registering Orbit's default store and verifying its publications, stop the old worker and retire the ext4 store's `checkout/` and `repository/`; keep old logs until any recorded failures are resolved.

Requests stay in `requests.json` until the worker records their outcome, so an interrupted worker leaves them pending. Repeated requests for the same target combine. The worker runs a copy of its own script from the store in a new session, so removing the calling worktree does not stop it. It runs at reduced CPU priority. After each batch, it deletes the run logs that no recorded result names.

The worker removes these variables from its environment and from every command, so setup settings cannot select a project runtime:

- names that start with `ORBIT_`, `APP_`, or `DB_`;
- `DATABASE_URL`, `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION`.

It keeps `TMPDIR`, `TMP`, and `TEMP`. Those names choose where nested Pest and Composer write temporary files, not which application, database, or cache the project uses. Stripping them forced those tools onto shared `/tmp`, which collides under concurrent worktrees and exhausts inodes.

It sets `PAO_DISABLE=1`, so the commands print their normal output even when an agent session queued the refresh.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches |
| `bin/tia-cache refresh --background` | Queues a refresh and starts a worker when none runs |
| `bin/tia-cache refresh` | Queues a refresh, waits for the worker, and exits nonzero when a requested project fails |
| `bin/tia-cache status` | Prints the publications and the maintenance state |
| `bin/tia-cache status --json --remote` | Reads `main` from the remote and prints the maintenance state as JSON, without changing anything |
| `bin/tia-cache register` | Registers this repository's store for its origin; `--development-instance=ID` assigns deployment ownership |
| `bin/tia-cache warm` | Checks and publishes caches from an unselected default release candidate |

Every cache command accepts `--repository=PATH`. `seed`, `refresh`, and `warm` accept repeatable `--project=apps/docs` options, and they cover all five projects by default.

### Recover a failed refresh

`bin/tia-cache status --json --remote` reports the remote `main`, whether a worker holds the lock, the pending requests, which projects are current, each project's command results with log paths, the failed projects in `failures`, the open correctness failures, whether a refresh is `needed`, and `refresh_log`. A failed check records the command's stdout and stderr in `error`, trimmed to the tail when the output is large, and keeps the full output in the named log. A successful status command reports state. It does not mean that the checks passed.

The worker sorts failures into two kinds.

| Kind | Cause | Effect |
| --- | --- | --- |
| Check failure | A nonzero exit of the tests, Pint, or PHPStan | A correctness signal on main. It stays open until that tool passes on a later refresh. |
| Maintenance failure | Any other error, as the list below shows | Checkouts keep using the previous publications or run cold. It never clears a check failure. |

These errors are maintenance failures:

- a failed install, fetch, or setup;
- a command that hits the 30-minute limit;
- a missing coverage driver;
- a checkout that changed during the run;
- a recovery that executed no tests.

While a test-impact failure is open, the next refresh runs `composer test:affected -- --fresh` on the checked commit. The failure clears only when the new graph records that commit, and the project result then shows `recovery: executed`. A run that executed no tests shows `recovery: not_executed` and keeps the failure. Diagnose a check failure on the exact failed commit. Fix it, or revert the change that caused it, in a separate worktree through a reviewed pull request.

## Why it works this way

### The maintainer approves every merge

The maintainer decides whether a feature belongs in Orbit. For named Orbit task work, the maintainer can delegate final review and merge. That delegation is the consent to submit the formal GitHub approval and to merge the reviewed commit. The review records the reviewed head, the evidence, and the verdict in GitHub's review state. A plain comment is not that approval, because its prose does not distinguish approval from requested changes. A Tasks engine subtask approval, or any other agent review, does not replace the final review of the whole pull request and does not authorize unrelated work.

The merge uses the maintainer's GitHub CLI profile. That admin profile bypasses GitHub enforcement of `Required checks`, and GitHub does not require an approving review, so the DevOps reviewer checks the formal approval and green CI before merging the reviewed commit. A Gateway App merge endpoint and a ruleset change that requires an approving review are deferred. Either change would enforce the gate for an identity without the admin bypass, and either change needs new credentials, API behavior, and deployment work.

### Every project passes the gate at each handoff

The gate runs all five Composer projects whatever the candidate changes. Focused tests of the changed project alone are a rejected alternative, because the other projects would then have no quality check before review. The gate runs at each handoff, before review. Running it only in the independent review is also rejected, because each deterministic failure would then cost a reviewer turn and a correction round before it reached the implementer.

### Web and Pi server checks join the gate, Rust stays in CI

A change to the web app or the Pi server could pass the gate and then fail a required CI job. So the gate runs the same commands as those CI jobs when their paths change. Running every CI job for every subtask is a rejected alternative, because path selection keeps unrelated work fast. Rust cross-compilation is too slow for each handoff, so the Rust agent stays a CI check.

### A missing tool fails the gate

A green gate must mean that every selected check ran. Skipping a check when its tool is absent would pass a workspace that happens to lack `bun` or `git`.

### The gate selects against main

A local `composer test:affected` run writes a branch baseline into the project's graph. Keeping that baseline in the gate's run copy is a rejected alternative. The gate would then compare the candidate with the last local run. Changes since `main` that the local run already covered then select no tests. The gate keeps only the `main` baseline in its run copy, so it selects tests for every change since `main`. Local runs are unchanged and still write the project's own graph.

Passing on a warning is a rejected alternative when a change selects no tests. The gate runs the project's full suite with `--no-tia` instead. Changed source files with no selected tests take this path.

### A changed test must run

Test-impact analysis can omit a changed test file even when it selects other tests. Reporting that miss only as a warning is a rejected alternative, because an edited test that Pest does not discover gives false confidence. The gate runs that file by path and fails when Pest finds no tests in it. When the selection is empty and source files changed, the gate also runs the full suite with `--no-tia`. That full suite does not replace this per-file run.

### Repeated findings become checks

Reviewers report `strtotime()`, inline type overrides, and unguarded classification fakes again and again. Each one is deterministic, so a check rejects it before review. Asking reviewers to remember them is a rejected alternative, because it spends a review round on a finding that code can detect.

### Main caches come only from clean main

A feature graph can hold unmerged code, failed tests, or working edits, so it cannot prove the state of main. Only a clean run on main publishes, and each checkout writes to its own private copy. One writable graph shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results. Recording every new worktree from scratch is also rejected, because unchanged projects would repeat the full run.

### Maintenance runs in the background

Waiting for every refresh before a new worktree or a merge would serialize delivery behind maintenance. A checkout starts from the newest compatible publication while the worker catches up. The cost is that a failure on main can surface after another feature merges. One worker per repository covers all five projects, because separate owners would compete for the same checkout and processors. A cache failure is not a source regression, so only check failures count as correctness signals.

The maintainer assigns one owner to recover failures across the monorepo, and routine refreshes run from the repository scripts. An owner per project or per merge is a rejected alternative, because several owners would compete for the same checkout and processors.

### Bootstrap publishes, the primary checkout stays put

A clean bootstrap on main already ran every check, so its results can warm later worktrees without another run. Refreshing a live checkout in place is rejected because it can run the Gateway. Orbit's default development deployment instead warms a new candidate and switches only after its required steps pass. A separate cache service is also rejected, because linked worktrees already share one store.

### Clones find the store through a registration

The Gateway provisions a task workspace as an independent clone, and it runs checks for every Project without knowing one repository's cache layout. So the repository's own scripts find the store through a link in the user's state directory. Setting `ORBIT_MAIN_CACHE_STORE` in the Gateway or in Project setup steps is a rejected alternative, because a fixed path breaks when the primary checkout moves. A global Git setting is also rejected, because it needs a manual step on every machine and goes stale without notice.

### CI caches stay separate

Hosted CI keeps its caches in GitHub Actions cache, keyed by branch and inputs. Each run starts from a fresh runner, so it cannot read the repository store. The Rector cache is shared only in CI, because Rector keys its cache on absolute file paths, and CI always uses the same checkout path.

### Findings are fixed, not silenced

A PHPStan ignore, a baseline, or a silencing cast keeps the wrong type in the code, and new code can repeat it. Fixing the value at its source lets CI catch these errors before review, so a reviewer spends the review on behavior. The [contributor guide](/contributor-guide#static-analysis) lists the rules.
