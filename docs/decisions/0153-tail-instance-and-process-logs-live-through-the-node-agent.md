---
title: "ADR 0153: Tail Instance and Process logs live through the Node agent"
sidebarTitle: "0153 Tail logs live through the Node agent"
description: "Proposed. A viewer opens a log stream through the Gateway with today's log access rules. The Node agent reads only the listed source, redacts each line, and sends it to the Gateway on a channel only the Gateway joins. The Gateway redacts again and relays the lines to a private channel for that viewer. One-shot reads stay on SSH."
---

# ADR 0153: Tail Instance and Process logs live through the Node agent

A viewer that watches an Instance log or a Process log opens a log stream through the Gateway, under the same access rule as the one-shot read. The Gateway lists the stream for the Node agent. The agent reads only that source, redacts each line, and sends the lines to the Gateway on a channel that only the agent and the Gateway join. The Gateway redacts the lines again and relays them to a private channel that belongs to that one viewer. The agent reads a log only while a stream is open. One-shot reads stay on SSH, and a viewer falls back to them when the live path is not available.

## Status

Proposed.

This amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes): the agent reads log content, and it starts and stops those reads when the Gateway lists them. It amends [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels) with a second agent channel for each Node. It extends [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state): the agent view subscriber receives log lines. It extends [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime): the subscriber's publisher gains a log lane, so relaying never runs in the socket loop. It keeps ADR 0151's rule that the agent never acts on the content of a channel message.

## Context

The web app polls every open log pane every 10 seconds. In the last seven days the Gateway answered 21,800 `instance:logs` calls. Each call runs `tail` over SSH on the Node. `process:logs` also reads over SSH, with `journalctl` for a systemd Process and `docker container logs` for a Docker Process. [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) listed Instance logs as a read that needs new agent data, and noted that logs carry content that every member of a Node channel would see.

The pieces for a live path exist. Every managed Node runs `orbit-agent`, which keeps one Reverb connection and publishes on `presence-node.{id}`. The Gateway's agent view subscriber joins every Node channel and can sign its own memberships.

Constraints shape the design:

- Logs are sensitive. They can hold secrets, tokens, customer data, and stack traces. They must never reach every member of a Node channel. Browsers with Gateway access join `presence-node.{id}`.
- Today's access rule for a log read is an access edge to the Node that serves the Instance or Process. The browser realtime auth endpoint needs Gateway access, which is a different rule.
- The agent is visibility-only. It runs no program, opens no port, and changes nothing. It runs as `root` without capabilities, so a file that `root` owns is readable to it.
- The Gateway redacts stored environment values from logs. The Node has those values on disk, but the Gateway must not send them over the wire.
- Reverb refuses a message over 10,000 bytes. A client event on a private channel carries no sender identity.
- The Gateway VM has 2 vCPUs, and the subscriber is one PHP process with one loop. ADR 0151 keeps every database read and broadcast out of that loop, so a slow Reverb or database never stalls the agent view.
- Ubuntu 26.04 writes the journal with zstd compression, keyed hashes, and the compact format. The agent is a static musl binary, so it cannot load `libsystemd`.

## Decision

The Gateway owns authorization, the stream list, the relay, and the second redaction. The agent owns reading, the first redaction, and the rate limits on its Node. Clients own renewal and the fallback.

### Open a stream

1. The viewer connects to Reverb and reads its `socket_id`.
2. The viewer calls `POST /api/v1/instances/{instance}/log-streams` or `POST /api/v1/processes/{process}/log-streams` with `socket_id` and `lines` (1 to 1,000, default 100). The route uses the access rule of `instance:logs` or `process:logs`: an access edge to the Node that serves the record.
3. The Gateway creates a stream with a random 128-bit ID. It records the source, the serving Node, the viewer's Node, and a lease of 60 seconds. It returns the channel `private-log-stream.{stream}` and a Pusher auth signature for that channel and that `socket_id`.
4. The viewer subscribes with that signature. The browser auth endpoint refuses every `private-log-stream.*` channel, so no one else can join. A signature is valid only for the socket it names.
5. When the subscription succeeds, the viewer renews the stream once. A stream starts inactive, and this first renewal activates it. The agent reads only active streams, so no line reaches the channel before the viewer listens.
6. The viewer renews the lease every 20 seconds with `PUT` and closes the stream with `DELETE` on the same path. Renewal checks the access edge again and requires the viewer's Node to be the Node that opened the stream. A viewer that disappears stops the stream when its lease ends.
7. Every 5 seconds while a stream may be open, a relay run ends each stream whose lease ended, with `expired`, and each stream whose viewer lost its access edge, with `revoked`.

