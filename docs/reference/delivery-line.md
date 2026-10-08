---
title: "Delivery-line proofs"
description: "Five repository commands that prove reproduction, task-group shape, pull-request head review, post-merge live state, and a healthy Gateway release, without filing, merging, deploying, or rolling back."
covers:
  - bin/{bug-repro,task-group-check,pr-head-check,deploy-verify,gateway-smoke}
  - apps/e2e/tests/Unit/E2E/{DeliveryLineCommandsTest,GatewaySmokeTest}.php
  - apps/e2e/tests/Fixtures/gateway-smoke-tests.py
  - apps/e2e/tests/Fixtures/delivery-line/**
  - .agents/skills/merging-pull-requests/SKILL.md
  - apps/gateway/app/Http/Requests/Tasks/CreateTaskGroupRequest.php
---

# Delivery-line proofs

Orbit's delivery line uses five repository commands. Each command prints one JSON object on stdout, has `--help`, and exits nonzero on failure. The error text says what to do next. Commands that can run a side effect support `--dry-run` and default to read-only work. They add no proof field on a task or subtask. They reuse the existing task fields and `fails_on_base`.

The style matches [`bin/review-check`](/reference/implementation-loop#the-candidate-gate), the Project [task check](/reference/tasks#prove-a-command-fails-on-the-start-commit), and [`doctor`](/cli/doctor): one structured result, no repair, and a next step on failure.

| Command | Result | Side effects |
| --- | --- | --- |
| [`bin/bug-repro`](#binbug-repro) | The command fails on current main | Runs the command on an extracted main tree. Does not file a task. |
| [`bin/task-group-check`](#bintask-group-check) | The payload is one valid ordered group | None. Does not create a group. |
| [`bin/pr-head-check`](#binpr-head-check) | The current head has a matching review and Required checks, and no named leftover | None. Does not merge. |
| [`bin/deploy-verify`](#bindeploy-verify) | Live `APP_VERSION` matches the merged SHA, `/up` is up, and gateway status is `ok` | None. Does not deploy or roll back. |
| [`bin/gateway-smoke`](#bingateway-smoke) | A switched Gateway release serves its version, CLI reads, and its web build, runs its scheduler from the new release with `tasks:tick` scheduled, and runs agent view | None by default. `--write-check` creates and removes one Project Document. |

Run every command from the repository root.

## Shared result

Success prints one JSON object and exits `0`. Failure prints one JSON object on stdout, writes the next step on stderr, and exits `1`. Wrong flags exit `2`. `--help` prints the command contract and exits `0`.

| Field | When |
| --- | --- |
| `passed` | Present on a verdict. `true` or `false`. |
| `dry_run` | Present when `--dry-run` skipped the side effect or the live calls. |
| `error` | Present on failure. A stable token. |
| `message` | Present on failure. One sentence. |
| `next` | Present on failure. What to do next. |

Machine output contains no heading, progress line, or ANSI sequence.

## bin/bug-repro

Run one command against current main the same way the task engine runs a `fails_on_base` command: extract main, overlay the given `paths`, and run the command. Pass when that command exits nonzero. Do not file a task.

```bash
bin/bug-repro --command COMMAND --paths PATH [--paths PATH ...] [--directory DIR] [--repository DIR] [--main REF] [--timeout SECONDS] [--dry-run]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--command` | required | The shell command the task engine would run. |
| `--paths` | required, repeatable | Workspace-relative files the task engine already requires for a base run. |
| `--directory` | `.` | Directory relative to the extracted tree. |
| `--repository` | the current checkout | Git repository that holds main and the overlay files. |
| `--main` | `origin/main`, then `main` | Pin that ref as the main SHA and skip the origin freshness check. |
| `--timeout` | `600` | Seconds before the base run stops, matching the task check. |
| `--dry-run` | off | Print the main SHA, command, directory, and paths. Do not extract or run. |

```bash
bin/bug-repro --command "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'" --paths apps/gateway/tests/Feature/HomeScreenTest.php --directory apps/gateway
bin/bug-repro --command "bin/docs-impact --gate" --paths bin/docs-impact --dry-run
```

When `--main` is omitted, the command names current main only after the local `origin/main` or `main` SHA matches `git ls-remote origin main`. It does not fetch. A cached ref that differs is `main_stale`. Fetch origin main, then run again. `--main` pins a ref and skips that check.

The command copies installed `vendor` and `node_modules` directories into the extracted tree, then copies each path from the working tree, including an uncommitted file. It registers no Git worktree and does not change the checkout. A `--paths` value that is absolute, that leaves the tree, that is a symlink, or that is not a file fails before the run.

When the command exits nonzero, stdout includes `main_sha`, `command`, `exit_code`, and `paths`. When it exits `0`, the verdict is `not_reproduced`. The next step is to stop: the failure is not on current main, so do not file a task.

| `error` | Next step |
| --- | --- |
| `usage` | Pass `--command` and at least one `--paths`. |
| `main_stale` | Fetch origin main, then run again. Do not name a cached main SHA. |
| `main_unavailable` | Fetch `origin/main` or pass `--main`. |
| `path_invalid` | Fix the named path so it is a regular workspace-relative file. |
| `not_reproduced` | Do not file a task. The command exited 0 on current main. |
| `command_unstarted` | Fix the overlay, the directory, or the extracted tree, then run again. |

## bin/task-group-check

Validate a task-group payload against [`CreateTaskGroupRequest`](/reference/tasks#deliverables) without creating a group. Then apply Orbit's repository policy from the [Orbit Tasks skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md).

```bash
bin/task-group-check --payload FILE [--kind auto|bug|feature]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--payload` | required | A JSON object, or `-` for stdin. The body `tasks-create` accepts. |
| `--kind` | `auto` | `bug` applies the failing-command rule. `feature` does not. `auto` treats a group as a bug when the brief starts with `Filed by the outer loop.` or the title starts with `Doctor `. |

The command refuses an unsupported top-level key, a duplicate top-level key, or a body that is not one JSON object. It checks `project_id`, `title`, `brief`, optional `status`, `notify_coder`, `notify_on_settle`, and `tasks` with the same required, prohibited, length, list, and `fails_on_base` rules as create. `project_id` must be an integer of at least 1. The check does not look up the Project row.

Each subtask may declare `topology` as a list of at most three distinct roles: `app-dev`, `app-prod`, and `app-prod-2`. Omission or `[]` requests no additional workload node. Validation rejects unknown roles, duplicate roles, and values that are not lists before creating a group. The [declared topology contract](/reference/compute-drivers) owns admission and readiness.

It then requires one ordered group: the payload is that group, and `tasks` is a nonempty list in order. A wrapper with `groups` is accepted only when that list has exactly one group. Every subtask brief must contain a `Goal` line and an `Acceptance` line, as the skill template writes them.

A subtask that only restates the final gate fails. That includes a goal or acceptance that only names `review-check`, the candidate gate, the task check, or preparing or proposing the pull request. Every handoff already runs the task check, and the last subtask's approval proposes the pull request.

A bug group's first code-changing subtask must carry `fails_on_base` `true` on a `command` deliverable, with `paths`. A docs-only first subtask does not count as the first code-changing subtask. A bug group with no failing command fails. The command does not add a proof field.

| `error` | Next step |
| --- | --- |
| `usage` | Pass `--payload` with one JSON object. |
| `validation.failed` | Fix the named field so it matches create. |
| `group_invalid` | Supply exactly one ordered group. |
| `brief_incomplete` | Add `Goal` and `Acceptance` to the named subtask brief. |
| `final_gate` | Give that subtask real work. Do not add a gate-only subtask. |
| `fails_on_base_missing` | Put `fails_on_base` true and `paths` on BugBot's command in the first code-changing subtask. |

## bin/pr-head-check

Read the pull request, its reviews, its `Required checks` run, and its file diff. Pass only when those records match the current head and the diff has no leftover the [merge skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/merging-pull-requests/SKILL.md) already names. Do not merge.

```bash
bin/pr-head-check --pr URL
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--pr` | required | A GitHub pull request URL or `owner/repo#number`. |
| `--pull-file`, `--reviews-file`, `--checks-file`, `--files-file` | GitHub through `gh api` | Recorded JSON for tests. |

The result includes `merged`, copied from the pull request record, alongside the existing `passed` and mismatch fields. Before any review or merge attempt, read this field even when the command exits nonzero. When the JSON has `merged:true`, stop: the pull request is already merged and the merge flow is a terminal no-op. Do not review it again or run `gh pr merge`. This applies even when `passed` is `false` or the result names a missing review or another mismatch. When `merged` is `false`, all review and merge requirements still apply. A missing or unreadable `merged` field is not evidence of a merge; resolve the read before proceeding.

The command keeps a review only when its `commit_id` equals the current full head SHA and its state is not `DISMISSED` or `PENDING`. `COMMENTED` counts. Pass does not require `APPROVED`. GitHub refuses `APPROVE` from the pull request author. An empty successful review list is missing. A failed, partial, or unparsable read is unreadable and is not treated as empty.

On that same full head SHA, the GitHub Actions check run named `Required checks` must have `status` `completed` and `conclusion` `success`. That run's `head_sha` must equal the pull request head. Newer `gh` wraps `gh api --paginate --slurp` check-runs in a one-element array of the check-runs object. Older `gh` has no `--slurp` and returns the object. Both shapes flatten to the `check_runs` list. A check that is not completed is pending. A completed check whose conclusion is not `success` is failed. No run with that name is missing. The admin bypass is not a successful check.

The diff fails when it adds a leftover the merge skill already names: GitHub auto-merge, `--auto`, `--admin`, an `orbit tasks:merge` command, a Gateway merge endpoint, a merge SDK, MCP, or API contract, a ruleset change, an App permission change, an `object-storage-host`, or a `linear-reference`. Markdown sentences that explicitly prohibit those leftovers are exempt. A negation in a URL, another sentence, or product code does not exempt the match. The scan also skips the detector (`bin/pr-head-check`), the delivery-line test that names the leftover refusal, and recorded delivery-line fixtures. Those files hold the forbidden names so the checker can refuse them. A leftover added in product code still fails.

Do not add hostnames under the generic `upcloudobjects.com` provider suffix (`object-storage-host`), `linear.app` references, Linear issue IDs, or Linear issue, ticket, project, product, task, integration, or workspace wording (`linear-reference`). A trailing sentence period or DNS root dot still matches the provider host; a domain that extends the suffix does not. Patterns and fixtures contain no real storage bucket, endpoint, region, or account names.

On any mismatch the JSON names the mismatch. For an unmerged pull request, the next step is to review the new head. For `merged:true`, stop instead, regardless of the mismatch. The command never runs `gh pr merge`.

| `error` | Next step |
| --- | --- |
| `usage` | Pass `--pr` with the pull request URL. |
| `review_missing` | Review the current head. Do not merge. |
| `review_unreadable` | Re-read the reviews until the list is complete. Do not merge. |
| `check_missing`, `check_pending`, `check_failed` | Wait for `Required checks` on this head, or review the new head. Do not merge. |
| `head_mismatch` | Review the new head. Do not merge. |
| `leftover` | Remove the named leftover. Do not merge. |

## bin/deploy-verify

Read the live tip version, `/up`, and gateway status. Pass when `APP_VERSION` equals the given merged SHA, `/up` is up, and gateway status is `ok`. Those are the checks recorded in ops verified-merges. Do not deploy and do not roll back.

Each [Gateway release](/reference/gateway-recovery#deploy-a-release) runs the same checks as its verify step. The command stays a read-only check from outside the Gateway.

```bash
bin/deploy-verify --sha SHA [--up-url URL] [--status-url URL] [--dry-run] [--fixture FILE]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--sha` | required | The merged commit to expect as live `APP_VERSION`. A prefix match is enough. |
| `--up-url` | `ORBIT_DEPLOY_UP_URL` or `https://gateway.orbit/up` | The health URL. |
| `--status-url` | `ORBIT_DEPLOY_STATUS_URL` or `https://gateway.orbit/api/v1/gateway/status` | The gateway status URL. Its `data.version` is the tip `APP_VERSION`. |
| `--dry-run` | off | Print the URLs and the expected SHA. Do not call them. |
| `--fixture` | off | A recorded response set. Used when production is unreachable. |

`--dry-run` exits `0` and does not claim a live pass. `--fixture` evaluates the recorded `/up` body, gateway status, and `APP_VERSION` `2f214816deae` marker shape from 2026-10-01. A fixture pass is not a live pass. The JSON says `source` is `fixture` or `live`.

Python HTTPS calls need `SSL_CERT_FILE` set to Orbit's root CA, or they fail certificate verification. That file is the Gateway profile `ca_path` after [`orbit gateway:trust`](/cli/gateway#orbit-gatewaytrust). An unreachable result from this machine is not a reason to change the live checks.

`instance:rollback` is not part of this command. A rollback selects one retained production release and does not undo deploy steps, environment writes, or database files. See [Roll back](/reference/deployments#roll-back).

| `error` | Next step |
| --- | --- |
| `usage` | Pass `--sha` with the merged commit. |
| `unreachable` | Use `--dry-run` or `--fixture`. For a certificate problem, set `SSL_CERT_FILE`. |
| `version_mismatch` | The live `APP_VERSION` is not this SHA. Do not treat the deploy as verified. |
| `up_failed` | `/up` is not up. Do not treat the deploy as verified. |
| `status_failed` | Gateway status is not `ok`. Do not treat the deploy as verified. |

## bin/gateway-smoke

Smoke-test a Gateway release after its switch, on the Gateway host. Each [Gateway release](/reference/gateway-recovery#smoke) runs it after the verify step and the web switch, and stores its JSON on the release record. Operators run it by hand the same way. It does not deploy, switch, restart, or roll back.

```bash
bin/gateway-smoke --sha SHA [--since TIME] [--wait-for-tick [--tick-within SECONDS]] [--php PATH] [--timeout SECONDS] [--skip CHECK ...] [--write-check --smoke-project PROJECT] [--dry-run]
```

Run it as the Gateway account from the release under test. It then uses that release's `apps/cli/orbit` with the account's Gateway profile. Python HTTPS calls need `SSL_CERT_FILE`, as for `bin/deploy-verify`.

| Check | Passes when | Skipped when |
| --- | --- | --- |
| `deploy_verify` | [`bin/deploy-verify`](#bindeploy-verify) passes for `--sha`. Its JSON is the check's `detail`. | |
| `node_list` | `orbit node:list --json` succeeds and lists at least one Node. | |
| `tasks_list` | `orbit tasks:list --json` succeeds. | The tasks extension is disabled. |
| `web` | `web/current` links to `releases/<sha12>` of `--sha`, and Caddy serves that release's `index.html` and the first hashed `/assets/` file it references, byte for byte. | |
| `scheduler` | The `orbit-process-*` unit whose command runs `schedule:work` in `--checkout` is `active` and `running`, and its main process runs from the release that `--checkout` links to. With `--since`, it started at or after that time. | |
| `tasks_tick` | `artisan schedule:list --json` of the release that `--checkout` links to loads and lists `tasks:tick`. With `--wait-for-tick`, a tick started instead, as described below. | With `--wait-for-tick`, the tasks extension is disabled. |
| `agent_view` | `orbit-agent-view.service` is `active` and `running`. With `--since`, it started at or after that time. | |
| `documents` | Creates one text file in the smoke Project, reads it back, renames it with its revision, and removes it. | Always, unless `--write-check` is set. |

A restarted scheduler starts its first tick on the next full minute, so a release does not pass `--wait-for-tick`. It [confirms the first tick afterwards](/reference/gateway-recovery#post-release-tick-confirmation). `tasks_tick` runs `artisan schedule:list` with only `HOME`, `PATH`, and `LANG`, as the release's own artisan commands do. With `--wait-for-tick`, it reads `orbit tasks:status --json` every 2 seconds until the total limit, and passes once `last_tick_at` is at or after `--since`, or no more than `--tick-within` seconds before the run.

All checks run at the same time. Each has its own time limit, and `--timeout` bounds the whole run, so a release waits at most that long. A check that does not finish in time is `timeout`. The [`tasks:status`](/cli/tasks#orbit-tasksstatus) tick record comes from the Gateway clock, so compare it on the Gateway host.

| Option | Default | Meaning |
| --- | --- | --- |
| `--sha` | required | The released commit, 7 to 40 hexadecimal characters. |
| `--since` | off | The runtime handoff time, in ISO 8601 with a zone. The scheduler and agent view must have started after it. With `--wait-for-tick`, a tick must have started after it too. |
| `--wait-for-tick` | off | Wait for a `tasks:tick` to start, instead of checking that the release schedules it. This can add up to a minute. |
| `--tick-within` | `60` | With `--wait-for-tick` and without `--since`, the latest tick may be this many seconds old. |
| `--php` | `ORBIT_SMOKE_PHP`, else `php8.5` or `php` on `PATH` | The PHP binary for `artisan schedule:list`. |
| `--timeout` | `60` | Seconds for the whole run. |
| `--up-url`, `--status-url` | the `bin/deploy-verify` defaults | Passed to `bin/deploy-verify`. |
| `--web-url` | `ORBIT_SMOKE_WEB_URL` or `https://gateway.orbit/` | The web app URL that Caddy serves. |
| `--web-dir` | `ORBIT_WEB_DIR` or `/home/orbit/web` | The [web directory](/reference/web-app#web-directory) that holds `current`. |
| `--checkout` | `ORBIT_GATEWAY_CHECKOUT` or `/home/orbit/orbit` | The Gateway checkout the scheduler unit runs in. |
| `--orbit` | `ORBIT_SMOKE_ORBIT` or `apps/cli/orbit` in this checkout | The Orbit CLI to run. |
| `--scheduler-unit` | discovered | Name the scheduler unit when discovery finds none or more than one. |
| `--agent-view-unit` | `orbit-agent-view.service` | The agent view unit. |
| `--skip` | none | Skip one check. Repeatable. Skipping every read check is a usage error. |
| `--write-check`, `--smoke-project` | off | Run `documents` in that Project, by ID or slug. Both or neither. |
| `--dry-run` | off | Print each check and its target. Call nothing. |

Each check command runs in a session of its own. When the run itself receives `SIGTERM`, `SIGINT`, or `SIGHUP`, it first kills every running check command. Then it prints a result with `error` `terminated` and exits `128` plus the signal number. So a caller that stops smoke leaves no check behind.

The write check is off by default, because it writes to the live Gateway on every release. Use a dedicated Project for it. The file is named `gateway-smoke-<sha12>-<random>.txt`. When a step fails after create, the check removes the file and records `cleanup` as `removed`. When that fails too, `cleanup` is `failed` and the message names the entry to remove.

### Result

The command prints one JSON object and exits `0` when no check failed or timed out. Skipped checks do not fail the run. It exits `1` when a check failed or timed out, and `2` on wrong flags. Store the object as it is: `schema` is `1`, and new fields are added without changing existing ones.

| Field | Meaning |
| --- | --- |
| `schema` | `1`. |
| `passed` | `true` when no check failed or timed out. |
| `source` | `live`, or `dry-run` with `dry_run: true`. |
| `expected_sha`, `since` | The inputs. `since` is `null` without `--since`. |
| `started_at`, `finished_at`, `duration_ms`, `timeout_seconds` | When the run happened and how long it took. |
| `summary` | The number of checks per status: `passed`, `failed`, `timeout`, `skipped`. |
| `checks` | One object per check, keyed by check name, in the order above. |
| `checks.<name>.status` | `passed`, `failed`, `timeout`, or `skipped`. |
| `checks.<name>.error` | `null`, or a stable token from the table below. |
| `checks.<name>.message` | One sentence about the result. |
| `checks.<name>.duration_ms`, `checks.<name>.detail` | The check's time, and what it read: URLs, status codes, units, timestamps, or the deploy-verify JSON. |
| `error`, `failed_checks`, `message`, `next` | Present on failure. `error` is `checks_failed`, or `terminated` when a signal stopped the run; a terminated result has no `checks` or `failed_checks`. |

| `error` | Check | Meaning |
| --- | --- | --- |
| `version_mismatch`, `up_failed`, `status_failed`, `unreachable` | `deploy_verify` | The `bin/deploy-verify` error. |
| `cli_failed` | CLI checks | The CLI exited nonzero. `detail.cli_error` holds its error envelope. |
| `cli_unexpected` | CLI checks | The CLI answered without the expected list or entry. |
| `command_unavailable` | any | The CLI or `systemctl` could not start. |
| `web_current_missing` | `web` | `web/current/index.html` does not exist. |
| `web_release_mismatch` | `web` | `web/current` is not the build of `--sha`. |
| `web_index_failed`, `web_asset_missing`, `web_asset_failed` | `web` | Caddy did not serve an HTML page, the page names no hashed asset, or the asset failed. |
| `web_not_current` | `web` | Caddy serves other bytes than `web/current`. |
| `scheduler_missing`, `scheduler_ambiguous` | `scheduler` | Discovery found no unit, or more than one. Pass `--scheduler-unit`. |
| `unit_missing`, `unit_inactive`, `unit_not_restarted`, `unit_start_unknown` | `scheduler`, `agent_view` | The unit is not installed, not running, older than `--since`, or has no start time. |
| `systemctl_unavailable`, `systemctl_failed` | `scheduler`, `agent_view` | `systemctl` is missing or failed. Run on the Gateway host. |
| `scheduler_pid_unknown`, `scheduler_release_unknown` | `scheduler` | The unit reports no main process, or its working directory cannot be read. |
| `scheduler_old_release` | `scheduler` | The scheduler's main process runs from another directory than the release `--checkout` links to. |
| `php_unavailable` | `tasks_tick` | No PHP binary was found. Pass `--php`. |
| `schedule_unreadable` | `tasks_tick` | `artisan schedule:list` failed or printed no JSON list. `detail.stderr` holds its error. |
| `tick_unscheduled` | `tasks_tick` | The release's schedule does not list `tasks:tick`. |
| `tick_stale` | `tasks_tick` | With `--wait-for-tick`, no tick started in time. |
| `tick_unreported` | `tasks_tick` | With `--wait-for-tick`, the Gateway does not report `last_tick_at` yet. |
| `document_mismatch` | `documents` | A step returned other content, name, or result than it wrote. |
| `timeout` | any | The check did not finish within its limit. |
| `check_crashed` | any | The check stopped on an unexpected error. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Existing fields already name the failing command

The first subtask that changes code in a bug group already carries a `command` deliverable with `fails_on_base` true and `paths`. A new proof field would duplicate that contract and break the Orbit Tasks policy. `bin/bug-repro` is the operator command that runs the same overlay against current main before anyone files a task.

### Repository commands stay outside the engine

The Gateway task engine is generic. Orbit's policy lives in the repository skill and these checks. Putting the proofs in `bin/` beside `review-check` keeps Project policy out of the engine, the CLI product, and the SDK.

### Doctor's verify-only boundary applies to deploy

`bin/deploy-verify` reads the same three signals ops already records after a merge: tip version, `/up`, and gateway status. It does not deploy, and it does not roll back. Wiring `instance:rollback` into the verify command would hide that a rollback does not restore data or undo steps.

### A stale head is named, not merged

`bin/pr-head-check` stops when the review or `Required checks` belong to another SHA. The next step is to review the new head. Auto-merge, `--admin`, and a `tasks:merge` command remain the leftovers the merge skill already forbids.

### A smoke test reads, and writing is opt-in

A release smoke test runs on every main commit against the live Gateway. Read checks prove that the release serves its version, API, web build, scheduler, and agent view without changing state. A document write proves storage too, but it writes on every release, so an operator turns it on for a dedicated Project.

### A release does not wait for the first tick

`schedule:work` runs `schedule:run` only at the start of a minute. After the runtime handoff restarts the scheduler, the first `tasks:tick` came 30 to 56 seconds later in the releases of 8 Oct 2026, and a drain that crossed a minute boundary cost a whole extra minute. Smoke instead proves what can fail at once: the scheduler runs from the new release, and that release loads its schedule with `tasks:tick` in it. The first tick itself is [confirmed after the release](/reference/gateway-recovery#post-release-tick-confirmation), where a silent scheduler alerts without holding up the next release.

### The scheduler records its own tick

`tasks:tick` writes the time it takes its lock into the Gateway cache, and `tasks:status` reports it. It also records the version of the code that ran it, so a release can tell its own scheduler's tick from the previous one's. The smoke test reads the time through the CLI with `--wait-for-tick`. A journal read depends on log permissions and log wording, and a process list cannot tell an idle scheduler from a stuck one.
