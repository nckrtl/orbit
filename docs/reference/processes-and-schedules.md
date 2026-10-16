---
title: "Processes and schedules"
description: "How Orbit runs Processes for an Instance or a Node, and how a Project declares Process and Schedule definitions that production Instances copy."
covers:
  - apps/gateway/app/Actions/{Processes,ProjectDefinitions}/**
  - apps/gateway/app/{Domain,Data}/Processes/**
  - apps/gateway/database/migrations/*process*
  - apps/gateway/app/Infrastructure/Processes/{RemoteProcessRuntimeManager,SystemdProcessRenderer,DockerProcessRenderer,NativeProcessAdmissionLock,NativeProcessRuntimeLease,SshProcessUserResolver}.php
  - apps/gateway/app/Http/{Controllers/Api/ProcessesController,Requests/Processes/*}.php
  - apps/gateway/app/Actions/Instances/InstantiateProjectRuntimeDefinitionsAction.php
  - apps/gateway/app/Http/{Controllers/Api/ProjectRuntimeDefinitionsController,Requests/ProjectDefinitions/*}.php
  - apps/gateway/app/Models/{Process,ProcessDefinition,ScheduleDefinition}.php
---

# Processes and schedules

A Process is one long-running systemd service or Docker container that Orbit manages. It belongs to exactly one owner: an Instance or a managed Node. A Project can also hold Process and Schedule definitions. Production preparation copies them into each new production Instance. [`process`](/cli/process) lists the commands. [Schedules](/reference/schedules) describes Schedules themselves.

## Owners

An Instance Process serves one Instance. The Gateway derives its Node and systemd user and default working directory from the Instance. Docker keeps the image's working directory instead. [Instance removal](/reference/instance-removal) removes it.

A Node Process serves the Node itself, for example a shared Docker database. A systemd Process runs as the Node's managed user, with `/home/{managed user}` as its default working directory, unless `user` names another account. A Docker Process keeps the image's working directory unless an explicit directory is supplied. A Node Process reads no Instance environment file.

### Node account

A Node systemd Process accepts `user`. The CLI flag is `--user`, and the API field is `user`. The name is one letter or underscore, then at most 31 letters, digits, underscores, or hyphens. The unit's `User=` is that name. When `user` is omitted, `User=` stays the derived account. `user` is part of the specification: a second create with the same name and a different account returns `process.name_taken`.

The Gateway checks the named account over SSH with `getent passwd` and defaults the working directory to that account's home. An explicit working directory overrides this default. JSON includes `user`, and it is null when the Process uses the derived account. The Gateway does not create the account. It refuses `root` and accounts with UID zero. An absent account or an invalid account home fails create with `process.user_unavailable` (HTTP 422).

Instance Processes, Docker Processes, presets, and Project definitions reject `user`. The API returns HTTP 422 and names the field `user`. [`process:create`](/cli/process#orbit-processcreate) returns `process.option_invalid` for a rejected combination and `process.user_invalid` for a name that fails the pattern, and it sends no request. For example, `--user=deploy` runs the unit as the existing account `deploy`. The Node must be an active Linux Node with a WireGuard address. macOS returns `process.platform_unsupported` (HTTP 422) before SSH. A Node Process stays when an Instance is removed. The Gateway refuses to remove a Node that still owns a Process with `node.has_processes`. [Offline removal](/reference/node-provisioning#remove-a-node) of an unreachable Node deletes its Process records without remote cleanup.

`process:create` and `process:list` take exactly one owner: `--instance`, `--node`, or `--project` for a definition. The API sends `target_type` as `instance` or `node` with a positive `target_id`. Update, start, stop, restart, logs, and destroy take the Process ID and use that record's owner.

## Runtimes

A Process uses one of two runtimes. Each runtime takes a complete specification.

| Runtime | Required | Optional | Default working directory |
| --- | --- | --- | --- |
| systemd | Name, and an absolute executable with its arguments | Working directory, restart policy, keep-alive, initial start, and `user` on a Node | The Instance's application directory in its checkout on `app-dev`, or under `<production-home>/current` on `app-prod`, or the selected account's home for a Node (`/home/{managed user}` when `user` is omitted) |
| Docker | Name, image, and command arguments | Working directory, environment, published ports, volumes, restart policy, keep-alive, initial start | The image's `WORKDIR` |

For Docker, an omitted or null `working_directory` means no Orbit override. The Gateway stores null and omits Docker's `--workdir` option, so the image's `WORKDIR` takes effect. If the image declares no `WORKDIR`, Docker uses its own default. An explicit absolute directory is stored and passed as `--workdir`; Orbit never substitutes `/app`. Systemd keeps its owner-derived default.

Existing Docker Processes keep their stored working directory, including `/app`. A schema migration does not clear that value or reinterpret it as the image default. A Gateway release or Node convergence does not rewrite units or replace or restart containers whose specification has not changed. This also applies to the collector when its script and environment are unchanged. Choosing the image default for an existing Process is an explicit operator update, not an automatic repair.

The command has at most 64 arguments of 4,096 bytes each. The restart policy is `never` (the default), `on-failure`, `always`, or `unless-stopped`. The API accepts `environment` only for Docker.

The systemd unit is `orbit-process-{id}-{name}.service`, and the Docker container is `orbit-process-{id}-{name}`. Before the Gateway replaces or removes one, it checks the exact Orbit Process ID marker. It never overwrites, adopts, or deletes a unit or container with another marker.

### Move data before removing an override

Keeping a Process ID and its named volumes does not preserve files in the container's writable layer. Updating a Docker specification can replace that container and discard those files, even if the working directory is unchanged. With Valkey's image `WORKDIR` of `/data`, an Orbit override of `/app` can put AOF and RDB files in `/app` instead of the volume mounted at `/data`. Removing the override alone would start Valkey against a different, possibly empty directory.

Before updating any stateful Docker Process, use this procedure on its Node with the application's backup and restore tools:

#### 1. Inspect the current data paths

Inspect the stored specification, container mounts, image `WORKDIR`, and the application's actual data paths. Record the Process ID, desired state, image, complete command, environment, ports, and volumes. Do not infer the data path from the intended volume mount.

#### 2. Quiesce writers and back up

Stop clients and writers, flush or checkpoint the application using its documented procedure, and make an application-consistent backup outside the container layer. Stop the Process with `process:stop` to prevent further writes. Copy any remaining files needed for recovery from the stopped container before any update; Docker's `docker cp` can read a stopped container. For Valkey, include the RDB and the complete AOF directory, manifest, and segments when enabled, not just one file.

#### 3. Test restoration

Verify the backup is readable and test restoration in a separate disposable environment. Keep the original backup until the migrated service is verified. An untested copy or an empty volume is not evidence that data is safe.

#### 4. Restore into persistent storage

Restore into a new named volume or bind-mounted directory at the image's expected data path, such as `/data` for Valkey. Use the application's restore instructions and preserve the required ownership and permissions. Do not overwrite a shared volume or another service's data. Confirm the restored data is present before replacing the container.

#### 5. Update and verify before admitting clients

Run `process:update` with the complete retained specification and the restored mount. Omit `--working-directory` to choose the image default, or send `working_directory: null` through the API. Because the Process was stopped for migration, update leaves it stopped. Start it explicitly, verify the effective working directory and mounts, and check the restored records and application health before admitting clients again.

#### 6. Recover from the backup if needed

If verification fails, stop the service and restore from the verified backup with the previous image, mounts, command, and explicit working directory. Do not rely on the old container layer still existing after update. Retain backups until the recovery window closes.

## Environment contract

`environment` is a name-to-value map everywhere: CLI parsing, SDK requests, API input and output, OpenAPI and MCP tool schemas, Project definition specifications, stored runtime configuration, Gateway-owned Processes, and runtime rendering. For example, `{"CACHE_HOST":"10.44.0.20","CACHE_PORT":"6379"}` maps each name to one string value. It is not a list of `NAME=VALUE` strings or a list of objects with `name` and `value` fields. The CLI accepts repeated `--environment=NAME=VALUE` flags as input syntax and converts them to the map before sending a request. Split each flag at the first `=` so the value can contain `=`.

Names match `[A-Za-z_][A-Za-z0-9_]*`. Values are strings, including the empty string. Invalid shapes and names are refused rather than silently converted. An empty environment serializes as `{}`, not `[]`. Responses and Activity retain the names and map shape but replace every value with `[REDACTED]`; errors and logs must not disclose values. This representation does not expand which runtimes accept public environment input: public create and update accept it for Docker, while Gateway-owned systemd Processes can store it internally.

## Environment of a systemd Process

The unit sets `PATH` and `NODE_USE_SYSTEM_CA=1`. An Instance Process then reads the Instance's `.env` file: in the [application directory](/reference/projects#application-directory) of the checkout on `app-dev`, or in the production home on `app-prod`. With root `apps/site/public`, the default working directory is `<checkout>/apps/site` in development or `<production-home>/current/apps/site` in production. Root `public` keeps the checkout or release root. An explicit working directory overrides this default; Docker keeps the image's `WORKDIR`, and Node systemd Process defaults stay unchanged. Instances that are not Laravel apps keep their checkout or release root as the default working directory.

A Process can also store an environment map in its specification. The unit receives each pair as an `Environment=` directive, never on `ExecStart`. The unit file under `/etc/systemd/system` is written with mode `0644`, so these values sit in plain text that every local user on the Node can read. For the proxycli collector that includes its management key and tokens. WireGuard membership and Node access are the security boundary. Gateway-owned features store such a map, for example the [proxycli](/reference/proxycli) collector. The public create API does not accept one for systemd.

The derived keys always win: `PATH`, `NODE_USE_SYSTEM_CA`, `VITE_DEV_SERVER_CERT`, `VITE_DEV_SERVER_KEY`, the `ORBIT_DEV_SERVER_*` keys, the Agentation keys, and the SSR keys when the Instance has an SSR port.

A development Process also receives `VITE_DEV_SERVER_CERT` and `VITE_DEV_SERVER_KEY`, the paths of the Instance's certificate files under `~/.orbit/certificates/app-instance-{id}/current/`. The `vp-dev` preset does not receive them. When the Instance has a Route, it receives `ORBIT_DEV_SERVER_ORIGIN`, `ORBIT_DEV_SERVER_HOST`, and `ORBIT_DEV_SERVER_PATH` for the [development-server endpoint](/reference/routes#development-server-endpoint), and `ORBIT_DEV_SERVER_PORT` only when `vite_port` is assigned. It also receives the Agentation keys when it has an Agentation port, and `ORBIT_SSR_PORT` and `INERTIA_SSR_URL` when it has an [assigned SSR port](/reference/assigned-ssr-ports), with or without a Route. These derived values are not secret. The unit carries them both as `Environment=` directives and on `ExecStart` through `/usr/bin/env`.

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

Create installs the unit or container. With `start`, it also starts it. An identical create returns the existing Process and keeps its desired state. A different specification with the same owner and name returns `process.name_taken` (409) and changes nothing. To change a Process, use [`process:update`](/cli/process#orbit-processupdate), which keeps its ID and links. Back up container-layer data before an update that replaces a Docker container.

On a Node with the active `app-dev` role, a systemd Instance Process installs without a boot start. A Docker Instance Process there maps restart policy `always` to Docker `unless-stopped`, so an idle stop survives a Docker daemon restart. Node Processes on the same Node keep the normal behavior. The Gateway starts it with `systemctl start` and does not enable the unit. After a reboot the Process stays down until `process:start` or the next HTTP wake. [Hibernation](/reference/app-dev-runtime-hibernation) describes idle stop, `keep_alive`, and wake.

An Instance on `app-prod` with no selected release accepts a stopped Process. A start fails with `process.release_unavailable` until [a deployment](/reference/deployments) selects a release. A later start uses the release that `current` selects then. After production activation and PHP refresh, a deployment or rollback restarts active Processes desired `running` before after-activation steps. `restart_on_deploy` defaults to `true`; set it to `false` in a complete create or update specification to opt out. Stopped Processes stay stopped. Restart results appear in deployment events, and a failure fails the deploy or rollback with its selected release still active. See [Deploy](/reference/deployments#deploy) to remove a hand-made step that terminates Horizon.

## Operate a Process

The Gateway exposes these Process endpoints.

| Request | Result |
| --- | --- |
| `GET /api/v1/processes` | The Processes with desired state, runtime status, `keep_alive`, `restart_on_deploy`, `cpu`, and `memory_bytes`. `target_type` and `target_id` limit the list to one owner. |
| `POST /api/v1/processes` | Creates one Process. |
| `PATCH /api/v1/processes/{process}` | Replaces the specification and reconverges the runtime of the same Process. The Process of a Valkey [Database server](/reference/database-servers#retry-and-response) is refused with `process.required_by_database_server` (409), so its command always keeps the server's ACL file. |
| `POST /api/v1/processes/{process}/start`, `/stop`, `/restart` | Changes the runtime and stores the desired state: `running` after start and restart, `stopped` after stop. |
| `GET /api/v1/processes/{process}/logs?lines=N` | A tail of 1 through 1,000 lines over SSH. See [Live logs](/reference/live-logs) to follow new lines. |
| `DELETE /api/v1/processes/{process}` | Stops the Process, removes its unit or container, and deletes the record. The Process of a [Database server](/reference/database-servers) is refused with `process.required_by_database_server` (409); remove the server instead. |

Creating or starting an Instance Process needs an active Instance on an active Node. Creating or starting a Node Process needs an active managed Node. Otherwise the Gateway refuses with `process.target_inactive` before it changes anything. Removal can use the recorded placement of a failed or removing Instance while its Node is active.

`cpu` is a ratio of one core and `memory_bytes` is bytes. Both come from the [Metrics role](/reference/metrics#cadvisor) and are null when the Process is not running or no sample exists. Responses, Activity, and errors show every stored environment value, Docker or systemd, as `[REDACTED]`.

Removing a systemd Process disables and stops the unit, deletes the unit file, reloads systemd, and resets the unit's failed state. A crashed unit therefore leaves no `failed` entry in `systemctl list-units`.

### Update in place

[`process:update`](/cli/process#orbit-processupdate) replaces an installed Process specification without deleting its record. `PATCH /api/v1/processes/{process}` accepts a complete specification with the same runtime fields and defaults as create: `runtime`, `command`, `image`, `working_directory`, `environment`, `ports`, `volumes`, `restart_policy`, `keep_alive`, `restart_on_deploy`, and `user` where allowed. It does not accept a new name, owner, preset, or initial-start flag. The Process ID, name, owner, and desired state stay the same. For Docker, omitted or null `working_directory` restores the image default; omitted environment, ports, and volumes are empty. This is replacement, not a merge of individual environment keys.

The Gateway validates the new specification before changing the runtime, holds the same admission and runtime locks as other Process operations, and verifies the exact ownership marker before replacing the unit or container. A Process desired `running` restarts with the new specification; one desired `stopped` is installed but stays stopped. Docker may need a replacement container, but its Orbit Process ID and managed container name stay the same.

Update does not delete named volumes, but replacing a container discards its writable layer. Follow [Move data before removing an override](#move-data-before-removing-an-override) before replacing a stateful container; preserving its ID and links is not a backup.

A runtime failure is reported with the Process lifecycle status, failed step, and error code; the caller must not treat it as success or assume the old runtime is still running. The response uses the same Process data and redaction as create and list, including nullable `working_directory` for a Docker Process without an override. The SDK preserves this null instead of converting it to an empty string. Updating a Project definition remains separate and affects later copies only.

For example, `PATCH /api/v1/processes/41` can replace a Docker specification with this body:

```json
{
  "runtime": "docker",
  "image": "valkey/valkey:8",
  "command": ["valkey-server"],
  "working_directory": null,
  "environment": {"CACHE_LABEL": "shared"},
  "ports": ["10.44.0.20:6379:6379"],
  "volumes": [{"source": "valkey-data", "target": "/data", "read_only": false}],
  "restart_policy": "unless-stopped",
  "keep_alive": false
}
```

This example updates an ordinary Process. Run a Valkey that clients log in to as a [Valkey Database server](/reference/database-servers#a-valkey-server) instead: it starts only with a password, and `process:update` refuses its Process.

The response keeps `id: 41`, returns `working_directory: null`, and exposes the environment only as `{"CACHE_LABEL":"[REDACTED]"}` under `runtime_config.environment`. Invalid fields return HTTP 422 before runtime mutation. An ownership conflict or competing operation returns the existing Process error code, not a successful replacement. A failed runtime operation uses the same failure contract as create, start, and restart.

### Persisted intent and retry recovery

Updating the Gateway record and changing a remote runtime are not one atomic transaction. The Gateway validates the request and acquires the operation locks before accepting new intent. Invalid input, an admission failure, or a detected ownership conflict before acceptance leaves the stored specification and runtime unchanged. Once accepted, the new specification and an incomplete convergence state are persisted before remote changes begin. The desired state remains unchanged. A later runtime failure does not roll the specification back or restore the old container layer.

The Gateway records pending systemd activation before publishing changed bytes, and clears that intent only after reload and desired-state activation succeed. It also records the successfully activated unit hash. Pending activation requires reload and activation on retry even when published bytes and service state match. Legacy Processes without a hash or pending activation remain untouched when their unit bytes and desired state match; upgrading does not restart them. A lost activation response can cause one extra restart of a pending update, but cannot falsely complete it. Explicit restart always activates the service, including an unchanged, active annotator.

Only a completed runtime convergence marks the update successful. A handled failure records the failed step and error code and returns failure. If the Gateway crashes after saving intent, its stored specification remains the recovery source; an incomplete update is not treated as successfully applied. A crash can leave the old runtime, a partially changed runtime, or the new runtime running before completion was recorded. Inspect the Process and retry after the lock is available; do not infer completion from a cached runtime status alone.

When an update switches runtime kind, the Gateway also saves which old runtime needs removal. An interrupted retry removes that owned footprint before converging the saved specification. Updating a systemd Process without a runtime switch does not require Docker to be installed.

An identical update retry uses the same Process ID and reconverges an incomplete or stale runtime even when the stored specification already equals the request. Equality alone is not a reason to skip unfinished work. A retry after verified completion returns the same Process without replacing or restarting an unchanged runtime. Recovery may repeat a remote operation whose completion was not recorded, so these guarantees do not promise exactly one restart. Retries preserve the ownership checks, named volumes, links, and desired state; they cannot recover discarded container-layer files.

A lost response does not prove failure or rollback. Read the current Process and compare its specification and lifecycle state before retrying the identical request. If another operator has changed the specification meanwhile, do not blindly replay the older request and overwrite that intent. Ordinary release and Node convergence leave unchanged, completed specifications alone; repairing a pending update is distinct from changing the working-directory default on an existing Process.

### Process runtime state

Desired state is stored intent: `running` after start or restart, and `stopped` after stop. Runtime status is an observation, not a health guarantee. Lists use the [runtime status index](/reference/metrics#process-runtime-status), which can show a systemd unit as `active` between crashes. Doctor inspects the runtime on the Node rather than treating a cached list status as proof of health.

For a systemd Process desired `running`, a crash loop means either `ActiveState=activating` with `SubState=auto-restart`, or an `NRestarts` count that increases between observations during the same bounded inspection. An `active` sample does not cancel evidence of repeated restarts. A nonzero restart count left by earlier restarts alone does not qualify. Ordinary startup in `activating` without `auto-restart` or an increasing restart count is not a crash loop, though it can still differ from the desired running state. A Process desired `stopped` uses the ordinary state comparison instead. This crash-loop rule does not apply to Docker Processes.

Doctor reports `process.crash_loop` as `drift`, with the Process ID and name and `expected=running`. Its bounded `observed` evidence carries the unit's active state and sub-state, plus the restart counts when available. For example, `activating` / `auto-restart` explains a restart wait, while an `active` / `running` sample with `NRestarts` increasing from 3 to 4 explains a restart observed between samples. Doctor emits this finding instead of an additional `process.state_mismatch` for that observation. It reads only and never starts, stops, or restarts the unit.

## Locks

The Gateway holds one runtime lock for each Process while it reads the record, changes the runtime, and writes the result. A competing request gets `process.runtime_lock_failed` and changes nothing.

Create, update, start, and restart of an Instance Process first take the Instance's operation lock. A competing request waits up to 30 seconds, or the rest of its command deadline, and then gets `process.operation_busy`. Node Processes skip that lock. Lifecycle commands that contend for the Instance lifecycle lock return `instance.lifecycle_busy` (409, with `details.outcome` set to `busy`); retry after the other lifecycle operation finishes.

Systemd replacement installs a checked candidate unit and restores the earlier unit when activation fails. Docker replacement keeps or restores the earlier container.

## Project definitions

A Project holds Process definitions and Schedule definitions. A definition has a UUID, a name that is unique within the Project and kind, an `environments` list, and a `spec`.

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

The systemd `User=` of a Node Process can be an account other than the managed user. Use it for a service that must not run as the managed user. The owner stays the Node. An Instance Process keeps the account derived from the Instance, because a chosen account would leave the production home and the development checkout. Docker rejects `user` because the image has its own user. The Gateway does not create the account. The Gateway checks the account with `getent passwd` over SSH before create and fails with `process.user_unavailable` when the account or its home is unavailable.