The Gateway refuses a stream with `logs.live_unavailable` (409) and a reason when it cannot serve one: the record is a production Instance (`ssh_only`), realtime is not configured, the subscriber is down, the Node's view is not fresh, the Node's agent is older than 0.3.0 (`agent_outdated`), or a newer agent has not joined the log channel yet (`agent_not_joined`). The subscriber reads the agent's version from its membership of `presence-node.{id}`. It allows 16 open streams for each serving Node and refuses more with `logs.stream_limit` (429).

### Tell the agent

- The agent learns what to read only from `GET /api/v1/agent/log-streams`. It sends that request over HTTPS to `gateway.orbit` and verifies the Orbit CA, as for every agent request. The response lists at most 16 active streams, each with its ID, `lines`, and one source.
- When a stream becomes active or closes, and at each renewal until the stream relays its first line, the Gateway publishes a server event, `log-streams.changed`, with no data on the Node's log channel. The event only prompts the agent to fetch the list. A forged or lost event cannot start a read, because the agent reads only what the list names.
- The agent fetches the list when it joins, after every prompt, and every 15 seconds while it reads at least one stream. It stops every stream that the list does not name. When it cannot fetch the list for 60 seconds, it stops every stream.

### Read only listed sources

A viewer names only an Instance or a Process. The Gateway resolves the source from its own records. The agent accepts three source types, which are the sources today's SSH reads use, and checks each one itself.

| Source | From the Gateway | The agent's checks |
| --- | --- | --- |
| `laravel` | The Instance checkout path | A normalized absolute path. The agent reads only `laravel.log`, or the newest `laravel-*.log` when it is absent, from `storage/logs`. |
| `journal` | The unit name | `orbit-process-{id}-{name}.service`, with the name rules the Gateway uses. The agent reads the journal files in `/var/log/journal` and `/run/log/journal` itself and returns only entries of that unit. |
| `docker` | The container name and the Process ID | `orbit-process-{id}-{name}` with the same rules. The container must carry the labels `orbit.managed=true` and `orbit.process.id={id}`. The agent reads it through the Docker Engine API. |

- For a `laravel` source, the path must be absolute, at most 4,096 bytes, and free of `.`, `..`, empty parts, and control characters. The agent opens `storage/logs` and the file without following a symbolic link. The file must be a regular file that `root` does not own.
- The agent runs no program for any source. It reads the journal files with its own reader, which supports the compact and regular formats, keyed and unkeyed hashes, and zstd and lz4 compression.
- The agent refuses a file that `root` owns. Without capabilities, it then reads only files that other users may read, and only inside the Instance root that [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime)'s unit binds. A symbolic link or a hard link to another user's file therefore cannot point the read elsewhere. Ubuntu's `fs.protected_hardlinks` already stops a hard link to a file that the app user does not own.
- When a source cannot be read, the agent ends the stream with a reason, and the viewer falls back to SSH reads.

### Send lines to the Gateway only

