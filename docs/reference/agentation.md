---
title: "Agentation"
description: "Per-app Agentation HTTP Processes, AGENTATION_URL projection, and the hibernate-tied Antigravity watcher."
---

# Agentation watch mode

This page tells an operator how Orbit runs Agentation's HTTP annotation server as an Instance Process, publishes it on the Route origin, projects `AGENTATION_URL`, and ties the Antigravity watcher to hibernation. [ADR 0082](/decisions/0082-wire-agentation-watch-mode-through-appinstance-processes) records the design. [Project processes and schedules](/reference/app-processes-and-schedules) owns Process add, start, stop, and removal. Idle halt and wake live on the [hibernation](/reference/app-dev-runtime-hibernation) page. The Agentation toolbar itself stays an application concern.

## What Orbit owns

Orbit owns the HTTP Process, the reserved Route path, the projected URL, the assigned loopback port, and the watcher Process. It does not install the toolbar, edit frontend code, or run a fleet-wide Agentation service.

| Piece | Owner | Role |
| --- | --- | --- |
| `agentation-mcp` Process | Instance | HTTP server that receives toolbar annotations on Node loopback. |
| Reserved path | Route origin | `https://<route-domain>/__orbit/agentation` through Cluster HTTPS. |
| `AGENTATION_URL` | Instance environment and Process units | Concrete public URL for the toolbar, MCP clients, and the watcher. |
| `antigravity-watch` Process | Instance | Antigravity agent that calls `agentation_watch_annotations` until Orbit stops the unit. |

Both Processes are ordinary development systemd Processes. They have no keep-alive. Idle halt stops them. The next HTTP request starts every desired-running Process, including the watcher.

## Create the HTTP Process

Use the explicit preset on a development Instance. `--instance` accepts a positive ID or an exact Route domain.

```bash
orbit process:create agentation --instance=commander.test --preset=agentation-mcp --start
```

The preset supplies the runtime, executable arguments, instance working directory, and restart-on-failure default. Conflicting `--app`, `--node`, command, runtime, Docker, working-directory, or environment options are refused. `--keep-alive` is refused. Naming an ordinary Process `agentation-mcp` has no special effect.

One Instance has at most one `agentation-mcp` Process. An identical create reuses the Process and preserves its desired state.

The preset command is:

```text
/usr/local/bin/agentation-mcp server --port=${ORBIT_AGENTATION_PORT}
```

The host must already provide `/usr/local/bin/agentation-mcp`. Orbit does not install that binary.

## Assigned port and reserved path

Creating the HTTP Process assigns a preferred loopback port on that Instance's Node. The first assignment is `4747`. Later Instances on the same Node receive the next unused port. The assignment survives hibernation. Transferring the Instance picks a free port on the destination Node. Removing the HTTP Process releases it.

Workload Caddy reverse-proxies `/__orbit/agentation` to `127.0.0.1:{agentation_port}` and strips the reserved prefix. The Agentation HTTP API keeps `/health` and `/sessions` at the upstream root. Creating or removing the HTTP Process republishes that Caddy site so the handle appears and disappears with the assignment. Sites without an assignment have no Agentation handle. [Routes](/reference/routes#agentation-endpoint) owns the reserved-path contract.

## Project AGENTATION_URL

When the HTTP Process exists and the Instance has a Route, Orbit projects this public URL.

| Location | Value |
| --- | --- |
| Stored environment | `https://{{app_instance.domain}}/__orbit/agentation` |
| Rendered workload `.env` after `env:sync` | `https://<route-domain>/__orbit/agentation` |
| Systemd Process environment | The same concrete URL, plus `ORBIT_AGENTATION_PORT` |

Example for `commander.test`:

```text
AGENTATION_URL=https://commander.test/__orbit/agentation
```

The stored placeholder follows the same renderer as other Instance environment values. A domain change updates the rendered URL on the next synchronization. Process units receive the concrete URL from the current Route, so the watcher does not depend on a prior `env:sync`. See [Instance environment variables](/reference/environment-variables).

## Create the Antigravity watcher

Create the watcher after the HTTP Process. It requires that `agentation-mcp` Process on the same Instance.

```bash
orbit process:create agentation-watch --instance=commander.test --preset=antigravity-watch --start
```

The preset owns runtime and command configuration. `--keep-alive` is refused. Restart policy is `always` so a finished prompt re-enters watch. The command is `/usr/local/bin/agy --dangerously-skip-permissions -p` with the Agentation hands-free prompt: call `agentation_watch_annotations` in a loop, acknowledge each annotation, apply the change, and resolve it. The host must already provide `/usr/local/bin/agy`.

Removing the HTTP Process while the watcher still exists returns `process.has_dependent`. Destroy the watcher first.

## Hibernate and wake

Do not add keep-alive to either preset. Hibernation stops both Processes after the idle HTTP window and starts them again on the next request when their desired state is `running`. Wake waits until the HTTP Process answers `/health` on the assigned loopback port. A watcher whose desired state is stopped stays stopped.

Verify the wiring like this:

```bash
orbit process:create agentation --instance=commander.test --preset=agentation-mcp --start
orbit process:create agentation-watch --instance=commander.test --preset=antigravity-watch --start
orbit process:list --instance=commander.test
orbit env:sync --instance=commander.test
```

Confirm `AGENTATION_URL` in the synchronized workload file, `https://<route-domain>/__orbit/agentation/health` through the Route, and that idle halt stops the watcher while the next HTTP request starts it again.
