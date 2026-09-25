---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, including task checkouts, and how it tails logs for live viewers. How the Gateway installs, connects, and removes it, keeps a view of its reports, relays log lines, pushes Process usage, and checks it with Doctor."
---

# Node agent

`orbit-agent` is a small Rust program that runs on every managed Linux Node. It reports whether it is running, the runtime state of the Node's Orbit Processes, and the Git state of the Node's task checkouts. The web app uses those reports to show Node presence and Process state live. The Gateway keeps a [view](#gateway-view) of them, so it can skip repeated SSH reads. While someone watches a log live, the agent also [tails](#log-tails) that log and sends the lines to the Gateway. The agent never runs commands, never changes the Node, and never listens on a port.

[ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) records why, [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels) defines the transport, [ADR 0130](/decisions/0130-publish-agent-binaries-as-github-releases) defines the releases, [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) defines the Gateway view, [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime) adds task checkouts and Process usage, and [ADR 0153](/decisions/0153-tail-instance-and-process-logs-live-through-the-node-agent) adds log tails.

## Where it runs

The Gateway installs the agent on every Node that uses the managed-node boundary of the Metrics exporters: an active Linux Node with a managed WireGuard address and Gateway-owned SSH management. [Exporter selection](/reference/metrics#exporter-selection) describes that boundary. Unlike an exporter, the agent needs no role or preference. An operator client, such as a Mac that runs the CLI, never runs the agent.

## What it observes

The agent watches two sources on the Node and reports the state of each Orbit Process unit or container. It also reports the Git state of the Node's [task workspaces](#task-workspaces).

| Source | What the agent watches | Reported `runtime_status` |
| --- | --- | --- |
| systemd over D-Bus | Units named `orbit-process-*.service` | The unit's `ActiveState`, such as `active`, `inactive`, `failed`, `activating`, or `deactivating`. A unit that does not exist reads as `inactive`. |
| Docker socket `/run/docker.sock` | Containers named `orbit-process-*` | The container's state, such as `running`, `exited`, `restarting`, `paused`, `created`, or `dead`. A container that does not exist reads as `exited`. |

These values use the same vocabulary as the `runtime_status` field of the Process API, which reads Prometheus. The agent reports each unit or container by its full name, such as `orbit-process-42-web`. It does not know which Process owns a name. The subscriber matches the name to a Process, so candidate and rollback containers such as `orbit-process-42-web-candidate` never match a Process.

The agent does not read Schedules, role services, CPU, or memory. Prometheus still provides CPU and memory.

When Docker is not installed or its socket is missing, the agent watches systemd only and reports Docker as `absent`. It checks for the socket every 10 seconds, and connects and sends a new snapshot when the socket appears. When the Docker event stream ends, for example because Docker restarted, the agent reconnects and sends a new snapshot.

The agent merges changes to the same unit or container that arrive within 250 milliseconds and sends only the latest state.

## Task workspaces

The agent reports the Git state of each task checkout on its Node, so the Gateway can skip `git` over SSH. It reads only Git metadata. It never sends file contents or file names, and it never starts `git` or any other program.

### Watch list

The agent asks the Gateway which checkouts to watch with `GET /api/v1/agent/workspaces`, at start and every 60 seconds:

```json
{
  "data": [
    {
      "instance_id": 31,
      "path": "/home/orbit/apps/orbit/task-58",
      "base": "main",
      "start": "9f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9"
    }
  ],
  "meta": { "request_id": "..." }
}
```

The list holds the Instances on the caller's Node that hold the workspace of a [task group](/reference/tasks) that has not finished: `backlog` or `todo` with an Instance, `reserved`, `running`, `reviewing`, or `settling`. `base` is the Project's default branch. `start` is the Instance's starting commit, or null. The list is empty while the tasks extension is disabled, and it holds at most 64 entries. The endpoint uses the same rules and errors as the other [agent endpoints](#how-it-connects).

The agent accepts only an absolute path of at most 4,096 bytes, without `.` or `..` parts, that opens as the root of a Git work tree. A linked worktree works. It never opens a path that the list does not name, and it never opens a submodule.

### Reading Git

The agent reads Git with libgit2 inside its own process. libgit2 runs no hooks, filters, or `fsmonitor` programs. The checkouts belong to `orbit` and the agent runs as `root`, so the agent turns off libgit2's check of the repository owner.

Every 2 seconds the agent checks the size, time, and inode of these files in each checkout's Git directory: `HEAD`, `index`, `packed-refs`, the current branch's ref, and the base ref. When one of them changed, it reads the checkout again. It also reads every checkout again every 30 seconds, because an edit to a working file changes none of those files. It reads one checkout at a time.

| Field | Meaning |
| --- | --- |
| `instance_id` | The Instance from the watch list. |
| `base`, `start` | The `base` and `start` values the agent read the state against. |
| `branch` | The current branch, such as `task-58`, or null when `HEAD` is detached. |
| `head` | The full `HEAD` commit, or null in an empty repository. |
| `dirty` | `true` when the index or working tree differs from `HEAD`, untracked files included. Ignored files and submodules do not count. Null when the agent cannot read the working tree. |
| `commits` | The number of commits reachable from `HEAD` but not from `start`, at most 1,000. Null without `start`, or when the checkout does not have `start`. |
| `diff` | `{ files, added, removed, truncated }` from the merge base of `base` and `HEAD` to `HEAD`, as `git diff --numstat base...HEAD` counts it. Null when `base` does not resolve. `truncated` is true over the file limit. |

| Limit | Value |
| --- | --- |
| Checkouts per Node | 64 |
| Changed files in `diff` | 5,000; a larger diff has an exact `files` count, no line counts, and `truncated: true` |
| Lines counted per file | Files up to 1 MiB. A larger file, and a binary file, counts as changed with no lines. |
| Submodules | A changed submodule counts as one changed file with no lines. The agent never opens it. |
| Renames | Counted as `git diff` counts them by default. When an added or deleted file is over 1 MiB, only exact renames count. |
| Branch name | 255 characters; a checkout on a longer branch is left out |
| libgit2 memory | 8 MiB object cache and 32 MiB mapped pack window |

A checkout the agent cannot open or read, for example because its Git files are not readable by other users, is left out of its reports. The Gateway then reads that checkout over SSH.

The agent publishes the state as `client-workspaces` and `client-workspace` events on its channel. [Realtime events](/reference/events#events) defines them.

## Log tails

The agent reads an Instance log or a Process log only while a viewer watches it through a [live log stream](/reference/live-logs). It never reads a log on its own, and it sends the lines only to the Gateway.

### Stream list

The agent asks the Gateway which logs to read with `GET /api/v1/agent/log-streams`:

```json
{
  "data": [
    {
      "id": "3f9c2a6b0d1e4f5a8b7c6d5e4f3a2b1c",
      "lines": 100,
      "source": { "type": "laravel", "path": "/home/orbit/apps/shop/main" }
    },
    {
      "id": "8a1d0c2e4b6f4a3c9e7d5b1a0f2c4e6d",
      "lines": 500,
      "source": { "type": "journal", "unit": "orbit-process-41-queue.service" }
    },
    {
      "id": "c0ffee00c0ffee00c0ffee00c0ffee00",
      "lines": 100,
      "source": { "type": "docker", "container": "orbit-process-42-web", "process_id": 42 }
    }
  ],
  "meta": { "request_id": "..." }
}
```

The list holds the active streams whose source is on the caller's Node, at most 16. A stream becomes active with its viewer's first renewal. The endpoint uses the same rules and errors as the other [agent endpoints](#how-it-connects).

The agent fetches the list when it joins its log channel, when the Gateway publishes `log-streams.changed` on that channel, and every 15 seconds while it reads at least one stream. The event carries no data. It only prompts the fetch, so the agent reads only what the HTTPS response names. The agent stops every stream that the list does not name. When it cannot fetch the list for 60 seconds, it stops every stream.

### Sources

The agent accepts three source types and checks each one itself. It runs no program for any of them.

| Type | Fields | What the agent reads | The agent refuses |
| --- | --- | --- | --- |
| `laravel` | `path`: the Instance checkout | `storage/logs/laravel.log`, or the newest `laravel-*.log` when it is absent. It follows a daily file to the next day's file. | A path that is not normalized and absolute. A link at `storage/logs` or at the file. A file that is not regular, or that `root` owns. |
| `journal` | `unit`: the Process's systemd unit | The journal files in `/var/log/journal` and `/run/log/journal`. It returns the entries of the unit and systemd's own messages about it. | A unit that is not `orbit-process-{id}-{name}.service`, with a positive `id` and a name of lowercase letters, digits, and inner hyphens. |
| `docker` | `container` and `process_id` | The container's standard output and standard error, through the Docker Engine API. | A name that is not `orbit-process-{id}-{name}` with those rules, and a container without the labels `orbit.managed=true` and `orbit.process.id` equal to `process_id`. |

A normalized absolute path has at most 4,096 bytes and no `.`, `..`, empty parts, or control characters. Refusing a file that `root` owns means the agent reads only files that other users may read, and only inside the Instance root that its unit binds. A link in the checkout therefore cannot point the read at a file of another user. The agent's own journal reader supports the compact and regular formats, keyed and unkeyed hashes, and zstd and lz4 compression. A journal field compressed with xz shows as `[orbit] entry not readable`.

When the agent cannot open or keep reading a source, it ends the stream with `source_unavailable`.

### Redaction and limits

The agent redacts each line with the Gateway's secret patterns before it sends it. [Live logs](/reference/live-logs#redaction) lists the patterns and their limits. One table of cases in `apps/agent/tests/redaction_cases.json` holds the expected result of each pattern, and both the agent tests and the Gateway tests run it.

| Limit | Value |
| --- | --- |
| Streams | 16 |
| First lines | The stream's `lines`, at most 256 KiB. They do not count against the rates. |
| Line length | 8 KiB; a longer line is cut and ends with `[truncated]` |
| Events | One `client-log` event for each stream every 250 milliseconds at most, each under 10,000 bytes |
| Rate | 32 KiB per second for each stream, with a 256 KiB burst, and 256 KiB per second for the agent |
| Read ahead | 1 MiB for each read. When a file is more than 4 MiB behind, the agent skips to its newest 64 KiB. |

The agent drops a line that exceeds a rate, counts it, and reports the count in the next event. It never queues more than one burst for each stream.

### Log channel

The agent sends log lines on a second presence channel, `presence-node-logs.{id}`, as member `agent.{id}`. Only the agent and the Gateway's subscriber join it; the browser auth endpoint refuses it. The agent publishes `client-log` and `client-log-end` events there. [Realtime events](/reference/events#log-channels) defines them.

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
       "log_channel": "presence-node-logs.12",
       "member": "agent.12"
     },
     "meta": { "request_id": "..." }
   }
   ```

   `url`, `address`, and `key` are `null` when no `websocket` role is active. The agent then asks again every 60 seconds.

2. The agent opens a TCP connection to the Reverb `address` on port 443. It uses `reverb.orbit` as the TLS server name and verifies that certificate against `ca.pem`. It reads its `socket_id` from `pusher:connection_established`.
3. The agent requests authorization at `POST /api/v1/agent/broadcasting/auth` with `socket_id`, `channel_name`, and `version`.

   The `version` value is the agent's short version string, such as `1.2.3`. The Gateway signs membership `agent.{id}` on the caller's own two channels only. The response has the Pusher `auth` and `channel_data` values.

4. The agent subscribes to `presence-node.{id}` and sends its snapshot. Then it publishes the events in [Realtime events](/reference/events#node-agent-channels).
5. The agent repeats steps 3 and 4 for `presence-node-logs.{id}` and fetches its [stream list](#stream-list). Agents before 0.3.0 skip this step.

When the Gateway role moves, its WireGuard address changes, and every agent loses the Gateway until its configuration is rewritten. Run `orbit node:add <node>` or a role converge on each Node; a changed `gateway_address` restarts the agent. The Reverb address needs no converge, because the agent reads it from each realtime response when it connects.

The agent endpoints, including the [watch list](#watch-list) and the [stream list](#stream-list), require an active WireGuard peer, but no Gateway access edge. They do not record Activity.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller's address does not belong to an active Node. |
| `agent.node_ineligible` | 403 | The caller's Node is outside the managed-node boundary. |
| `agent.channel_forbidden` | 403 | `channel_name` is not the caller's own `presence-node.{id}` or `presence-node-logs.{id}`. |
| `validation.failed` | 422 | `socket_id` is missing or not a Pusher socket ID, `channel_name` is missing, or `version` is not a short version string. |
| `realtime.not_configured` | 404 | No `websocket` role is active, so there is nothing to sign. |

When the connection drops, the agent reconnects with exponential backoff from 2 seconds to 30 seconds, with jitter. A session that stayed joined for 60 seconds starts the backoff again from 2 seconds. A session that fails sooner keeps backing off, so an agent that fails right after every join does not retry every 2 seconds. Every connection repeats all steps, because Reverb gives each connection a new `socket_id`.

The agent bounds each step, so a peer that stops answering never holds it:

| Step | Limit |
| --- | --- |
| A Gateway request | 10 seconds to connect and 30 seconds in total |
| One join, from the TCP connection to `pusher_internal:subscription_succeeded` | 30 seconds |
| A joined connection | After 15 seconds without a message from Reverb, the agent sends `pusher:ping`. When nothing arrives within 10 more seconds, it reconnects. |

The agent answers Reverb's `pusher:ping` with `pusher:pong`.

One Node runs one agent. The agent holds an exclusive lock on `/etc/orbit/agent` while it runs, and a second agent process exits with `another orbit-agent already runs on this Node`. A second process would join as the same member, and Reverb announces neither its join nor its exit, so it would mix two event streams on the channel.

Agent 0.1.1 has none of the limits above, no lock, and no 60-second snapshot. [ADR 0154](/decisions/0154-recover-the-gateway-agent-view-without-a-membership-change) added them, and 0.2.0 is the first release that has them.

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
| Channels | `presence-node.{id}` and `presence-node-logs.{id}` for every Node inside the [managed-node boundary](#where-it-runs) |
| Member | `gateway.{socket id}`, with `user_info` `{ "kind": "gateway" }`, signed by the subscriber with the Reverb app secret |

The subscriber joins each channel as a new member, so each agent sends it a full snapshot.

The subscriber asks an agent for a snapshot when a channel owes one for 5 seconds. A channel owes a snapshot while agent events arrive without a complete snapshot, and after the agent's `sequence` goes back without a membership change, until the next request. To ask, the subscriber sends `pusher:unsubscribe` and `pusher:subscribe` for that channel on its connection. Reverb announces it as a new member, and every agent connection sends a complete snapshot. The subscriber asks each channel at most once every 5 seconds and keeps what it knows until the snapshot arrives. [ADR 0154](/decisions/0154-recover-the-gateway-agent-view-without-a-membership-change) records why.

Every 5 seconds it reads the Reverb connection again, and every 30 seconds the Node list: it joins new Nodes, leaves removed Nodes, and reconnects when the Reverb key changes. During a `websocket` move it keeps one link to each Reverb server that holds clients and takes each Node's state from the link with the newest agent event, as [Caddy configuration](/reference/caddy-configuration#node-caddy-build) describes. Without an active `websocket` role, it checks again every 60 seconds.

When the connection drops, the subscriber clears the view and reconnects with exponential backoff from 1 second to 30 seconds, with jitter. While a second link is open, or for 15 seconds after one closed, it keeps a Node's stored view instead of clearing it. It answers `pusher:ping`, sends its own ping after 30 quiet seconds, and reconnects when no answer arrives within 30 more seconds. It ignores a message larger than 64 KB and keeps at most 4,096 units for each Node.

Every 60 seconds the subscriber compares the Gateway checkout's commit with the commit it started from. When they differ, it exits, and systemd starts it again with the new code. It writes connection changes and failures to the Gateway log.

### Stored state

The view lives in its own file cache store in `ORBIT_HOME/cache/agent-view`. The Gateway pins that store in code, whatever `CACHE_STORE` says, so heartbeats never write the SQLite database. The subscriber and every PHP-FPM worker run as `orbit` and share those files.

| Entry | Contents | Kept for |
| --- | --- | --- |
| One for each Node | The agent's units, `docker` state, task workspaces, version, log channel membership, last `sequence`, and the Gateway time of its last event | 60 seconds after its last write |
| One for each Node with open log streams | The Node's [live log streams](/reference/live-logs): each stream's ID, record, source, `lines`, opening Node, and lease end | Until its last lease ends |
| One for the subscriber | Whether realtime is configured, whether the socket is connected, the number of joined channels, and the Gateway time of the last write | 30 seconds after its last write |

The subscriber writes its own entry every 5 seconds, and at once when its connection drops or comes back. It removes the entry when it stops.

The subscriber applies agent events with the rules in [Realtime events](/reference/events#events): it accepts an event only when Reverb's `user_id` is `agent.{id}`, applies a snapshot when every part has arrived, and starts over when the agent's `sequence` restarts. It removes a Node's entry when `agent.{id}` leaves the channel. Reverb announces that only when the member's last connection leaves. It keeps a unit only when the name has the form `orbit-process-{id}-{name}`, the runtime is `systemd` or `docker`, and the status is a short lowercase word.

It keeps a workspace only with a positive Instance id, a 40-character hexadecimal `head` and `start` or null, a branch of at most 255 characters, and non-negative counts, and it keeps at most 64 workspaces for each Node.

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

Two task reads use the Node's [task workspaces](#task-workspaces). A reader uses a workspace only when the Node's view is fresh, the workspace is listed, and its `base` and `start` equal the values the reader would use.

| Read | With a fresh workspace | Otherwise |
| --- | --- | --- |
| The [scheduler tick](/reference/tasks#session-routing)'s check for new commits since the thread started | `commits` is greater than 0 | `git rev-list` or `git log` over SSH |
| The line diff that [showing an active group](/reference/tasks#tokens-and-line-diff) refreshes | `diff.added` and `diff.removed`, unless `diff` is truncated | `git diff --shortstat` over SSH |

Reads that decide what the Gateway commits or tells an agent stay on SSH: the branch check before an approval commit, a subtask's start commit, the `composer.json` check script, run receipts, and the Project check. A workspace can lag its checkout by up to 2 seconds.

When the subscriber sees a new `head` or new diff counts for the workspace of an unfinished task group, it hands the work to a [publish run](#publish-runs). The run stores the group's line counts when the diff is complete, and always broadcasts [`task_group.updated`](/reference/events#tasks), also for a truncated or unknown diff. The database is written only for such a change.

### Log relay

The subscriber relays the lines of every [live log stream](/reference/live-logs) through a [publish run](#publish-runs), so a slow Reverb or database never stalls the view. In its socket loop it accepts a `client-log` or `client-log-end` event on `presence-node-logs.{id}` only when Reverb's `user_id` is `agent.{id}`. It cuts a line longer than 8 KiB, drops lines above 64 KiB per second for each stream, with a 256 KiB burst, and queues the rest in arrival order.

During a `websocket` move it joins the log channel on each Reverb server. A Node can stream while its agent is a member on either server, and its streams end with `agent_left` only when the agent has left both. The Gateway publishes each log event to both servers, as it does record events, so viewers and agents still on the old server keep receiving lines and prompts.

A log run takes the queued events in order, at most 256 KiB of lines at a time. It drops lines unless the stream is open for Node `{id}`. It redacts each line again, with the Gateway's patterns and the stored environment values of the Instance or Process, and publishes `log.lines` on the stream's channel through the Reverb HTTP API, in parts under 10,000 bytes. After each part it saves how far it got, so a repeated run skips what it already published.

A log run publishes `log.ended` when the agent ends a stream, when `agent.{id}` leaves the log channel, and when the subscriber's queue falls behind. While a stream may be open, a run every 5 seconds also ends streams whose lease ended and streams whose opening Node lost its access edge. A run removes ended streams from the store and prompts the agent with `log-streams.changed`.

Every 15 seconds, it also prompts again each Node with an active stream that has not relayed a line yet, so an agent that missed a prompt while Reverb was unreachable still starts the stream. Each renewal of such a stream prompts the agent again as well, because the sweeps may have stopped when the prompt was lost.

No line is lost silently. When a stream's queued lines pass 1 MiB, or all queued lines pass 16 MiB, or its lines wait through five failed runs in a row, the subscriber drops that stream's queued lines and ends it with `relay_behind`.

### Process usage

The subscriber also pushes the CPU and memory of every Process to browsers, so no browser polls the Process list for them. It counts the `viewer.*` members on the channels it joins. While at least one viewer is present, it queues a sample every 15 seconds. A [publish run](#publish-runs) reads CPU and memory for every Process from [Prometheus](/reference/metrics#process-runtime-status), with the two fleet-wide queries that the Process list uses, and broadcasts one [`process.usage`](/reference/events#process-usage) event on `orbit`. A Process without a sample has null values, as every Process has while Prometheus cannot answer. With no viewer, nothing is queried or broadcast.

### Publish runs

The subscriber never reads Prometheus or the log stream store, writes the database, or broadcasts in its socket loop. It starts `php artisan orbit:agent-view-publish` as a child process with the queued work, and does not wait for it. Task workspaces, Process usage, and log lines have separate lanes, with one run at a time in each; work that arrives meanwhile waits for the next run in its lane. A log run reads its events as JSON on standard input. A run that takes longer than 12 seconds is stopped.

When a workspace run fails, is stopped, or cannot start, its workspaces are queued again and retried 15 seconds later; the run reads the current view, so a retry is safe. After five failed runs in a row, a workspace is dropped with an error in the Gateway log, until the agent reports a new change for it.

When a log run fails, is stopped, or cannot start, its events go back in front of the queue and run again 2 seconds later. A refused `log.lines` publish fails the run, so the lines wait instead of being lost.

A failed usage sample is dropped, because the next one replaces it. After a subscriber restart, the first workspace list from each agent queues every workspace again. A hung Prometheus or Reverb HTTP API therefore costs a skipped sample or a delayed notice, and the view stays fresh. A failed run's error output goes to the Gateway log.

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

The unit runs the agent as `root` with `Restart=always` and `RestartSec=2`. It grants no capabilities (`CapabilityBoundingSet=` is empty) and sets `NoNewPrivileges=yes`, `ProtectSystem=strict`, `ProtectHome=tmpfs`, `BindReadOnlyPaths=-{Instance root}`, `PrivateTmp=yes`, `MemoryMax=128M`, and `RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6`. Root ownership of the Docker socket lets the agent read it without capabilities.

The Instance root is the Node's apps path from its settings, or `apps` in the managed user's home. The Gateway resolves it on every converge. The bind line appears only when the root lies under `/home`. When the Gateway cannot resolve the managed user, or the root holds characters outside letters, digits, `.`, `_`, `-`, and `/`, the unit sets `ProtectHome=yes` instead, and the Gateway reads that Node's checkouts over SSH.

The Instance root and each checkout must stay world-traversable (`0755`, as Orbit creates them); otherwise the agent leaves the checkout out, and the Gateway reads it over SSH. Orbit removes the world bits from an Instance `.env` when it configures the Laravel URL, after a registration moves a checkout, after a transfer, and for every Instance checkout in the Instance root on each agent converge. When the converge fails to close a checkout's `.env`, it logs a warning that names the checkout and continues.

What the agent can read:

| Path | Access |
| --- | --- |
| `/home` and `/root` | Empty, except the Instance root |
| The Instance root | Read-only. Without capabilities, root reads only files that other users may read: the tracked files and `.git` that Orbit checks out, but not an Instance `.env` |
| Everything else | Read-only, as `ProtectSystem=strict` sets |

From version 0.3.0 the agent refuses to start outside `orbit-agent.service`. A copy started by hand, for example from a shell on a Node, exits at once and never joins Reverb as that Node's agent.

The Gateway restarts the agent only when the binary, configuration, certificate, or unit changed. An unchanged converge leaves the running agent and its connection alone.

The Gateway converges the agent at these points:

| When | Result of a failure |
| --- | --- |
| `node:add`, for a new or an existing Node, after the Metrics exporters | Provisioning fails at step `agent` with `node.agent_install_failed`. A new Node becomes `failed`, and an existing active Node stays `active`. |
| A role converge on the Node | The role converge continues. The Gateway logs a warning, and Doctor reports the drift. |

To upgrade the fleet, publish a new release, update the pin in the Gateway, deploy the Gateway, and run `orbit node:add <node>` or a role converge on each Node. Doctor reports every Node that still runs another version. Version 0.1.1 requires `gateway_address` in its configuration. Version 0.2.0 reports task workspaces and needs the unit above. Version 0.3.0 tails logs for [live log streams](/reference/live-logs); the Gateway refuses streams for a Node with an older agent.

## Failures

The agent recovers from each failure below without an operator.

| Situation | What happens |
| --- | --- |
| The agent crashes | systemd restarts it after 2 seconds. The web app shows the Node offline until the agent rejoins. The Gateway drops the Node's view and reads over SSH until then. |
| The agent stops cleanly | The agent closes its connection, so the web app shows the Node offline at once, and the Gateway drops the Node's view. |
| The Node loses power or network | Heartbeats stop. The web app shows the Node offline after 15 seconds without a heartbeat, and the Gateway's view of the Node turns stale at the same time. |
| The agent cannot read a task checkout | It leaves the checkout out of its reports, and the Gateway reads that checkout over SSH. |
| The watch list request fails | The agent keeps its last list and asks again after 60 seconds. |
| The stream list request fails | The agent keeps reading its current streams and asks again. After 60 seconds without a list, it stops every stream. |
| A log source cannot be read | The agent ends that stream with `source_unavailable`. The viewer falls back to one-shot reads over SSH. |
| The agent stops while it streams | It leaves the log channel. The subscriber ends each stream of that Node with `agent_left`, and viewers fall back to one-shot reads over SSH. |
| Reverb is down or the `websocket` role is absent | The agent and the subscriber retry. The web app polls Prometheus, and Gateway reads use Prometheus and SSH. |
| The agent view subscriber stops | systemd restarts it after 2 seconds. Until it rejoins, the view turns stale after 15 seconds, and Gateway reads use Prometheus and SSH. The web app reloads the Process list every 60 seconds. |
| The Gateway is down | The agent cannot get a membership signed and retries. An agent that is already connected keeps publishing. |
| A snapshot is lost | The subscriber asks for a new snapshot within about 10 seconds. Agent 0.2.0 also sends one every 60 seconds. |
| A second agent process runs on the Node | Agent 0.2.0 refuses to start it. A second 0.1.1 process keeps resetting the subscriber's state, so the view stays `missing` until about 10 seconds after it exits. |
| Reverb stops answering without closing the connection | The agent reconnects within about 30 seconds. |
| systemd D-Bus is unavailable | The agent exits with an error, and systemd restarts it. |

The agent logs to the systemd journal. Logs contain no Reverb key, signature, or log line that it streams.

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
| `missing` | The subscriber is connected but has no complete snapshot from this Node's agent. The subscriber asks for one within about 10 seconds of the agent's next event. | Check `journalctl -u orbit-agent` on the Node, and `pgrep -a orbit-agent` for a second agent process. |
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

- The agent reports presence, Process runtime state, and task checkout Git state. The Gateway uses them for the reads in [Gateway view](#gateway-view).
- The agent tails logs only for live log streams.
- One-shot log reads, task reads that gate an action, the Horizon queue, and `ufw status` still run over SSH.
- The agent does not report CPU or memory. The Gateway pushes them from Prometheus as [Process usage](#process-usage).
- The agent supports Linux on `x86_64` and `aarch64` only.
