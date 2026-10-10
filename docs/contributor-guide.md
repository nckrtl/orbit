---
title: "Contributing to Orbit"
sidebarTitle: "Contributor guide"
description: "Prepare architecture and documentation, build a feature, and submit a complete pull request."
covers:
  - .agents/skills/reviewing-pull-requests/references/test-*.md
  - "{composer,vet}.json"
  - bin/{bootstrap,test,pest-plain,review-check,bug-repro,task-group-check,pr-head-check,deploy-verify,docs-merge-check,dependency-audit}
  - "{apps/*,packages/php-sdk}/{composer,vet}.json"
  - "{apps/*,packages/php-sdk}/phpstan.neon"
  - apps/gateway/tests/Support/{LinuxHost,TestToolchain}.php
  - apps/docs/**
  - .github/{dependabot,workflows/ci,workflows/dependency-audit}.yml
---

# Contributing to Orbit

Submit a complete feature: its implementation, tests, documentation, and any architecture decisions. Orbit reproduces the feature on Incus during independent review, and the maintainer approves each merge.

The order is always the same: architecture, documentation, implementation, a complete pull request, review, maintainer approval, and merge.

From the repository root, install the project dependencies:

```bash
bin/bootstrap
```

## 1. Check the architecture

Read the [mission](/mission), the [architecture](/architecture), and the [concepts](/concepts). Then read the documentation, code, and tests of the parts your feature changes. The documentation is the current truth about Orbit, and each page explains its design in a "Why it works this way" section. [Architecture decisions](/decisions/overview) holds the decisions that are not built yet.

Find the pages for a component with `composer docs-context -- --component=apps/cli`. `--concept=Cluster` selects pages by concept. The command returns an ordered reading list. It does not decide what the feature must do.

When the feature makes a significant architecture decision, draft an architecture decision record (ADR) on the same branch. A decision is significant when it sets a contract between components, an architecture boundary, a security or ownership model, or a choice that is costly to reverse. Explain the alternatives and the consequences, and name the mission principle it serves. Implementation details stay in code and tests.

Mark the draft `In progress.` Reviewers assess it with the implementation and the documentation. The pull request that completes the decision absorbs it into the owning page and retires the record, so the documentation stays the single source of truth. The [ADR guide](https://github.com/nckrtl/orbit/blob/main/docs/decisions/README.md) covers the steps, numbering, and format.

## 2. Write the documentation

Update the pages under `docs/` before you write code. Describe what a user can do, the limits, and what happens when an operation fails. Write in the present tense, and check that the pages and the proposed ADRs agree. The documentation ships in the same pull request as the behavior it describes.

Update the documentation when the feature changes behavior, terms, architecture, a public or operational contract, or knowledge that another contributor needs. A fix that restores documented behavior, or a refactor that changes no behavior, can leave the documentation alone. Do not write prose only to produce a documentation change.

When an ADR, a page, the code, or a test disagree, stop and report the conflict. Do not resolve it by quietly changing one of them.

Run the deterministic impact check for the group's start commit and every planned path, including paths that do not exist yet. [Docs impact stays in the repository](#docs-impact-stays-in-the-repository) explains why Orbit owns this check:

```bash
bin/docs-impact --base <start-commit> --paths <planned-path>
```

The start commit is the merge base with `origin/main`; repeat `--paths` for every planned path in the brief, including paths that do not exist yet. Every Orbit group starts with this docs subtask, as required by the [Orbit Tasks policy](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md). A `docs_required` report means the subtask updates each impacted page or runs its named generator. A `no_docs_change` report is the fast path: hand off the complete JSON report as evidence. The reviewer confirms the planned paths and report are complete before implementation starts. The report, not an agent's opinion, is the evidence.

At every subtask handoff, Orbit's own task check runs the impact check against the candidate diff from the group's start commit. An impacted page missing from that diff fails with the list of pages.

A reviewer-confirmed waiver is a `page: reason` line in `.git/orbit/docs-unaffected/<branch>.txt`, named after the checked-out branch. Linked worktrees use the primary checkout's `.git`. The gate reads only the current branch's file, and a detached HEAD has none. The file is never committed, so a waiver never reaches `main` or another branch. The gate's log lists the waivers it accepted under `exceptions`, and the reviewer confirms each one. Required generator checks still apply. This policy runs through Orbit's `composer check` and `bin/review-check`; the generic Gateway task engine does not know about docs-first or documentation conventions. Jev is out of scope for this version.

Run from the repository root:

```bash
composer docs-build
composer docs-lint
```

Commit `docs/generated/context.json` when `docs-build` changes it. For Mintlify page or navigation changes, also run `npx mint validate` and `npx mint broken-links` from `docs/`.

## 3. Implement and verify

Build the feature and its tests against the documented behavior. Keep the in-progress ADRs and the documentation in line with what the implementation delivers. Explain any change of direction in the pull request.

`composer test:affected` selects tests with Pest test-impact analysis (TIA), which needs PCOV or Xdebug. Without a coverage driver, TIA is skipped and every test runs. On macOS, install PCOV with `brew install shivammathur/extensions/pcov@8.5`. Every project sets Composer's `process-timeout` to `0`, so Composer never stops a long test or check run.

CI uses different test selection for pull requests and pushes to `main`. Pull requests run the TIA-selected tests plus every architecture test, so TIA cannot omit architecture checks when a new file has no coverage links yet. They also run the `subprocess` group of each project that the change reaches. TIA does not link a test to the code that it runs in a PHP subprocess, so a test that starts PHP, such as `artisan` with `PHP_BINARY`, declares `pest()->group('subprocess')` at the top of its file. A contract test fails when such a test lacks the group.

A push to `main` runs the tests affected since the commit of the newest `main` graph and records that graph for the pushed commit. It runs the full suite with `--tia --fresh` when that graph is unusable, or when a change is one that TIA cannot link to tests, such as a script, a data file, or a file outside the project. An affected run also runs the architecture tests, and the `subprocess` group when the change reaches the project. A nightly run and a manual dispatch always run the full suite.

The [CI reference](/reference/implementation-loop#ci) lists the rules. Orbit's task gate, `bin/review-check`, also runs the architecture tests of each project that the candidate changes. The [feature delivery reference](/reference/implementation-loop#the-candidate-gate) lists its steps.

The `test` and `test:affected` scripts in each PHP project, and root `bin/test`, pass `--colors=never` to Pest. `bin/pest-plain` strips any ANSI control sequences that remain. The output has no ANSI escape codes and still ends with the `Tests:` summary. The scripts do not pass `--no-progress`, because parallel Pest then omits that summary. Keep the flag on the scripts. `phpunit.xml` is a TIA input, and a change to it rebuilds the test impact graph.

Run these commands in each changed project, such as `apps/cli`:

```bash
composer test:affected
composer check
```

Gateway tests run the shell programs that Orbit installs on Ubuntu Nodes. On macOS, install the Linux tools they need with `brew install bash coreutils gnu-sed findutils caddy`. The test bootstrap puts these tools first on `PATH`, supplies `setsid` and `flock`, and stops with the missing package names when a tool is absent. The test application reads the tracked `.env.example`, not your `.env`. Child processes that boot the Gateway, such as `artisan` calls, still read `.env` when it exists.

Tests of Node programs that use Linux kernel interfaces, such as `/proc/net/tcp` or `os.O_PATH`, run on a Linux test host. The host is beast, an Ubuntu machine like the Nodes, or the SSH host that `ORBIT_LINUX_TEST_HOST` names. On Linux, such as in CI or on beast, these tests run directly. The host must accept `ssh` without a prompt, or these tests fail.

Each test process copies `apps/gateway` to the host with rsync once and reuses the copy. The copy holds only the files that Git would track, as `git ls-files --cached --others --exclude-standard` lists them, and `vendor/`. So ignored files such as keys, logs, caches, and databases stay local, and no `.env` file except `.env.example` leaves your machine. The copy lives in a mode 700 directory under `/tmp/orbit-gateway-linux-tests-<uid>`. The process removes its copy when it ends, also on Ctrl-C or `SIGTERM`. The host stops a test that runs longer than five minutes, or `ORBIT_LINUX_TEST_TIMEOUT` seconds. A later run removes the copies that a killed process left, after six hours.

Each Gateway test process keeps its fixtures in its own directory under `TMPDIR`, `orbit-gateway-tests-<pid>-<random>`, and removes it when it ends, also on Ctrl-C or `SIGTERM`. A later run removes the directories of killed processes. E2E test processes keep their temporary paths in `orbit-e2e-tests-<random>` and remove it when they end. So a suite run leaves nothing in `TMPDIR`. Create fixtures under `sys_get_temp_dir()`, not a fixed `/tmp`, unless the test needs a short Unix socket path or a path that a Node program fixes. On `app-dev` Nodes, the [tmpfiles rule](/reference/node-provisioning#converge-the-orbit-footprint) empties `/tmp/orbit-*` and `/dev/shm/orbit-*` directories a day after their contents last changed.

Add regression tests for behavior changes and their important failure modes. Confirm that the tests that exercise the new behavior ran. Temporary Git repositories in DocsImpact tests disable automatic garbage collection and maintenance before the first commit, so background Git processes cannot write pack files during fixture cleanup.

Use the [delivery-line commands](/reference/delivery-line) to prove a bug on current main, to validate a task-group payload, to check the current pull-request head, and to verify post-merge live state. They print one JSON object, exit nonzero on failure, and do not file, merge, deploy, or roll back.

GitHub CI runs quality checks and affected tests for all five projects, including documentation lint. Root `composer check` runs `bin/review-check`. It runs `composer validate --strict`, `composer check`, and `composer test:affected` in each of the five projects. It checks the working tree as it is, uncommitted changes included, and writes a report under `<git-common-dir>/orbit-checks/<HEAD>/`. For changed paths it also runs the web and Pi server CI profiles, every changed Pest file that the affected selection missed, and a PHP finding pack. A missing tool fails its check. Orbit's Project task check runs this gate at every task handoff. [The candidate gate](/reference/implementation-loop#the-candidate-gate) lists every check.

## Audit tests and unused code

Routine test review uses each project's testing guidance and the existing reviewer skill. For a requested suite cleanup or unused-code sweep, use the reviewer's [optional audit procedure](https://github.com/nckrtl/orbit/blob/main/.agents/skills/reviewing-pull-requests/references/test-audit.md). It requires evidence for removals, named retained coverage, and checks that repaired assertions catch the intended defect. It does not add a mandatory PR gate. A script that checks test quality remains a proposed follow-up.

## Static analysis

PHPStan checks each PHP project during `composer check`, at level 9. The projects are `apps/gateway`, `apps/cli`, `packages/php-sdk`, `apps/e2e`, and `apps/docs`. Laravel projects use Larastan. The SDK uses PHPStan directly.

Fix the code that PHPStan reports, so that the declared type and the runtime value agree. Do not clear a finding with any of these:

- an ignore, including `@phpstan-ignore`, or a baseline
- `assert()` or an inline `@var` that overrides the inferred type
- a cast that only silences the finding
- a wider type that hides the finding

The finding pack also rejects a call to `strtotime()`, so use Carbon parsing. In a test, chain `preventStrayClassifications()` to every `Classification::fake()` call.

The CLI and E2E projects each keep one counted `ignoreErrors` entry for a Larastan finding on an inherited command helper. Each entry names the commands, the option, the file, and the count. Do not add an entry or raise a count.

## Dependencies

Manual updates take only releases that are at least three days old:

- Composer: the `laravel/vet` plugin skips releases younger than `minimum-release-age` days, as each project's `vet.json` sets.
- Bun: `bunfig.toml` sets `install.minimumReleaseAge` to 259200 seconds in `apps/web`, `apps/pi-server`, and `apps/desktop`.

The wait applies only when Composer or Bun resolves a version. `composer install` and `bun install --frozen-lockfile` install the locked versions. Vet keeps no trust entries, so it prints a notice on each install.

Update only the packages that you need, for example `composer update vendor/package --with-dependencies` or `bun update package`. Review the lockfile diff and run the checks.

Dependabot is the automatic updater. Every entry in `.github/dependabot.yml` sets `cooldown.default-days: 7`, so its daily pull request proposes only releases that are at least seven days old. Security updates through Dependabot are not delayed.

When a published advisory has a fix, link the CVE or GHSA advisory in the pull request, update the package, run `composer audit:dependencies` and the checks, and get a review. If the fixed release is younger than three days, add its exact package name to `minimum-release-age-exclude` in `vet.json`, or to `install.minimumReleaseAgeExcludes` in `bunfig.toml`. Remove the exclusion when the release is three days old.

If an advisory has no fix, do not hide it. Record it in the pull request with the advisory link and the reason that the risk is acceptable.

From the repository root, `composer audit:dependencies` runs `composer audit --locked` for each Composer lockfile and `bun audit` for each Bun lockfile. The Dependency audit workflow runs it every night and on manual dispatch.

## 4. Submit a complete pull request

Explain the problem, the resulting behavior, the architecture decisions, the documentation changes, the verification results, and the remaining limits. Link an issue when one exists.

Request maintainer review when the feature is complete. You can use a draft pull request while you work. Mark it ready when the implementation is complete.

## Review and merge

Orbit's independent reviewer checks the code, the documentation, and the ADRs, and reproduces the feature on an [Incus topology](/reference/incus-topologies). The reviewer records the commit, the environment, the actions, the results, and the limits. The [feature delivery reference](/reference/implementation-loop) lists that evidence.

Address the review findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. A merge needs passing CI, a successful independent code and Incus review, resolved findings, and the maintainer's approval.

For Orbit task pull requests whose final review and merge the maintainer delegates, a Tasks engine subtask approval is not the final review of the whole pull request. The DevOps reviewer submits a formal GitHub `APPROVED` review for the exact head and merges that commit through the maintainer's GitHub CLI profile.

The review body records the full head SHA, the checks and results, limitations, evidence links, and the verdict. A plain comment alone does not satisfy the gate. Follow the [final review workflow](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request) for the delegated consent, the reviewer's identity and `commit_id`, successful `Required checks` on that head, the immediate merge with `--match-head-commit`, and a new formal approval after the head changes.

## Use an agent

The skills in the repository guide an agent through the work.

| Task | Skill |
| --- | --- |
| Shape the feature and prepare its ADRs and documentation | [grill-with-docs](https://github.com/nckrtl/orbit/blob/main/.agents/skills/grill-with-docs/SKILL.md) |
| Split an agreed feature into Orbit Tasks | [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) |
| Implement, verify, and submit the feature | [developing-features](https://github.com/nckrtl/orbit/blob/main/.agents/skills/developing-features/SKILL.md) |
| Review a proposal or a completed pull request | [reviewing-pull-requests](https://github.com/nckrtl/orbit/blob/main/.agents/skills/reviewing-pull-requests/SKILL.md) |
| Merge an approved pull request and clean up | [merging-pull-requests](https://github.com/nckrtl/orbit/blob/main/.agents/skills/merging-pull-requests/SKILL.md) |

For focused work, use [writing-documentation](https://github.com/nckrtl/orbit/blob/main/.agents/skills/writing-documentation/SKILL.md), [verifying-cli-output](https://github.com/nckrtl/orbit/blob/main/.agents/skills/verifying-cli-output/SKILL.md), [verifying-web-ui](https://github.com/nckrtl/orbit/blob/main/.agents/skills/verifying-web-ui/SKILL.md), or [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md). CLI work follows the [CLI design standard](/reference/cli-ux) and the [command vocabulary](/reference/cli-command-vocabulary). A web UI change follows [web verification](/reference/web-verification).

An independent reviewer can review the proposed ADRs and documentation before coding, on request.

## Report a problem

Use [GitHub issues](https://github.com/nckrtl/orbit/issues) for bugs and questions. Include the source commit, the Orbit version, the operating system, the exact command, the expected result, and the observed result. Remove credentials, environment values, private keys, tokens, and personal data from shared logs. Include a request ID and a stable error code when you have them.

## Why it works this way

These reasons explain the process. Check them before you propose a change.

### Decisions ship with their feature

A pull request carries the ADR, the implementation, and the documentation together, and absorbs the ADR when it completes the decision. The maintainer then reviews a decision with the code that shows its consequences. Merging ADRs before their implementation is a rejected alternative, because it separates the decision from the evidence. A required plan in every pull request is also rejected, because the implementation, the documentation, and the decisions already describe the feature.

### Review reproduces on a discovery topology

Orbit's reviewer reproduces the feature on a fresh [discovery topology](/reference/incus-topologies) and records the evidence. Contributors do not have to run Incus before they submit, because they may not have the environment and Orbit owns the machine review. External contributors and internal automation meet the same review and merge standard.

A separate proof run for each candidate is a rejected alternative. That flow kept a proof plan and fixtures beside each branch, captured immutable evidence on a proof topology, kept those machines through review, compared later commits by recorded inputs, and promoted the proof topology into the snapshot at closeout. It doubled the delivery steps and held machines through review. The ownership and cleanup rules of that flow still apply to every topology.

### One documentation corpus

All maintained documentation lives under the root `docs/` directory, for humans and agents alike. `apps/docs` holds only the tooling: the lint rules, the context index builder, and their tests. A second content tree would drift from the first.

### Docs impact stays in the repository

The Gateway Tasks engine stores work, deliverables, and lifecycle state. Each Project supplies its own task check and repository policy. Orbit keeps `bin/docs-impact` and its extraction logic in the repository, and enforces the result through `composer check` and `bin/review-check`. Putting docs-first rules in the Gateway is rejected, because it would couple a generic engine to Orbit's documentation, ADRs, and tooling.

A deterministic report makes the docs-first decision repeatable. It combines changed paths with planned paths, maps public and operational surfaces to owning pages or existing generators, and reports impacted pages, reasons, generator status, and errors as stable JSON. Unknown or unowned changed surfaces are errors, not guesses. Relying only on agent or reviewer judgment is rejected, because it makes the no-change path slow and inconsistent. Reviewers still confirm the report and any page-specific waiver; the task check repeats the analysis at every handoff so later work cannot silently leave a page unchanged.

A waiver lives in the Git directory, not in a tracked file, because it matters only to one branch and nothing reads it after the merge. A shared tracked file is rejected: concurrent branches appended to the same lines, so most merges of `main` into a task branch conflicted.

Page ownership belongs beside the documentation, in `covers:` frontmatter and the extractor's surface mappings. Listing owners in every task brief is rejected, because duplicate lists go stale and miss new surfaces. Running only existing generators is also rejected: generated API, CLI, and MCP contracts do not find every hand-written page affected by configuration, errors, migrations, or schedules. Current generator output needs no duplicate prose, but stale or missing output still requires an update.

Coverage declarations are optional, but docs-lint checks that their globs are safe and match tracked paths. A committed ratchet prevents already-covered pages from dropping their declarations. Requiring coverage on every page at once is rejected, because a monotonic ratchet lets documentation consolidation add ownership incrementally without weakening existing coverage.

### Checks that need no network

`composer docs-lint` checks structure, links, ADR format, blocked wording, and the freshness of the committed context index. It also enforces the ADR lifecycle. A number in the retirement table on the [decisions overview](/decisions/overview) must have no matching file in `docs/decisions`. Matching uses the full slug recorded in `apps/docs/config/adr-retired-slugs.php`, so the Tasks 0114 slug clash is allowed. Every row in that table must have a redirect from that exact ADR path.

Every ADR numbered 0180 or higher must say `In progress.` in its Status section and include a `Principle:` line. A lower number follows the same two rules only when the retirement table does not list its number. The open gaps are 0007 and 0020. Older ADRs still in Records with other statuses are listed in a committed allowlist that can only shrink.

Check a proposed head and its merge result with the current Docs tooling:

```bash
bin/docs-merge-check --base origin/main --head HEAD --head-only
bin/docs-merge-check --base origin/main --head HEAD
```

`--head` defaults to `HEAD`. `--head-only` checks that committed tree alone; otherwise `--base` is required and Git builds the merge result in a temporary local clone. Both modes run strict docs lint, including the ADR lifecycle rules, without changing the working tree. The merge preview retains both parents for history checks and binds the coverage ratchet baseline to the resolved `--base` commit, not the checkout's local `main`. Head-only mode preserves the source checkout's `origin/main` baseline when present; without it, lint uses the head's committed ratchet. Neither mode substitutes local `main` for that baseline.

The command isolates Laravel's configuration-cache path in its private scratch directory so a workspace or inherited cache cannot select a different documentation tree; existing caches are left untouched.

An ADR number closed on the base can pass at an older branch's head but fail in the merge result: the merge result is what lands on main.

On pull requests, CI's `Docs (merge ref)` job checks out the base repository's `refs/pull/N/merge` and runs `composer check` in `apps/docs`. It logs the merge commit and fails with `Docs merge ref unavailable` if checkout fails; it never falls back to the PR head. `Required checks` requires this job to succeed on pull requests. Pushes to `main` and manual runs skip it and keep their existing checks.

The command never fetches. Make both refs available locally before running it. `--help` lists the flags and exit codes: 0 means lint passed, 1 means lint failed, and 2 means the preview could not be checked. Missing refs report `base_unavailable` or `head_unavailable`; conflicts report `merge_conflict` and never fall back to checking the head.

The lint command reads the repository only, with no network, external service, or Incus topology. Live behavior is proved on Incus, separately. A lint rule earns its place only when it protects a current invariant and has tests for a valid and an invalid case.

### Audits stay outside the gate

Advisories appear upstream at any time. An audit in `composer check` or in `Required checks` would block unrelated work for a change that the branch did not make. The nightly audit reports new advisories, and an advisory fix pull request ships the fix.

Vet runs without a trust baseline. A baseline makes each routine update a manual approval, and the release age already holds back a compromised release while it is found and pulled.

### A committed context index

`docs/generated/context.json` maps pages to concepts and components. Agents use it to load the right pages without reading all the code. Generation is an explicit write, and lint only checks that the committed index is current.
