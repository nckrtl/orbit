---
title: "Delivery-line proofs"
description: "Four repository commands that prove reproduction, task-group shape, pull-request head review, and post-merge live state without filing, merging, deploying, or rolling back."
covers:
  - bin/{bug-repro,task-group-check,pr-head-check,deploy-verify}
  - apps/e2e/tests/Unit/E2E/DeliveryLineCommandsTest.php
  - apps/e2e/tests/Fixtures/delivery-line/**
  - .agents/skills/merging-pull-requests/SKILL.md
  - apps/gateway/app/Http/Requests/Tasks/CreateTaskGroupRequest.php
---

# Delivery-line proofs

Orbit's delivery line uses four repository commands. Each command prints one JSON object on stdout, has `--help`, and exits nonzero on failure. The error text says what to do next. Commands that can run a side effect support `--dry-run` and default to read-only work. They add no proof field on a task or subtask. They reuse the existing task fields and `fails_on_base`.

The style matches [`bin/review-check`](/reference/implementation-loop#the-candidate-gate), the Project [task check](/reference/tasks#prove-a-command-fails-on-the-start-commit), and [`doctor`](/cli/doctor): one structured result, no repair, and a next step on failure.

| Command | Result | Side effects |
| --- | --- | --- |
| [`bin/bug-repro`](#binbug-repro) | The command fails on current main | Runs the command on an extracted main tree. Does not file a task. |
| [`bin/task-group-check`](#bintask-group-check) | The payload is one valid ordered group | None. Does not create a group. |
| [`bin/pr-head-check`](#binpr-head-check) | The current head has a matching review and Required checks, and no named leftover | None. Does not merge. |
| [`bin/deploy-verify`](#bindeploy-verify) | Live `APP_VERSION` matches the merged SHA, `/up` is up, and gateway status is `ok` | None. Does not deploy or roll back. |

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

The command keeps a review only when its `commit_id` equals the current full head SHA and its state is not `DISMISSED` or `PENDING`. Pass needs one kept review. An empty successful review list is missing. A failed, partial, or unparsable read is unreadable and is not treated as empty.

On that same full head SHA, the GitHub Actions check run named `Required checks` must have `status` `completed` and `conclusion` `success`. That run's `head_sha` must equal the pull request head. A check that is not completed is pending. A completed check whose conclusion is not `success` is failed. No run with that name is missing. The admin bypass is not a successful check.

The diff fails when it adds a leftover the merge skill already names: GitHub auto-merge, `--auto`, `--admin`, an `orbit tasks:merge` command, a Gateway merge endpoint, a merge SDK, MCP, or API contract, a ruleset change, or an App permission change. Documentation that states those leftovers stay forbidden is not a leftover.

On any mismatch the JSON names the mismatch. The next step is to review the new head. The command never runs `gh pr merge`.

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
| `unreachable` | Production is not reachable from this machine. Use `--dry-run` or `--fixture`. Do not invent a live pass. When the failure is a certificate problem, set `SSL_CERT_FILE` to Orbit's root CA, then run again. |
| `version_mismatch` | The live `APP_VERSION` is not this SHA. Do not treat the deploy as verified. |
| `up_failed` | `/up` is not up. Do not treat the deploy as verified. |
| `status_failed` | Gateway status is not `ok`. Do not treat the deploy as verified. |

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
