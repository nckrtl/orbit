---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, how the Gateway installs, upgrades, and removes it, how it connects to Reverb, how the Gateway keeps a view of its reports, and how Doctor checks it."
---

# Node agent

`orbit-agent` is a small Rust program that runs on every managed Linux Node. It reports whether it is running and the runtime state of the Node's Orbit Processes. The web app uses those reports to show Node presence and Process state live. The Gateway keeps a [view](#gateway-view) of them, so it can skip repeated SSH reads. The agent never runs commands, never changes the Node, and never listens on a port. [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) records why, [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels) defines the transport, [ADR 0130](/decisions/0130-publish-agent-binaries-as-github-releases) defines the releases, and [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) defines the Gateway view.

## Where it runs

The Gateway installs the agent on every Node that uses the managed-node boundary of the Metrics exporters: an active Linux Node with a managed WireGuard address and Gateway-owned SSH management. [Exporter selection](/reference/metrics#exporter-selection) describes that boundary. Unlike an exporter, the agent needs no role or preference. An operator client, such as a Mac that runs the CLI, never runs the agent.

## What it observes

The agent watches two sources on the Node and reports the state of each Orbit Process unit or container.

| Source | What the agent watches | Reported `runtime_status` |
| --- | --- | --- |
| systemd over D-Bus | Units named `orbit-process-*.service` | The unit's `ActiveState`, such as `active`, `inactive`, `failed`, `activating`, or `deactivating`. A unit that does not exist reads as `inactive`. |
| Docker socket `/run/docker.sock` | Containers named `orbit-process-*` | The container's state, such as `running`, `exited`, `restarting`, `paused`, `created`, or `dead`. A container that does not exist reads as `exited`. |

These values use the same vocabulary as the `runtime_status` field of the Process API, which reads Prometheus. The agent reports each unit or container by its full name, such as `orbit-process-42-web`. It does not know which Process owns a name. The subscriber matches the name to a Process, so candidate and rollback containers such as `orbit-process-42-web-candidate` never match a Process.

The agent does not read Schedules, role services, CPU, or memory. Prometheus still provides CPU and memory.

When Docker is not installed or its socket is missing, the agent watches systemd only and reports Docker as `absent`. It checks for the socket every 10 seconds, and connects and sends a new snapshot when the socket appears. When the Docker event stream ends, for example because Docker restarted, the agent reconnects and sends a new snapshot.

The agent merges changes to the same unit or container that arrive within 250 milliseconds and sends only the latest state.

## How it connects

The agent connects to the Gateway at `https://gateway.orbit` and to Reverb at `wss://reverb.orbit`. It never uses system DNS: its configuration contains the Gateway's WireGuard address, and the realtime response contains Reverb's serving address. The agent connects to each address while verifying the certificate for the unchanged hostname against the Orbit root certificate that the Gateway installs with it. The Gateway identifies the agent by the Node's WireGuard address, as it identifies every other caller.

The Gateway writes `gateway_address` to the agent's `config.toml` on every converge. This is the WireGuard address that Orbit's private DNS answers for `gateway.orbit`, also while the `gateway` role itself converges. The agent sends every Gateway request to `gateway_address` on port 443, with `gateway.orbit` as the TLS server name, and verifies the certificate against `ca.pem` without resolving the hostname.

1. The agent calls `GET /api/v1/agent/realtime`. The response names the Reverb connection, its serving address, the Node's channel, and the agent's member ID.

   ```json
   {
     "data": {
       "url": "wss://reverb.orbit",
       "address": "10.44.0.3",
       "key": "<reverb-app-key>",
       "channel": "presence-node.12",
       "member": "agent.12"
     },
     "meta": { "request_id": "..." }
   }
   ```

   `url`, `address`, and `key` are `null` when no `websocket` role is active. The agent then asks again every 60 seconds.

2. The agent opens a TCP connection to the Reverb `address` on port 443. It uses `reverb.orbit` as the TLS server name and verifies that certificate against `ca.pem`. It reads its `socket_id` from `pusher:connection_established`.
3. The agent requests authorization at `POST /api/v1/agent/broadcasting/auth` with `socket_id`, `channel_name`, and `version`.

   The `version` value is the agent's short version string, such as `1.2.3`. The Gateway signs membership `agent.{id}` on the caller's own channel only. The response has the Pusher `auth` and `channel_data` values.

4. The agent subscribes to `presence-node.{id}`, sends its snapshot, and then sends heartbeats and changes. [Realtime events](/reference/events#node-agent-channels) defines the events.

When the Gateway role moves, its WireGuard address changes, and every agent loses the Gateway until its configuration is rewritten. Run `orbit node:add <node>` or a role converge on each Node; a changed `gateway_address` restarts the agent. The Reverb address needs no converge, because the agent reads it from each realtime response when it connects.

The two agent endpoints require an active WireGuard peer, but no Gateway access edge. They do not record Activity.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller's address does not belong to an active Node. |
| `agent.node_ineligible` | 403 | The caller's Node is outside the managed-node boundary. |
| `agent.channel_forbidden` | 403 | `channel_name` is not the caller's own `presence-node.{id}`. |
| `validation.failed` | 422 | `socket_id` is missing or not a Pusher socket ID, `channel_name` is missing, or `version` is not a short version string. |
| `realtime.not_configured` | 404 | No `websocket` role is active, so there is nothing to sign. |

When the connection drops, the agent reconnects with exponential backoff from 1 second to 30 seconds, with jitter. Every connection repeats all four steps, because Reverb gives each connection a new `socket_id`. The agent answers Reverb's `pusher:ping` with `pusher:pong`.

## Gateway view

The Gateway keeps the latest Process state that each agent reports, so that it can skip repeated SSH reads. A long-running Gateway process, the agent view subscriber, receives the reports. The view is only an input to reads: every change to a Node still runs over SSH.

### Subscriber

The subscriber is a Gateway process that runs next to PHP-FPM on the Gateway host.

| Item | Value |
| --- | --- |
| Command | `/usr/bin/php8.5 artisan orbit:agent-view`, run as the `orbit` user in the Gateway checkout |
| Unit | `/etc/systemd/system/orbit-agent-view.service`, with `Restart=always` and `RestartSec=2` |
| Installed by | `orbit:bootstrap` and `orbit:gateway-web`, which install, enable, and restart the unit |
| Connection | One WebSocket to Reverb for all Nodes, to the `websocket` role's WireGuard address on port 443, verifying the `reverb.orbit` certificate against the Orbit root CA |
| Channels | `presence-node.{id}` for every Node inside the [managed-node boundary](#where-it-runs) |
| Member | `gateway.{socket id}`, with `user_info` `{ "kind": "gateway" }`, signed by the subscriber with the Reverb app secret |

The subscriber joins each channel as a new member, so each agent sends it a full snapshot. Every 30 seconds it reads the Node list and the Reverb connection again: it joins new Nodes, leaves removed Nodes, and reconnects when the Reverb key or address changes. Without an active `websocket` role, it checks again every 60 seconds.

When the connection drops, the subscriber clears the view and reconnects with exponential backoff from 1 second to 30 seconds, with jitter. It answers `pusher:ping`, sends its own ping after 30 quiet seconds, and reconnects when no answer arrives within 30 more seconds. It ignores a message larger than 64 KB and keeps at most 4,096 units for each Node.

Every 60 seconds the subscriber compares the Gateway checkout's commit with the commit it started from. When they differ, it exits, and systemd starts it again with the new code. It writes connection changes and failures to the Gateway log.

### Stored state

The view lives in its own file cache store in `ORBIT_HOME/cache/agent-view`. The Gateway pins that store in code, whatever `CACHE_STORE` says, so heartbeats never write the SQLite database. The subscriber and every PHP-FPM worker run as `orbit` and share those files.

| Entry | Contents | Kept for |
| --- | --- | --- |
| One for each Node | The agent's units, its `docker` state, its last `sequence`, and the Gateway time at which the last agent event arrived | 60 seconds after its last write |
| One for the subscriber | Whether realtime is configured, whether the socket is connected, the number of joined channels, and the Gateway time of the last write | 30 seconds after its last write |

The subscriber writes its own entry every 5 seconds and at once when its connection drops. It removes the entry when it stops.

The subscriber applies agent events with the rules in [Realtime events](/reference/events#events): it accepts an event only when Reverb's `user_id` is `agent.{id}`, applies a snapshot when every part has arrived, and starts over when the agent's `sequence` restarts. It removes a Node's entry when `agent.{id}` leaves the channel. It keeps a unit only when the name has the form `orbit-process-{id}-{name}`, the runtime is `systemd` or `docker`, and the status is a short lowercase word.

### Freshness

A reader asks for one Node's state and gets one of three answers. Freshness uses only the Gateway clock, so a Node whose clock is wrong still reads as fresh.

| Answer | Meaning |
| --- | --- |
| Fresh | A complete snapshot exists, and an agent event arrived in the last 15 seconds. |
| Stale | An entry exists, but no agent event arrived in the last 15 seconds. |
| Missing | No entry exists: the subscriber is down, Reverb is unreachable, the agent is not a member, or no complete snapshot arrived yet. |

In a fresh view, a systemd Process that the agent does not list is `inactive`, and a Docker Process that it does not list is `exited`. When the agent reports Docker as `absent`, the view does not answer for Docker Processes.

### Reads that use the view

Four repeated reads ask the view first and fall back when it is not fresh.

| Read | With a fresh view | Otherwise |
| --- | --- | --- |
| Process list `runtime_status` | A status that the Gateway observed after its own start, stop, or restart wins for 30 seconds. Otherwise the view answers. | [Prometheus](/reference/metrics#process-runtime-status), then SSH for each Process |
| Readiness while a hibernated Instance [wakes](/reference/app-dev-runtime-hibernation#wake) | The view answers every 0.5 seconds. A `failed` answer, or the wake timeout, is checked once over SSH before the wake fails. | SSH every 0.5 seconds |
| [`process:logs`](/cli/process#orbit-processlogs) | When the view lists the exact unit or container, the Gateway runs only the log read. | The ownership check over SSH, then the log read |
| The hibernator's [idle halt](/reference/app-dev-runtime-hibernation#idle-window-and-sweep) | When the view shows the Process stopped, the hibernator skips its stop. | The ownership check and the stop over SSH |

The status that a start, stop, or restart itself returns stays on SSH, because it must show the change the Gateway just made. A wake's readiness checks are separate reads that follow the start, so they use the view.

## Install and upgrade

The Gateway pins one agent version and one SHA-256 checksum for each architecture. It picks the asset for the Node's recorded architecture, `x86_64` or `aarch64`.

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-agent`, owned by `root`, mode `0755` |
| Configuration | `/etc/orbit/agent/config.toml`, with `gateway_url = "https://gateway.orbit"` and required `gateway_address` (the Gateway's WireGuard address) |
| Orbit root certificate | `/etc/orbit/agent/ca.pem` |
| Unit | `/etc/systemd/system/orbit-agent.service`, marked `# Managed by Orbit: agent` |
| Download | `https://github.com/nckrtl/orbit/releases/download/agent-v{version}/orbit-agent-{version}-linux-{arch}` |

The Gateway downloads the asset on the Node to a candidate file. It checks the checksum, then moves the candidate into place. A checksum mismatch deletes the candidate and fails with `agent.checksum_mismatch`. When the installed binary already matches the pin, the Gateway skips the download.

The unit runs the agent as `root` with `Restart=always` and `RestartSec=2`. It grants no capabilities (`CapabilityBoundingSet=` is empty) and sets `NoNewPrivileges=yes`, `ProtectSystem=strict`, `ProtectHome=yes`, `PrivateTmp=yes`, `MemoryMax=64M`, and `RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6`. Root ownership of the Docker socket lets the agent read it without capabilities.

The Gateway restarts the agent only when the binary, configuration, certificate, or unit changed. An unchanged converge leaves the running agent and its connection alone.

The Gateway converges the agent at these points:

| When | Result of a failure |
| --- | --- |
| `node:add`, for a new or an existing Node, after the Metrics exporters | Provisioning fails at step `agent` with `node.agent_install_failed`. A new Node becomes `failed`, and an existing active Node stays `active`. |
| A role converge on the Node | The role converge continues. The Gateway logs a warning, and Doctor reports the drift. |

To upgrade the fleet, publish a new release, update the pin in the Gateway, deploy the Gateway, and run `orbit node:add <node>` or a role converge on each Node. Doctor reports every Node that still runs another version. Version 0.1.1 requires `gateway_address` in its configuration.

## Failures

The agent recovers from each failure below without an operator.

| Situation | What happens |
| --- | --- |
| The agent crashes | systemd restarts it after 2 seconds. The web app shows the Node offline until the agent rejoins. The Gateway drops the Node's view and reads over SSH until then. |
| The agent stops cleanly | The agent closes its connection, so the web app shows the Node offline at once, and the Gateway drops the Node's view. |
| The Node loses power or network | Heartbeats stop. The web app shows the Node offline after 15 seconds without a heartbeat, and the Gateway's view of the Node turns stale at the same time. |
| Reverb is down or the `websocket` role is absent | The agent and the subscriber retry. The web app polls Prometheus, and Gateway reads use Prometheus and SSH. |
| The agent view subscriber stops | systemd restarts it after 2 seconds. Until it rejoins, the Gateway's view turns stale after 15 seconds, and Gateway reads use Prometheus and SSH. |
| The Gateway is down | The agent cannot get a membership signed and retries. An agent that is already connected keeps publishing. |
| systemd D-Bus is unavailable | The agent exits with an error, and systemd restarts it. |

The agent logs to the systemd journal. Logs contain no Reverb key or signature.

## Removal

Online `orbit node:remove` stops and disables `orbit-agent.service` and deletes the unit, the binary, and `/etc/orbit/agent`. This step is best-effort: a failure does not stop the removal, and the Gateway logs a warning. [Remove a Node](/reference/node-provisioning#remove-a-node) lists every removal step.

`--offline` on a Node that still answers the probe removes the agent as online removal does. Removing an unreachable Node with `--offline --force` changes nothing on the machine. The agent stays installed, and the response lists it under `retained_on_node`. Once its Node record is gone, the Gateway refuses its requests with `peer.identity_unknown`, and the agent keeps retrying at the 30-second backoff limit.

## Doctor

Doctor checks the agent in the `node` family on every eligible Node. It checks the binary, the unit, and the version on the machine, and whether the Gateway has a fresh view of the Node.

| Issue code | Meaning |
| --- | --- |
| `node.agent_missing` | The binary or the unit is absent. |
| `node.agent_inactive` | The unit exists but is not active. |
| `node.agent_outdated` | The binary's checksum differs from the pinned checksum for the Node's architecture. |
| `node.agent_view_stale` | The agent unit is active and a `websocket` role is active, but the Gateway has no fresh view of the Node. |

Run `orbit node:add <node>` to repair the first three. `node:add` refuses a Node that owns Instances; repair such a Node by converging one of its roles with `orbit node:role:add <node> <role> --converge`.

`node.agent_view_stale` reports what it observed:

| Observed | Meaning | Repair |
| --- | --- | --- |
| `subscriber_down` | The subscriber stopped, or it has not written its health in the last 30 seconds. | Check `systemctl status orbit-agent-view` on the Gateway host, or run `php artisan orbit:gateway-web` in the Gateway checkout. |
| `disconnected` | The subscriber runs but has no Reverb connection. | Check the `websocket` role with `orbit doctor --family=role`. |
| `missing` | The subscriber is connected but has no complete snapshot from this Node's agent. | Check `journalctl -u orbit-agent` on the Node. |
| `stale` | No agent event arrived from this Node in the last 15 seconds. | Check the Node's network and `journalctl -u orbit-agent` on the Node. |

## Releases

Pushing a tag named `agent-v{version}` releases the agent. The version must equal the version in `apps/agent/Cargo.toml`. The release job builds static musl binaries and publishes them as a GitHub release. Create the tag with the GitHub CLI on a commit that is already on GitHub:

```bash
gh api repos/nckrtl/orbit/git/refs -f ref=refs/tags/agent-v{version} -f sha={commit}
```

| Asset | Contents |
| --- | --- |
| `orbit-agent-{version}-linux-x86_64` | Static binary for `x86_64` Nodes |
| `orbit-agent-{version}-linux-aarch64` | Static binary for `aarch64` Nodes |
| `SHA256SUMS` | The SHA-256 checksum of both binaries |

The job refuses to replace the assets of an existing release. Pull requests and pushes to `main` build and test the agent without publishing.

## Limits

The first version of the agent has these limits.

- The agent reports presence and Process runtime state only. The Gateway uses it for the four reads in [Gateway view](#gateway-view).
- Task workspace probes, Instance logs, the Horizon queue, and `ufw status` still run over SSH. The agent does not collect their data.
- The agent does not report CPU or memory, so the web app still polls the Process list for them.
- The agent supports Linux on `x86_64` and `aarch64` only.
