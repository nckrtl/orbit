# App process and Schedule definitions and copies

This page tells an operator how an App declares reusable process and Schedule definitions, how Orbit manages independent Process and Schedule copies for one AppInstance, and how a Process can target a managed Node for shared infrastructure. [ADR 0036](../decisions/0036-support-only-appinstances.md) owns the AppInstance-only application model, [ADR 0069](../decisions/0069-allow-node-process-targets.md) owns the AppInstance or Node Process-target boundary, [ADR 0038](../decisions/0038-cascade-appinstance-removal-through-processes-and-schedules.md) owns AppInstance child cleanup, and [ADR 0048](../decisions/0048-copy-app-process-and-schedule-definitions-into-appinstances.md) owns App definitions and independent AppInstance copies.

## Define reusable runtime intent

An App owns separate process and Schedule definition collections. A definition has a universally unique identifier (UUID), a name that is unique within its App and definition kind, a nonempty applicability list, and one complete runtime specification. The applicability list contains each selected environment at most once.

The Gateway exposes the two collections through these API resources.

| Definition kind | Collection | Item operations |
| --- | --- | --- |
| Process | `GET` and `POST` on `/api/v1/apps/{app}/process-definitions` | `GET`, full `PUT` replacement, and `DELETE` on `/api/v1/apps/{app}/process-definitions/{definition}` |
| Schedule | `GET` and `POST` on `/api/v1/apps/{app}/schedule-definitions` | `GET`, full `PUT` replacement, and `DELETE` on `/api/v1/apps/{app}/schedule-definitions/{definition}` |

Both kinds accept the common definition fields below.

| Field | Requirement |
| --- | --- |
| `name` | A bounded lowercase name that is unique within the App and definition kind. |
| `environments` | A nonempty array of unique `development` or `production` values. |
| `spec` | The complete specification for the selected definition kind. |

A process definition uses the same runtime inputs as an AppInstance Process: runtime, command arguments, optional working directory, restart policy, and the Docker-only image, environment, ports, and volumes. It does not accept a target, initial or desired start state, host Node, runtime user, home, or generated environment-file identity.

A Schedule definition specification contains `command`, `calendar`, and `timeout_seconds`. The Gateway accepts `command` and `timeout_seconds` under the same limits as an installed Schedule. For `calendar`, it accepts one nonempty printable ASCII line of at most 255 bytes. It does not run `systemd-analyze calendar` when it creates or updates a definition, because a definition has no host Node. The target Node's `systemd-analyze calendar` accepts or rejects that stored calendar when Orbit copies the definition into an AppInstance Schedule or when an operator creates a Schedule. The [Schedules](schedules.md) page owns that host check. A definition does not select a target, host Node, execution identity, or timer state.

The API rejects unknown or duplicate members at every definition object and specification boundary. Item operations select a definition by name within the App, and the Gateway rejects a name that belongs to another App. Collection responses omit command content; an authorized item response returns the complete definition, including its UUID.

Creating, updating, or destroying a definition changes only App-owned configuration. It makes no remote call and does not change a Process, Schedule, selected release, desired runtime state, or existing AppInstance copy. Removing an AppInstance retains the App's definitions, while removing an otherwise removable App deletes its definitions.

## Prepare production copies

Production preparation captures the App definitions whose applicability includes `production` before it installs any target runtime. It ignores development-only definitions and candidate-specific Process or Schedule settings. The captured selection belongs to that target and does not change when an App definition is later added, replaced, or removed.

For each captured process definition, Orbit creates a new AppInstance-owned Process with its own ID and target-derived runtime identity. It preserves the supported systemd or Docker specification and installs the Process stopped. For each captured Schedule definition, Orbit creates a new AppInstance-owned Schedule with its own UUID and target-derived host identity. It installs the timer disabled and stopped, and that installation applies the host calendar check. A prepared production home does not need a selected release for these stopped installations, and preparation does not execute application code.