- The agent joins a second presence channel, `presence-node-logs.{id}`, as member `agent.{id}`. The agent auth endpoint signs it only for the Node's own address. The subscriber joins it as `gateway.{socket id}`. The browser auth endpoint refuses it.
- The agent sends `client-log` events there: `{ stream, sequence, lines, dropped, skipped }`, each under 10,000 bytes. It sends `client-log-end` with a reason when a source ends. Reverb stamps both with `agent.{id}`.
- The subscriber accepts an event only from `agent.{id}` and queues it. A relay run then drops it unless the stream is open for Node `{id}`.
- Only the agent may join the log channel as `agent.{id}`. The agent endpoints identify the Node by its WireGuard address, which every local user on the Node shares. Every `agent/*` route therefore also requires the Node's agent secret, a file that only `root` can read and that `orbit-agent.service` loads. [ADR 0155](/decisions/0155-authenticate-the-node-agent-with-a-per-node-secret) adds it, and the stream list is one of those routes. Without the secret, a local user such as an app user cannot fetch the stream list or sign a membership of `presence-node-logs.{id}`, so it can neither read another viewer's lines nor inject lines into their streams. Agent 0.3.0 also refuses to start outside `orbit-agent.service`, which it checks in its own cgroup, and the running agent holds the lock on `/etc/orbit/agent`. Those checks stop a stray copy of the agent, not a user who writes their own client; the secret stops that user.

### Production Instances stay on SSH

A production Instance lives in its own home directory. ADR 0151's unit hides every home directory from the agent except the Instance root, and this ADR does not widen it. The Gateway therefore refuses a stream for a production Instance before it creates one, with `logs.live_unavailable` and the reason `ssh_only`. The web app polls the one-shot read at once, with no error and no live state first. The CLI prints `Following over SSH; polling every 5 seconds.` A log tail runs only while someone watches, so SSH reads are enough there.

### Relay to the viewer

The relay follows ADR 0151's pattern. The socket loop only records what arrived. Every database read, cache read, and broadcast runs in a child process that the loop never waits for.

- The subscriber checks each agent event's shape, applies the relay rate for each stream, and puts the event in a queue in arrival order. Lines that follow lines of the same stream join one item. The queue lives in the subscriber's memory.
- ADR 0151's publisher gains a third lane, `logs`. Like the other lanes, it runs one child at a time and stops a run after 12 seconds. The child is `orbit:agent-view-publish --logs`. It reads a batch of items as JSON on its standard input, at most 256 KiB of lines, and runs them in order.
- For each item, the child checks that the stream is open for the sending Node. It redacts each line again, with the Gateway's patterns and the stored environment values of the Instance or Process. It publishes the lines as a `log.lines` server event on `private-log-stream.{stream}` through the Reverb HTTP API, in parts under 10,000 bytes. A viewer accepts only server events on that channel.
- After each part, the child saves a cursor for the stream: the last `sequence`, and the item and part it carried. When a publish or a store read fails, the child fails. The publisher puts the batch back in front of the queue and runs it again after 2 seconds. The repeat skips every part the cursor names, so each line reaches the viewer once and in order. When a part was published but its cursor was not saved, the repeat sends it with the same `sequence`, and clients ignore a `sequence` they already have.
- No line is lost silently. When a stream's waiting lines pass 1 MiB, or all waiting lines pass 16 MiB, or its lines wait through 5 failed runs in a row, the queue drops that stream's waiting lines and ends it with `relay_behind`. For the next 120 seconds it ignores every event for that stream. The viewer falls back to SSH reads.
- When the agent ends a stream or leaves the log channel, the queue adds an item after that stream's waiting lines. The run delivers those lines, then closes the stream and publishes `log.ended` with the reason. A failed `log.ended` or `log-streams.changed` publish does not fail the run: the viewer's next renewal finds the stream closed, and the agent's next list omits it.
- Every 5 seconds while a stream may be open, a batch also asks for a sweep: the run ends streams whose lease ran out, with `expired`, and streams whose viewer lost access, with `revoked`. A stream may be open after the subscriber connects and after any log event or `log-streams.changed` prompt. The sweeps stop when a run finds no open stream.
- Every 15 seconds, the sweep also publishes `log-streams.changed` again for each Node with an active stream that has not relayed a line yet. An agent that reads no stream fetches its list only when prompted, so a prompt lost while Reverb was unreachable would otherwise leave that stream silent. A lost prompt also hides the new stream from the subscriber, whose sweeps may have stopped, so each renewal of such a stream prompts the agent again too.

