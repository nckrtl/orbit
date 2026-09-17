---
title: "schedule"
description: "Create, run, inspect, enable, and remove recurring commands that native systemd timers execute on a Node or an App instance, and record App-owned Schedule definitions."
commands:
  - schedule:create
  - schedule:list
  - schedule:show
  - schedule:update
  - schedule:run
  - schedule:logs
  - schedule:enable
  - schedule:destroy
---

A Schedule is Gateway intent for one recurring command. The Gateway projects it to a protected script, a oneshot service, and a persistent timer on the host Node, named only by the Schedule UUID. The `schedule` family creates that intent, runs it by hand, reads its logs, activates a stopped timer, and removes it.

The [Schedules reference](/reference/schedules) owns the stored fields, execution context, systemd artifacts, latest-run reporting, and error codes. The [App process and Schedule definitions](/reference/app-processes-and-schedules) reference owns App-owned definitions and their production copies.

## Choose the owner

`schedule:create` needs exactly one selector. The CLI refuses two selectors together, a missing selector, `--for` without `--app`, and `--no-start` with `--node` before it sends a request, and it never prompts for a target.

| Selector | Owner | Result |
| --- | --- | --- |
| `--node=ID` | Node | The command runs as the Node's managed `orbit` user. The timer installs enabled. |
| `--instance=ID` | App instance | The command runs as the App instance runtime user in its checkout or `current` release. The timer can install disabled. |
| `--app=APP --for=ENV[,ENV]` | App definition | The Gateway records a reusable definition. Production preparation copies it into a stopped App instance Schedule. |

`schedule:show`, `schedule:update`, and `schedule:destroy` with `--app` select a definition by name. `schedule:run`, `schedule:logs`, and `schedule:enable` take the UUID of an installed Schedule and do not accept `--app`.

## Commands

| Command | Result |
| --- | --- |
| [`schedule:create`](#orbit-schedulecreate) | Create one Node or App instance Schedule, or record one App definition. |
| [`schedule:list`](#orbit-schedulelist) | List authorized Schedules or one App's definitions. |
| [`schedule:show`](#orbit-scheduleshow) | Show one Schedule or one definition. |
| [`schedule:update`](#orbit-scheduleupdate) | Replace one App Schedule definition. |
| [`schedule:run`](#orbit-schedulerun) | Run one Schedule now without changing its timer. |
| [`schedule:logs`](#orbit-schedulelogs) | Return a bounded log tail. |
| [`schedule:enable`](#orbit-scheduleenable) | Enable and start an installed App instance timer. |
| [`schedule:destroy`](#orbit-scheduledestroy) | Remove one Schedule or one definition. |

Every command accepts `--json`. Output shows `desired_timer_state` separately from the lifecycle `status`, and every result includes the Gateway request ID. Human output uses the shared table, detail tree, and progress helpers. `schedule:destroy` requires default-No confirmation or `--yes`. `schedule:create` still requires exactly one explicit target in every mode.

{/* commands */}

## Error codes

The Gateway returns these stable codes for Schedule operations.

| Code | Meaning |
| --- | --- |
| `schedule.name_invalid`, `schedule.calendar_invalid`, `schedule.command_invalid`, `schedule.timeout_invalid` | An input is outside the accepted form or size. The CLI checks these before it sends a request. |
| `schedule.target_invalid`, `schedule.target_unavailable` | The target is invalid, or its derived execution context cannot run the operation. |
| `schedule.retry_conflict` | The owner and name already identify a different specification. |
| `schedule.state_invalid` | The lifecycle state does not permit the operation. |
| `schedule.target_in_use` | A Node or App instance change would invalidate an existing Schedule. |
| `schedule.artifact_conflict` | A named host artifact is not the exact object the Schedule owns. |
| `schedule.install_failed`, `schedule.rollback_failed`, `schedule.run_failed`, `schedule.activation_failed`, `schedule.logs_failed`, `schedule.remove_failed` | The named operation did not complete. |
| `schedule.node_unreachable` | The host Node could not be reached. |

## Related

- [`process`](/cli/process) manages long-running services; Schedules are timers, not Processes.
- [`doctor`](/cli/doctor) with `--family=schedule` reports artifact, timer, calendar, and placement drift.
- [`instance`](/cli/instance) removal disables and removes every owned Schedule without waiting for an active command.
