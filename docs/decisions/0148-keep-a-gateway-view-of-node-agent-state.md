---
title: "ADR 0148: Keep a Gateway view of Node agent state"
sidebarTitle: "0148 Keep a Gateway view of Node agent state"
description: "Proposed. One long-running Gateway process joins every Node agent's presence channel over one Reverb connection and keeps the latest Process state in the Gateway cache. Four repeated Process reads use that view and fall back to SSH when it is missing or stale."
---

# ADR 0148: Keep a Gateway view of Node agent state

One long-running Gateway process, the agent view subscriber, joins every Node agent's presence channel over one Reverb connection. It keeps the latest Process state of each Node in the Gateway cache, with the time the Gateway received it. Four repeated Process reads use this view instead of SSH, and fall back to SSH when the view is missing or stale. SSH stays the only way the Gateway changes a Node.

## Status

Proposed.

This amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels). ADR 0128 left the hibernation probes on SSH and asked for a new decision to move them. ADR 0129 kept agent events out of the Gateway and asked for a new decision for a Gateway view. This is that decision for Process state.

## Context

Every managed Node runs `orbit-agent`. It publishes presence and Process runtime state on `presence-node.{id}`. Only browsers subscribe, so the Gateway still reads Process state over SSH.

[PR #615](https://github.com/nckrtl/orbit/pull/615) moved Process list status to Prometheus, and [ADR 0127](/decisions/0127-share-one-ssh-connection-per-node) shares one SSH connection per Node. A trace of every `ssh` command the Gateway started in 10 minutes found 1,622 commands: 1,610 from API-triggered Doctor runs and converges, and 11 from one hibernator pass. Several reads still repeat while a page is open or an Instance wakes, and the agent already has their data:

| Read | SSH today | When it repeats |
| --- | --- | --- |
| Process list status when Prometheus cannot answer | 3 commands per systemd Process and 2 per Docker Process, for every list call | Every 15 seconds for each open web page |
| Process state while a hibernated Instance wakes | The same 3 or 2 commands every 0.5 seconds for each Process until it runs | Every wake |
| Ownership check before Process logs load | 2 commands for a systemd Process and 1 for a Docker Process, before the log read | Every 10 seconds for each open log pane |
| Ownership check before the hibernator stops a Process | 2 or 1 commands before each stop, also when the Process is already stopped | Every 10-minute pass for each idle Instance |

Other repeated reads need data the agent does not collect: the task tick's git checks, the task diff, Instance logs, the Horizon queue, and `ufw status`. Each costs about 240 to 360 commands per hour for each open page or idle task.

ADR 0129 rejected HTTP reports because each heartbeat would cost a PHP-FPM worker: 720 requests per hour per Node at a 5-second heartbeat. The Gateway VM has 2 vCPUs, 2 GiB of memory, and a PHP-FPM pool capped at a few workers. A reader in a PHP-FPM request cannot wait for Reverb, because an agent sends its full state only when a member joins.

## Decision

The Gateway owns the subscriber, the view, and the read contract. Node agents and Reverb stay unchanged.

### The subscriber

- The Gateway runs `php artisan orbit:agent-view` as `orbit-agent-view.service` on the Gateway host, with the PHP that PHP-FPM runs. The unit runs as the `orbit` user, uses `Restart=always`, and waits 2 seconds before a restart. `orbit:bootstrap` and `orbit:gateway-web` install, enable, and restart it, as they install the hibernator timer.
- The subscriber is a Pusher-protocol client of the existing Reverb app. It opens one WebSocket to Reverb for all Nodes. It connects to the `websocket` role's WireGuard address on port 443 and verifies the `reverb.orbit` certificate against the Orbit root CA, as the Gateway's broadcaster does.
- The subscriber joins `presence-node.{id}` for every Node that `ManagedNodeEligibility` allows. It signs each membership itself with the Reverb app secret the Gateway already holds, so no HTTP request is involved. Its member ID is `gateway.{socket id}` and its `user_info` is `{ "kind": "gateway" }`. The browser auth endpoint never signs a `gateway.*` member.
- A new member makes each agent send a full snapshot, so the view fills within seconds of every connection.
- Every 30 seconds the subscriber reads the Node list and the Reverb connection again. It joins new Nodes, leaves removed ones, and reconnects when the Reverb key or address changes. Without an active `websocket` role, it checks again every 60 seconds.
- When the connection drops, the subscriber clears the view and reconnects with exponential backoff from 1 to 30 seconds, with jitter. It answers Reverb's `pusher:ping`, and it sends its own ping after 30 quiet seconds. It reconnects when no answer arrives within 30 more seconds.
- The subscriber handles messages in one loop. It merges the changes of one loop pass and writes each changed Node at most once per pass. It ignores a message larger than 64 KB and keeps at most 4,096 units for each Node.
- Every 60 seconds the subscriber compares the Gateway checkout's commit with the commit it started from. When they differ, it exits and systemd starts it with the new code. A Gateway update needs no extra step.

### The view

- The view lives in the Gateway's default cache store, one entry for each Node and one for the subscriber's own health. The subscriber and every PHP-FPM worker share that store.
- A Node entry holds the agent's units, `docker` state, last `sequence`, and the Gateway time at which the last agent event arrived. Each write keeps the entry for 60 seconds.
- The subscriber applies agent events with the rules from ADR 0129. It accepts an event on `presence-node.{id}` only when Reverb's `user_id` is `agent.{id}`. It applies a snapshot once every part has arrived. It starts over when the agent's `sequence` restarts. It removes the Node entry when `agent.{id}` leaves the channel.
- The subscriber keeps a unit only when its name has the form `orbit-process-{id}-{name}`, its runtime is `systemd` or `docker`, and its status is a short lowercase word. Readers look up only the unit name that they compute from a Process on that Node.

### The read contract

A reader asks for one Node's state and receives one of three answers.

| Answer | Meaning | Reader behavior |
| --- | --- | --- |
| Fresh | A complete snapshot exists, and an agent event arrived in the last 15 seconds by the Gateway clock. | Use the view. |
| Stale | An entry exists, but no agent event arrived in the last 15 seconds. | Fall back. |
| Missing | No entry: no subscriber, no Reverb, no agent member, or no complete snapshot yet. | Fall back. |

In a fresh view, a systemd Process that is not listed is `inactive`, and a Docker Process that is not listed is `exited`. When the agent reports Docker as `absent`, the view does not answer for Docker Processes.

### Reads that move now

| Read | With a fresh view | Fallback |
| --- | --- | --- |
| Process list status | A status the Gateway observed after its own start, stop, or restart still wins for 30 seconds. Otherwise the view answers. | Prometheus, then SSH per Process |
| Wake readiness | The view answers every 0.5 seconds. A `failed` answer, or the wake timeout, is confirmed once over SSH before the wake fails. | SSH status every 0.5 seconds |
| Process logs | When the view lists the exact unit or container, the Gateway skips the ownership check and runs only the log read. | Ownership check, then the log read |
| Hibernator stop | When the view shows the Process stopped, the hibernator skips its stop. | Ownership check, then the stop |

A read right after the Gateway starts, stops, or restarts a Process stays on SSH, because it must observe the change that the Gateway just made. Every change to a Node still runs over SSH.

Estimated savings, from the measured command counts:

| Read | Commands saved |
| --- | --- |
| Process list during a Prometheus outage | About 12,000 per hour for each open page with 20 Processes: 240 lists × 2 or 3 commands × 20 Processes |
| Wake readiness | About 6 per second for each waking Process, for example 90 for a 5-second wake of 3 systemd Processes |
| Process logs | 720 per hour for each open systemd log pane, or 360 for a Docker one; the 360 log reads stay |
| Hibernator | 3 for each already-stopped systemd Process and 2 for each Docker one, for each idle Instance in every pass |

### Reads that need new agent data later

Each of these reads needs a new agent observation and its own decision. Logs and diffs carry content that every channel member would see.

| Read | Data the agent would need | Estimated commands saved |
| --- | --- | --- |
| Task tick git checks | Checkout HEAD, branch, and dirty state | About 360 per hour for each idle task |
| Task diff | Changed files and line counts | About 240 to 360 per hour for each open diff |
| Instance logs | The tail of `storage/logs/laravel.log`, redacted | About 360 per hour for each open log pane |
| Horizon queue | Queue sizes and recent jobs | About 360 per hour for each open queue page |
| `ufw status` | The live firewall rules | About 240 per hour for each open firewall page |

### The web app

The web app keeps reading Process state from each agent's channel, as ADR 0129 defines. It keeps its 15-second Process list poll, because no event carries CPU and memory. With a fresh view, that poll causes no SSH even when Prometheus is down. The poll can stop once the agent reports CPU and memory.

### Doctor

Doctor reports `node.agent_view_stale` in the `node` family for an eligible Node whose agent unit is active while a `websocket` role is active, when the Gateway has no fresh view of that Node. The observed value is `subscriber_down`, `disconnected`, `missing`, or `stale`.

### Failure modes

| Failure | Result |
| --- | --- |
| Reverb is down or the `websocket` role is absent | The subscriber clears the view and retries. Readers fall back to Prometheus and SSH, at today's cost. |
| An agent stops cleanly or crashes | Reverb removes its member, and the subscriber removes the Node entry at once. |
| A Node loses power or network | Its entry is stale 15 seconds after the last event. |
| The subscriber crashes | systemd restarts it after 2 seconds. Until it rejoins, entries turn stale after 15 seconds and expire after 60 seconds. |
| The Gateway and a Node disagree about the time | Freshness uses only the Gateway clock. The agent's `at` is stored but never compared. |
| The cache store fails | The subscriber logs a warning and keeps running. Readers find no entry and fall back. |

## Rejected alternatives

- Report agent state to the Gateway over HTTP: rejected, as in ADR 0129, because each heartbeat costs a PHP-FPM worker.
- Join the channel from a PHP-FPM request when a reader needs state: rejected because each read would open a connection and wait for a snapshot, which takes longer than the SSH read it replaces.
- Store the view in SQLite: rejected because heartbeats would write the Gateway's central store every few seconds and contend with API writes. The view is disposable and rebuilds within seconds.
- Keep the view in the subscriber's memory and serve it over a local socket: rejected because it adds a listener to the Gateway host, and readers would fail when the subscriber restarts.
- Re-broadcast agent state on the `orbit` channel: rejected because the web app already receives it from the agents, and a copy would double the traffic.
- Replace the SSH reads fully and drop the fallback: rejected because the agent, the subscriber, and Reverb are each optional, and a missing view must not break a wake or a log read.

## Consequences

- Wakes, log panes, and hibernator passes cost fewer SSH commands, and Process lists no longer need SSH during a Prometheus outage.
- The API and the CLI report live `runtime_status` while the view is fresh. ADR 0129's statement that `runtime_status` keeps coming from Prometheus no longer holds.
- The Gateway host runs one more long-running process, of about 50 MB, and holds one more WebSocket connection.
- Every subscriber connection makes every agent send a snapshot, as a new browser does.
- The largest SSH cost in the trace, Doctor and converges, does not change.
- A compromised Node can report false state about itself. False reports can end a wake early, make the hibernator skip a stop, or skip the ownership check before a log read on that Node. Root on that Node can already fake SSH answers, and no report affects another Node.
- The subscriber holds the Reverb secret, which the Gateway already holds, and it opens no port.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels); keeps [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) and [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role) unchanged
- Detail: [Node agent](/reference/node-agent#gateway-view), [Realtime events](/reference/events#node-agent-channels), [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation), [Metrics](/reference/metrics#process-runtime-status), [`doctor`](/cli/doctor)
- Verify: Gateway tests for the subscriber protocol, the view's freshness rules, the four reads, and the Doctor check; an Incus proof that counts SSH commands during a wake, a log view, a hibernator pass, and a Prometheus outage, stops an agent, and restarts Reverb