We chose the relay over direct publishing by the agent. With direct publishing, the agent would join each viewer's channel, learn viewer channel names, and hold the stored environment values for redaction. With the relay, a Node can send only to its own log channel, and only for streams that the Gateway assigned to that Node. The Gateway checks each batch against the open streams, applies the environment value redaction without sending the values anywhere, and enforces its own rate limit. The cost is that every line passes through the subscriber's queue and a relay run, with one Reverb HTTP call per part. The rate limits below bound that cost.

### Redact on the Node, then again on the Gateway

- The agent applies the Gateway's secret patterns to each line before it sends it: PEM blocks, URL credentials, `Authorization` headers, `Bearer` tokens, and values after a secret-named key in `KEY=value`, JSON, and `key: value` form. A PEM block that spans lines is redacted from its `BEGIN` line to its `END` line, for at most 200 lines.
- One table of test cases in `apps/agent` holds the expected result of each pattern. The agent tests and a Gateway test both run it, so the two implementations stay the same.
- The Gateway applies the patterns again and replaces the stored environment values of eight characters or more, as the one-shot reads do. It skips the values of setting keys, such as `APP_ENV` and `LOG_CHANNEL`, which would otherwise hide ordinary words such as `production` in `production.INFO`.
- Redaction is a safety net, not access control. It misses a secret with no recognizable shape or key name, a secret split across lines or encoded, a short environment value that the Gateway skips, and personal data such as email addresses. Access to the Node decides who may read a log.

### Bound rate and memory

| Limit | Value |
| --- | --- |
| Open streams | 16 for each serving Node |
| First lines | `lines`, 1 to 1,000, at most 256 KiB, outside the rate limits |
| Line length | 8 KiB; a longer line is cut and ends with `[truncated]` |
| Batching | The agent sends at most one event for each stream every 250 milliseconds |
| Agent rate | 32 KiB per second for each stream, with a 256 KiB burst, and 256 KiB per second for the agent |
| Relay rate | 64 KiB per second for each stream, with a 256 KiB burst |
| Relay backlog | 1 MiB of waiting lines for each stream, 16 MiB for all streams, and 5 failed runs in a row. Past a limit the stream ends with `relay_behind`. |
| Read ahead | 1 MiB for each read. When a file source is more than 4 MiB behind, the agent skips to its newest 64 KiB. |

- The agent drops a line that exceeds a rate and counts it. The next event carries the count in `dropped` and the skipped bytes in `skipped`. Clients show `[orbit] 120 lines dropped` or `[orbit] 5.0 MiB skipped`. Memory stays fixed: the agent never queues more than one burst for each stream.
- The subscriber drops lines above its own rate in the same way and adds them to `dropped`. It queues the lines it keeps until a relay run takes them, up to the relay backlog.

### Clients

- The web app log panes open a stream when the realtime socket is live, and show the first lines from the stream. They do not poll while the stream runs. They fall back to the 10-second poll when the Gateway refuses the stream, the stream ends, or the socket drops. A refusal or an end shows no error, except `revoked`.
- `orbit process:logs` gains `--follow`. `orbit instance:logs` is new, with the same options. Without `--follow`, both return one tail over SSH, as today. With `--follow`, the CLI prints the first lines and then each new line from the stream. When the live path is not available, it polls the one-shot read every 5 seconds and prints the lines after the last line it printed. After the first poll, it reads 1,000 lines to find that line. When the line is not there, because more lines were written or the log was rotated, it prints `[orbit] lines may be missing` and the newest `--lines` lines. A reopened stream also asks for 1,000 earlier lines, for the same reason.
- Six reasons pass on their own: `agent_not_joined`, `agent_unavailable`, `subscriber_down`, `logs.stream_limit`, and the ends `agent_left` and `relay_behind`. For these, both clients try the live path again every 30 seconds while they poll. A `websocket` move is such a case: the agent rejoins its log channel on the new server a few seconds after it reconnects.

