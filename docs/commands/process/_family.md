---
title: "process"
description: "Install, start, stop, inspect, and remove systemd services and Docker containers owned by an App instance or a Node, and record App-owned process definitions."
commands:
  - process:create
  - process:list
  - process:show
  - process:update
  - process:start
  - process:stop
  - process:restart
  - process:logs
  - process:destroy
---

A Process is Gateway intent for one systemd service or Docker container. It belongs to exactly one owner: an App instance, a managed Node, or, as a reusable definition, an App. The `process` family installs the runtime artifact, changes its desired state, reads a bounded log tail, and removes it.

The [App process and Schedule definitions](/reference/app-processes-and-schedules) reference owns the runtime contract, the owner rules, and the App-definition copy model. The [hibernation reference](/reference/app-dev-runtime-hibernation) owns idle halt and wake for development App instance Processes.

## Choose the owner

`process:create` and `process:list` need exactly one selector. The CLI refuses two selectors together and refuses `--for` without `--app` before it sends a request.

| Selector | Owner | Result |
| --- | --- | --- |
| `--instance=ID` | App instance | The Gateway derives the host Node and runtime user from the App instance. |
| `--node=ID-or-name` | Managed Node | The Process runs as the Node's managed runtime user for shared infrastructure such as a Docker database. |
| `--app=APP --for=ENV[,ENV]` | App definition | The Gateway records a reusable definition for `development`, `production`, or both. Production preparation copies it into a new App instance Process. |

`process:show`, `process:update`, and `process:destroy` with `--app` select a definition by name. `process:start`, `process:stop`, `process:restart`, `process:logs`, and `process:destroy` without `--app` take the numeric Process ID from `process:list`.

## Commands

| Command | Result |
| --- | --- |
| [`process:create`](#orbit-processcreate) | Install one Process or record one App process definition. |
| [`process:list`](#orbit-processlist) | List the Processes of one App instance or Node, or the definitions of one App. |
| [`process:show`](#orbit-processshow) | Show one App process definition. |
| [`process:update`](#orbit-processupdate) | Replace one App process definition. |
| [`process:start`](#orbit-processstart) | Start one Process and record the running desired state. |
| [`process:stop`](#orbit-processstop) | Stop one Process and record the stopped desired state. |
| [`process:restart`](#orbit-processrestart) | Restart one Process. |
| [`process:logs`](#orbit-processlogs) | Return a bounded log tail. |
| [`process:destroy`](#orbit-processdestroy) | Remove one Process and its artifacts, or one definition. |

Every command accepts `--json`. Human and JSON output include the Gateway request ID. Human output uses the shared table, detail tree, and progress helpers. Process list and detail include lifecycle state, failed step, and error code. Human definition show, create, update, and destroy include the complete specification, including `command`. Process lists still omit `spec.command`. Process environment values stay redacted. `process:destroy` requires default-No confirmation or `--yes`.

{/* commands */}

## Related

- [`schedule`](/cli/schedule) records recurring commands; Process commands do not operate on Schedules.
- [`instance`](/cli/instance) removal cleans up every owned Process; Node-owned Processes stay in place.
- [`doctor`](/cli/doctor) with `--family=process` compares desired and observed state without changing it.
- [`database`](/cli/database) connections can align with a same-Node Docker Process on a published port. [`database:user:create`](/cli/database#orbit-databaseusercreate) creates a MySQL user and database through that Process.
