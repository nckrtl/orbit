---
title: "Processes and schedules"
description: "How Orbit runs Processes for an Instance or a Node, and how a Project declares Process and Schedule definitions that production Instances copy."
covers:
  - apps/gateway/app/Actions/Processes/**
  - apps/gateway/app/Domain/Processes/**
  - apps/gateway/app/Infrastructure/Processes/{RemoteProcessRuntimeManager,SystemdProcessRenderer,DockerProcessRenderer,NativeProcessAdmissionLock,NativeProcessRuntimeLease,SshProcessUserResolver}.php
  - apps/gateway/app/Http/{Controllers/Api/ProcessesController,Requests/Processes/*}.php
  - apps/gateway/app/Actions/ProjectDefinitions/**
  - apps/gateway/app/Actions/Instances/InstantiateProjectRuntimeDefinitionsAction.php
  - apps/gateway/app/Http/{Controllers/Api/ProjectRuntimeDefinitionsController,Requests/ProjectDefinitions/*}.php
  - apps/gateway/app/Models/{Process,ProcessDefinition,ScheduleDefinition}.php
---

# Processes and schedules

A Process is one long-running systemd service or Docker container that Orbit manages. It belongs to exactly one owner: an Instance or a managed Node. A Project can also hold Process and Schedule definitions. Production preparation copies them into each new production Instance. [`process`](/cli/process) lists the commands. [Schedules](/reference/schedules) describes Schedules themselves.

## Owners

An Instance Process serves one Instance. The Gateway derives its Node, user, and default working directory from the Instance. [Instance removal](/reference/instance-removal) removes it.

A Node Process serves the Node itself, for example a shared Docker database. It runs as the Node's managed user, with `/home/{managed user}` as its default working directory, unless `user` names another account. It reads no Instance environment file.

### Node account

A Node systemd Process accepts `user`. The CLI flag is `--user`, and the API field is `user`. The name is one letter or underscore, then at most 31 letters, digits, underscores, or hyphens. The unit's `User=` is that name. When `user` is omitted, `User=` stays the derived account. `user` is part of the specification: a second create with the same name and a different account returns `process.name_taken`.

The Gateway checks the named account over SSH with `getent passwd` and defaults the working directory to that account's home. An explicit working directory overrides this default. JSON includes `user`, and it is null when the Process uses the derived account. The Gateway does not create the account. It refuses `root` and accounts with UID zero. An absent account or an invalid account home fails create with `process.user_unavailable` (HTTP 422).

Instance Processes, Docker Processes, presets, and Project definitions reject `user`. The API returns HTTP 422 and names the field `user`. [`process:create`](/cli/process#orbit-processcreate) returns `process.option_invalid` for a rejected combination and `process.user_invalid` for a name that fails the pattern, and it sends no request. `pi-server` uses `--user=orbit-worker`. [Pi server](/reference/pi-server#install-on-a-node) is that install. The Node must be an active Linux Node with a WireGuard address. macOS returns `process.platform_unsupported` (HTTP 422) before SSH. A Node Process stays when an Instance is removed. The Gateway refuses to remove a Node that still owns a Process with `node.has_processes`. [Offline removal](/reference/node-provisioning#remove-a-node) of an unreachable Node deletes its Process records without remote cleanup.

`process:create` and `process:list` take exactly one owner: `--instance`, `--node`, or `--project` for a definition. The API sends `target_type` as `instance` or `node` with a positive `target_id`. Start, stop, restart, logs, and destroy take the Process ID and use that record's owner.

## App target

An Instance Process, Instance Schedule, Process definition, or Schedule definition stores one app name. The API and MCP use top-level `app`. The CLI uses `--app=NAME` on `process:create`, `process:update`, `schedule:create`, and `schedule:update`. PHP SDK requests and responses use `$app`. Responses and lists include the stored `app`; lists have no app filter.

Omission resolves and stores the sole app of the Project. On a Project with several apps, omission returns `app.required` (422). An unknown app returns `app.not_found` (422). A Node Process or Schedule stores null and refuses a selector with `app.selector_unsupported` (422). A definition is checked against its Project's apps, and each copy keeps the app.

Start, stop, restart, run, logs, and removal by resource ID use the stored app. The migration to named apps assigns `web` to existing Instance records and definitions; Node records keep null. A Project update cannot remove an app that a definition names; it returns `project.app_in_use`.

Default working directories and environment files use the app's path. An explicit Process working directory still overrides the default; Docker keeps `/app`. Presets take their working directory, origins, certificates, and ports from the same app, and each preset is unique per Instance/app pair. Hibernation still covers every app of the Instance.

## Runtimes

A Process uses one of two runtimes. Each runtime takes a complete specification.

| Runtime | Required | Optional | Default working directory |
| --- | --- | --- | --- |
| systemd | Name, and an absolute executable with its arguments | Working directory, restart policy, keep-alive, initial start, and `user` on a Node | The Instance's application directory in its checkout on `app-dev`, or under `<production-home>/current` on `app-prod`, or the selected account's home for a Node (`/home/{managed user}` when `user` is omitted) |
| Docker | Name, image, and command arguments | Working directory, environment, published ports, volumes, restart policy, keep-alive, initial start | `/app` |

The command has at most 64 arguments of 4,096 bytes each. The restart policy is `never` (the default), `on-failure`, `always`, or `unless-stopped`. The API accepts `environment` only for Docker.

The systemd unit is `orbit-process-{id}-{name}.service`, and the Docker container is `orbit-process-{id}-{name}`. Before the Gateway replaces or removes one, it checks the exact Orbit Process ID marker. It never overwrites, adopts, or deletes a unit or container with another marker.

## Environment of a systemd Process

The unit sets `PATH` and `NODE_USE_SYSTEM_CA=1`. An Instance Process then reads its app's `.env` file: in that app's [application directory](/reference/projects#application-directory) on `app-dev`, or in the production home on `app-prod`. With app path `apps/site`, the default working directory is `<checkout>/apps/site` in development, or `<production-home>/current/apps/site` for a Laravel app in production. App path `.` keeps the checkout or release root. An explicit working directory overrides this default; Docker's `/app` default and Node Process defaults stay unchanged.

A Process can also store an environment map in its specification. The unit receives each pair as an `Environment=` directive, never on `ExecStart`. The unit file under `/etc/systemd/system` is written with mode `0644`, so these values sit in plain text that every local user on the Node can read. For the proxycli collector that includes its management key and tokens. WireGuard membership and Node access are the security boundary. Gateway-owned features store such a map, for example the [proxycli](/reference/proxycli) collector. The public create API does not accept one for systemd.

The derived keys always win: `PATH`, `NODE_USE_SYSTEM_CA`, `VITE_DEV_SERVER_CERT`, `VITE_DEV_SERVER_KEY`, the `ORBIT_DEV_SERVER_*` keys, the Agentation keys, and the SSR keys when the Instance has an SSR port.

A development Process also receives `VITE_DEV_SERVER_CERT` and `VITE_DEV_SERVER_KEY`, the paths of its app's certificate files under `~/.orbit/certificates/app-instance-{id}-app-{app}/current/`. An Instance created before named apps can keep `app-instance-{id}` until Orbit moves it to the app identity. The `vp-dev` preset does not receive them. When its app has a Route, it receives `ORBIT_DEV_SERVER_ORIGIN`, `ORBIT_DEV_SERVER_HOST`, and `ORBIT_DEV_SERVER_PATH` for the [development-server endpoint](/reference/routes#development-server-endpoint), and `ORBIT_DEV_SERVER_PORT` only when `vite_port` is assigned. It also receives the Agentation keys when it has an Agentation port, and `ORBIT_SSR_PORT` and `INERTIA_SSR_URL` when the Instance has an [assigned SSR port](/reference/assigned-ssr-ports), with or without a Route. These derived values are not secret. The unit carries them both as `Environment=` directives and on `ExecStart` through `/usr/bin/env`.

## Presets

A preset configures a development Instance Process. It sets the command, the runtime, the working directory, and the environment, and it refuses those options from the caller.

| Preset | Result |
| --- | --- |
| `vp-dev` | Runs VitePlus on an [assigned Vite port](/reference/assigned-vite-ports). The default restart policy is `on-failure`. |
| `annotator` | Installs the server and injection asset, reserves a port until proxy withdrawal, and publishes `/__orbit/annotator`. Uses systemd with restart on failure and refuses keep-alive. |
| `agentation-mcp` | Runs the [Agentation](/reference/agentation) HTTP server, assigns its port, and publishes `/__orbit/agentation`. It refuses keep-alive. |
| `antigravity-watch` | Runs the Agentation watcher. It needs the `agentation-mcp` Process and refuses keep-alive. Its default restart policy is `always`. |

Explicit start or restart activates a stopped annotator. An identical create still respects its desired state. Sibling environment projection rewrites units without activation, including for sleeping workers and cold dependencies. See [Annotator Process](/reference/agentation#annotator-process).

## Create a Process

Create installs the unit or container. With `start`, it also starts it. An identical create returns the existing Process and keeps its desired state. A different specification with the same owner and name returns `process.name_taken` (409) and changes nothing. To change a Process, destroy it and create it again.

On a Node with the active `app-dev` role, a systemd Instance Process installs without a boot start. A Docker Instance Process there maps restart policy `always` to Docker `unless-stopped`, so an idle stop survives a Docker daemon restart. Node Processes on the same Node keep the normal behavior. The Gateway starts it with `systemctl start` and does not enable the unit. After a reboot the Process stays down until `process:start` or the next HTTP wake. [Hibernation](/reference/app-dev-runtime-hibernation) describes idle stop, `keep_alive`, and wake.

An Instance on `app-prod` with no selected release accepts a stopped Process. A start fails with `process.release_unavailable` until [a deployment](/reference/deployments) selects a release. A later start uses the release that `current` selects then. A new release does not restart a running Process.

## Operate a Process

The Gateway exposes these Process endpoints.

| Request | Result |
| --- | --- |
| `GET /api/v1/processes` | The Processes with desired state, runtime status, `keep_alive`, `cpu`, and `memory_bytes`. `target_type` and `target_id` limit the list to one owner. |
| `POST /api/v1/processes` | Creates one Process. |
| `POST /api/v1/processes/{process}/start`, `/stop`, `/restart` | Changes the runtime and stores the desired state: `running` after start and restart, `stopped` after stop. |
| `GET /api/v1/processes/{process}/logs?lines=N` | A tail of 1 through 1,000 lines over SSH. See [Live logs](/reference/live-logs) to follow new lines. |
| `DELETE /api/v1/processes/{process}` | Stops the Process, removes its unit or container, and deletes the record. The Process of a [Database server](/reference/database-servers) is refused with `process.required_by_database_server` (409); remove the server instead. |

Creating or starting an Instance Process needs an active Instance on an active Node. Creating or starting a Node Process needs an active managed Node. Otherwise the Gateway refuses with `process.target_inactive` before it changes anything. Removal can use the recorded placement of a failed or removing Instance while its Node is active.

`cpu` is a ratio of one core and `memory_bytes` is bytes. Both come from the [Metrics role](/reference/metrics#cadvisor) and are null when the Process is not running or no sample exists. Responses, Activity, and errors show every stored environment value, Docker or systemd, as `[REDACTED]`.

Removing a systemd Process disables and stops the unit, deletes the unit file, reloads systemd, and resets the unit's failed state. A crashed unit therefore leaves no `failed` entry in `systemctl list-units`.

### Process runtime state

Desired state is stored intent: `running` after start or restart, and `stopped` after stop. Runtime status is an observation, not a health guarantee. Lists use the [runtime status index](/reference/metrics#process-runtime-status), which can show a systemd unit as `active` between crashes. Doctor inspects the runtime on the Node rather than treating a cached list status as proof of health.

For a systemd Process desired `running`, a crash loop means either `ActiveState=activating` with `SubState=auto-restart`, or an `NRestarts` count that increases between observations during the same bounded inspection. An `active` sample does not cancel evidence of repeated restarts. A nonzero restart count left by earlier restarts alone does not qualify. Ordinary startup in `activating` without `auto-restart` or an increasing restart count is not a crash loop, though it can still differ from the desired running state. A Process desired `stopped` uses the ordinary state comparison instead. This crash-loop rule does not apply to Docker Processes.

Doctor reports `process.crash_loop` as `drift`, with the Process ID and name and `expected=running`. Its bounded `observed` evidence carries the unit's active state and sub-state, plus the restart counts when available. For example, `activating` / `auto-restart` explains a restart wait, while an `active` / `running` sample with `NRestarts` increasing from 3 to 4 explains a restart observed between samples. Doctor emits this finding instead of an additional `process.state_mismatch` for that observation. It reads only and never starts, stops, or restarts the unit.

## Locks

The Gateway holds one runtime lock for each Process while it reads the record, changes the runtime, and writes the result. A competing request gets `process.runtime_lock_failed` and changes nothing.

Create, start, and restart of an Instance Process first take the Instance's operation lock. A competing request waits up to 30 seconds, or the rest of its command deadline, and then gets `process.operation_busy`. Node Processes skip that lock. Lifecycle commands that contend for the Instance lifecycle lock return `instance.lifecycle_busy` (409, with `details.outcome` set to `busy`); retry after the other lifecycle operation finishes.

Systemd replacement installs a checked candidate unit and restores the earlier unit when activation fails. Docker replacement keeps or restores the earlier container.

## Project definitions

A Project holds Process definitions and Schedule definitions. A definition has a UUID, a name that is unique within the Project and kind, an `app` selector, an `environments` list, and a `spec`. The app is a top-level field, not hidden inside `spec`.

| Field | Contract |
| --- | --- |
| `name` | 1 through 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. A Schedule definition name also allows no two hyphens in a row. |
| `environments` | Must include `production` for the definition to have an effect. Production preparation copies only definitions that include it. |
| `spec` | For a Process: the runtime fields above, without owner, start, user, or environment file. For a Schedule: `command`, `calendar`, and `timeout_seconds`, with the limits of a Schedule. |

A Schedule definition has no host Node, so the Gateway does not run `systemd-analyze calendar` when it stores one. The host Node checks the calendar when production preparation installs the copy.

| Kind | Collection | Item |
| --- | --- | --- |
| Process | `GET`, `POST /api/v1/projects/{project}/process-definitions` | `GET`, `PUT`, `DELETE /api/v1/projects/{project}/process-definitions/{name}` |
| Schedule | `GET`, `POST /api/v1/projects/{project}/schedule-definitions` | `GET`, `PUT`, `DELETE /api/v1/projects/{project}/schedule-definitions/{name}` |

These routes need an access grant to one Node that runs an Instance of the Project, or to the Gateway Node when the Project has no Instance. `PUT` replaces the whole definition. The API refuses unknown or duplicate members. Lists omit `spec.command`. An item response returns the complete definition with its UUID.

A definition change touches only the Project. It makes no remote call and does not change any existing copy. Removing an Instance keeps the definitions. Removing a Project deletes them.

## Production copies

[Cloning](/reference/instance-cloning) a production Instance copies the Project's definitions into that Instance. The Gateway captures every definition whose `environments` include `production`, once for each target. It ignores every other definition and the candidate's own Processes and Schedules.

Each Process definition becomes a new Instance Process with its own ID, installed stopped. Each Schedule definition becomes a new Instance Schedule with its own UUID, installed with its timer disabled. The copies do not need a selected release, and preparation runs no application code. Start them with [`process:start`](/cli/process#orbit-processstart) and [`schedule:enable`](/cli/schedule#orbit-scheduleenable) when the Instance is ready.

A copy that the Instance already has by name stops preparation with `process.name_taken` or `schedule.retry_conflict`. The Gateway does not adopt the existing record. A retry installs only the unfinished copies from the captured set. It does not read the definitions again, rewrite a finished copy, or stop a copy that an operator started.

A copy is independent. Changing or removing a copy does not change the definition. Changing a definition does not change existing copies.

## Inspect with Doctor

[Doctor](/cli/doctor) compares the desired state of each Instance and Node Process with the systemd or Docker status on the Node. It reports a missing runtime, a state mismatch, a [systemd crash loop](#process-runtime-state), a failed inspection, or an unreachable Node, and changes nothing. A sleeping development Process is not a mismatch; see [hibernation](/reference/app-dev-runtime-hibernation).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Node Processes

Shared services, such as a Docker database, have no Instance. A synthetic Instance would give them the Instance removal lifecycle, which does not fit. So a Process can belong to a Node. Workspace owners do not exist.

### Definitions on the Project, copies on the Instance

A candidate can run test options or a development-only server, such as Vite. Those choices must not become production defaults. So production copies come from the Project's definitions, not from the candidate. Live inheritance is a rejected alternative: one definition edit would change every running production Instance.

### Copies start stopped

A copied worker or Schedule can run before the new Instance's data is ready, for example with copied queue jobs. So preparation installs every copy stopped, and the operator starts each one.

### Managed environment as `Environment=` directives

A Node Process has no Instance `.env` file, but Gateway-owned Processes need secrets. Values on `ExecStart`, for example through `/usr/bin/env`, would put secrets in the process list. So the unit carries the stored map as `Environment=` directives. The cost is that the values sit in the mode-`0644` unit file. A separate mode-`0600` environment file is a possible later change and is not built.

The `vp-dev`, `agentation-mcp`, and `antigravity-watch` presets apply only to development Instance Processes. Runtime definitions are copied only to production Instances.

### A Node Process can name its account

The systemd `User=` of a Node Process can be an account other than the managed user. `pi-server` uses this to run as `orbit-worker`. The owner stays the Node. An Instance Process keeps the account derived from the Instance, because a chosen account would leave the production home and the development checkout. Docker rejects `user` because the image has its own user. The Gateway does not create the account. The Gateway checks the account with `getent passwd` over SSH before create and fails with `process.user_unavailable` when the account or its home is unavailable.
