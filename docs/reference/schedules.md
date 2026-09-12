# Schedules

This page tells an operator how the Gateway stores a Schedule, projects it to native systemd execution, reports its latest result, and removes its owned state. [ADR 0013](../decisions/0013-native-systemd-schedule-management.md) owns native timer execution, [ADR 0038](../decisions/0038-cascade-appinstance-removal-through-processes-and-schedules.md) owns AppInstance cleanup, [ADR 0048](../decisions/0048-copy-app-process-and-schedule-definitions-into-appinstances.md) owns stopped AppInstance installation and explicit activation, and [ADR 0060](../decisions/0060-record-latest-schedule-run-status.md) owns latest-run reporting.

## Store one target-owned Schedule

The Gateway stores one Schedule for exactly one Node or AppInstance target. The Schedule UUID is its public identity and the only value used to name its host artifacts.

| Value | Contract |
| --- | --- |
| `id` | An immutable UUID. |
| `target` | Exactly one Node or AppInstance. |
| `name` | Unique within the target. It contains 1 through 63 lowercase ASCII letters or numbers, with hyphens only between them. |
| `calendar` | One printable ASCII line of at most 255 bytes, accepted only when the target Node's `systemd-analyze calendar` accepts it. |
| `command` | One non-empty UTF-8 line of at most 4,096 bytes. NUL, carriage return, and line feed are invalid. |
| `timeout` | An integer from 1 through 86,400 seconds. The default is 3,600 seconds. |
| `status` | `provisioning`, `active`, `failed`, or `removing`. |
| `desired_timer_state` | `enabled` or `disabled`. |
| `last_run_at` | The nullable time when the Gateway last accepted a completion report. |
| `last_run_status` | Nullable `success` or `error`. |

An identical add retries or returns the same Schedule without changing its desired timer state. A different specification with the same target and name returns `schedule.retry_conflict`. Orbit changes a specification only when the operator removes the Schedule and adds its replacement.

## Use the Schedule API

An active Gateway peer uses eight endpoints under `/api/v1/schedules`. Every endpoint requires Node access to the Schedule's target Node. The collection contains only Schedules whose target Node the caller may address, and a completion report is accepted only from the Schedule's recorded host Node.

| Operation | Request | Result |
| --- | --- | --- |
| List | `GET /api/v1/schedules` | Returns authorized Schedule summaries without command text. |
| Add | `POST /api/v1/schedules` | Accepts one complete Schedule specification and returns bounded Schedule data. |
| Show | `GET /api/v1/schedules/{uuid}` | Returns bounded Schedule data, including command text, to an authorized caller. |
| Run | `POST /api/v1/schedules/{uuid}/run` | Starts the installed service without changing the desired timer state and returns bounded Schedule data. |
| Logs | `GET /api/v1/schedules/{uuid}/logs` | Returns bounded output for the exact service and reports whether older or incomplete output was removed. |
| Complete | `POST /api/v1/schedules/{uuid}/complete` | Records the latest result from the installed Node and returns no content. |
| Remove | `DELETE /api/v1/schedules/{uuid}` | Starts or resumes exact-owned cleanup and returns bounded Schedule data. |
| Activate | `POST /api/v1/schedules/{uuid}/activate` | Accepts an empty body, enables an AppInstance timer, and returns bounded Schedule data. |

Add accepts `target_type`, `target_id`, `name`, `calendar`, and `command`. It also accepts optional `timeout_seconds` and boolean `start`; the timeout defaults to 3,600 seconds, and `start` defaults to `true`. A Node target rejects `start: false`. An AppInstance target can install with its timer disabled.

List and show expose `desired_timer_state` as `enabled` or `disabled`, independent of the installation lifecycle `status`. A request for an unknown Schedule UUID with valid syntax returns `404`. A malformed JSON object, duplicate or escaped-duplicate member, unsupported member, or wrong member type returns `422` before the Gateway changes Schedule intent or host state.