Preparation records completed copies and resumes only unfinished installation after an interruption. A retry uses the target's captured selection instead of reading the App definitions again. It does not rewrite a completed copy, undo a later operator edit, or stop a copy that an operator started. A name conflict or a conflict with a runtime artifact stops preparation without adopting the existing record or artifact. Removing the target later cleans the instantiated copies through the [AppInstance removal lifecycle](appinstance-removal.md) and retains the App definitions.

## Manage definitions from the CLI

The process and schedule families select App-owned definitions with `--app`. `APP` is a positive numeric App ID. `NAME` is unique within that App and definition kind. `--for` names the environments the definition applies to. Because `--environment` already names Docker variables on `process:create`, `--for` is the only spelling for definition environments.

| Command | Result |
| --- | --- |
| `orbit process:create NAME --app=APP --for=ENV[,ENV] ...` | Record a process definition on the App with the runtime, command, image, working-directory, environment, port, volume, and restart options of an AppInstance target. |
| `orbit process:list --app=APP` | List the App's process definitions. |
| `orbit process:show NAME --app=APP` | Show one process definition by name. |
| `orbit process:update NAME --app=APP --for=ENV[,ENV] ...` | Replace one process definition with a complete specification. |
| `orbit process:destroy NAME --app=APP` | Destroy one process definition by name. |
| `orbit schedule:create NAME --app=APP --for=ENV[,ENV] --calendar=CALENDAR --command=COMMAND` | Record a Schedule definition on the App. Add `--timeout=SECONDS` to change the 3600-second execution timeout. |
| `orbit schedule:list --app=APP` | List the App's Schedule definitions. |
| `orbit schedule:show NAME --app=APP` | Show one Schedule definition by name. |
| `orbit schedule:update NAME --app=APP --for=ENV[,ENV] --calendar=CALENDAR --command=COMMAND` | Replace one Schedule definition with a complete specification. |
| `orbit schedule:destroy NAME --app=APP` | Destroy one Schedule definition by name. |

`--for` is required with `--app` on create and update. The CLI refuses `--app` together with `--instance` or `--node`, and it refuses `--for` on an AppInstance or Node target, before it sends an HTTP request. Create refuses a name that another definition of that App and kind already uses. `process:start`, `process:stop`, `process:restart`, and `process:logs` do not accept `--app`. `schedule:run`, `schedule:logs`, and `schedule:enable` do not accept `--app`. Every command also accepts `--json`. Human and JSON results include the Gateway request ID, and safe errors include that ID when the Gateway supplies it.

The CLI sends the structured flags as the Gateway request body. It does not read a definition file, execute a definition command, or apply the definition to a machine. An operator can record a systemd process definition and a production Schedule definition like this:

```bash
orbit process:create queue \
  --app=1 \
  --for=development,production \
  --runtime=systemd \
  --command=/usr/bin/php \
  --command=artisan \
  --command=queue:work \
  --restart=on-failure

orbit schedule:create hourly-report \
  --app=1 \
  --for=production \
  --calendar=hourly \
  --command="php artisan report:send" \
  --timeout=3600
```

List output omits each definition's command. Show, create, update, and destroy results contain the complete item returned by the Gateway. Changing an App definition affects later copies only. To change an existing copy, the operator explicitly destroys and creates the AppInstance-owned Process or Schedule.

## Select the owner

A Process has exactly one target: an AppInstance or a managed Node. The `process:create` and `process:list` commands require one selector: `--instance=ID` or `--node=ID-or-name` for a Process, or `--app=APP` for an App-owned definition. The CLI refuses more than one of those selectors together. The public API and PHP software development kit (SDK) send the Process target token `instance` or `node` with a positive numeric ID. Process start, stop, restart, logs, and destroy accept a positive Process ID and use that record's owner.

Orbit accepts no Workspace Process target. It does not convert or adopt legacy Process records or runtime artifacts, and it does not create a synthetic AppInstance to host a Node-scoped service. A fleet operator owns any required legacy transition outside Orbit, and Orbit provides no migration command or compatibility selector.

A Node Process belongs to that Node. Its execution host, runtime user, and default working directory come from the Node. It stays in place when an AppInstance is removed, and Node decommissioning removes it. An AppInstance Process still follows the AppInstance lifecycle on this page.