### One-shot reads stay on SSH

`GET .../logs` keeps reading over SSH. A single read costs one SSH command and happens only when someone asks. Serving it from the agent would need a request and response path through Reverb for a read that does not repeat. The live stream sends its first lines itself, so opening a pane costs no SSH read.

### The agent boundary

This ADR changes ADR 0128's boundary in two exact ways:

1. The agent reads log content: the three sources above, and nothing else. ADR 0128 covered only presence and Process state.
2. The agent starts and stops those reads when the Gateway's HTTPS list names them. ADR 0128's agent only reported what it observed on its own.

The rest of the boundary stays. The agent runs no program, opens no port, writes nothing on the Node, and changes no unit or container. A list entry can make it read one of three kinds of log and send the lines to the Gateway, nothing more. A channel message never carries what to read.

### Threat model

| Actor | Attempt | Result |
| --- | --- | --- |
| A viewer without access to the serving Node | Open a stream | Opening a stream needs the access edge of the one-shot read. |
| Any other client | Join another viewer's channel | The browser auth endpoint refuses `private-log-stream.*`. A signature works only for the socket that opened the stream. The stream ID is random and never listed. |
| A viewer whose access is removed | Keep watching | A relay run checks the access edge of every open stream every 5 seconds and ends the stream with `revoked`. Renewal also checks it. |
| A member of `presence-node.{id}`, such as any browser with Gateway access | Read log lines | No log line is sent on `presence-node.{id}`. Log lines use `presence-node-logs.{id}`, which the browser endpoint refuses. |
| A browser or client that sends input | Name a path, unit, or container | Clients send only a record ID and a line count. The agent never receives client input. |
| App code on a Node | Point `laravel.log` or `storage/logs` at another file with a link | The agent opens both without following a symbolic link and refuses a file that `root` owns. |
| A local user on the serving Node, such as an app user | Call the agent endpoints from the Node's WireGuard address, join `presence-node-logs.{id}` as `agent.{id}`, read the Node's streams, or inject lines | Every `agent/*` route requires the Node's agent secret, which only `root` can read ([ADR 0155](/decisions/0155-authenticate-the-node-agent-with-a-per-node-secret)). Without it the Gateway lists no stream and signs no membership. |
| A compromised Node | Send lines for another Node's stream, or flood the Gateway | A relay run publishes lines from `agent.{id}` only for streams of Node `{id}`. The subscriber's rate and backlog limits bound a flood. A Node can already lie about its own logs. |
| A compromised Gateway | List any path | It can list only the three source types, and the agent checks each. A compromised Gateway already holds SSH to every Node. |
| The `websocket` role Node | Read lines in transit | Reverb sees every log line in plain text after TLS ends on that Node, as it sees every realtime message. Orbit trusts it as part of the realtime layer. |
| Anyone with a forged `log-streams.changed` event | Start a read | The event carries nothing. The agent reads only what the HTTPS list names. Only the Reverb app secret can publish a server event. |

## Rejected alternatives