The Gateway records one sanitized Activity for list, add, show, run, logs, remove, and activate. Each record can identify the operation, Schedule UUID, target, result, and request, but it contains no command, calendar, journal line, output, path, runtime user, or systemd unit text. Completion creates no Activity.

## Derive the execution context

The Gateway derives the host Node, runtime user, home, working directory, and shell from authoritative target placement. It rejects caller-supplied values for those fields and refuses an unavailable target or unusable derived account before remote mutation.

| Target | Execution context |
| --- | --- |
| Node | The Node's managed `orbit` user, home, and non-interactive login-shell context. |
| Development AppInstance | The host Node, managed application-development runtime user and home, recorded checkout working directory, and derived non-interactive login-shell context. |
| Production AppInstance | The host Node, dedicated production user and home, fixed `/bin/bash` without login, and the production home's `current` working directory. |

Each production execution resolves `current` when it starts. Selecting another release changes later executions without rewriting the Schedule or restarting a command that is already active. A production AppInstance without `current` can accept a Schedule whose timer starts disabled, but a manual run or activation returns `schedule.target_unavailable` until a release is selected.

Removing a target Node or host Node, or changing a target's Node, user, home, or stable working-directory path, returns `schedule.target_in_use` while the Schedule exists. AppInstance removal uses the owned cascade instead of this guard.

## Project protected systemd artifacts

The Gateway projects exactly three artifacts that root owns. Their names depend only on the Schedule UUID.

| Artifact | Behavior |
| --- | --- |
| Protected script | Contains the caller command, runs as the derived user, and is not writable or readable by unrelated unprivileged users. |
| Oneshot service | Uses the derived user and working directory, stored timeout, and fixed protected-script path. It contains no caller command text. |
| Persistent timer | Uses the accepted calendar and triggers the oneshot service without overlapping an active execution. It contains no caller command text. |

The caller command appears only in the protected script. It never appears in an SSH, `sudo`, `systemctl`, `journalctl`, `systemd-analyze`, or other infrastructure argument.

A Node Schedule installs with its timer enabled and active and rejects a disabled initial state. An AppInstance Schedule can install enabled or disabled. A disabled installation still becomes `active`, with the timer disabled and stopped. Explicit activation enables and starts the AppInstance timer, verifies both states, and is idempotent. A failed activation restores the prior desired and actual timer states or returns `schedule.rollback_failed` without claiming success.

A manual run starts the same oneshot service without waiting for completion and never changes the desired timer state. The persistent timer lets systemd run one missed occurrence after Node downtime.

## Read bounded logs

The Gateway reads only the exact Schedule service with fixed `journalctl` arguments. A request defaults to 100 lines and accepts 1 through 1,000 lines, a maximum response size of 1 MiB, and a 10-second deadline.

The response keeps the newest complete lines. It sets `truncated` to `true` when the byte limit removes older or incomplete output. Orbit does not persist journal output.

## Record the latest run

After the command finishes, the host Node makes one authenticated completion callback. The Gateway accepts it only from the Schedule's recorded host Node and replaces `last_run_at` with its receipt time and `last_run_status` with `success` or `error`.

The callback updates no other Schedule field. The Node does not retry a failed callback, and a failure leaves the prior latest-run metadata, command result, and later executions unchanged. Orbit stores no run identity, ordering value, projection generation, retry state, or run history.

## Recover installation and activation

The Gateway validates target state, ownership, and calendar before it changes the active projection. It stages the candidate artifacts, stops the timer, places the protected script, verifies the staged units, and then publishes and activates the units. It locks the Schedule transaction and host artifact set throughout convergence, and a failure restores the prior artifacts and timer state.

An unexpected file, unit, owner, mode, or identity at an owned name returns `schedule.artifact_conflict`. Orbit does not adopt, overwrite, or delete the conflicting object.

When a matching add fails while converging an existing Schedule, the Gateway restores the exact prior owned artifacts and timer state. A failed first installation removes only artifacts created by that attempt. If restoration fails, the Schedule stays non-active and returns `schedule.rollback_failed` for safe retry.

