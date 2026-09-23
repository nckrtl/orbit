---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, how the Gateway installs, upgrades, and removes it, how it connects to Reverb, and how Doctor checks it."
---

# Node agent

`orbit-agent` is a small Rust program that runs on every managed Linux Node. It reports whether it is running and the runtime state of the Node's Orbit Processes. The web app uses those reports to show Node presence and Process state live. The agent never runs commands, never changes the Node, and never listens on a port. [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) records why, [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels) defines the transport, and [ADR 0130](/decisions/0130-publish-agent-binaries-as-github-releases) defines the releases.

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

The agent connects to the Gateway at `https://gateway.orbit` and to Reverb at `wss://reverb.orbit`. It verifies both against the Orbit root certificate that the Gateway installs with it. The Gateway identifies the agent by the Node's WireGuard address, as it identifies every other caller.

1. The agent calls `GET /api/v1/agent/realtime`. The response names the Reverb connection, the Node's channel, and the agent's member ID.

   ```json
   {
     "data": {
       "url": "wss://reverb.orbit",
       "key": "<reverb-app-key>",
       "channel": "presence-node.12",
       "member": "agent.12"
     },
     "meta": { "request_id": "..." }
   }
   ```

   `url` and `key` are `null` when no `websocket` role is active. The agent then asks again every 60 seconds.

2. The agent opens the WebSocket and reads its `socket_id` from `pusher:connection_established`.
3. The agent requests authorization at `POST /api/v1/agent/broadcasting/auth` with `socket_id`, `channel_name`, and `version`.

   The `version` value is the agent's short version string, such as `1.2.3`. The Gateway signs membership `agent.{id}` on the caller's own channel only. The response has the Pusher `auth` and `channel_data` values.

4. The agent subscribes to `presence-node.{id}`, sends its snapshot, and then sends heartbeats and changes. [Realtime events](/reference/events#node-agent-channels) defines the events.

The two agent endpoints require an active WireGuard peer, but no Gateway access edge. They do not record Activity.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller's address does not belong to an active Node. |
| `agent.node_ineligible` | 403 | The caller's Node is outside the managed-node boundary. |
| `agent.channel_forbidden` | 403 | `channel_name` is not the caller's own `presence-node.{id}`. |
| `validation.failed` | 422 | `socket_id` is missing or not a Pusher socket ID, `channel_name` is missing, or `version` is not a short version string. |
| `realtime.not_configured` | 404 | No `websocket` role is active, so there is nothing to sign. |

When the connection drops, the agent reconnects with exponential backoff from 1 second to 30 seconds, with jitter. Every connection repeats all four steps, because Reverb gives each connection a new `socket_id`. The agent answers Reverb's `pusher:ping` with `pusher:pong`.

## Install and upgrade

The Gateway pins one agent version and one SHA-256 checksum for each architecture. It picks the asset for the Node's recorded architecture, `x86_64` or `aarch64`.

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-agent`, owned by `root`, mode `0755` |
| Configuration | `/etc/orbit/agent/config.toml`, with the Gateway URL |
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

To upgrade the fleet, publish a new release, update the pin in the Gateway, deploy the Gateway, and run `orbit node:add <node>` or a role converge on each Node. Doctor reports every Node that still runs another version.

## Failures

The agent recovers from each failure below without an operator.

| Situation | What happens |
| --- | --- |
| The agent crashes | systemd restarts it after 2 seconds. The web app shows the Node offline when the agent leaves the channel, and online again when it rejoins. |
| The agent stops cleanly | The agent closes its connection, so the web app shows the Node offline at once. |
| The Node loses power or network | Heartbeats stop. The web app shows the Node offline after 15 seconds without a heartbeat. |
| Reverb is down or the `websocket` role is absent | The agent retries. The web app polls and uses Prometheus, as it does without an agent. |
| The Gateway is down | The agent cannot get a membership signed and retries. An agent that is already connected keeps publishing. |
| systemd D-Bus is unavailable | The agent exits with an error, and systemd restarts it. |

The agent logs to the systemd journal. Logs contain no Reverb key or signature.

## Removal

Online `orbit node:remove` stops and disables `orbit-agent.service` and deletes the unit, the binary, and `/etc/orbit/agent`. This step is best-effort: a failure does not stop the removal, and the Gateway logs a warning. [Remove a Node](/reference/node-provisioning#remove-a-node) lists every removal step.

Offline removal changes nothing on the machine. The agent stays installed, and the response lists it under `retained_on_node`. Once its Node record is gone, the Gateway refuses its requests with `peer.identity_unknown`, and the agent keeps retrying at the 30-second backoff limit.

## Doctor

Doctor checks the agent in the `node` family on every eligible Node. It checks only what is on the machine. It does not check whether the agent is connected to Reverb, because the Gateway does not see agent traffic.

| Issue code | Meaning |
| --- | --- |
| `node.agent_missing` | The binary or the unit is absent. |
| `node.agent_inactive` | The unit exists but is not active. |
| `node.agent_outdated` | The binary's checksum differs from the pinned checksum for the Node's architecture. |

Run `orbit node:add <node>` to repair any of them.

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

- The agent reports presence and Process runtime state only. It does not replace SSH, the task workspace probes, or the hibernation probes.
- The Gateway API, the CLI, and `orbit top` do not see agent reports. They read `runtime_status` from Prometheus.
- The agent supports Linux on `x86_64` and `aarch64` only.
