---
title: "Agentation"
description: "How Orbit runs the Agentation HTTP server and the Antigravity watcher as Processes of a development Instance, publishes the server on the Route origin, and projects AGENTATION_URL."
covers:
  - apps/gateway/app/Domain/Processes/{AgentationMcpPreset,AntigravityWatchPreset,ProcessPresets}.php
  - apps/gateway/app/Domain/AppDev/{AgentationPortAllocator,AgentationUrlProjection,AgentationSiteProjection}.php
  - apps/gateway/app/Infrastructure/AppDev/RemoteAgentationSiteProjection.php
---

# Agentation

Orbit runs Agentation watch mode as two Processes of a development Instance. The `agentation-mcp` Process receives toolbar annotations. The `antigravity-watch` Process runs an Antigravity agent that applies them. Both sleep and wake with the Instance.

The Agentation toolbar belongs to the application. Orbit does not install the toolbar, edit frontend code, or run a fleet-wide Agentation service. Orbit also does not install the binaries: the Node must provide `/usr/local/bin/agentation-mcp` and `/usr/local/bin/agy`. [Processes and schedules](/reference/processes-and-schedules) owns the Process lifecycle.

| Piece | What it does |
| --- | --- |
| `agentation-mcp` Process | Serves the Agentation HTTP API on a loopback port of the Node. |
| `/__orbit/agentation` | Publishes that API on the Route's HTTPS domain. |
| `AGENTATION_URL` | Gives the toolbar, MCP clients, and the watcher the public URL. |
| `antigravity-watch` Process | Watches for annotations and applies each one. |

## Create the HTTP Process

Create it with the preset on a development Instance. `--instance` accepts an Instance ID or the exact Route domain.

```bash
orbit process:create agentation --instance=commander.test --preset=agentation-mcp --start
```

The preset sets the runtime to systemd and the command to `/usr/local/bin/agentation-mcp server --port=${ORBIT_AGENTATION_PORT}`. Its restart policy defaults to `on-failure`. The preset owns the runtime, command, working directory, and environment, so the Gateway refuses those fields with `validation.failed`. A preset also needs an Instance target: the Gateway refuses a Node target with `validation.failed` on `target_type`, and the CLI refuses `--node`. A Process name alone never selects a preset.

An Instance has at most one Process of each preset. A second `agentation-mcp` Process with another name fails with `process.preset_exists`. The same request again returns the existing Process and keeps its desired state.

## Port and path

Creation assigns the Instance an `agentation_port` on its Node. The first port is `4747`. The Gateway gives the next Instance on the same Node the lowest port from `4747` up that no other Instance on that Node holds in the Gateway database. It does not probe the Node, and it does not skip ports that Vite uses. It skips ports assigned to an `annotator` Process. Ports stay unique per Node. When no port is free, creation fails with `process.agentation_ports_exhausted`.

The port stays through hibernation. A [transfer](/reference/instance-transfer) picks a free port on the destination Node. Removing the HTTP Process releases the port.

Workload Caddy proxies `/__orbit/agentation` to `127.0.0.1:{agentation_port}` and strips the prefix. The Agentation API serves `/health` and `/sessions` at its root. A site without a port has no Agentation handle. Creating or removing the HTTP Process rebuilds the Node's Caddy when the Instance has a Route. [Routes](/reference/routes#agentation-endpoint) describes the path next to the Vite path.

## AGENTATION_URL

Creation stores `AGENTATION_URL` in the Instance environment as `https://{{instance.domain}}/__orbit/agentation`. `env:sync` renders it with the Route domain, for example `https://commander.test/__orbit/agentation`. See [Instance environment variables](/reference/environment-variables).

Every systemd Process of the Instance also gets the concrete `AGENTATION_URL` and `ORBIT_AGENTATION_PORT` in its unit, when the Instance has a port and a Route. So the watcher does not need an earlier `env:sync`. Removing the HTTP Process deletes the stored value.

## Create the watcher

Create the watcher after the HTTP Process.

```bash
orbit process:create agentation-watch --instance=commander.test --preset=antigravity-watch --start
```

The command is `/usr/local/bin/agy --dangerously-skip-permissions --print-timeout 60m -p` with a fixed prompt. The prompt tells the agent to call `agentation_watch_annotations` in a loop. For each annotation, the agent acknowledges it, applies the UI change without running tests, and calls `agentation_resolve` with a summary. The restart policy defaults to `always`, so the agent watches again after each prompt ends.

Without an `agentation-mcp` Process on the Instance, creation fails with `process.preset_dependency_missing`. While the watcher exists, removing the HTTP Process fails with `process.has_dependent`. Remove the watcher first.

## Hibernation

