---
title: "Feature delivery"
description: "How a change reaches main: review evidence, merge, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/AdrLifecycle.php
  - bin/{review-check,bug-repro,task-group-check,pr-head-check,deploy-verify,test,tia-cache,ci-cache-ref,ci-tia,worktree-cache,worktree-create,worktree-remove,check-classification-fakes,check-processes,quality-scope,php-build}
  - tools/{check-processes,rector-cache}.php
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/setup.sh}
  - .github/{workflows/ci.yml,actions/php/action.yml}
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

A Project with [review and merge](/reference/tasks#review-and-merge) on does not use this workflow. Orbit's final review, merge check, and App merge replace it. The workflow below applies to every other Project.

When the maintainer delegates final review and merge of a named Orbit task pull request, that delegation is the consent for that work only. The DevOps reviewer uses the maintainer's GitHub CLI profile. The reviewer checks the whole pull request, confirms the independent code and Incus review evidence, and submits a formal GitHub review for the exact head.

The review body names the full head commit SHA, the checks and their results, any remaining limitations, links to the review evidence, and the verdict. A limitation that leaves required behavior unverified prevents approval. A Tasks engine subtask approval does not replace this final review of the whole pull request.

The merge gate requires a formal GitHub `APPROVED` review from the designated final reviewer for the current head. Submit `APPROVE` through the GitHub reviews API from the maintainer profile, with `commit_id` set to the reviewed SHA. A plain comment, including a ready-to-merge verdict, does not satisfy the gate. The pull request author cannot approve their own pull request. The delegation does not authorize an unrelated merge.

Use [`bin/pr-head-check`](/reference/delivery-line#binpr-head-check) to check for an `APPROVED` review and successful `Required checks` on the exact current head, and for named leftovers in the diff. A `COMMENTED` review on that head does not pass. The command does not verify the designated reviewer's identity or merge the pull request.

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

[Delivery-line proofs](/reference/delivery-line) are the read-only commands for reproduction on current main, task-group shape, the current pull-request head, and post-merge live state. They do not file a task, merge, deploy, or roll back. Use `bin/pr-head-check` to confirm the diff adds no named leftover, including `object-storage-host` and `linear-reference`; the [leftover list](/reference/delivery-line#binpr-head-check) defines their generic, public-safe forms.

## CI

CI passes its job-scoped, read-only `GITHUB_TOKEN` to Composer through workflow-level `COMPOSER_AUTH`. GitHub API requests use `github-oauth`; `codeload.github.com` uses bearer authentication too, because Composer does not send the API OAuth header to that host. The token stays in the workflow environment and is not written to an auth file.

GitHub CI runs on every pull request, on every push to `main`, every night on `main`, and on manual dispatch. It has these jobs.

| Job | Checks |
| --- | --- |
| One job per Composer project: CLI, Docs, Gateway, E2E, PHP SDK | `composer validate --strict`, `composer check`, the classification-fakes check, and the tests |
| Gateway subprocess, E2E subprocess | The project's `subprocess` group, beside the project's own job |
| Gateway plan | Pull requests only: lists the Gateway tests that TIA selects and chooses one Gateway test job or up to four shards |
| Gateway shards (2) to Gateway shards (4) | Pull requests with a large Gateway selection only: every Gateway test shard after the first |
| Docs (merge ref) | Pull requests only: `composer check` in `apps/docs` on the base repository's `refs/pull/N/merge`, with no head fallback if the merge ref is unavailable |
| API reference | `bin/project-vocabulary`, `bin/env-docs`, `bin/tooling-baseline`, `bin/docs-nav`, `bin/docs-openapi --check`, and `bin/mcp-tools --check` |
| Web | Generated API types, formatting, lint, types, tests, and build. A run on `main` also publishes the build |
| Pi server | Formatting, lint, types, tests, and build |
| Rust agent | `cargo fmt`, `cargo clippy`, tests, and static builds for x86_64 and aarch64, with Cargo caches. A pull request that changes neither `apps/agent` nor `ci.yml` skips these steps |
| Remote programs | Installs shellcheck and ruff 0.17.0, then runs `bin/remote-programs-check`: the lint and direct tests of the Gateway's [remote programs](/reference/remote-programs#lint-and-direct-tests) |
| Desktop app | `bun install --frozen-lockfile`, then `cargo fmt` and `cargo clippy` on the Tauri shell with the WebKitGTK libraries installed. A pull request that changes neither `apps/desktop` nor `ci.yml` skips these steps |
| Required checks | Passes only when every other job passes, as described below |

Gateway project, subprocess, and shard jobs supply Bun 1.4.2 alongside Node/npm. The package-cache integration tests run real npm and Bun installs against seeded private caches and a local test registry.

The Docs project job also supplies Bun 1.4.2 and installs the Gateway's Composer dependencies and the web app's locked Bun dependencies. Its [proof for merging generated files](/contributor-guide#merging-generated-files) runs the real generators in disposable clones, including during the subprocess test run.

### One PHP build in CI

Every job that runs PHP uses the recipe's [PHP build](/reference/compute-drivers#php-build) of version `php.ci`, through the local action `.github/actions/php`. That action runs [`bin/php-build`](https://github.com/nckrtl/orbit/blob/main/bin/php-build), which reads the recipe. So CI and the workspaces that import its [cache refs](#ci-cache-refs) run the same PHP version and the same loaded extensions.

On a GitHub-hosted runner the action runs these steps:

1. It restores `/var/cache/orbit/php-packages` from `actions/cache`, keyed on `bin/php-build key`, the digest of the PHP pins of the runner's architecture.
2. `sudo bin/php-build install --ci --purge-extras` runs [`php.install-build`](/reference/compute-drivers#install-the-php-build), the installer that Nodes and images run too.
3. A hosted runner image ships other PHP packages and the `ondrej/php` source. `--purge-extras` removes them, and only CI machines use it.
4. `--ci` sets `zend.exception_ignore_args=0` for the CLI. The action installs Composer 2.10.3 from a pinned `composer.phar` at `/usr/local/bin/composer`.
5. A job with input `coverage: none` keeps PCOV loaded and sets `pcov.enabled=0`. The job collects no coverage and still loads the recipe's extensions.
6. `bin/php-build verify` fails unless `PHP_VERSION`, each package version, and `php -m` match the recipe.

A self-hosted runner never installs packages in a job. The action only runs `bin/php-build verify`. It fails with the expected and the found build, and names the step that fixes the host. After a PHP bump, update each runner while it is idle:

- Sabre is a roleless Orbit Node. On the Gateway, run `php artisan node:converge-recipe sabre --profile=ci-runner`. The recipe's [`ci-runner` profile](/reference/compute-drivers#role-and-base-tool-sets) installs the build and purges PHP packages outside the recipe. No other profile purges.
- The first run records the profile. Then nightly recipe maintenance converges Sabre, and a manual run needs only `php artisan node:converge-recipe sabre`.
- On Beast and Shark, run `tools/ci-runners/setup.sh container NAME` for each shard container.

#### Merge a PHP build change

A pull request that changes the PHP build runs its CI on the self-hosted runners too, and they only verify. So the runners must hold the new build before that CI runs. With the maintainer's approval, an operator runs from the pull request branch:

1. On Sabre: `sudo bin/php-build install --ci --purge-extras`.
2. On Beast and Shark: `tools/ci-runners/setup.sh container NAME` for each shard container.

Then the pull request's CI runs, and `main` stays green after the merge. After the merge, converge Sabre with the `ci-runner` profile so that Orbit manages it.

[Nightly recipe maintenance](/reference/compute-drivers#bump-the-php-build) opens a pull request when Sury publishes a newer build, and converges Sabre in its overnight service tier after merge. `setup-php` is not used. It cannot pin a patch release, and a hosted runner gets a new patch on its own schedule. It also loads about 26 extensions that Orbit does not use, such as `amqp`, `apcu`, `mongodb`, and `soap`.

`Required checks` expects Docs (merge ref) and Gateway plan to succeed on pull requests and to be skipped on pushes and manual runs. It expects the Gateway shards to succeed when the plan chose shards, and to be skipped otherwise.

On a pull request, each Composer project job runs the TIA-selected tests and the architecture tests. The architecture tests include the contract tests that read the workflow files, `CliBinaryBuildContractTest`, `ComposerConfigurationTest`, and `DocsMergeRefWorkflowTest`, because TIA does not link a workflow file to the tests that read it. They also include `CommandSurfaceTest`, which checks every CLI command against its section in `docs/cli`, so a pull request that changes only docs runs it too.

A pull request job also runs the project's `subprocess` group when the pull request changes a file that the project's tests read. A test that starts PHP in a subprocess, such as `artisan` or a fixture script, declares `pest()->group('subprocess')` at the top of its file. PCOV records only the test's own process, so TIA does not link the code that the subprocess runs to the test.

`bin/ci-tia subprocess` makes the choice. It compares the pull request with its merge base, and a push to `main` with the commit of the restored graph. A change inside the project, or outside it on a path that `bin/ci-tia` does not list as unrelated, runs the group. When the changes cannot be read, the group runs. The PHP SDK has no subprocess tests, so only its step passes with an empty group.

Gateway and E2E run their `subprocess` group in a separate job, so it runs at the same time as the rest of the project's checks instead of after them. That job installs the project, restores the graph, and makes the same choice. It runs no other check and saves no cache. When a run on `main` is full, the project's own job runs the group with the whole suite, and the subprocess job runs nothing.

The E2E contract `SubprocessTestGroupTest` fails when a test names `PHP_BINARY` or `PhpExecutableFinder`, or starts a `php` or `composer` command, without the group. It also follows a test helper under `tests/` that does so to the tests that use it.

On `main`, `bin/ci-tia plan` chooses each project's tests from the restored `main` graph. A push runs the tests affected since the commit that the graph records, with `--tia`. That commit can be older than the parent when runs overlap, so the selection covers every merge since it. The project runs its full suite with `--tia --fresh` instead when any of these hold:

- The run is the nightly run or a manual dispatch.
- The newest completed nightly or manual run on `main` failed.
- No `main` graph with the project's current cache prefix was restored.
- Pest cannot read the graph, or it was recorded with other dependencies or another PHP version.
- The graph's commit is not an ancestor of the tested commit, or a test file in the graph has no result.
- A change since that commit is one that TIA cannot link to tests.

A failed nightly or manual run can be a test that an affected-only run missed. So pushes run the full suite until such a run passes again. After the fix, dispatch CI on `main` to return to affected runs before the next night.

TIA only sees files inside the project. It links a non-PHP file only through a `pest()->tia()->watch()` pattern in `tests/Pest.php` or one of its own defaults. It links a PHP file only when a test covered it in the same process. So these changes run the full suite:

- a non-PHP file without a `watch()` pattern;
- a removed file that no test covered;
- a changed PHP file that no test covered;
- a new PHP file outside `app/` and `src/`, such as a config file or a migration;
- a new command or listener that the framework discovers;
- a test file whose name does not end in `Test.php`;
- a change outside the project that `bin/ci-tia` does not list as unrelated to it.

A `watch()` pattern matches the whole path relative to the project. `*` stays inside one directory and `**` crosses directories. Each pattern names one test file or directory, so a file that several test files run takes one pattern per test, in a chained `watch()` call. `bin/ci-tia` reads the patterns of every chained call. The Gateway lists each program in `resources/compute` by name, so a new program runs the full suite until it is listed with its tests.

Fixture changes run the full suite under TIA anyway. Docs and E2E tests read files across the repository, so these projects run their full suite whenever a file outside them changes. The [contributor guide](/contributor-guide#3-implement-and-verify) describes the selection from a contributor's view.

An affected run on `main` also runs the architecture tests, as a pull request does. It runs the `subprocess` group when a change since the graph's commit is inside the project.

After a passing run, `bin/ci-tia finish` requires the graph to record the tested commit and to hold a result for every test file it links. Pest records the commit itself after it runs tests. When no test is affected, Pest stops before it records the commit, so `finish` records it. The job then saves the graph to the cache.

A new push to a pull request cancels that pull request's older run. Pushes to `main` share one concurrency group and never cancel a running run. GitHub keeps one running and one waiting run per group, and a newer push replaces the waiting one. So when several merges land close together, the newest `main` commit is tested next. The commits in between get a cancelled run instead of a result.

Nothing goes untested. A push run selects each project's tests affected since the commit its restored `main` graph describes. That is the newest commit whose run of that project passed, so a project that failed keeps the older graph and tests those changes again. So the newest run covers every change in between. [Automatic Gateway releases](/reference/gateway-recovery#automatic-releases) deploy the newest `main` commit with a successful `Required checks` result and skip commits without one, so the release includes the skipped commits. Scheduled and manual full runs have a group per commit, so a push never cancels one.

On `main`, the project jobs check out the branch by name, then reset it to the run's own commit, so a run that starts after a later push still tests the commit its result is reported for. On a pull request, they check out the base repository's `refs/pull/N/merge`.

On `main`, the Web job uploads `apps/web/dist` as the workflow artifact `web-dist-<commit>`, named with the full 40-character commit SHA, and keeps it for 14 days. A manual dispatch and the nightly run on `main` upload it too, so every successful `Required checks` run on `main` comes with the web build of its commit. Automatic releases install only the build of a push or a manual run. The artifact holds the contents of `dist` at its root, so `index.html` and `version.json` are at the top level. The Web job fails when the build lacks either file; open pages read `version.json` to find a newer release ([Updates to open pages](/reference/web-app#updates-to-open-pages)).

The nightly run tests the newest `main` commit again, with every project's full suite. It adds a `Required checks` run to that commit. An automatic release needs every such run to pass, so a failed nightly run keeps that commit from shipping. Later pushes then run their full suites, so they ship only when the failure is fixed.

The upload includes hidden files and fails when the build produced nothing. The build runs from a clean checkout with no `VITE_*` variables, as [`bin/web-deploy`](/reference/web-app#release-a-build) builds a release. Pull requests and runs on other branches publish no web build. Successful project jobs on `main` also publish the [test baselines](#ci-artifacts).

On `main`, GitHub enforces three rules. The branch cannot be deleted, and it accepts no force pushes, with no bypass. A change to `main` also needs a passing `Required checks` status from GitHub Actions. The branch does not have to be up to date first, so the merge rules in [Merge and cleanup](#merge-and-cleanup) still check the merged result. GitHub requires no review.

Repository admins bypass the status rule automatically, so the maintainer can push straight to `main`. The bypass also applies to `gh pr merge` from an admin account, with or without `--admin`. An admin who merges must first wait until `Required checks` passes on the pull request's head commit. The [contributor guide](/contributor-guide#3-implement-and-verify) describes how pull requests and pushes select tests.

Each Composer project job checks out full history. On `main`, the job checks out the branch by name, so Pest can write its test-impact graph. A pull request job tests the merge commit, the tree that would land on `main`. Pest runs it on a detached HEAD, where it reads the `main` baseline of the graph and does not save the graph. The merge commit descends from the `main` commit that the restored graph records, so TIA selects only the tests that the pull request's changes affect. When the graph's commit is not an ancestor of the merge commit, Pest runs the full suite.

The Docs job's `composer check` also runs `composer docs-lint`. The E2E job runs `bin/bootstrap --skip-checks` to install every project, because its integration tests use the other projects. The Gateway job installs the Linux tools that the Gateway tests need and creates the `caddy` user. That step stops after 10 minutes, and apt retries a mirror that does not answer within 30 seconds.

The separate `Docs (merge ref)` job logs the merge commit and checks the tree that would land on `main`, including Docs lint and the ADR lifecycle rules. It uses a GitHub-hosted runner, read-only permissions, and no persisted checkout credentials. Its result gates `Required checks` without a ruleset change.

Hosted jobs run on `ubuntu-26.04`, the Ubuntu release that Nodes run, so tests use the same uutils coreutils as a Node.

### Gateway shards

A pull request that changes Gateway boot files, such as providers, `bootstrap/app.php`, routes, or migrations, selects nearly the whole Gateway suite: about 760 test classes and 12 minutes on Sabre. So the Gateway tests of a large selection run in up to four shards on separate runners.

The `Gateway plan` job runs on a GitHub-hosted runner at the start of each pull request run. It installs the Gateway, restores the same `main` graph as the Gateway job, and runs `bin/ci-tia shards`. That command lists the test classes that `pest --tia` would run, with `pest --tia --list-tests`, so the plan holds exactly Pest's selection.

The plan leaves out the `privileged` group: the shard runners have no `sudo`, and the Gateway privileged job runs that whole group on every run. Pest turns TIA off for a group option, so the command lists the group on its own, with `pest --no-tia --group=privileged --list-tests`, and subtracts it test by test. A class stays in the plan while any of its selected tests is not privileged, because some classes tag only some of their tests. Each shard runs with `--exclude-group=privileged`, so it leaves out the privileged tests of such a class.

With 200 classes or fewer, the Gateway job runs its tests with `--tia`, as before. 200 classes take about 3 minutes, so the job already ends about when the slowest other job does.

With more than 200 classes, the plan splits them into four shards. It balances the shards by the time that the `main` graph records for each class. It adds about 0.2 seconds per test for the application boot. A class that the graph does not know counts as the median class. The first shard starts with 210 process seconds, the time of the architecture tests. When the selection is small, that head start can leave the first shard without classes. The plan then drops the empty shard, because an empty filter finds no tests and fails.

The shards run different numbers of Pest processes: 6 for the first shard on Sabre, 4 for each of the others. So the plan gives each class, longest first, to the shard that would end first: its process seconds divided by its processes.

`bin/ci-tia shards` fails when a listed test does not parse as `Class::test`. It also fails when a selected test that is not privileged matches no shard filter. So every selected test runs in a shard or in the Gateway privileged job, and none can silently drop out.

The Gateway job runs the first shard, and the `Gateway shards` job runs the others, each on its own runner. Each shard runs `pest --no-tia --filter` with a regular expression that names its classes. PHPUnit matches it against `Class::test`, so every selected test runs in exactly one shard. The Gateway job still runs the quality checks, the classification-fakes check, and the architecture tests, once. The subprocess group stays in the Gateway subprocess job.

The plan job also uploads the plan as the artifact `gateway-plan` and keeps it for 30 days, as long as GitHub allows re-runs. The Gateway job does not wait for the plan job, so the other project jobs start at once. After its quality checks, the Gateway job polls the run's jobs through the Actions API until the plan job ends, then downloads the plan. It retries a failed API call every 5 seconds, for up to a minute.

The Gateway job fails when the plan job fails, or when the plan does not end within 10 minutes. The plan job and the Gateway job both check out `refs/pull/N/merge`, so the Gateway job also fails when the plan names another commit. The shard jobs check out the commit that the plan names.

"Re-run failed jobs" copies the finished plan job and keeps its artifact, so a re-run of the Gateway job or a shard reads the same plan. A re-run of the Gateway job checks out `refs/pull/N/merge` again. When `main` moved since the first attempt, the merge ref names a new commit, and the job fails with an error that asks for "Re-run all jobs". A full re-run plans again and replaces the artifact.

A sharded pull request holds one Sabre runner and up to three [shard runners](#self-hosted-shard-runners) on Beast and Shark. Two Sabre runners stay free for other runs, and only one shard uses Sabre's CPU. On the pull request that added the shard runners, a touched boot file selected 604 classes. They ran as 201, 135, 134, and 134 classes, and the Gateway tests ended about 3.5 minutes after the Gateway job started. On the shard runner containers, 609 classes took about 4 minutes. One job runs the full suite of about 760 classes in about 13.5 minutes. At that rate, the same 604 classes would take about 11 minutes in one job.

Runs on `main` never shard. A push or a full run there keeps one Gateway job, because Pest writes the whole graph in one run, and the graph and the main cache artifact must describe the tested commit. A full Gateway run on `main` still takes about 13 minutes.

### Self-hosted Gateway runner

When the repository variable `ORBIT_SABRE_RUNNER` is `true`, the Gateway job runs on the self-hosted runner on Sabre, with the labels `self-hosted` and `sabre`. The Gateway shards run on the [shard runners](#self-hosted-shard-runners) under the same condition. The Gateway plan, Gateway subprocess, and Gateway privileged jobs always run on GitHub-hosted runners. So a run takes one Sabre runner, and up to three shard runners when the Gateway tests run in shards.

Pushes, manual dispatches, and pull requests from branches in this repository use it. A pull request from a fork always uses a GitHub-hosted runner, so code from outside the repository never runs on Sabre. Set the variable to anything else to move the job back to GitHub-hosted runners.

On Sabre the job skips the Homebrew and system package steps, because Sabre already has Caddy, `acl`, `attr`, and `wireguard-tools`. Its PHP step only [verifies](#one-php-build-in-ci) that Sabre runs the recipe's PHP build. Its PHP CLI sets `zend.exception_ignore_args=0` in `99-github-actions.ini`.

A job never installs software on Sabre: the former PHP setup action made `/usr/local/bin` world-writable on a self-hosted runner, and the program that configures service metrics refuses a Node with such a directory. Its `/usr/bin/composer` is Composer 2.10, installed over the Ubuntu package with a `dpkg-divert`, because Composer 2.9 rejects the GitHub Actions token that the former PHP setup action exported. Pest runs 6 processes there instead of 4.

The job installs Node 24 with the Node setup step on Sabre, and tests run the `node` on PATH. On Sabre the runner user has traverse-only access to the managed user's home (`setfacl -m u:github-runner:x`).

Sabre is an Orbit Node with no role and serves no Instance. Orbit manages its PHP through the `ci-runner` recipe profile. Three runner services, `sabre-1` to `sabre-3`, run as the `github-runner` user, so several pull request runs and a `main` run do not wait for each other. That user has passwordless `sudo`. Each runner service mounts its own work directory at `/home/runner/work`, the path that GitHub-hosted runners use. PHPStan and Rector key their caches on absolute paths, so the caches saved by either kind of runner stay valid on the other.

The `github-runner-egress` systemd unit loads an nftables rule that rejects traffic from `github-runner` to private addresses: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, and `100.64.0.0/10`. Orbit trusts WireGuard source addresses, so this rule keeps a job from reaching the Gateway or another Node as Sabre. DNS still works through the local resolver. A command that a job runs with `sudo` runs as root, and the rule does not cover it. So the runner accepts only code from this repository.

### Self-hosted shard runners

Three more runners take the Gateway shards after the first: `ci-beast-1` and `ci-beast-2` on Beast, and `ci-shark-1` on Shark. They have the labels `self-hosted`, `Linux`, `X64`, and `orbit-shard`, and not `sabre`, so no other job lands on them. The workflow selects them by label, not by name.

Beast and Shark are app-dev Nodes with live Instances and task workspaces. So each runner runs inside an unprivileged Incus container, not on the host. [`tools/ci-runners/setup.sh`](https://github.com/nckrtl/orbit/blob/main/tools/ci-runners/setup.sh) creates every part and repairs its configuration, and each of its steps is idempotent:

- `host SUBNET` creates the Incus project `orbit-ci`, the bridge `orbitci0`, the network ACL `orbit-ci-egress`, the project's default profile, and a `ufw route allow in on orbitci0` rule. Beast uses `10.253.1.1/24` and Shark `10.253.2.1/24`.
- `container NAME` launches the container, sets its `raw.apparmor` line, and installs what is missing.
- `register NAME [TOKEN]` registers the runner and starts its service.
- `verify NAME` probes, as the runner user, the host addresses and ports that a job must not reach.

`container` upgrades no installed package, except the PHP build, which it brings to the recipe. Run it only while the runner is idle, because a repaired `raw.apparmor` line applies at the next restart.

The registration token comes from `ORBIT_RUNNER_TOKEN` or the argument, and the script never stores or prints it. `config.sh` takes the token as an argument, so the token shows in the host's process list for a few seconds.

The project is restricted: containers stay unprivileged, nesting is blocked, virtual machines are refused, and the only network is `orbitci0`. The profile gives each container 4 CPUs, 8 GiB of memory, and 8192 processes, with no devices beyond its network card and root disk.

Ubuntu 26.04's systemd hangs at boot in an unprivileged container without nesting. AppArmor denies its PID 1 and udevd the signals and traces to their own processes, whose labels are stacked. So each container sets one `raw.apparmor` line: `signal peer=@{profile_name}//&*, ptrace peer=@{profile_name}//&*,`. It allows signals and traces only to processes that carry the container's own profile, so it reaches nothing outside the container. Nesting stays off. The project allows container low-level keys only for this line; only `incus-admin` members can set them.

The ACL rejects every egress to private, CGNAT, loopback, link-local, multicast, and reserved IPv4 ranges, and all IPv6. Its default for egress and ingress is reject. So a job reaches the public internet, but not the host's LAN, WireGuard, the Gateway, docker and task bridges, the other container, or the bridge gateway. A container's `127.0.0.1` is its own loopback, so the host's loopback services, such as the Caddy admin API, are out of reach.

Inside each container, the `runner` user runs the runner service, with no `sudo` and no group beyond its own. So a shard leaves out the `privileged` group. The container has the recipe's [PHP build](#one-php-build-in-ci), installed by `bin/php-build install`, Composer 2, Caddy from its own apt source with its service disabled, `acl`, `attr`, `wireguard-tools`, `dig`, Git, and Python 3. `container NAME` copies `bin/php-build`, the recipe, and the remote program tree that `bin/php-build` reads into the container, and runs that install. A later run replaces the copy and updates the build in place. Composer is at `/usr/local/bin/composer`, with a link at `/usr/bin/composer`, because the Composer update program and its tests run that path, as on a Node.

The job installs Node 24 with the Node setup step, and each shard runs at most 4 Pest processes. The container's PHP CLI keeps `zend.exception_ignore_args` on, so the job turns it off only for its own processes: it writes an ini file into the runner's temporary directory and adds it to `PHP_INI_SCAN_DIR`.

Two limits remain:

- The runners are not ephemeral. A job can leave files or processes in its container for the next job. The container is the boundary, and only approved code from this repository runs there.
- Incus accepts DNS on port 53 from `orbitci0` to every host address, before the ACL applies. Closing it needs a host firewall rule.

So a job can query each dnsmasq on the host, such as the task bridge's. The answers reveal task names and addresses that the job still cannot reach, and a query changes nothing.

To remove the runners from a host, stop and uninstall each runner service as root in its container, and run `config.sh remove` as `runner` with a remove token. Then delete the containers, the project, the bridge, the ACL, and the ufw rule:

```bash
token=$(gh api -X POST repos/nckrtl/orbit/actions/runners/remove-token --jq .token)
for name in ci-beast-1 ci-beast-2; do  # ci-shark-1 on Shark
  incus exec "$name" --project orbit-ci --cwd /home/runner/actions-runner -- sh -c './svc.sh stop && ./svc.sh uninstall'
  printf '%s\n' "$token" | incus exec "$name" --project orbit-ci --cwd /home/runner/actions-runner -- \
    su runner -c 'IFS= read -r token; ./config.sh remove --token "$token"'
  incus delete --force "$name" --project orbit-ci
done
incus image list --project orbit-ci -f csv -c f | xargs -r -n1 incus image delete --project orbit-ci
incus project delete orbit-ci
incus network delete orbitci0
incus network acl delete orbit-ci-egress
sudo ufw route delete allow in on orbitci0
```

Docs-lint also checks the ADR lifecycle. A row in the decisions overview's lower table has no file with its recorded slug, and a redirect exists from that exact path. ADRs from 0180 onward have an `In progress.` Status and a `Principle:` line. A lower number follows those rules only when that lower table does not list its number. The open gaps are 0007 and 0020. The committed allowlist of older live ADRs can only shrink. The [contributor guide](/contributor-guide#checks-that-need-no-network) explains these checks.

Each Composer project job caches three sets of files in GitHub Actions cache.

| Cache | Key inputs |
| --- | --- |
| PHPStan result cache, `vendor/phpstan/cache/resultCache.php` | `composer.lock`, `phpstan.neon` |
| Pint and Rector caches, `vendor/pint.cache` and `vendor/rector/cache` | `composer.lock`, `pint.json`, `rector.php` |
| Test-impact graph, `.orbit-tia` | `composer.lock`, `tests/Pest.php`, `phpunit.xml`, `phpunit.xml.dist` |

A run on `main` restores the newest cache for its branch, then any cache for the project. A pull request restores only `main` caches. For the test-impact graph, it first takes the graph of its base commit, then the newest `main` graph. GitHub tries every restore key in a pull request's own cache scope before it looks at `main`, so a pull request saves no cache.

An older cache of the pull request would otherwise shadow `main`'s, and its graph would count every `main` change since it as changed. Runs outside pull requests save each cache only after their checks succeed. Each such run saves its test-impact graph under its own key, so a full run on a commit that already has a graph still replaces the newest one. On `main`, a graph restored from another cache prefix runs the full suite. These caches are separate from the [main caches](#main-caches), and CI never calls `bin/tia-cache`.

A passing project job on `main` also stages the [cache files](#ci-artifacts) for the main cache store and the [SHA-bound Git ref](#ci-cache-refs) used by workspaces.

The separate `Orbit CLI Binary` workflow builds the toolbox binaries on pull requests. It is not part of `Required checks`. After a `CI` run on `main` passes, the `Orbit CLI Release` workflow publishes that commit's binaries as a GitHub release. See [CLI binaries](/reference/cli-binaries).

## Local checks

Run these commands in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. Each project keeps its own dependencies, test configuration, and test-impact graph.

| Command | Result |
| --- | --- |
| `composer test:affected` | Runs the affected tests with test-impact analysis (TIA) in parallel processes |
| `composer check` | Runs `guidance:check`, PHPStan, Rector, and the Pint check. It does not run the test suite. |
| `composer test` | Runs the project suite with TIA in parallel processes |
| `composer format` | Applies Pint's Laravel preset |
| `composer analyse` | Runs PHPStan. The applications use Larastan. |

In `apps/docs`, `composer check` also runs the documentation lint and the API fixture check. `guidance:check` runs the project's guidance tests with a fresh graph separate from the graph that `test:affected` reads. In every project, `bin/guidance-check` creates private temporary directories for the graph and Blade views before Pest starts, then removes them when the command exits. The gate does not depend on `storage/framework/views` existing or on caches written by another user. The check still fails if Boost cannot render guidance or changes the markers required by the hard-stop transformation. The [contributor guide](/contributor-guide#static-analysis) sets the PHPStan level and the rules for fixing findings.

TIA needs PCOV or Xdebug. The first run, or a run without a usable graph, runs the full suite. Later runs select the tests that the changes affect. An explicit path or a partial-selection option such as `--filter`, `--group`, or `--testsuite` turns TIA off for that run. Confirm that the tests of the feature ran. Zero selected tests is not acceptance evidence. Every `tests/Pest.php` stores its graph in the directory that `ORBIT_TIA_DIRECTORY` names, and in `.orbit-tia` by default. E2E scenario runs use the directory in `ORBIT_SCENARIO_TIA_DIRECTORY` instead, which must be under the primary checkout's `.e2e/scenarios/runs/`.

Each project requires the `nckrtl/pestphp-monorepo` Pest fork from its lock file. The fork adds the monorepo and test-impact fixes. To adopt a new release, update the dependency and the lock file in every project, then run the project checks. A runner change invalidates the published graphs, so the first run after it records a fresh graph.

Root `bin/test` runs all five project suites in parallel and splits the processes that [`ORBIT_CHECK_PROCESSES`](#parallel-processes) allows between them. It still reads the older `ORBIT_TEST_PROCESSES` when `ORBIT_CHECK_PROCESSES` is unset.

### Parallel processes

Pest, PHPStan, Rector, and Pint run in parallel processes. One setting sets how many: `ORBIT_CHECK_PROCESSES`. When it is unset, each tool uses the machine's core count, up to 4. An explicit value is used as it is, also above 4. `bin/check-processes` prints the value that the checks use and fails when the setting is not a positive integer.

| Tool | How it reads the setting |
| --- | --- |
| Pest | `--processes=$(../../bin/check-processes)` in the `test` and `test:affected` scripts |
| Pint | `--parallel --max-processes=$(../../bin/check-processes)` in the `format:check` script |
| PHPStan | `tools/phpstan/parallel.php`, which every `phpstan.neon` includes |
| Rector | `->withParallel()` in `rector.php`, with the value from `tools/check-processes.php` |

Each parallel Rector worker keeps its cache in its own directory under `vendor/rector/cache/workers/`. `tools/rector-cache.php` names the directory and removes it when the worker exits. The next run's `--clear-cache` removes a directory that a killed worker left. Rector writes a cache file with a plain copy, and every worker rewrites the same configuration entry when it starts. Workers that share a directory can read a half-written file and fail with a syntax error.

Each PHPStan worker may use up to 1 GB, the `--memory-limit` of `composer analyse`, and a task VM may have 2 GB. That is why the default stops at 4. Lower the setting on a Node, a task VM, or a CI job with little memory, and raise it on a large machine. The candidate gate splits the setting between the projects it runs at once, as [Projects run at the same time](#projects-run-at-the-same-time) describes. CI sets its own Pest process counts, as [Gateway shards](#gateway-shards) describes.

### The candidate gate

Every Orbit task handoff selects affected tests from the subtask's latest passed handoff tree, or its start commit when none passed. This includes rework, final-review fixups, the last subtask, and a group with only one subtask. The Gateway supplies `ORBIT_TASK_CHECK_BASE`.

CI ignores that base when `CI` or `GITHUB_ACTIONS` is set and selects the full branch. The gate accepts a stored tree or an ancestor commit, records the test base, and keeps documentation checks on the full branch. It reuses dependency graphs from a prior passing handoff only when that handoff's tree matches the test base. Missing graphs or changed dependencies still trigger Pest's full-suite fallback.

Root `composer check` runs `bin/review-check`. It checks the working tree as it is, including uncommitted and untracked files. The Orbit Project uses it as its task check, so it also runs at every subtask handoff. It runs these checks.

1. It runs `bin/docs-impact --gate` against the merge base with `origin/main`. The fallback is the merge base with local `main`. Without a merge base, this check fails.
2. It runs `bin/project-vocabulary`, `bin/env-docs`, `bin/tooling-baseline`, and `bin/docs-nav`.
3. It seeds absent quality and test caches with `bin/worktree-cache` and `bin/tia-cache seed`.
4. It checks the five Composer projects, as the next paragraph describes. [Projects run at the same time](#projects-run-at-the-same-time) explains how steps 4 to 8 share the machine.
5. When the candidate changes `apps/web`, `docs/openapi.json`, or `apps/pi-server`, it adds the matching checks, as [Web and Pi server checks](#web-and-pi-server-checks) describes.
6. It checks generated API artifacts when their inputs change, as [Generated artifacts](#generated-artifacts) describes.
7. When the candidate changes the Rust agent, the desktop app, or `.github/workflows/ci.yml`, it runs `cargo fmt --all -- --check` in the matching Rust project.
8. It runs `bin/check-classification-fakes` on changed test sources. A change to the checker itself checks every project’s tests.

When the candidate changes `apps/gateway/resources`, `apps/gateway/tests/Programs`, `apps/gateway/ruff.toml`, or `bin/remote-programs-check`, the gate also runs `bin/remote-programs-check`. See [Remote programs](/reference/remote-programs#lint-and-direct-tests).

For each Composer project, the gate runs `composer validate --strict`, `composer check`, and `composer test:affected`. Its `composer check` runs Pint and Rector only on the files that the candidate affects. [Changed-file quality checks](#changed-file-quality-checks) lists them.

Each affected-test run records into its own copy of the project graph. That copy keeps only the `main` baseline. The gate selects tests for every change since `main`, whatever local `composer test:affected` runs happened earlier. Local runs are unchanged. Each one still writes its branch baseline into the project's own graph, and the gate leaves that graph unchanged.

When the candidate changes the project, the gate runs that project’s architecture tests from CI, including the CLI binary contract. CLI documentation, binary scripts, and workflows also trigger the CLI contracts. The SDK’s existing architecture checks include its request inventory in `RepositoryGuidanceTest`.

On every nonempty candidate diff, the gate runs the E2E topology catalog and the Composer, subprocess-group, and Docs merge-ref configuration contracts. These tests read files across projects. TIA cannot reliably link all those inputs to the tests. Architecture and artifact checks always use the full candidate diff, even at a task handoff.

When that project has changed and `test:affected` selects no tests, the gate runs its full suite with `--no-tia` in parallel processes instead of only warning. A project with changed source files but no selected tests is this case. When `test:affected` passed, the gate looks for changed test files that TIA did not select. It lists the tests of each such file and runs the file without TIA. A file without tests fails.

#### Projects run at the same time

The gate groups its project checks by project and runs the groups at the same time. Each group runs its own commands in order. `ORBIT_CHECK_PROCESSES` is the budget of the whole gate, and the tools of all groups together stay within it. The gate shares the budget in three ways:

1. It runs one more group than half the budget at once. So the largest group can keep at least half of the budget.
2. It gives each command a share by the size of its group's test suites. The share compares the group with the groups that still run.
3. It holds back a command until a process is free. Each command frees its processes at its end.

The largest group starts first. Its later commands get the processes that finished groups freed.

A budget of 2 or less runs one group at a time. The budget counts workers, not each tool's main process. Two groups of PHPStan or Rector at once could outgrow a 2 GB task VM.

With the default of 4, the Gateway's checks run with two processes while two other groups run with one each. Set the budget to `1` to check one project at a time. The gate prints each result when the command ends. The receipt lists the checks and warnings in the gate's own order.

The web checks and the repository checks run in the Gateway's group, after the Gateway's checks. `bin/docs-openapi --check` boots the Gateway application. The other groups share no files that a check writes.

#### Changed-file quality checks

The gate's `composer check` sets `ORBIT_CHECK_SCOPE=changed` and `ORBIT_CHECK_BASE` to the merge base with `origin/main`. `bin/quality-scope` reads them and narrows Pint and Rector. PHPStan keeps its result cache and analyses the whole project, so `composer check` runs it before Rector. The changed files are the PHP files that differ from the merge base: committed on the branch, staged, unstaged, or untracked.

| Tool | Files it checks in the gate |
| --- | --- |
| Pint | The changed PHP files that `pint.json` formats. With none, it logs `Pint: no changed PHP files to check` and passes. |
| Rector | The changed files in its paths, the files that PHPStan records as depending on a changed file, and the files in its paths that PHPStan does not analyse |

Rector reads the dependents from PHPStan's result cache, `vendor/phpstan/cache/resultCache.php`. It still runs with `--clear-cache`. The tool runs on all its files, and logs why, in these cases:

- The result cache is missing or cannot be read.
- Another PHPStan than the installed one wrote the result cache, or its format is not one that `bin/quality-scope` reads. The script lists the formats it knows.
- The result cache does not describe the files as they are now: a file it records has another hash, or a file in PHPStan's paths is not in it.
- The candidate deletes a PHP file that PHPStan analyses or records. After PHPStan runs again, nothing records the files that used it.
- The candidate changes the tool's configuration, `composer.json`, or `composer.lock`.
- The gate has no merge base.

CI, a plain `composer check`, and a run with `CI` or `GITHUB_ACTIONS` set check every file. Only the gate sets the scope. To make the gate check every file, run it with `ORBIT_CHECK_SCOPE=full`.

#### Generated artifacts

Changes under `apps/gateway`, `apps/cli`, or `packages/php-sdk`, to the gate or either API generator, to `docs/openapi.json` or `docs/docs.json`, or to the generated MCP outputs run `bin/docs-openapi --check` and `bin/mcp-tools --check`. These checks generate the expected bytes in memory and print a unified diff when files are stale. They leave the candidate files unchanged. A stale-artifact failure names the exact regeneration command: `bin/docs-openapi` or `bin/mcp-tools`. Regenerate OpenAPI first when both are stale, then run `cd apps/web && bun run types` to update the web schema.

Changes to `docs/openapi.json`, `packages/php-sdk/fixtures`, `apps/web/fixtures`, the fixture checker, or the gate also run `bin/api-fixtures --check` explicitly. Docs’ `composer check` continues to validate the SDK and web fixtures on every run.

The gate checks the local candidate tree. CI still verifies the PR merge tree, browser tests, privileged tests, subprocess suites, Rust clippy/tests, and release builds. These expensive or runner-specific checks remain in CI; the gate samples PHP suites through `test:affected` and checks the fast architecture contracts explicitly.

#### Web and Pi server checks

A change under `apps/web` runs `bun install --frozen-lockfile` in `apps/web`, then `bun run check`, `bun run build`, and a generated-types check in `apps/web`. The build also requires nonempty `dist/index.html` and `dist/version.json`, as CI does. The generated-types check writes `openapi-typescript` output for `docs/openapi.json` to a temporary file and compares it with `src/api/schema.d.ts`. It does not change the working tree. On failure it prints a unified diff and says to run `cd apps/web && bun run types`. A change to `docs/openapi.json` alone runs the install and the generated-types check. A change under `apps/pi-server` runs `bun install --frozen-lockfile`, `bun run check`, `bun run test`, and `bun run build` there. Rust formatting runs for changed Rust projects; the remaining Rust checks run only in CI.

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

The gate writes a receipt, `result.json`, and one log per command in a new `review-*` directory under `orbit-checks/<HEAD>/` in the Git common directory. Each command also prints its duration in seconds, so stored task output shows slow steps without opening the receipt. Cache seeding prints its duration too. The receipt passes only when at least one command ran, every command passed, and the commit and the working tree did not change during the run. When `test:affected` selects no tests for a changed project, the full suite with `--no-tia` is one of those commands. A warning does not replace that run.

Before it writes, the gate removes the `review-*` directories its own Unix user wrote more than a day ago, and `<HEAD>` directories that stayed empty for a day. It cannot remove another user's reports; each user cleans up its own on its next run.

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

`bin/worktree-remove ISSUE` refuses a dirty worktree, a branch that `origin/main` does not contain, and a path that exists but is not a registered worktree. It queues a background cache refresh, releases the worktree's discovery topology, and removes the worktree. Then it deletes the merged branch. A discovery topology holds no review evidence, so removal never waits on it.

## Main caches

Main caches let a new checkout start with warm test-impact graphs and warm Pint and PHPStan result caches. Main CI produces them: each project job that passes on `main` uploads its graph and quality caches, and a background worker imports them. No local command and no deployment runs a test suite, Pint, or PHPStan to fill the caches. Each repository keeps one store in its Git common directory under `orbit-tia/v1`. Linked worktrees share it.

| Path under `orbit-tia/v1` | Content |
| --- | --- |
| `published/<project>.json` | One test-impact graph per Composer project |
| `quality/<project>/<tool>.json` | One Pint or PHPStan result cache per project |
| `requests.json` | Pending refresh requests, the last result per project, and open correctness failures |
| `refresh.log`, `run-*/` | The worker log and the per-run logs |
| `runners/` | The frozen worker scripts |
| `requests.lock`, `refresh.lock` | The queue lock and the worker lock |

Each publication records the tested commit, the CI run, a checksum, and the inputs it is valid for. A new publication must descend from the one it replaces, so older results never replace newer ones.

### CI cache refs

The `publish-ci-cache` job in `ci.yml` publishes workspace test caches after `Required checks` succeeds on `main`. It runs for a main push, scheduled run, or manual dispatch on main, never for a pull request, fork, or other branch. It gathers the five Composer projects' cache exports from the same run and tested SHA. All project jobs, including Gateway privileged and subprocess checks and E2E subprocess checks, must pass before publication. The publisher checks the `required` job's result explicitly and uses `!cancelled()` so skipped PR-only dependencies do not block publication. A failed, cancelled, or incomplete run publishes no ref.

Only this job receives `permissions: contents: write`. It pushes to the repository with its short-lived `GITHUB_TOKEN`; no personal token or Gateway credential is stored in CI. The workflow's other jobs keep their existing permissions. The Gateway later fetches the ref with its GitHub App's repository-scoped `Contents: read` token, through the same protected Git path as source reads. Neither a task VM nor an agent receives either credential.

The job runs `bin/ci-cache-ref publish` to write a parentless cache-only Git commit. Its tree contains no source files or source history, and the job and pushes it to `refs/orbit/ci-cache/<sha>`. The suffix is the full tested code SHA. It is not the cache commit's SHA. The tree groups payloads by repository-relative project path.

Each project has a schema-2 manifest, a Pest graph, and optional Pint and PHPStan caches. The manifest records the tested code SHA, project, CI run and exporting attempt, checksums of all payload files, dependency and test inputs, each tool's configuration, and the PHP minor version. A partial re-run may keep exports from earlier successful attempts of the same run and SHA. [Rector runs without a cache](#ci-caches-stay-separate); this transport does not change that policy.

Before pushing, the publisher verifies that every manifest and graph names the run's tested SHA and that the required project exports are complete. Consumers repeat the SHA and checksum checks; a ref name alone is not evidence that the payload fits. The source of trust is the successful main workflow and repository write access, not the checksums. PHPStan caches contain executable PHP.

A ref is immutable while retained. A retry at the same code SHA keeps an existing valid publication rather than force-pushing it; a conflicting publication fails the publishing job. Publications for different code SHAs use different refs, so an older run finishing later cannot replace a newer commit's cache. Publication failure leaves the code available without a cache ref and records the failure in the publishing job log.

The Gateway fetches the `refs/orbit/ci-cache/*` namespace into its mirror alongside source refs. Cache fetching is optional: a cache-ref miss must not fail the required source fetch. The Gateway sends only the ref for the selected code baseline to a workspace over SSH. [Instance setup](/reference/instance-setup#import-ci-main-caches) owns the baseline selection, compatibility checks, import, and cold fallback. A cache ref for another SHA, even a newer one, is never substituted. The workspace imports Git objects, not an Actions download or a base64 command payload.

### Cache ref retention

The publisher prunes only `refs/orbit/ci-cache/*` after a successful publication. It retains publications for 14 days from publication time and always keeps the newest successful tested main commit's ref, even if it is older than 14 days. Main commit ancestry, not job completion order, identifies that newest commit. Pruning never touches source branches, tags, or other refs. Each successful scheduled main run also performs this cleanup; if CI stops, cleanup stops rather than expiring the last useful publication.

The Gateway removes upstream-deleted refs only from its mirror's cache namespace. It does not delete an active workspace's local cache objects or private tool caches. Ref deletion removes a future fetch opportunity, not already imported caches; an old baseline whose ref has been pruned starts cold. Git garbage collection can reclaim unreferenced cache objects later. This retention is separate from Actions artifact retention, package caches, and machine images.

### CI artifacts

After a project job passes on `main`, CI uploads the artifact `sandbox-tia-<index>-<commit>` and keeps it for 14 days. Pull request runs upload none. These artifacts stage the exports for `publish-ci-cache` and remain an input to the existing background main-cache worker. They are not a workspace transport: the Gateway reads Git refs for `@orbit/ci-caches`, without downloading artifacts or putting their contents in a setup command.

| File | Content |
| --- | --- |
| `graph.json` | The Pest graph. It records the tested commit and a result for every test file it links, whether the job ran the affected tests or the full suite. |
| `manifest.json` | Schema version 2, project path, tested commit, CI run and exporting attempt, graph checksum, checksums of `composer.lock`, `tests/Pest.php`, `phpunit.xml`, and `phpunit.xml.dist`, PHP minor version, and the checksum and configuration checksum of each quality cache |
| `quality/pint`, `quality/phpstan` | The Pint and PHPStan result caches that `composer check` wrote in the same job, when it wrote them |

### Seed a checkout

Seeding copies a publication into an absent private cache and never replaces an existing one. `bin/tia-cache seed` copies test-impact graphs. `bin/worktree-cache` copies Pint and PHPStan caches. Bootstrap and the candidate gate run both. A missing or incompatible publication leaves that project cold, and seeding does not fail.

| Cache | Compatible when |
| --- | --- |
| Test-impact graph | The project's `composer.lock`, `tests/Pest.php`, and Pest fingerprint match, the checksum matches, and the tested commit is an ancestor of `HEAD` |
| Pint or PHPStan cache | The project's `composer.lock`, the tool's configuration, and the PHP minor version match, the checksum matches, and the tested commit is an ancestor of `HEAD` |

[Importing a CI cache ref](/reference/instance-setup#import-ci-main-caches) also checks the runtime facts that each tool compares.

The Pest fingerprint holds checksums of the dependency and test configuration files and the PHP minor version, so a graph that CI recorded fits a local checkout with the same inputs. When no quality publication fits, `bin/worktree-cache` copies the cache from the primary checkout when that checkout is clean on `main` and has the same lock file and configuration. Feature worktrees are never a source.

When `ORBIT_MAIN_CACHE_STORE` is set, a checkout seeds only from that store, even when it has no publications, and `bin/worktree-cache` skips the primary-checkout fallback. Bootstrap uses this variable only while seeding. When the variable is unset, a checkout seeds from its own store when that store has publications, and otherwise from the store registered for its origin on the same machine.

The registration is a link at `$XDG_STATE_HOME/orbit/main-cache-stores/<key>`, and `$XDG_STATE_HOME` defaults to `~/.local/state`. The key comes from the origin URL, so the HTTPS and SSH URLs of one repository share it. Each publication registers its store unless another live store already holds the registration. `bin/tia-cache register` takes the registration for the current repository.

When `ORBIT_MAIN_CACHE_STORE` is unset and the store has publications that lag the checkout's fetched `main`, seeding queues a background refresh for the lagging projects. It does not queue a project whose last refresh failed at that commit or at a later one.

Orbit task workspaces are linked worktrees of Orbit's `default` repository, starting at the last commit that [deployed](/reference/deployments#development-defaults) there. They share its main cache store but keep private tool caches. `bin/bootstrap` and `bin/review-check` seed those private caches from the publications. A Project setup step reflinks each dependency tree and private cache from `ORBIT_SEED_PATH` first.

### Refresh from CI

`bin/tia-cache refresh --background` records a request and starts one worker when none runs. It returns at once. `bin/worktree-remove` queues it when it removes a merged worktree, and seeding queues it when the store lags. Cache freshness never holds worktree creation, a merge, or cleanup.

The worker uses `git` and the GitHub CLI with the login of the user who runs it. That `gh` login needs read access to the repository and its Actions. For each batch of requests, the worker:

1. fetches `main` from `origin`;
2. reads the newest 30 finished `CI` runs on `main`: pushes, nightly runs, and manual runs;
3. reads the conclusions of each project's jobs in those runs;
4. finds the newest commit on which the project's jobs passed;
5. downloads, checks, and publishes that commit's graph and quality caches.

The worker skips the download when the store already holds that commit. It ignores pull request runs and runs from forks. The Gateway has three jobs: `Gateway`, `Gateway subprocess`, and `Gateway privileged`. E2E has two, `E2E` and `E2E subprocess`. The other projects have one job each. A failure in any of a project's jobs counts as a failure of the project.

Before it publishes, the worker checks that:

- the manifest names the project, the run, and the commit of that run;
- the test configuration checksums match the files at that commit;
- the graph checksum matches, and the graph describes that commit, holds only passed results, and uses relative paths;
- each quality cache matches its checksum.

The published graph keeps the fingerprint that CI recorded, and its runner identity comes from the lock file and `tests/Pest.php` at the tested commit. A quality publication records the lock file and tool configuration at the tested commit, and the PHP minor version of the CI job. The worker deletes the downloads after each batch.

The worker runs a frozen copy of `bin/tia-cache` as `origin/main` of the store's repository holds it, never the caller's copy. So a clone of an unmerged change only records requests, and it cannot change how the shared store is maintained. Without the tool on that `main`, no worker starts.

Requests stay in `requests.json` until the worker records their outcome, so an interrupted worker leaves them pending. Repeated requests for the same target combine. The worker runs in a new session, so removing the calling worktree does not stop it. It runs at reduced CPU priority, and each command stops after 10 minutes. After each batch, it deletes the run logs that no recorded result names. It removes variables that start with `ORBIT_`, `APP_`, or `DB_`, and `DATABASE_URL`, `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION`, from the commands it runs. It keeps `TMPDIR`, `TMP`, and `TEMP`.

For Orbit, the store lives under the stable `default` repository's `.git/orbit-tia/v1` on `/fast`. Register it with `bin/tia-cache register --repository=/fast/apps/orbit/default`. The managed user that owns the store runs the worker, so its `gh` login must read `nckrtl/orbit`.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches, and queues a refresh when the store lags |
| `bin/tia-cache refresh --background` | Queues a refresh and starts a worker when none runs |
| `bin/tia-cache refresh` | Queues a refresh, runs main's copy as the worker in the foreground, and exits nonzero when a requested project has a failure |
| `bin/tia-cache status` | Prints the publications and the maintenance state |
| `bin/tia-cache status --json --remote` | Reads `main` from the remote and prints the maintenance state as JSON, without changing anything |
| `bin/tia-cache register` | Registers this repository's store for its origin |
| `bin/tia-cache import-ci` | Validates and imports one project's CI cache files into private caches without publishing them; workspace files come from the selected Git ref. |

`import-ci` replaces an existing private graph only when CI tested a descendant of that graph's main commit. It exits 3, with the reason, when the payload does not fit the checkout: another test configuration, or a commit outside its history. The project's tests then run cold. Workspace imports also require the exact [selected-ref SHA match](/reference/instance-setup#import-ci-main-caches); an ancestor check alone cannot authorize a different ref. Every cache command accepts `--repository=PATH`. `seed` and `refresh` accept repeatable `--project=apps/docs` options, and they cover all five projects by default.

### Failures on main

`bin/tia-cache status --json --remote` reads the remote `main` and prints the maintenance state. `current` names the projects whose graph publication is at that `main`. The output also has the worker lock state, the pending requests, each project's last result with log paths, the failed projects in `failures`, the open correctness failures, whether a refresh is `needed`, and `refresh_log`. A successful status command reports state. It does not mean that main passes.

The worker sorts failures into two kinds.

| Kind | Cause | Effect |
| --- | --- | --- |
| Check failure, tool `ci` | A job of the project failed or timed out in a finished `CI` run on `main` | A correctness signal on main. It stays open until a run that finished later passes the project's jobs on the same commit or on a descendant. |
| Maintenance failure | `git` or `gh` failed, the newest 30 runs have no finished job for the project, or the artifact is missing or refused | Checkouts keep the previous publications. It never sets or clears a check failure. |

A check failure records the commit, the run ID and URL, and the failed jobs. While it is open, the newest commit on which the project passed stays published. When a nightly full run fails after an affected-only run of a newer commit passed, the failure stays open, because that affected run did not run the test that failed. Diagnose a check failure on the failed commit. Fix it, or revert the change that caused it, through a reviewed pull request. The next passing CI run on `main` clears it at the next refresh.

Earlier versions of `bin/tia-cache` ran the checks locally and recorded failures with tool `tia`, `pint`, or `phpstan`. The first refresh that reads the project's CI history replaces them.

## Why it works this way

### The maintainer approves every merge

The maintainer decides whether a feature belongs in Orbit. Turning on [review and merge](/reference/tasks#review-and-merge) for a Project is that decision for every task and listed pull request of the Project.

For named Orbit task work, the maintainer can delegate final review and merge. That delegation is the consent to submit the formal GitHub approval and to merge the reviewed commit. The review records the reviewed head, the evidence, and the verdict in GitHub's review state. A plain comment is not that approval, because its prose does not distinguish approval from requested changes. A Tasks engine subtask approval, or any other agent review, does not replace the final review of the whole pull request and does not authorize unrelated work.

The merge uses the maintainer's GitHub CLI profile. That admin profile bypasses GitHub enforcement of `Required checks`, and GitHub does not require an approving review, so the DevOps reviewer checks the formal approval and green CI before merging the reviewed commit. A ruleset change that requires an approving review is deferred: it needs new credentials, API behavior, and deployment work. The App merge of [review and merge](/reference/tasks#merge-on-green) has no admin bypass, so the ruleset binds it. It runs only for Projects that opt in.

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

### Only the newest waiting main commit runs

Pushes to `main` share one concurrency group. A running run always finishes, and a newer push replaces the waiting one. An automatic Gateway release ships only a commit with its own successful `Required checks` run, so a replaced commit never ships by itself. It ships inside the next green commit, which descends from it and whose affected tests cover its changes.

A group per `main` commit is a rejected alternative. It gave every commit a result, but during a burst of merges every commit queued a full run on the runners on Sabre, and the newest commit, the one a release ships, ran last. On 2026-10-08 about a dozen merges in 15 minutes delayed the release by 30 to 40 minutes.

The cost is a smaller fallback when the newest run fails. The release resolver examines at most 20 commits, and a replaced commit has no result to fall back to. After a burst of more than 20 merges whose newest run fails, auto-release waits for a fix to merge and pass. A manual re-run of an older `main` run also joins the shared group, so it replaces a waiting run.

### Main caches come only from clean main

A feature graph can hold unmerged code, failed tests, or working edits, so it cannot prove the state of main. Only a job that passed on main publishes, and each checkout writes to its own private copy. One writable graph shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results. Recording every new worktree from scratch is also rejected, because unchanged projects would repeat the full run.

### Only main CI publishes

CI already tests every `main` commit, so its graphs and quality caches cost nothing extra. A deployment never runs a test suite.

Running the suites on a development machine to fill the store is a rejected alternative. It repeated the work of CI, competed with other agents for the processors, and inside a deployment it exceeded the deployment's deadline. Its results also depended on the host: a test that passes in CI failed on a host with other file ACLs, and that failure held merges as a correctness failure on main. Publishing the caches of a clean bootstrap on `main` is rejected for the same reason, and because two producers would race for one store.

Pest's own baselined mode fetches one artifact per repository with `gh`. Orbit has one artifact per Composer project and a store that many worktrees share, so the worker imports the artifacts into that store instead. A separate cache service is also rejected, because GitHub already keeps the artifacts and linked worktrees already share one store.

### Maintenance runs in the background

Waiting for every refresh before a new worktree or a merge would serialize delivery behind maintenance. A checkout starts from the newest compatible publication while the worker catches up. The cost is that a failure on main can surface after another feature merges. One worker per repository covers all five projects, so they share one list of runs and one download per run. A failed download or API call is not a source regression, so only a failed CI job counts as a correctness signal.

A failure stays open until a run that finished later passes on that commit or a descendant. Clearing it at the next passing commit is a rejected alternative: a push after a nightly failure can pass with affected tests that never ran the failing test.

### Clones find the store through a registration

The Gateway provisions a task workspace as an independent clone, and it runs checks for every Project without knowing one repository's cache layout. So the repository's own scripts find the store through a link in the user's state directory. Setting `ORBIT_MAIN_CACHE_STORE` in the Gateway or in Project setup steps is a rejected alternative, because a fixed path breaks when the primary checkout moves. A global Git setting is also rejected, because it needs a manual step on every machine and goes stale without notice.

### Main runs test what changed since the newest main graph

Most merges change a small part of a project, and the full Gateway suite took most of each `main` run. The newest `main` graph already holds a result for every test, so a push needs to run only the tests that the changes since the graph's commit affect.

Comparing with the parent commit is a rejected alternative: when runs overlap, the restored graph can be older than the parent, and the merges between them would go untested. Reusing the results of the pull request run is also rejected, because `main` can differ from the tested pull request head, and GitHub does not let `main` read caches that pull request runs saved.

TIA cannot link every file to its tests. `bin/ci-tia` treats a change as visible only when it can show that TIA links it, and runs the full suite otherwise. Listing only the paths known to be invisible is a rejected alternative, because a new kind of input would then skip its tests silently.

TIA does not link a test to the code that it runs in a PHP subprocess. So a run that is not full also runs the `subprocess` group, and a contract test keeps each test that starts PHP in that group. Running those test files by path is a rejected alternative, because Paratest takes one path per run, and about fifty files would each pay its startup time. Leaving these tests to the nightly full run is also rejected, because a broken `main` commit can then ship before the nightly run fails.

The group costs about two minutes on the Gateway and E2E jobs. So a run skips it when no change reaches the project, with the rules that `bin/ci-tia` already uses for unrelated paths. Running the group on every pull request and affected run is a rejected alternative, because a web-only or docs-only change would then wait for Gateway tests that cannot fail from it. Docs and E2E tests read files across the repository, so these projects run the group on every change.

Some misses remain:

- A wrong entry in the list of unrelated paths can skip a test.
- A test that starts PHP only through project code, without naming the PHP binary, is not in the group.
- A test that runs a PHP script directly, through its `#!/usr/bin/env php` line, is not in the group. The contract does not resolve script paths.
- The contract does not treat `vendor/bin/pest` as a marker, because most tests name it as data in a command.

The nightly full run finds such a miss within a day, and its failure switches pushes back to full runs until a full run passes again.

### Large Gateway selections run in shards

A pull request that changes a Gateway boot file selects nearly every Gateway test, and that one job took about 13 minutes while every other job ended within 4. One Sabre runner and three shard runners on Beast and Shark run four shards at once. Three shards on Sabre's three runners are a rejected alternative: they shared one 6-core host, so the gain under load was about 2.5 minutes, and they took every Sabre runner from other runs.

Pest's own `--shard` option is a rejected alternative: it adds a `--filter`, and Pest turns TIA off for a filtered run, so each shard would run a part of the whole suite. Splitting the files of the selection in each shard job is also rejected, because each job restores the graph at another moment and could select other tests. One plan job lists the selection once, and every shard runs a part of that list.

Making every project job wait for the plan is rejected, because it would delay each pull request's other jobs by the plan's half minute. Sharding on `main` is rejected for now: Pest writes the graph in one run, and partial graphs would need a merge that Pest does not offer.

### CI caches stay separate

Hosted CI keeps its caches in GitHub Actions cache, keyed by branch and inputs. Each run starts from a fresh runner, so it cannot read the repository store. On `main` it uploads the artifacts that the store imports. Pint and PHPStan key their caches on paths relative to the project, so their CI caches work in any checkout.

CI does not restore or save Rector's cache. Every project's `composer rector` uses `--clear-cache`, including runs through `composer check` in CI and the local review gate. Rector's unchanged-file cache does not track all class dependencies: a parent-only property change can leave an unchanged child's missing `#[Override]` undetected. Clearing the cache trades warm-run speed for a complete check of the configured Rector paths; Pint, PHPStan, and Pest keep their existing caches. The candidate gate narrows the files instead of keeping a cache, as [The gate checks changed files](#the-gate-checks-changed-files) explains.

The regression in `apps/docs/tests/Unit/ToolingBaselineTest.php` warms the cache with an empty parent and a child that declares `public string $name = '';`, then adds that same property only to the parent. Before the fix, the second check exits 0 without reporting the child. With the project's check command, it exits 2 and proposes `#[\Override]` on the child's property. The dry run leaves the child unchanged.

Gateway timings were measured in the task workspace on Linux x86_64, with four CPUs, PHP 8.5.11, and Rector 2.6.7. They were 126.24 seconds for an uncached run and 0.65 seconds for a warm run before the fix. After adding `--clear-cache`, `composer rector` took 154.77 seconds on the first run and 118.92 seconds on the repeat. These are wall-clock samples, not a performance guarantee: checks now pay the full analysis cost on every run.

### The gate checks changed files

A new Orbit Instance or task VM starts at a `main` commit that CI already checked. Before this change, `composer check` there took about 10 minutes, almost all of it in the quality tools. Rector ran in one process without a cache, and Pint ran in one process. In the Gateway, Rector took about 155 seconds and a cold Pint about 288 seconds.

The gate now checks with Pint only the files the candidate changes, because Pint judges each file on its own. Rector's findings in a file can depend on other files, such as a parent class. So Rector also checks every file that PHPStan records as depending on a changed file. PHPStan records these dependents for its own result cache, and the gate runs PHPStan first, so the record describes the files as they are. A record that does not match the files makes Rector check everything. The gate never checks less than it can prove covers the change.

CI checks every file. It tests the merge result of a pull request, and its result decides the merge. The gate gives fast feedback at each handoff.

All four tools run in parallel processes. The old limits of two PHPStan and Pest processes had no recorded reason. Rector ran in one process, and parallel workers that share its cache directory can fail on a half-written cache file, so each worker now has its own directory. With 16 processes on a 16-core machine, a full Rector run in the Gateway takes about 32 seconds with the same findings, and a cold Pint run about 37 seconds.

Memory is the real limit. A task VM with 2 GB cannot run 16 PHPStan workers, so the default stops at 4, and the gate shares that budget between the projects it runs at once instead of giving each project the whole budget. The Gateway's suite is the largest, so it gets the largest share. An even split once left its full-suite fallback with one process for half an hour. `ORBIT_CHECK_PROCESSES` changes every tool at once.

### Findings are fixed, not silenced

A PHPStan ignore, a baseline, or a silencing cast keeps the wrong type in the code, and new code can repeat it. Fixing the value at its source lets CI catch these errors before review, so a reviewer spends the review on behavior. The [contributor guide](/contributor-guide#static-analysis) lists the rules.

### Shared-worker feedback

Shared Orbit task checks exclude the Gateway `privileged` test group. These tests need host sudo or another Unix identity and always run in the required Gateway privileged CI job. The task test wrapper keeps Pest TIA enabled and uses a separate feedback cache. CI ignores the feedback setting. VM checks run the privileged tests as the managed user. Check report directories permit group reads so the worker can inspect failures.