## Remove owned state

Standalone removal marks the Schedule `removing`, disables and stops only its timer, and inspects the service directly. It lets an active command finish before it removes the exact timer, service, script, and Schedule record. A failure leaves the Schedule `removing`, and an identical retry resumes cleanup without depending on a completion callback.

AppInstance removal first completes source preflight and prevents new Schedule attachment to every accepted member. It then disables each owned timer and removes every owned Schedule record and persistent artifact without waiting for an active command. A late or racing callback cannot recreate Schedule intent, restore artifacts, bypass `removing`, or delay AppInstance removal.

A failed cascade keeps the AppInstance removal and unfinished Schedule cleanup resumable. Retry processes only recorded unfinished owned work. Node-owned Schedules, other AppInstances' Schedules, and unrecognized artifacts on the same Node remain unchanged.

## Handle stable operation errors

Gateway Schedule operations return only these stable domain error codes.

| Error code | Meaning |
| --- | --- |
| `schedule.name_invalid` | The name is outside the accepted form or size. |
| `schedule.target_invalid` | The target type or identity is invalid. |
| `schedule.target_unavailable` | The derived target context cannot run the requested operation. |
| `schedule.calendar_invalid` | The calendar input or host systemd validation failed. |
| `schedule.command_invalid` | The command is empty, malformed, multiline, or too large. |
| `schedule.timeout_invalid` | The timeout is outside the accepted integer range. |
| `schedule.retry_conflict` | A target and name already identify a different specification. |
| `schedule.state_invalid` | The lifecycle state does not permit the operation. |
| `schedule.target_in_use` | A placement or identity change would invalidate an existing Schedule. |
| `schedule.artifact_conflict` | A named host artifact is not the exact object owned by the Schedule. |
| `schedule.install_failed` | Installation did not complete. |
| `schedule.rollback_failed` | Recovery could not restore the exact prior state. |
| `schedule.run_failed` | The manual service start failed. |
| `schedule.activation_failed` | Explicit timer activation did not complete. |
| `schedule.logs_failed` | The bounded journal read failed. |
| `schedule.remove_failed` | Standalone or cascading cleanup did not complete. |
| `schedule.node_unreachable` | The required host Node could not be reached. |

## Inspect Schedule drift

Doctor checks Schedule as the explicit `schedule` family in its canonical order. It compares stored intent with bounded read-only host observations and never installs, reloads, enables, starts, stops, completes, repairs, adopts, or removes Schedule state.

| Doctor issue code | Difference |
| --- | --- |
| `schedule.artifact_missing` | A required owned artifact is absent. |
| `schedule.artifact_permissions_mismatch` | An artifact owner or mode differs. |
| `schedule.specification_mismatch` | A service, timer, or script fingerprint differs. |
| `schedule.timer_state_mismatch` | Enabled, disabled, active, or stopped state differs from intent. |
| `schedule.calendar_mismatch` | The timer calendar differs. |
| `schedule.execution_context_mismatch` | The runtime user, shell, home, or working context differs. |
| `schedule.completion_callback_mismatch` | The protected completion callback projection differs. |
| `schedule.placement_mismatch` | Installed Node placement differs from target placement. |
| `schedule.orphan_artifact` | A UUID-named artifact in the owned namespace has no Schedule record. |
| `schedule.node_unreachable` | The host Node cannot be inspected. |
| `schedule.inspection_failed` | Inspection is malformed or fails. |

Doctor issues, Activity, errors, and generic diagnostics contain no command, calendar, journal line, path, user, unit content, credential, raw output, exit code, signal, or exception text. Doctor values use only bounded booleans, enums, identifiers, fingerprints, and fixed sentinels.

## Limits

Schedule owns no Workspace or Orbit-wide target, central scheduler, queue, worker, run-history store, replay, backfill, automatic movement, failover, or specification edit. It does not place the public Orbit CLI on workload Nodes. Orbit exposes no PHP software development kit or CLI Schedule operation.