## Create a Process

The operator selects one runtime and supplies its complete specification. For an AppInstance Process, Orbit derives the target Node and host execution user from the AppInstance; caller input cannot replace either value. For a Node Process, Orbit uses the Node as the execution host and the Node's managed runtime user.

The two runtimes accept these values.

| Runtime | Required values | Optional values | Default working directory |
| --- | --- | --- | --- |
| systemd | Process name and absolute executable with argv | Absolute working directory, restart policy, and initial start | The AppInstance development checkout, the production home's `current` path, or `/home/{user}` on a Node target |
| Docker | Process name, image, and command argv | Container working directory, environment, published ports, volumes, restart policy, and initial start | `/app` |

A development systemd Process runs as the Node's managed runtime user. It reads the environment file in the recorded checkout and receives `VITE_DEV_SERVER_CERT` and `VITE_DEV_SERVER_KEY` for the AppInstance Route hostname from that user's certificate projection. When the AppInstance has a Route, the unit also receives `ORBIT_DEV_SERVER_ORIGIN`, `ORBIT_DEV_SERVER_HOST`, `ORBIT_DEV_SERVER_PATH`, and `ORBIT_DEV_SERVER_PORT` so the frontend toolchain publishes assets and hot module replacement on the [development-server endpoint](routes.md#development-server-endpoint).

A production systemd Process runs as the AppInstance's dedicated production user. It reads the persistent environment file in the recorded production home and uses the `current` path as its default working directory. Orbit resolves the recorded Node, user, home, and current release when it performs an operation, independent of Node role co-location or certificate mode.

A prepared production home without `current` accepts a stopped Process installation for either runtime. An initial start requested by `process:create` and a later `process:start` both fail before the Process record or runtime changes until a release is selected. A later explicit start uses the release then selected by `current`. Changing `current` does not restart an already running Process.

A Node systemd Process runs as the Node's managed runtime user. It uses `/home/{user}` as the default working directory and does not read an AppInstance environment file or receive development-server certificate or origin values. Creating or starting it requires an active Linux Node with a recorded WireGuard address. Shared infrastructure such as a Docker database uses this target. The [Database role](database-role.md) can converge Docker on that Node, and a Node Process does not require that role. A named Herdr session also uses a Node Process; [Herdr sessions](herdr-sessions.md) owns that integration:

```bash
orbit process:create postgres \
  --node=beast \
  --runtime=docker \
  --image=postgres:18

orbit process:create herdr-observer \
  --node=beast \
  --runtime=systemd \
  --command=/usr/local/bin/herdr-observer
```

`--node` accepts a positive Node ID or the registered Node name. The CLI resolves a name through the node list before it sends the create request.

Repeating an identical create returns the same Process and preserves its desired running or stopped state. A changed specification with the same owner and name returns `process.name_taken` and changes neither the record nor its runtime. To change a specification, destroy that Process and create it again.

## Operate a Process

The CLI exposes these Process operations through the Gateway.

| Command | Result |
| --- | --- |
| `orbit process:create NAME --instance=ID ...` | Install one stopped or initially running systemd service or Docker container on an AppInstance. |
| `orbit process:create NAME --node=ID-or-name ...` | Install one stopped or initially running systemd service or Docker container on a managed Node. |
| `orbit process:create NAME --app=APP --for=ENV[,ENV] ...` | Record one App-owned process definition. |
| `orbit process:list --instance=ID` | List the Process records owned by one AppInstance with their desired and observed states. |
| `orbit process:list --node=ID-or-name` | List the Process records owned by one Node with their desired and observed states. |
| `orbit process:list --app=APP` | List the App's process definitions. |
| `orbit process:show NAME --app=APP` | Show one process definition by name. |
| `orbit process:update NAME --app=APP --for=ENV[,ENV] ...` | Replace one process definition with a complete specification. |
| `orbit process:start PROCESS` | Start an installed Process and record the running desired state. |
| `orbit process:stop PROCESS` | Stop an installed Process and record the stopped desired state. |
| `orbit process:restart PROCESS` | Restart an installed Process and record the running desired state. |
| `orbit process:logs PROCESS --lines=COUNT` | Return a non-streaming tail from 1 through 1,000 lines. |
| `orbit process:destroy PROCESS` | Stop and remove the exact owned runtime artifacts, then delete the Process record. |
| `orbit process:destroy NAME --app=APP` | Destroy one process definition by name. |

Creating or starting an AppInstance Process requires an active, available AppInstance and reachable active Node. Creating or starting a Node Process requires a reachable active managed Node. Orbit refuses either operation before mutation when that target is unavailable or inactive. Cleanup can use the recorded placement of a failed or removing AppInstance while its Node remains reachable, and Node-owned cleanup can use an active Node.

The Gateway holds one runtime owner for a Process while it re-reads the record, applies the remote systemd or Docker change, and writes the matching success, failure, or deletion. A competitor that cannot take that owner receives `process.runtime_lock_failed` and leaves desired state, errors, and lifecycle status unchanged.

Create, start, and restart of an AppInstance Process also take a bounded AppInstance admission owner first. A competitor waits for at most 30 seconds or the remaining command deadline, then receives `process.operation_busy` before it mutates that AppInstance. The Gateway acquires the AppInstance admission owner before the Process runtime owner and does not take a second nested Process lock. Node-targeted create, start, and restart skip that AppInstance admission owner.

Node role cleanup uses the same Process runtime owner. It removes exact-owned runtime artifacts and leaves the Process row for the parent removal to delete after recovery. A delayed start or stop that lost the owner cannot rewrite that row after cleanup has finished.

Repeating an identical create refreshes the surviving Process and its desired state. It does not create a second record.

Every runtime mutation rechecks exact Orbit ownership. Systemd replacement uses a validated candidate and restores the previous owned unit when activation fails. Docker replacement retains or restores exact-owned canonical and rollback containers. Orbit does not overwrite, adopt, or delete a colliding unit, container, or recovery artifact.

Process responses identify the owning AppInstance or Node. Activity records identify that owner and the execution Node. Docker environment values and credential-shaped runtime data are redacted from responses, activity, errors, debug output, and bounded logs. Stored mysql, pgsql, and sqlite credentials live in the [Database connection](database-connections.md) registry; that registry does not start or stop a Process.

Systemd units use `orbit-process-{id}-{name}.service` and Docker containers use `orbit-process-{id}-{name}`. Collision checks require the exact Orbit process ID marker before replacement or deletion.

## Inspect and remove owned state

Doctor reads each recorded AppInstance Process and Node Process on the selected Node and compares its desired state with the bounded systemd or Docker status. It reports an absent runtime, state mismatch, failed inspection, or unreachable Node without changing the Process, AppInstance, Node, or machine.

### Removal order

When an operator removes an AppInstance, the Gateway runs source preflight before it changes any Process. Once removal accepts its fixed AppInstance set, no new Process can attach to a member. The removal then stops and removes every owned running, stopped, failed, or removing Process and its persistent exact-owned artifacts before it reports success. A cleanup failure keeps the AppInstance and unfinished Process cleanup resumable. Node-owned Processes stay in place. The [AppInstance removal reference](appinstance-removal.md) describes the order and retry boundary.

When an operator removes a Node, the Gateway stops and removes every Node-owned Process before it tears down WireGuard. A cleanup failure keeps the Node and unfinished Process cleanup resumable. Offline decommissioning of an unreachable Node deletes those Process records without remote runtime cleanup. The [Node removal reference](node-provisioning.md#remove-a-node) describes that order.

The AppInstance Process copy is independent. Changing or removing it does not change an App-owned definition, and creating, updating, or destroying an App definition does not reconcile an existing copy or its runtime state.

An AppInstance Schedule is also an independent copy with its own identity, systemd artifacts, desired timer state, and removal lifecycle. The [Schedules reference](schedules.md) describes target context, stopped installation, explicit timer activation, manual execution, latest-run reporting, and cleanup. Process commands do not operate on Schedules.
