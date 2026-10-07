---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, how it connects and authenticates, how the Gateway installs it and keeps a view of its reports, and how Doctor checks it."
covers:
  - apps/agent/**
  - apps/gateway/app/Infrastructure/AgentView/**
  - apps/gateway/app/Domain/AgentView/**
  - apps/gateway/app/Infrastructure/Nodes/{NodeAgentSshExecutor,NodeAgentFootprint}.php
  - apps/gateway/app/Http/Controllers/Api/AgentRealtimeController.php
  - apps/gateway/app/Http/Middleware/RequireNodeAgentSecret.php
  - apps/gateway/database/migrations/2026_09_30_090000_drop_agent_secret_exempt_from_nodes.php
  - .github/workflows/orbit-agent-release.yml
---

# Node agent

`orbit-agent` is a small Rust program on every managed Linux Node. It reports that it runs, the state of the Node's Orbit Processes, and the Git state of the Node's task checkouts. While someone watches a log live, it also reads that log and sends the lines to the Gateway. The web app shows these reports live, and the Gateway keeps a [view](#gateway-view) of them to skip repeated SSH reads.

The agent service observes and listens on no port. The separate `orbit-agent sandbox` command controls task-owned Incus resources through a closed protocol. The Gateway invokes that command over SSH; the service does not accept remote execution requests.

## Where it runs

The Gateway installs the agent only on active managed Linux Nodes with a WireGuard address and a pinned SSH host key. macOS tool-only Nodes need no agent and hold no agent secret. An install or repair aimed at macOS returns `node.agent_unsupported` (HTTP 422) before SSH. Only a Node inside the Linux agent boundary runs the agent and holds an agent secret. [Exporter selection](/reference/metrics#exporter-selection) uses the same boundary. The agent needs no role. A Node that the Gateway does not manage over SSH runs no agent.

## What it observes

The agent watches two sources and reports each Orbit Process unit or container by its full name, such as `orbit-process-42-web`.

| Source | What it watches | Reported `runtime_status` |
| --- | --- | --- |
| systemd over D-Bus | Units named `orbit-process-*.service` | The unit's `ActiveState`, such as `active`, `inactive`, or `failed`. A missing unit reads as `inactive`. |
| The Docker socket `/run/docker.sock` | Containers named `orbit-process-*` | The container state, such as `running`, `exited`, or `restarting`. A missing container reads as `exited`. |

The values use the same words as the `runtime_status` field of the Process API. The agent does not know which Process owns a name. The Gateway matches the name to a Process, so a candidate container such as `orbit-process-42-web-candidate` never matches. The agent does not read Schedules, role services, CPU, or memory.

Without Docker, the agent watches systemd only and reports Docker as `absent`. It checks for the socket every 10 seconds and sends a new snapshot when it appears. When the Docker event stream ends, the agent reconnects and sends a new snapshot. It merges changes to one unit that arrive within 250 milliseconds and sends only the latest state. It sends a heartbeat every 5 seconds and a complete snapshot at least every 60 seconds.

## Task workspaces

The agent reports the Git state of each [task](/reference/tasks) checkout on its Node, so the Gateway can skip `git` over SSH. It reads only Git metadata, never file contents or file names, and it starts no program.

### Watch list

The agent asks `GET /api/v1/agent/workspaces` which checkouts to watch, at start and every 60 seconds:

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

The list holds the Instances on the caller's Node that hold the workspace of an unfinished task: `backlog` or `todo` with an Instance, `reserved`, `running`, `reviewing`, or `settling`. `base` is the Project's default branch. `start` is the Instance's starting commit, or null. The list is empty while Tasks is disabled, and it holds at most 64 entries.

The agent accepts only a normalized absolute path of at most 4,096 bytes that opens as the root of a Git work tree. A linked worktree works. It never opens a path that the list does not name, and never opens a submodule.

### Reading Git

The agent reads Git with libgit2 in its own process, which runs no hooks, filters, or `fsmonitor` programs. The checkouts belong to `orbit` and the agent runs as `root`, so the agent turns off libgit2's owner check.

Every 2 seconds, the agent checks the size, time, and inode of `HEAD`, `index`, `packed-refs`, the current branch ref, the local base ref, and `origin/base`. When one changed, it reads the checkout again. It also reads every checkout every 30 seconds, because an edit to a working file changes none of those files.

| Field | Meaning |
| --- | --- |
| `instance_id`, `base`, `start` | The values from the watch list. |
| `branch` | The current branch, or null when `HEAD` is detached. |
| `head` | The full `HEAD` commit, or null in an empty repository. |
| `dirty` | `true` when the index or working tree differs from `HEAD`, untracked files included. Null when the agent cannot read the working tree. |
| `commits` | Commits reachable from `HEAD` but not from `start`, at most 1,000. Null without `start`. |
| `diff` | `{ files, added, removed, truncated }` as `git diff --numstat origin/base...HEAD` counts it. Null when `origin/base` does not resolve. |

| Limit | Value |
| --- | --- |
| Changed files in `diff` | 5,000. A larger diff has an exact `files` count, no line counts, and `truncated: true`. |
| Lines counted per file | Files up to 1 MiB. A larger or binary file counts as changed with no lines. |
| Submodules | A changed submodule counts as one file with no lines. |
| Branch name | 255 characters. A checkout on a longer branch is left out. |
| libgit2 memory | 8 MiB object cache and 32 MiB mapped pack window. |

A checkout that the agent cannot open or read is left out of its reports, and the Gateway reads it over SSH. The agent publishes the state as `client-workspaces` and `client-workspace` events. [Realtime events](/reference/events#events) defines them.

## Log tails

The agent reads an Instance log or a Process log only while a viewer watches it through a [live log stream](/reference/live-logs). It sends the lines only to the Gateway.

### Stream list

The agent asks `GET /api/v1/agent/log-streams` which logs to read. The list holds at most 16 active streams whose source is on the caller's Node. Each entry has an `id`, a number of first `lines`, and a `source`. The agent fetches the list when it joins its log channel, when the Gateway publishes `log-streams.changed` there, and every 15 seconds while it reads a stream. The event carries no data, so the agent reads only what the HTTPS response names. It stops every stream that the list does not name.

When it cannot fetch the list for 60 seconds, the agent stops every stream and ends each one with `client-log-end` and reason `list_unavailable`. It never reads that stream again.

### Sources

The agent checks each source itself and runs no program for any of them.

| Type | Fields | What it reads | What it refuses |
| --- | --- | --- | --- |
| `laravel` | `path`: the Instance's [application directory](/reference/projects#application-directory) | `storage/logs/laravel.log`, or the newest `laravel-*.log`. It follows a daily file to the next day's file. | A path that is not normalized and absolute. A link at `storage/logs` or at the file. A file that is not regular, or that `root` owns. |
| `journal` | `unit` | The journal files in `/var/log/journal` and `/run/log/journal`, for the unit and systemd's messages about it. | A unit that is not `orbit-process-{id}-{name}.service`. |
| `docker` | `container`, `process_id` | The container's output through the Docker Engine API. | A name that is not `orbit-process-{id}-{name}`, and a container without the labels `orbit.managed=true` and `orbit.process.id` equal to `process_id`. |

Refusing a file that `root` owns means the agent reads only files that other users may read, inside the Instance root that its unit binds. So a link in a checkout cannot point the read at another user's file. The journal reader supports the compact and regular formats and zstd and lz4 compression. A field compressed with xz shows as `[orbit] entry not readable`. When the agent cannot open or keep reading a source, it ends the stream with `source_unavailable`.

The agent redacts each line with the Gateway's secret patterns before it sends it. [Live logs](/reference/live-logs#redaction) lists the patterns. The agent and the Gateway tests share one table of cases in `apps/agent/tests/redaction_cases.json`.

| Limit | Value |
| --- | --- |
| Streams | 16 |
| First lines | The stream's `lines`, at most 256 KiB |
| Line length | 8 KiB. A longer line is cut and ends with `[truncated]`. |
| Events | One `client-log` event per stream every 250 milliseconds at most, each under 10,000 bytes |
| Rate | 32 KiB per second per stream, with a 256 KiB burst, and 256 KiB per second for the agent |
| Read ahead | 1 MiB per read. A file more than 4 MiB behind skips to its newest 64 KiB. |

The agent drops a line that exceeds a rate and reports the count in its next event. It sends log events on a second presence channel, `presence-node-logs.{id}`, as member `agent.{id}`. Only the agent and the Gateway's subscriber join it. [Realtime events](/reference/events#log-channels) defines the events.

## How it connects

The agent connects to the Gateway at `https://gateway.orbit` and to Reverb at `wss://reverb.orbit`. It never uses system DNS. It connects to the Gateway's WireGuard address from its configuration, `gateway_address`, and to the Reverb address from the realtime response. It verifies each certificate for the hostname against the Orbit root certificate in `ca.pem`.

1. The agent calls `GET /api/v1/agent/realtime` and reads the Reverb connection from the response.

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

   Without an active `websocket` role, `url`, `address`, and `key` are null, and the agent asks again every 60 seconds.

2. It opens a connection to the Reverb address on port 443 and reads its `socket_id`.
3. It asks `POST /api/v1/agent/broadcasting/auth` to sign its membership, with `socket_id`, `channel_name`, and `version`. The Gateway signs `agent.{id}` only on the caller's own two channels.
4. It joins `presence-node.{id}`, sends its snapshot, and then publishes its events.
5. It joins `presence-node-logs.{id}` the same way and fetches its [stream list](#stream-list).

When the `gateway` role moves, the Gateway's WireGuard address changes. Every agent then loses the Gateway until its configuration is rewritten. Run `orbit node:add <node>` or a role converge on each Node. The Reverb address needs no converge, because each realtime response carries it.

Every agent endpoint call requires an active WireGuard peer and a valid [agent secret](#agent-secret); there is no exemption for older agents or Nodes without a stored secret. Agent endpoints need no access grant and record no Activity.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller's address does not belong to an active Node. |
| `agent.secret_required` | 401 | The request carries no bearer secret. |
| `agent.secret_invalid` | 403 | The secret's hash differs from the one stored for the Node, or none is stored. |
| `agent.node_ineligible` | 403 | The Node is outside the managed-Node boundary. |
| `agent.channel_forbidden` | 403 | `channel_name` is not one of the caller's own channels. |
| `validation.failed` | 422 | `socket_id`, `channel_name`, or `version` is missing or malformed. |
| `realtime.not_configured` | 404 | No `websocket` role is active. |

The agent bounds each step, so a peer that stops answering never holds it.

| Step | Limit |
| --- | --- |
| A Gateway request | 10 seconds to connect, 30 seconds in total |
| One join, from the connection to `pusher_internal:subscription_succeeded` | 30 seconds |
| A quiet connection | After 15 seconds without a message, the agent sends `pusher:ping`. Without an answer in 10 more seconds, it reconnects. |

After a drop, the agent reconnects with a backoff from 2 to 30 seconds, with jitter. A session that stayed joined for 60 seconds starts the backoff again from 2 seconds. Every connection repeats all steps, because Reverb gives each connection a new `socket_id`.

One Node runs one agent. The agent holds an exclusive lock on `/etc/orbit/agent` while it runs, and a second process exits with `another orbit-agent already runs on this Node`. The agent also refuses to start outside `orbit-agent.service`, so a copy started from a shell never joins as the Node's agent.

The separate `orbit-agent sandbox` command accepts a bounded JSON request on
standard input for task sandbox provisioning, observation, capacity, parking,
resume, and destruction. The Gateway invokes it over pinned SSH on an Incus
host. It runs the controller embedded in the binary and accepts no host shell
command. It does not load the live agent secret or join the realtime channel.
The controller checks the sandbox UUID, project ownership, VM budget, image
fingerprints, and external network policy before changing resources.

## Agent secret

Every Unix user on a Node reaches the Gateway from the Node's WireGuard address, and production Nodes run customer code as unprivileged users. The agent secret keeps those users from acting as the agent.

| Item | Value |
| --- | --- |
| File | `/etc/orbit/agent/secret`, `root:root` mode `0600`, in `/etc/orbit/agent`, `root:root` mode `0700` |
| Contents | 64 lowercase hexadecimal characters: 32 random bytes from the Gateway |
| On the Gateway | Only the SHA-256 hash, in the Node record. The API never returns it. |
| Sent by the agent | `Authorization: Bearer {secret}` on every Gateway request |

The agent reads the file when it starts. It exits with an error when the file is missing or malformed, and systemd starts it again after 2 seconds. The Gateway compares the hashes in constant time.

Each agent converge reads the file's hash with `sudo sha256sum` and keeps the secret while it matches the stored hash. Otherwise it writes a new secret through standard input, never in a command's arguments, and restarts the agent. There is no scheduled rotation. To rotate a secret, delete the file on the Node and converge.

The converge writes the secret file first, then installs the other files, restarts the agent, and stores the new hash last. The running agent reads its secret only when it starts. So a converge that fails at any step leaves the running agent with a secret that the Gateway still accepts. Doctor then reports `mismatch`, and the next converge repairs it.

One converge runs per Node at a time, under a lock in a file cache store under `ORBIT_HOME`. A second converge waits up to 2 minutes and then fails with `agent.converge_busy`. The lock expires after 4 minutes. A running converge renews it before each step and stops with `agent.converge_lock_lost` when the lock has expired. The binary download is limited to 20 seconds to connect and 120 seconds in total, so one step fits in the lock's term.

## Gateway view

The Gateway keeps the latest state that each agent reports, so it can skip repeated SSH reads. A long-running Gateway process, the agent view subscriber, receives the reports. The view is only an input to reads.

### Subscriber

The subscriber runs next to PHP-FPM on the Gateway machine.

| Item | Value |
| --- | --- |
| Command | `/usr/bin/php8.5 artisan orbit:agent-view`, as the `orbit` user in the Gateway checkout |
| Unit | `/etc/systemd/system/orbit-agent-view.service`, with `Restart=always` and `RestartSec=2` |
| Installed by | `orbit:bootstrap` and `orbit:gateway-web` |
| Connection | One WebSocket to Reverb for all Nodes, verifying `reverb.orbit` against the Orbit root CA |
| Channels | `presence-node.{id}` and `presence-node-logs.{id}` for every managed Linux Node |
| Member | `gateway.{socket id}`, with `user_info` `{ "kind": "gateway" }`, signed with the Reverb app secret |

A new member makes every agent on the channel send a complete snapshot. So when a channel owes a snapshot for 5 seconds, the subscriber leaves and joins it again. A channel owes one while agent events arrive without a complete snapshot, or after the agent's `sequence` goes back without a membership change. The subscriber asks each channel at most once every 5 seconds and keeps what it knows until the snapshot arrives.

Every 5 seconds, the subscriber checks the Reverb connection, and every 30 seconds the Node list. It joins new Nodes, leaves removed ones, and reconnects when the Reverb key changes. During a `websocket` move, it keeps one link to each Reverb server that holds clients and uses the link with the newest agent event. Without an active `websocket` role, it checks again every 60 seconds.

When the connection drops, the subscriber clears the view and reconnects with a backoff from 1 to 30 seconds, with jitter. It sends its own ping after 30 quiet seconds and reconnects when no answer comes in 30 more seconds. It ignores a message larger than 64 KB and keeps at most 4,096 units per Node. Every 60 seconds, it compares the Gateway checkout's commit with the one it started from. When they differ, it exits, and systemd starts it with the new code.

### Stored state

The view lives in its own file cache store in `ORBIT_HOME/cache/agent-view`, whatever `CACHE_STORE` says, so heartbeats never write the SQLite database. The subscriber and every PHP-FPM worker run as `orbit` and share those files.

| Entry | Contents | Kept for |
| --- | --- | --- |
| One per Node | Units, `docker` state, task workspaces, agent version, log channel membership, last `sequence`, and the Gateway time of the last event | 60 seconds after its last write |
| One per Node with open log streams | Each stream's ID, source, `lines`, opening Node, and lease end | Until its last lease ends |
| One for the subscriber | Whether realtime is configured, whether the socket is connected, the number of joined channels, and the time of the last write | 30 seconds after its last write |

The subscriber accepts an event only when Reverb's `user_id` is `agent.{id}`. It applies a snapshot once every part has arrived, and starts over when the agent's `sequence` restarts. It removes a Node's entry when `agent.{id}` leaves the channel. It keeps a unit only when the name has the form `orbit-process-{id}-{name}`, the runtime is `systemd` or `docker`, and the status is a short lowercase word.

### Freshness

A reader asks for one Node's state and gets one of three answers. Freshness uses only the Gateway's clock.

| Answer | Meaning |
| --- | --- |
| Fresh | A complete snapshot exists, and an agent event arrived in the last 15 seconds. |
| Stale | An entry exists, but no agent event arrived in the last 15 seconds. |
| Missing | No entry: the subscriber is down, Reverb is unreachable, the agent is not a member, or no complete snapshot arrived. |

In a fresh view, a systemd Process that the agent does not list is `inactive`, and a Docker Process it does not list is `exited`. When the agent reports Docker as `absent`, the view does not answer for Docker Processes.

### Reads that use the view

These reads ask the view first and fall back when it is not fresh.

| Read | With a fresh view | Otherwise |
| --- | --- | --- |
| Process list `runtime_status` | A status the Gateway observed after its own start, stop, or restart wins for 30 seconds. Otherwise the view answers. | [Prometheus](/reference/metrics#process-runtime-status), then SSH |
| Readiness while a hibernated Instance [wakes](/reference/app-dev-runtime-hibernation#wake) | The view answers every 0.5 seconds. A `failed` answer, or the timeout, is checked once over SSH. | SSH every 0.5 seconds |
| [`process:logs`](/cli/process#orbit-processlogs) | When the view lists the exact unit or container, the Gateway runs only the log read. | Ownership check over SSH, then the log read |
| The hibernator's [idle halt](/reference/app-dev-runtime-hibernation#idle-window-and-sweep) | When the view shows the Process stopped, the hibernator skips the stop. | Ownership check and stop over SSH |
| The [scheduler tick](/reference/tasks#session-routing)'s check for new commits | `commits` is greater than 0 | `git` over SSH |
| The line diff of an active [task](/reference/tasks#tokens-and-line-diff) | `diff.added` and `diff.removed`, unless truncated | `git diff --shortstat` over SSH |

A task read uses a workspace only when its `base` and `start` equal the values the reader would use. Reads that decide what the Gateway commits or tells an agent stay on SSH: the branch check before an approval commit, a subtask's start commit, turn receipts, and the Project check. The status that a start, stop, or restart returns also stays on SSH, because it must show the change the Gateway just made.

### Publish runs

The subscriber never reads Prometheus, writes the database, or broadcasts in its socket loop. It starts `php artisan orbit:agent-view-publish` as a child process with the queued work and does not wait for it. Task workspaces, Process usage, and log lines each have their own lane, with one run at a time. A run that takes longer than 12 seconds is stopped.

When a workspace of an unfinished task reports a new `head` or new diff counts, a run stores the task's line counts when the diff is complete and broadcasts [`task_group.updated`](/reference/events#tasks). A failed run retries after 15 seconds. After five failures in a row, the workspace is dropped until the agent reports a new change.

### Process usage

The subscriber pushes the CPU and memory of every Process to browsers, so no browser polls the Process list for them. While at least one `viewer.*` member is present on the channels it joins, it queues a sample every 15 seconds. A publish run reads CPU and memory for every Process from Prometheus and broadcasts one [`process.usage`](/reference/events#process-usage) event on `orbit`. With no viewer, nothing is queried. A failed sample is dropped, because the next one replaces it.

### Log relay

The subscriber accepts `client-log` and `client-log-end` only from `agent.{id}` on that Node's log channel, and queues them in order. A publish run drops lines unless the stream is open for that Node. It redacts each line again, also with the stored environment values of the Instance or Process, and publishes `log.lines` in parts under 10,000 bytes. A failed run puts its events back in front of the queue and retries after 2 seconds. [Live logs](/reference/live-logs#limits) lists the Gateway's queue limits and the end reasons.

## Install and upgrade

The Gateway pins agent 0.3.0. It stores the SHA-256 checksum of each architecture's binary and picks the asset for the Node's recorded architecture, `x86_64` or `aarch64`.

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-agent`, owned by `root`, mode `0755` |
| Directory | `/etc/orbit/agent`, `root:root` mode `0700` |
| Configuration | `/etc/orbit/agent/config.toml`, with `gateway_url = "https://gateway.orbit"` and `gateway_address` |
| Orbit root certificate | `/etc/orbit/agent/ca.pem` |
| Agent secret | `/etc/orbit/agent/secret` |
| Unit | `/etc/systemd/system/orbit-agent.service`, marked `# Managed by Orbit: agent` |
| Download | `https://github.com/nckrtl/orbit/releases/download/agent-v{version}/orbit-agent-{version}-linux-{arch}` |

The Node downloads the asset to a candidate file, checks the checksum, and moves it into place. A mismatch deletes the candidate and fails with `agent.checksum_mismatch`. When the installed binary already matches, the download is skipped. The Gateway restarts the agent only when the binary, configuration, certificate, secret, or unit changed.

The unit runs the agent as `root` with `Restart=always` and `RestartSec=2`. It grants no capabilities and sets `NoNewPrivileges=yes`, `ProtectSystem=strict`, `ProtectHome=tmpfs`, `BindReadOnlyPaths=-{Instance root}`, `PrivateTmp=yes`, `MemoryMax=128M`, and `RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6`.

The Instance root is the Node's [apps root](/reference/node-settings#derive-the-effective-root). The bind line appears only when the root lies under `/home` or `/root`. When the Gateway cannot resolve the managed user, or the root has characters other than letters, digits, `.`, `_`, `-`, and `/`, the unit sets `ProtectHome=yes`, and the Gateway reads that Node's checkouts over SSH.

| Path | What the agent can read |
| --- | --- |
| `/home` and `/root` | Nothing, except the Instance root |
| The Instance root | Read-only. Without capabilities, root reads only files that other users may read: the tracked files and `.git`, not an Instance `.env`. |
| Everything else | Read-only |

The Instance root and each checkout must stay world-traversable, mode `0755` as Orbit creates them. Otherwise the agent leaves the checkout out. Orbit removes the world bits from an Instance `.env` when it configures the Laravel URL, after registration or transfer, and on each agent converge. Agent converge independently closes environment permissions for the Instances under the Node's apps root; it does not rely on registration or transfer having run.

For Laravel, it uses the shared [application directory](/reference/projects#application-directory): root `apps/site/public` means `<checkout>/apps/site/.env`, not only `<checkout>/.env`. Root `public` and non-Laravel Instances keep the checkout-root path. It closes only regular environment files, without following symlinks or discovering apps by scanning the repository. A converge that cannot close a `.env` logs a warning and continues.

The Gateway converges the agent at these points.

| When | Result of a failure |
| --- | --- |
| `node:add`, after the Metrics exporters | Provisioning fails at step `agent` with `node.agent_install_failed`. A new Node becomes `failed`, and an active Node stays `active`. |
| A role converge on the Node | The role converge continues. The Gateway logs a warning, and Doctor reports the drift. |

To upgrade the fleet, [release](#releases) a new version, update the pin in the Gateway, deploy the Gateway, and converge each Node. Doctor reports each Node that runs another version.

## Failures

The agent recovers from each failure below without an operator.

| Situation | What happens |
| --- | --- |
| The agent crashes | systemd restarts it after 2 seconds. The web app shows the Node offline until the agent joins again, and the Gateway reads over SSH. |
| The agent stops cleanly | It closes its connection. The web app shows the Node offline at once, and the Gateway drops the Node's view. |
| The Node loses power or network | Heartbeats stop. After 15 seconds, the web app shows the Node offline and the view turns stale. |
| The watch list request fails | The agent keeps its last list and asks again after 60 seconds. |
| A log source cannot be read | The agent ends that stream with `source_unavailable`. |
| The agent leaves while it streams | The subscriber ends each stream of that Node with `agent_left`. |
| Reverb is down, or no `websocket` role is active | The agent and the subscriber retry. The web app polls Prometheus, and Gateway reads use Prometheus and SSH. |
| The subscriber stops | systemd restarts it after 2 seconds. Meanwhile the view turns stale, and Gateway reads use Prometheus and SSH. |
| A snapshot is lost | The subscriber asks for a new one within about 10 seconds. |
| Reverb stops answering without closing the connection | The agent reconnects within about 30 seconds. |
| systemd D-Bus is unavailable | The agent exits, and systemd restarts it. |
| The secret differs from the Gateway's hash, such as after a Gateway database restore | The Gateway refuses the agent with `agent.secret_invalid`. Doctor reports `node.agent_secret_mismatch`, and a converge writes a new secret. |

The agent logs to the systemd journal. Its logs contain no Reverb key, signature, secret, or streamed log line.

## Removal

Online [`node:remove`](/reference/node-provisioning#remove-a-node) stops and disables `orbit-agent.service` and deletes the unit, the binary, and `/etc/orbit/agent`, secret included. A failure only logs a warning. `--offline --force` for an unreachable Node leaves the agent installed and lists it under `retained_on_node`. The Gateway then refuses its requests with `peer.identity_unknown`, and the agent keeps retrying.

## Doctor

Doctor checks the agent in the `node` family on every managed Linux Node.

| Issue code | Meaning |
| --- | --- |
| `node.agent_missing` | The binary or the unit is absent. |
| `node.agent_binary_mismatch` | The binary exists but does not match the pinned checksum. |
| `node.agent_inactive` | The unit exists but is not active. |
| `node.agent_secret_mismatch` | The secret file is `missing`, or its hash does not match the stored one: `mismatch`. Doctor reads only the hash, and the report shows neither the secret nor a hash. |
| `node.agent_view_stale` | The agent unit is active and a `websocket` role is active, but the Gateway has no fresh view of the Node. |

Run `orbit node:add <node>` to repair the first four. `node:add` refuses a Node that owns Instances. Repair such a Node by converging one of its roles with `orbit node:role:add <node> <role> --converge`.

`node.agent_view_stale` reports what it observed.

| Observed | Meaning | Repair |
| --- | --- | --- |
| `subscriber_down` | The subscriber stopped, or wrote no health in the last 30 seconds. | Check `systemctl status orbit-agent-view` on the Gateway host, or run `php artisan orbit:gateway-web`. |
| `disconnected` | The subscriber runs but has no Reverb connection. | Check the `websocket` role with `orbit doctor --family=role`. |
| `missing` | The subscriber has no complete snapshot from this agent. It asks for one within about 10 seconds of the agent's next event. | Check `journalctl -u orbit-agent` on the Node. |
| `stale` | No agent event arrived in the last 15 seconds. | Check the Node's network and `journalctl -u orbit-agent`. |

## Releases

A tag named `agent-v{version}` releases the agent. The version must equal the one in `apps/agent/Cargo.toml`. The release job runs on `ubuntu-26.04`, builds static musl binaries, and publishes them as a GitHub release of that tag. Create the tag with the GitHub CLI on a commit that is already on GitHub:

```bash
gh api repos/nckrtl/orbit/git/refs -f ref=refs/tags/agent-v{version} -f sha={commit}
```

| Asset | Contents |
| --- | --- |
| `orbit-agent-{version}-linux-x86_64` | Static binary for `x86_64` Nodes |
| `orbit-agent-{version}-linux-aarch64` | Static binary for `aarch64` Nodes |
| `SHA256SUMS` | The checksums of both binaries |

The job refuses to replace the assets of an existing release. Pushes to `main`, and pull requests that change `apps/agent` or the CI workflow, build and test the agent without publishing.

## Limits

The agent has these limits.

- The agent reports presence, Process state, and task checkout Git state, and tails logs only for live log streams.
- One-shot log reads, task reads that gate an action, the Horizon queue, and `ufw status` run over SSH.
- The agent does not report CPU or memory. The Gateway pushes them from Prometheus as [Process usage](#process-usage).
- The agent supports Linux on `x86_64` and `aarch64` only.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The agent only observes

An agent that also runs commands would be a second way to change a Node, with its own authorization, listener, and recovery, while SSH would still be needed when the agent breaks. So the agent listens on no port, runs nothing on anyone's behalf, and SSH stays the only way to change a Node. A compromised Gateway gains no new way into a Node, and a compromised Node can only report false state about itself.

### Rust

The agent runs on every Node, so memory matters. A Rust agent uses about 5 MB. PHP or Bun would use about 20 times more. Rust also has mature libraries for systemd D-Bus and the Docker socket.

### Pinned GitHub release assets

A workflow artifact needs a GitHub login and expires. A release asset of the public repository has a permanent URL that any Node can fetch. Building on each Node would put a Rust toolchain on production hosts. Copying the binary from the Gateway would make the Gateway store and serve binaries. Resolving the latest release at install time would let an unreviewed release reach Nodes. So the Gateway pins the version and checksums in code.

### The view in a file cache

HTTP reports would cost a PHP-FPM worker per heartbeat on a small Gateway. A PHP-FPM request cannot wait for a snapshot, which comes only when a member joins. SQLite would take a write every few seconds that competes with API writes. So one long-running subscriber keeps a disposable view in files, and every reader falls back to Prometheus and SSH when the view is missing.

### Rejoin to ask for a snapshot

Reverb gives neither client events nor presence events a socket ID, so the subscriber cannot tell two connections of one member apart. A client event that asks for a snapshot is rejected, because the subscriber never sends client events. A new membership already makes every agent send a snapshot, so the subscriber leaves and joins again.

### A per-Node secret

The WireGuard address alone does not tell the agent apart from other users on the Node. A firewall owner match depends on per-Node rules that fail silently when missing. Mutual TLS needs a certificate issuer and renewal. A bearer secret in a file that only root can read fails closed, at a fraction of that cost. The Gateway stores only the hash, because a leaked database then reveals no secret, and a slow password hash adds nothing for 256 random bits.

### The hash is stored last

Storing a new hash before the agent restarts would refuse the running agent if the restart fails, while the file would match the record. Doctor would then report nothing. Storing it last keeps the running agent accepted and leaves a mismatch that Doctor reports.
