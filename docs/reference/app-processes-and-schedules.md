# AppInstance processes

This page tells an operator how Orbit installs and manages systemd services and Docker containers owned by one AppInstance. [ADR 0036](../decisions/0036-support-only-appinstances.md) owns the AppInstance-only target boundary, [ADR 0038](../decisions/0038-cascade-appinstance-removal-through-processes-and-schedules.md) owns removal cleanup, and [ADR 0048](../decisions/0048-copy-app-process-and-schedule-definitions-into-appinstances.md) owns independent AppInstance copies.

## Select the owner

The `process:add` and `process:list` commands require `--instance=ID`, where the value is a positive AppInstance ID. The public API and PHP software development kit (SDK) send the target token `instance` with that ID. Every other process command accepts a positive Process ID and uses its recorded AppInstance owner.

Orbit accepts no Workspace or Node Process target. It does not convert or adopt legacy Process records or runtime artifacts. A fleet operator owns any required legacy transition outside Orbit, and Orbit provides no migration command or compatibility selector.

## Add a Process

The operator selects one runtime and supplies its complete instance-specific specification. Orbit derives the target Node and host execution user from the AppInstance; caller input cannot replace either value.

The two runtimes accept these values.

| Runtime | Required values | Optional values | Default working directory |
| --- | --- | --- | --- |
| systemd | Process name and absolute executable with argv | Absolute working directory, restart policy, and initial start | The development checkout or the production home's `current` path |
| Docker | Process name, image, and command argv | Container working directory, environment, published ports, volumes, restart policy, and initial start | `/app` |

A development systemd Process runs as the Node's managed runtime user. It reads the environment file in the recorded checkout and receives `VITE_DEV_SERVER_CERT` and `VITE_DEV_SERVER_KEY` for the AppInstance Route hostname from that user's certificate projection.

A production systemd Process runs as the AppInstance's dedicated production user. It reads the persistent environment file in the recorded production home and uses the `current` path as its default working directory. Orbit resolves the recorded Node, user, home, and current release when it performs an operation, independent of Node role co-location or certificate mode.

A prepared production home without `current` accepts a stopped systemd Process installation. Starting that Process fails before application code runs until a release is selected. A later explicit start uses the release then selected by `current`. Changing `current` does not restart an already running Process.

Repeating an identical add returns the same Process and preserves its desired running or stopped state. A changed specification with the same AppInstance and name returns `process.name_taken` and changes neither the record nor its runtime. To change a specification, remove that AppInstance-owned Process and add it again.

## Operate a Process

The CLI exposes these Process operations through the Gateway.

| Command | Result |
| --- | --- |
| `orbit process:add NAME --instance=ID ...` | Install one stopped or initially running systemd service or Docker container. |
| `orbit process:list --instance=ID` | List the Process records owned by one AppInstance with their desired and observed states. |
| `orbit process:start PROCESS` | Start an installed Process and record the running desired state. |
| `orbit process:stop PROCESS` | Stop an installed Process and record the stopped desired state. |
| `orbit process:restart PROCESS` | Restart an installed Process and record the running desired state. |
| `orbit process:logs PROCESS --lines=COUNT` | Return a non-streaming tail from 1 through 1,000 lines. |
| `orbit process:remove PROCESS` | Stop and remove the exact owned runtime artifacts, then delete the Process record. |

Adding or starting a Process requires an active, available AppInstance and reachable active Node. Orbit refuses either operation before mutation when that target is unavailable or inactive. Cleanup can use the recorded placement of a failed or removing AppInstance while its Node remains reachable.

Every runtime mutation rechecks exact Orbit ownership. Systemd replacement uses a validated candidate and restores the previous owned unit when activation fails. Docker replacement retains or restores exact-owned canonical and rollback containers. Orbit does not overwrite, adopt, or delete a colliding unit, container, or recovery artifact.

Process responses identify the owning AppInstance. Activity records identify that AppInstance and its target Node. Docker environment values and credential-shaped runtime data are redacted from responses, activity, errors, debug output, and bounded logs.

## Inspect and remove owned state

Doctor reads each recorded Process on the selected Node and compares its desired state with the bounded systemd or Docker status. It reports an absent runtime, state mismatch, failed inspection, or unreachable Node without changing the Process, AppInstance, or machine.

AppInstance removal runs source preflight before it changes any Process. Once removal accepts its fixed AppInstance set, no new Process can attach to a member. The removal then stops and removes every owned running, stopped, failed, or removing Process and its persistent exact-owned artifacts before it reports success. A cleanup failure keeps the AppInstance and unfinished Process cleanup resumable. The [AppInstance removal reference](appinstance-removal.md) describes the order and retry boundary.

The AppInstance Process copy is independent. Changing or removing it does not change an App-owned definition, and changing an App definition does not reconcile an existing copy or its runtime state. Orbit exposes no AppInstance Schedule operation in this Process command surface.