Both presets refuse keep-alive: the Gateway answers `validation.failed` on `keep_alive`, and the CLI answers `process.preset_keep_alive_invalid`. Idle halt stops both Processes. The next HTTP request starts every Process whose desired state is `running`. Wake waits until the HTTP Process answers `/health` on its port, or fails with `hibernation.agentation_not_ready`. [Hibernation](/reference/app-dev-runtime-hibernation) describes idle halt and wake.

## Errors

The Gateway refuses these cases with the listed code. It refuses preset-owned fields, a Node target, and keep-alive earlier, with `validation.failed`.

| Code | Condition |
| --- | --- |
| `process.preset_target_invalid` | The Instance is not a development Instance. |
| `process.preset_exists` | The Instance already has a Process with this preset. |
| `process.preset_dependency_missing` | The watcher has no `agentation-mcp` Process on its Instance. |
| `process.agentation_ports_exhausted` | The Node has no free port from `4747` up. |
| `process.has_dependent` | The HTTP Process still has a watcher. |
| `hibernation.agentation_not_ready` | The HTTP Process did not answer `/health` before the wake timeout. |

## Annotator Process

The separate `annotator` preset runs Orbit's [annotation server](/reference/agent-annotation), not Agentation MCP. Create it with `orbit process:create annotator --instance=<id|domain> --preset=annotator --start`. The preset owns systemd, its command, working directory, and environment. It restarts on failure and refuses keep-alive. An identical create returns the existing Process; another name for the same preset is refused.

The Gateway installs its copy of `packages/agent-annotation/bin` under `/opt/orbit/annotator/current/bin` on the Node. Installation publishes immutable files through an atomic symlink switch, so other Instances can keep serving. The command is `/usr/local/bin/node /opt/orbit/annotator/current/bin/serve.mjs serve --port <port> --store /var/lib/orbit/annotator/instance-<id> --allow-origin https://<domain> --allow-origin t3code://app`. The Node must provide Node.js. The Gateway distribution includes the matching injection bundle as `apps/gateway/resources/annotator/inject.js.gz`, generated by `bin/annotator-build`. Installation checks its source manifest and refuses a missing or stale asset before publication. `composer check` verifies that the distributed asset matches the package sources. The private store belongs to the Instance, outside its checkout. It follows transfer and is deleted on removal of the Instance or Process, even when removal keeps source files.

### Port reservations

Creation stores `annotator_port` on the Instance, starting at `4848`, unique per Node among annotation server assignments. Hibernation keeps it. Transfer assigns a free destination port but retains the source reservation until source Caddy retirement succeeds. Process removal persists a withdrawal phase and keeps the reservation until Caddy confirms that the proxy is gone. A failed projection or crash is retryable and cannot expose a new Instance's queue through an old Route. The allocator is shared with Agentation. Workload Caddy strips `/__orbit/annotator` and forwards to that loopback port. Creation and removal rebuild Caddy when a Route exists.

### Queue URL and environment

Creation stores `ANNOTATOR_URL=https://{{instance.domain}}/__orbit/annotator/annotations` in the Instance environment. Every systemd Process receives the concrete URL and `ORBIT_ANNOTATOR_PORT`; derived values override caller values.

The Instances API, CLI, MCP, and PHP SDK expose `annotator_url` and `annotator_port`. The API's `annotator_url` is the service base; `ANNOTATOR_URL` points to its annotation queue. Without a Route, the API URL is null. The server permits only the page HTTPS origin and `t3code://app`, including queue reads, SSE, deletion, and preflight; it does not accept arbitrary browser origins.

### Start and wake

Start, restart, and wake render the current port and Route domain again. Explicit start and restart activate a stopped annotator; an identical create still respects its recorded stopped state. Sibling projection re-renders existing units without starting, stopping, or restarting them, so sleeping workers and cold dependencies stay asleep. Running siblings read the new environment on their next start or restart. Units unset stale annotator values after Process removal, even before the next environment sync. Wake waits for the server's `/health` endpoint and fails with `hibernation.annotator_not_ready` on timeout.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Processes, not a sidecar

Processes already own units, desired state, start and stop, Doctor, and hibernation. A Gateway-owned sidecar would need a second runtime model for all of these.

### No keep-alive

A sleeping Instance gets no toolbar traffic. With keep-alive, the watcher would keep an agent running and spending tokens after idle halt.

### A path on the Route origin

The toolbar talks to the application's own Route. A path on that origin needs no second domain or certificate. A custom proxy Route is for Node services without an Instance, so it does not fit.

### A separate path and port from Vite

Vite keeps its path prefix, and Agentation needs the prefix stripped. So each has its own reserved path and its own port assignment.

### Explicit presets

A preset is a field of the request, never a guess from the Process name. A Process named `agentation-mcp` without the preset is an ordinary Process.