- The agent publishes straight to each viewer's channel: rejected because the agent would join viewer channels, the environment value redaction would need secrets on the Node, and revocation would depend on each agent. See [Relay to the viewer](#relay-to-the-viewer).
- Send log lines on `presence-node.{id}`: rejected because every browser with Gateway access is a member of that channel.
- One channel for each Node's logs, shared by all viewers: rejected because a viewer of one Instance would receive the logs of every other Instance on that Node.
- Push the source in the channel event: rejected because the agent would act on the content of a channel message, which ADR 0151 rejects. A prompt with no data and an HTTPS list keep the Gateway's authenticated response as the only authority.
- Start `journalctl` or `tail` from the agent: rejected because the agent runs no program. Its own journal reader and file reader keep that rule.
- Link `libsystemd` for the journal: rejected because the agent is a static musl binary. The pure Rust journal crates that exist are either GPL-licensed or read only whole files.
- Move one-shot reads to the agent: rejected because they do not repeat. See [One-shot reads stay on SSH](#one-shot-reads-stay-on-ssh).
- Keep stream state in SQLite: rejected because renewals would write the central store every 20 seconds for each viewer. Streams live in the agent view's file cache store and rebuild when a viewer renews.
- Serve the live tail over HTTP from the Gateway, for example as server-sent events: rejected because a PHP-FPM worker would stay busy for the whole stream, and the Gateway has only a few workers.
- Relay inside the subscriber's socket loop: rejected because a slow Reverb HTTP call or database read would stall the agent view for every Node, which ADR 0151 rules out.
- Widen the agent's unit to production homes: rejected because the agent would see every production `.env` file that its owner left readable. A tail runs only while someone watches, so SSH reads are enough for production.
- Drop the oldest waiting lines when the relay falls behind: rejected because the viewer would miss lines without knowing it. Ending the stream with `relay_behind` sends the viewer to SSH reads, which show the current tail.

## Consequences

- An open log pane costs no SSH command and no API call while its stream runs, apart from one renewal every 20 seconds. New lines show within about two seconds.
- The Gateway handles one request to open a stream, one renewal every 20 seconds for each viewer, and one agent list request for each change and every 15 seconds while a Node streams.
- Every log line passes through the subscriber's queue and a relay run. A flood on many Nodes can use Gateway CPU up to the relay rate of 64 KiB per second for each open stream. While streams are open, the relay starts a PHP process every 5 seconds to sweep, and one for each batch of lines.
- A slow or stopped Reverb delays lines, not the agent view. Lines wait in the subscriber's memory, up to the relay backlog. A stream that falls further behind ends with `relay_behind`.
- Production Instance logs never stream. Their panes and `--follow` use SSH reads every 10 or 5 seconds.
- The Node reads log content only while someone watches it. Redaction happens twice, and it still misses what no pattern recognizes.
- The agent gains a journal reader and a file reader. A journal field that uses xz compression shows as `[orbit] entry not readable`. The journal view shows the unit's entries and systemd's own messages about the unit, in the form `2026-09-25T10:15:02+00:00 name[pid]: message`, which differs from `journalctl --output short-iso` by the missing host name.
- A log file that `root` owns, for example one written by `sudo php artisan`, cannot stream. A `laravel.log` that is a symbolic link also cannot stream, while the one-shot read skips it and reads the newest daily file. Both streams end with `source_unavailable`, and the viewer falls back to SSH reads.
- Nodes need agent 0.3.0 for the live path. Older agents never join the log channel, so the Gateway refuses streams for their Nodes with `agent_outdated` and clients use SSH reads.
- The `websocket` role Node sees log lines in transit.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, apps/web, packages/php-sdk
- ADRs: amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels); extends [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state); extends [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime)'s publisher with a log lane and keeps its rule for channel messages; relies on [ADR 0155](/decisions/0155-authenticate-the-node-agent-with-a-per-node-secret) to keep local users off the agent routes
- Detail: [Live logs](/reference/live-logs), [Node agent](/reference/node-agent#log-tails), [Realtime events](/reference/events#log-channels), [Instance logs](/reference/instance-logs), [`process:logs`](/cli/process#orbit-processlogs), [`instance:logs`](/cli/instance#orbit-instancelogs)
- Verify: `apps/agent` tests for source checks, path traversal, the journal reader, redaction, rate limits, and event sizes; Gateway tests for stream authorization, the `ssh_only` refusal, renewal, the agent list, channel refusals, the relay queue's order, rate, and backlog, the relay run's cursor and repeats, and the shared redaction cases; CLI and web tests for follow, repeated parts, and fallback; an Incus proof that tails a development Instance log and a Process log, refuses a second viewer, shows a secret redacted, counts SSH commands, keeps the agent view current while Reverb is slow or stopped, sends a production Instance to SSH reads, revokes access during a stream, stops the agent, and floods a log
