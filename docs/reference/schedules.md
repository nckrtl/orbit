---
title: "Schedules"
description: "How the Gateway stores a Schedule, projects it to a native systemd timer, reports its latest run, and removes its owned state."
covers:
  - apps/gateway/app/Actions/Schedules/**
  - apps/gateway/app/Domain/Schedules/**
  - apps/gateway/app/Infrastructure/Schedules/**
  - apps/gateway/app/Data/Schedules/**
  - apps/gateway/app/Http/{Controllers/Api/Schedule*,Requests/Schedules/*}.php
  - apps/gateway/app/Models/Schedule.php
  - packages/php-sdk/src/{Requests,Responses}/Schedules/**
---

# Schedules

A Schedule runs one command on a timer for one Node or one Instance. The Gateway stores the Schedule. The host Node runs it with a native systemd timer, so the timer keeps firing while the Gateway is down. macOS has no systemd timers in this feature. A Schedule mutation there returns `schedule.platform_unsupported` (HTTP 422) before SSH. [`schedule`](/cli/schedule) lists the commands. [Processes and schedules](/reference/processes-and-schedules) describes Project Schedule definitions and their Instance copies.

An Orbit Schedule is not a command on the Gateway's own Laravel schedule. The Tasks extension registers `tasks:tick`, `problems:collect`, and `problems:file` there. [Tasks](/reference/tasks#scheduler) describes those timers. The Gateway also runs `orbit:deploy-development-defaults` every minute without overlap as the push fallback for [development defaults](/reference/deployments#development-defaults). That internal tick is not a user-created Schedule and does not install a timer on an app-dev Node.

The Gateway also attempts `project-documents:probes:reconcile` once per minute without overlap. It processes a finite batch from the private probe journal and permanently retains each cleanup record for late PUT recovery. This is an internal attempt schedule, not a promise of object lifetime or a Schedule on a Node. [Reserved probe recovery](/reference/project-documents#recover-reserved-probes) defines its bounds, backoff, repair, and separation from the gate for document-body cleanup.

## Fields

The Schedule UUID is its public identity. It is also the only value that names the host artifacts.

| Field | Contract |
| --- | --- |
| `id` | An immutable UUID. |
| `target_type`, `target_id` | Exactly one `node` or `instance` target. |
| `name` | Unique within the target. 1 through 63 lowercase ASCII letters or digits, with hyphens only between them. |
| `calendar` | One printable ASCII line of at most 255 bytes. The host Node's `systemd-analyze calendar` must accept it. |
| `command` | One non-empty UTF-8 line of at most 4,096 bytes, without NUL, carriage return, or line feed. |
| `timeout_seconds` | 1 through 86,400. The default is 3,600. |
| `desired_timer_state` | `enabled` or `disabled`. |
| `status` | `provisioning`, `active`, `failed`, or `removing`. `failed_step` and `error_code` name a failure. |
| `last_run_at`, `last_run_status` | The time the Gateway received the latest completion report, and `success` or `error`. Both are null before the first report. |

A Schedule has no edit operation. An identical create returns the existing Schedule and keeps its desired timer state. A different specification with the same target and name returns `schedule.retry_conflict` (409). To change a Schedule, destroy it and create it again.

## API

Every endpoint is under `/api/v1/schedules` and needs an access grant to the Node that owns the target. For an Instance target, that is the Instance's Node.

| Operation | Request | Result |
| --- | --- | --- |
| List | `GET /api/v1/schedules` | The Schedules whose target Node the caller may address, ordered by name, without `command`. |
| Create | `POST /api/v1/schedules` | Creates and installs one Schedule. Returns 201 for a new Schedule and 200 for an identical one. |
| Show | `GET /api/v1/schedules/{uuid}` | One Schedule, with `command`. |
| Run | `POST /api/v1/schedules/{uuid}/run` | Starts the service once. The desired timer state does not change. |
| Logs | `GET /api/v1/schedules/{uuid}/logs?lines=N` | The newest journal lines of the service. |
| Enable | `POST /api/v1/schedules/{uuid}/activate` | Enables and starts the timer of an Instance Schedule. |
| Destroy | `DELETE /api/v1/schedules/{uuid}` | Starts or resumes removal. |
| Complete | `POST /api/v1/schedules/{uuid}/complete` | The host Node reports a run result. Returns 204. |

Create takes `target_type`, `target_id`, `name`, `calendar`, and `command`, and optionally `timeout_seconds` and `start`. `start` defaults to `true`. A Node target refuses `start: false` with `schedule.state_invalid`. An Instance target can install with its timer disabled. Run, enable, and destroy take an empty body. The Gateway refuses unknown, duplicate, or wrongly typed members with 422 before it changes anything.

Each operation except Complete records one Activity entry. The entry names the Schedule and its target. It never holds the command, calendar, journal lines, paths, users, or unit text.

## Execution context

The caller picks only the target. The Gateway derives the host Node, the user, the working directory, and the shell from the target's placement.

| Target | User | Working directory | Shell |
| --- | --- | --- | --- |
| Node | The Node's managed user | That user's home | The user's login shell, with `-lc` |
| Instance on `app-dev` | The Node's managed user | The Instance's application directory in its checkout | The user's login shell, with `-lc` |
| Instance on `app-prod` | The Instance's production user | The Instance's application directory under `<production-home>/current` | `/bin/bash -c`, without a login |

For Laravel, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, a Schedule runs in `<checkout>/apps/site` on `app-dev` or `<production-home>/current/apps/site` on `app-prod`, so `php artisan schedule:run` finds that app's `artisan` and `.env`. Root `public` keeps the checkout or release root. Node Schedule working directories do not change. Instances that are not Laravel apps keep their checkout or release root.

The target Node must be an active Linux Node with a WireGuard address. An Instance target must be active. A production Schedule resolves `current` each time it runs, so a new release changes later runs. A production Instance needs a selected release before it can install a Schedule, even with a disabled timer.

A Schedule does not follow its target. While a Schedule exists, the Gateway refuses to remove its target Node or host Node with `schedule.target_in_use` (409). [Instance transfer](/reference/instance-transfer) does not check Schedules. After a transfer, list and show still work, and an identical create returns `schedule.target_in_use`. Run, logs, enable, and destroy fail with `schedule.target_unavailable`, because the Instance runs on another Node than the host Node. Destroy the Instance's Schedules before a transfer. Instance removal removes the Instance's Schedules itself.

## Host artifacts

Each Schedule owns three files on the host Node. Only the UUID names them.

| Artifact | Path | Owner and mode |
| --- | --- | --- |
| Script | `/etc/orbit/schedules/{uuid}.sh` | `root:<runtime group>`, `0750` |
| Service | `/etc/systemd/system/orbit-schedule-{uuid}.service` | `root:root`, `0644` |
| Timer | `/etc/systemd/system/orbit-schedule-{uuid}.timer` | `root:root`, `0644` |

The script holds the command. The oneshot service sets the user, group, working directory, `HOME`, and the timeout as `TimeoutStartSec`. Its `ExecStart` runs the script, and its `ExecStopPost` sends the completion report. The timer uses the calendar with `Persistent=true`, so systemd runs one missed occurrence after the Node was down. systemd never starts the service while it still runs. The command text appears only in the script, never in an SSH, `sudo`, `systemctl`, `journalctl`, or `systemd-analyze` argument.

Installation keeps `/etc/orbit` as a real directory owned by `root:root` with mode `0711`, so the runtime user can reach its script but cannot list the directory. It refuses a symbolic link, a non-directory, or another owner there.

## Install and recover

Create validates the input and the target first. Then it installs the artifacts over SSH in one script that holds a lock on the host Node. The script checks the calendar with `systemd-analyze calendar`, stages the new files, checks them with `systemd-analyze verify`, moves them in place, reloads systemd, and sets the timer to the desired state. It checks the timer state before the Schedule becomes `active`.

An existing file at an owned path must be a regular file with the expected owner, mode, and `X-Orbit-Schedule-ID` marker. Otherwise installation stops with `schedule.artifact_conflict`, and Orbit does not overwrite, adopt, or delete the file. When a step fails, the script restores the earlier files and timer state. A first installation removes only the files it created. When the restore fails too, the Schedule becomes `failed` with `schedule.rollback_failed`. Repeat the identical create to retry.

## Timer state

Production Schedules require a selected release. Each execution resolves `current` when it starts. Selecting another release changes later executions without rewriting the Schedule or restarting a command that is already active.

A Node Schedule installs with its timer enabled and active and rejects a disabled initial state. An Instance Schedule can install enabled or disabled. Production Instance installation requires a selected `current` release even when the timer is disabled. A disabled installation still becomes `active`, with the timer disabled and stopped. Explicit activation enables and starts the Instance timer, verifies both states, and is idempotent. A failed activation restores the prior desired and actual timer states or returns `schedule.rollback_failed` without claiming success.

Enable turns on and starts the timer of an active Instance Schedule, checks both states, and stores `enabled`. It is idempotent. When it fails, it restores the earlier timer state and returns `schedule.activation_failed`, or `schedule.rollback_failed` when the restore fails too. A Node Schedule refuses enable with `schedule.target_invalid`, because its timer is always on.

Run starts the service with `systemctl start --no-block` and returns at once. It uses the same service, user, timeout, and completion report as a timer run.

## Logs

Logs read only the Schedule's service, with fixed `journalctl --output cat` arguments. `lines` is 1 through 1,000, and the default is 100. The read has a 10-second deadline and returns at most 1 MiB. The response holds `output` with the newest complete lines, oldest first, and sets `truncated` to `true` when the byte limit removed older lines. Logs need an `active` Schedule; otherwise the Gateway returns `schedule.state_invalid`. Orbit does not store journal output, and it does not redact it: Process and Instance logs are redacted, Schedule logs are not. Keep secrets out of command output. The host Node's journal settings decide how long lines stay, and Orbit keeps no copy elsewhere.

## Latest run

When the service stops, the script sends one report to `https://gateway.orbit/api/v1/schedules/{uuid}/complete` with `success` or `error`. It uses `curl` with the Orbit root CA embedded in the script, so the Node needs no Orbit CLI. The Gateway accepts it only from the Schedule's host Node, which it identifies by the WireGuard address. It sets `last_run_at` to the time of receipt and `last_run_status` to the reported status, and changes nothing else. A report for a Schedule that is `removing` changes nothing.

The Node does not retry a failed report. A lost report leaves the earlier values in place and does not affect the command or later runs.

## Remove

Destroy marks the Schedule `removing` and disables and stops its timer. When the service is not running, it deletes the three files, reloads systemd, resets the failed state of both units, checks that the files are gone, and deletes the record. When the service still runs, destroy stops there and returns the Schedule as `removing`. The command finishes on its own. Repeat destroy after it ends to finish the removal. An artifact with the wrong owner, mode, or marker stops removal with `schedule.artifact_conflict`, and the Schedule stays `removing`.

[Instance removal](/reference/instance-removal) removes each Schedule of the Instance without waiting for a running command. It leaves Node Schedules and the Schedules of other Instances in place.

## Error codes

Schedule operations return these codes in the Orbit error envelope. The CLI checks input before it sends a request. The API answers malformed input with `validation.failed` (422).

| Error code | Meaning |
| --- | --- |
| `schedule.name_invalid`, `schedule.calendar_invalid`, `schedule.command_invalid`, `schedule.timeout_invalid` | The input is outside the accepted form, or the host's systemd refuses the calendar. |
| `schedule.target_invalid` | The target does not exist, or the operation does not apply to it. The Gateway also uses it with 403 when a completion report comes from another Node. |
| `schedule.target_unavailable` | The target, its Node, its user, or its working directory cannot run the operation. |
| `schedule.retry_conflict` | The target already has a Schedule with this name and a different specification. |
| `schedule.state_invalid` | The Schedule status does not allow the operation, or another operation on the Instance is running. |
| `schedule.target_in_use` | The change would move or remove the target of an existing Schedule. |
| `schedule.artifact_conflict` | A file at an owned path is not the Schedule's own file. |
| `schedule.install_failed`, `schedule.rollback_failed`, `schedule.run_failed`, `schedule.activation_failed`, `schedule.logs_failed`, `schedule.remove_failed` | The operation did not finish. |
| `schedule.node_unreachable` | SSH to the host Node failed. |

## Inspect Schedule drift

[Doctor](/cli/doctor) checks Schedules in its `schedule` family. It compares each Schedule with its script, service, and timer on the host Node: presence, owner and mode, content, timer state, calendar, execution context, completion report, and placement. It also reports Schedule artifacts that have no record. It changes nothing.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Native systemd timers

systemd already gives timing, overlap protection, timeouts, and a journal on every managed Node. The timer also keeps running while the Gateway is down. A central scheduler, queue, or run-history store in the Gateway would duplicate this and make the Gateway responsible for timing. Do not add one.

### The command only in a protected script

The command is operator input that runs as the target user. It must never become part of a root command line on the Node. So the Gateway writes it only into a script that root owns, and every infrastructure command uses fixed arguments.

### No edit and no automatic move

A Schedule changes only through destroy and create. When its target moves, an existing timer would keep firing on the old Node. So a Schedule never moves by itself, and the operator recreates it on the new placement.

### Only the latest run

The completion report is informational. The Gateway stores only the latest result and time. It keeps no run history, run identity, or generation, and the Node does not retry a report. A retry would look the same as the next run with the same result.

### A stopped timer for copied Schedules

A copied Schedule on a new production Instance can run before the data of that Instance is ready. So an Instance Schedule can install with its timer disabled, and enable is a separate step. A manual run is not an enable, because one run does not start the recurring timer.
