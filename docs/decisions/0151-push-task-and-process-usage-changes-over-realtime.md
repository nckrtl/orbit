---
title: "ADR 0151: Push task and Process usage changes over realtime"
sidebarTitle: "0151 Push task and Process usage changes over realtime"
description: "Proposed. The Gateway broadcasts task, subtask, comment, and agent thread changes on the orbit channel, and the web app stops polling tasks while it is live. Node agents report each task checkout's Git state, and the Gateway reads it instead of SSH. The agent view subscriber pushes Process CPU and memory every 15 seconds while a browser watches."
---

# ADR 0151: Push task and Process usage changes over realtime

The Gateway broadcasts a small notice on the `orbit` channel whenever a task group, subtask, check, comment, or agent thread changes. The web app refetches what changed and stops polling tasks while its socket is live. Each Node agent watches the task checkouts on its Node and reports their branch, HEAD, dirty state, and diff line counts. The Gateway's view keeps that state, and the task tick and `tasks:show` read it instead of running `git` over SSH. The agent view subscriber pushes the CPU and memory of every Process every 15 seconds while a browser watches, so no tab polls the Process list for them.

## Status

Proposed.

This extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) with task events and a Process usage event. It amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels): the agent also observes task checkouts, and its unit gains read access to them. It extends the Gateway view of [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) with workspace state, and it carries out that ADR's deferred task tick, task diff, and CPU and memory items.

## Context

The web app polls every task read. In the last seven days the Gateway answered 85,000 `tasks:list`, 25,000 `tasks:show`, and 12,000 `tasks:status` calls, and 62,000 `process:list` calls. One open tab asks for the task list every 10 seconds on every page, because the navigation shows the task count. The quota page also asks for the extension status every 10 seconds. An open task page adds its group, its agent threads, and its comments every 10 seconds. The Process list reloads every 15 seconds, only for CPU and memory, because no event carries them.

Polling also costs SSH. The task tick runs every 10 seconds. For each running task whose acting thread is not working, it checks for new commits with one `git` command over SSH, even with no page open: 360 commands per hour for each such task. Each `tasks:show` of an active group runs `git diff --numstat` over SSH.

The pieces for push already exist:

- The Gateway broadcasts record changes on the private `orbit` channel. Its auth endpoint requires Gateway access.
- Every managed Node runs `orbit-agent`. It publishes Process state on `presence-node.{id}`, and the Gateway's agent view subscriber keeps it as a view.
- The Gateway already reads CPU and memory for every Process from Prometheus with two fleet-wide queries.

Constraints shape the design:

- Reverb refuses a message over 10,000 bytes. A task group with its briefs and subtasks can be larger, and comments hold run receipts.
- The agent is visibility-only. It must not run commands, and it must not start `git`.
- The agent unit sets `ProtectHome=yes` and grants no capabilities, so the agent cannot see `/home`. Task checkouts live in the Node's Instance root, by default `apps` in the managed user's home, which `useradd` creates with mode `0750`.
- The agent view subscriber answers Reverb and keeps the view fresh in one socket loop. A read or a broadcast that waits in that loop can turn every Node's view stale.
- A Node can hold many task checkouts, and a checkout can be a large repository with submodules.

## Decision

The Gateway owns the task events, the workspace view, and the Process usage push. The agent owns the Git reads on its Node. The web app refetches over HTTP when an event names a record.

### Task events on the orbit channel

- The Gateway broadcasts task changes on the existing private `orbit` channel. Only a caller with Gateway access can subscribe to it, and such a caller can already read every task group, comment, and agent thread over HTTP. No new channel or rule is needed.
- Task events are notices, as annotation events are. They carry identifiers, status, and counts, never titles, briefs, receipts, or messages. A client refetches the record over HTTP.

| Type | Fires when | Data |
| --- | --- | --- |
| `task_group.created` | A group is created. | `{ id, status }` |
| `task_group.updated` | A group, one of its subtasks, or one of its checks changes, or the Gateway sees a new diff summary for its workspace. | `{ id, status, lines_added, lines_deleted, line_diff }` |
| `task_comment.created` | A comment or run receipt is stored. | `{ id, task_group_id, task_id }` |
| `agent_thread.updated` | A thread is created, or its state, error, or observation error changes. | `{ id, task_group_id, task_id, state }` |
| `tasks.updated` | The tasks extension is enabled or disabled. | `{ enabled }` |

- A change only to tokens, line counts, or duration does not broadcast `task_group.updated` by itself. `tasks:show` refreshes those values, and a broadcast for them would make every open tab show the group again in a loop. The web app counts an active group's duration forward itself. Token and line counts reach it with the next state change, the next workspace commit, or the thread's own stream.
- The Gateway collects the task changes of one request, one tick, or one subscriber pass, and broadcasts each record once when that work ends. A broadcast failure never fails the work, as ADR 0084 requires.

### The web app

- While the `orbit` socket is live, the web app refetches the task list, a task group, its agent threads, its comments, and the extension status when an event names them, and otherwise only every 5 minutes, as a safety net for a lost notice. A refetch that an event starts replaces a request already in flight, because that request may predate the change.
- When the socket first subscribes, the web app refetches the task and Process queries, because a change between their first load and the subscription sent no event. When the socket comes back after a drop, it refetches every query, as it does today.
- The web app waits 100 milliseconds after a task event and refetches each named query once, so one Gateway flush that names a group twice shows it once.
- While the socket is down or realtime is not configured, those queries poll every 30 seconds instead of every 10.

### Task workspace state from the agent

The agent reports the Git state of every task checkout on its Node. It reads only Git metadata and never sends file contents or file names.

- **Watch list.** The agent asks `GET /api/v1/agent/workspaces` at start and every 60 seconds. The Gateway lists the Instances on the caller's Node that hold the workspace of a task group that has not finished, with `instance_id`, the checkout `path`, the Project's default branch as `base`, and the Instance's starting commit as `start`. The list is empty while the tasks extension is disabled, and it holds at most 64 entries. The endpoint uses the rules of the other agent endpoints: an active WireGuard peer inside the managed-node boundary, no access edge, no Activity.
- **Paths.** The agent accepts only an absolute path of at most 4,096 bytes, without `.` or `..` parts, that opens as the root of a Git work tree. It never follows a path the list does not name, and it never opens a submodule.
- **Reading Git.** The agent reads Git with libgit2 inside its own process. It never starts `git` or any other program, and libgit2 runs no hooks, filters, or `fsmonitor` programs. The agent runs as `root`, and the checkouts belong to `orbit`, so it turns off libgit2's owner check. Running no repository program makes that safe.
- **Detection.** Every 2 seconds the agent reads the status of a few files in each checkout's Git directory: `HEAD`, `index`, `packed-refs`, the current branch's ref, and the base ref. When one changed, it reads the checkout again. It also reads every checkout again every 30 seconds, because an edit to a working file changes none of those files. The agent reads one checkout at a time.
- **Reported state.** For each checkout the agent reports:

| Field | Meaning |
| --- | --- |
| `instance_id` | The Instance from the watch list |
| `base`, `start` | The `base` and `start` values the state was read against |
| `branch` | The current branch, or null when `HEAD` is detached |
| `head` | The full `HEAD` commit, or null in an empty repository |
| `dirty` | Whether the index or working tree differs from `HEAD`, untracked files included and ignored files and submodules excluded; null when it cannot be read |
| `commits` | The number of commits reachable from `HEAD` but not from `start`, at most 1,000; null without `start` or when `start` is unknown |
| `diff` | `{ files, added, removed, truncated }` from the merge base of `base` and `HEAD` to `HEAD`, as `git diff --numstat base...HEAD` counts it; null when `base` is unknown. Over 5,000 changed files, `truncated` is true, `files` is exact, and no lines are counted |

- **Limits.** A diff over 5,000 changed files is truncated. A file over 1 MiB, and a binary file, counts as changed with no lines. A changed submodule counts as one changed file with no lines. Renames count as git counts them by default, but when an added or deleted file is over 1 MiB, only exact renames count. A branch name over 255 characters leaves the checkout out. libgit2's object cache is 8 MiB and its mapped pack window is 32 MiB. A checkout that the agent cannot read is left out of its reports.
- **Events.** The agent publishes two more client events on its own channel. `client-workspaces` carries every watched checkout, in parts under 10,000 bytes. The agent sends it after every snapshot, when the watch list changes, and when a checkout that it read before fails to open. `client-workspace` carries one checkout whose state changed. Reverb stamps both with `agent.{id}`, and subscribers apply ADR 0129's sender rule.
- **Unit.** The agent unit changes `ProtectHome=yes` to `ProtectHome=tmpfs`, adds `BindReadOnlyPaths=-{Instance root}` when that root lies under `/home`, and raises `MemoryMax` from `64M` to `128M`. The Gateway resolves the Instance root from the Node's managed user and settings on every converge. When it cannot resolve them, or the path holds characters outside letters, digits, `.`, `_`, `-`, and `/`, the unit keeps `ProtectHome=yes`, and the Gateway reads that Node's checkouts over SSH. The extra memory covers libgit2's caches and a diff at the file limit.
- **What the agent can read.** `/home` and `/root` are empty for the agent, except the Instance root, which it sees read-only. The agent runs as `root` without capabilities, so inside the Instance root it reads only files that other users may read. Tracked files and the `.git` directory that Orbit checks out allow that. The Instance root and the checkout directories must stay world-traversable, as Orbit creates them (`0755`); otherwise the agent leaves the checkout out, and the Gateway reads it over SSH. An Instance `.env` is closed to the agent: Orbit removes the world bits from an Instance `.env` when it configures the Laravel URL, after a registration moves a checkout, after a transfer, and, for every existing Instance checkout in the Instance root, on each agent converge. When the converge fails to close a checkout's `.env`, it logs a warning that names the checkout and continues. Environment updates and imports already write it as `0600`. Outside `/home` and `/root`, the whole file system is read-only for the agent, as ADR 0128 set. The agent code opens only the listed checkouts, reads Git metadata, and hashes a tracked file only when its size or time changed.

### The Gateway view and the reads

- The subscriber keeps each Node's workspaces in its view entry, keyed by Instance. It accepts a workspace only with a positive integer Instance, a 40-character hexadecimal `head` and `start`, a branch of at most 255 characters, and non-negative counts. It keeps at most 64 workspaces for each Node.
- A reader uses a workspace only when the Node's view is fresh, as ADR 0148 defines, and the entry's `base` and `start` equal the values the reader would use. Otherwise it runs today's SSH command.

| Read | With a fresh workspace | Fallback |
| --- | --- | --- |
| The tick's check for new commits since the thread started | `commits` is greater than 0 | `git rev-list` or `git log` over SSH |
| The line diff that `tasks:show` refreshes for an active group | `diff.added` and `diff.removed`, unless `diff` is truncated | `git diff --shortstat` over SSH, whose one-line output never reaches the SSH output cap |

- The reads that gate an action stay on SSH: the branch check before the Gateway commits an approval, the start commit of a subtask, the `composer.json` check script, run receipts, and the Project check. A view can lag the checkout by up to 2 seconds, and these reads decide what the Gateway commits or tells an agent.
- When the subscriber sees a new `head` or new diff counts for the workspace of an unfinished task group, the agent view subscriber starts a child process, `php artisan orbit:agent-view-publish`, and never waits for it. Task workspaces and Process usage run in separate lanes, one child at a time in each, so a slow Prometheus never delays a commit's notice. The subscriber stops a run after 12 seconds. When a workspace run fails, is stopped, or cannot start, the subscriber queues its workspaces again and retries them after a 15-second pause. After five failed runs in a row, it drops a workspace with an error in the Gateway log, until the agent reports a new change for it. After a subscriber restart, the first workspace list from each agent queues every workspace again. A successful run stores the group's line counts when the diff is complete, and broadcasts `task_group.updated` also for a truncated or unknown diff, so such a commit reaches open tabs. They then show the group, which counts the lines over SSH. The database is written only for such a change, never for a heartbeat.

### Process CPU and memory

- The agent view subscriber already joins every `presence-node.{id}` channel. It counts the `viewer.*` members there, which are browsers.
- While at least one viewer is present, the subscriber queues a sample every 15 seconds, and the same child process reads CPU and memory for every Process, with the two fleet-wide Prometheus queries the Process list uses. It broadcasts one `process.usage` event on `orbit` for all Processes. The event's `id` is the sample time in Unix seconds, and its data is `{ part, parts, processes }`, where `processes` lists `[id, cpu, memory_bytes]` for at most 200 Processes per part. A Process without a sample has null values, as every Process has while Prometheus cannot answer. The Process list reports the same nulls then.
- The subscriber's socket loop never waits on Prometheus, the database, or a broadcast. A slow or hung Prometheus or Reverb HTTP API costs at most a skipped sample and a delayed notice.
- No viewer means no queries and no broadcasts.
- The web app writes each sample into its cached Process list. While the socket is live, it reloads the Process list only when no `process.usage` event arrived for 60 seconds. While the socket is down, it polls every 15 seconds, as today.
- The agent does not sample CPU or memory.

### Failure and fallback

| Failure | Result |
| --- | --- |
| Reverb is down or realtime is not configured | Task queries poll every 30 seconds and the Process list every 15 seconds. The tick and `tasks:show` use SSH, as the view is empty. |
| A broadcast fails | The Gateway logs a warning. Clients catch up at the next event, reconnect, or fallback poll. |
| An agent is stopped or older than this change | The Node has no fresh workspaces, and the Gateway uses SSH for that Node. |
| The agent cannot read a checkout | It leaves the checkout out, and the Gateway uses SSH for it. |
| The subscriber is down | No workspace view and no `process.usage`. The web app reloads the Process list every 60 seconds while live. |
| Prometheus is down | Samples carry null CPU and memory, as the Process list does. |
| Prometheus or the Reverb HTTP API hangs | The subscriber stops the publish run after 12 seconds and starts the next one 15 seconds after that. The subscriber's loop does not wait, so the view stays fresh. |

### Rollout

1. The Gateway, web, and agent unit changes ship first. They work with agent 0.1.1, which reports no workspaces, so the Gateway keeps using SSH for task Git reads. The next converge on each Node writes the new unit and restarts the agent.
2. Agent 0.2.0 is released from an `agent-v0.2.0` tag on `main`. A Gateway change pins it and both checksums from `SHA256SUMS`. `node:add` or a role converge on each Node installs it. The release checklist in [PR #687](https://github.com/nckrtl/orbit/pull/687) tracks these steps.

### Expected savings

| Load | Before | After, with no change to show |
| --- | --- | --- |
| Task API calls from one open tab | 6 per minute on every page, plus 18 per minute on an open task page | 0, plus one refetch for each change |
| Process list calls from one open tab | 4 per minute | 0 while `process.usage` arrives |
| Prometheus queries for CPU and memory | 2 for each list call from each tab | 8 per minute in total while any browser watches |
| SSH `git` commands from the tick | 6 per minute for each running task whose acting thread is not working | 0 while the Node's view is fresh |
| SSH `git diff` from `tasks:show` | 1 for each show of an active group | 0 while the Node's view is fresh |

## Rejected alternatives

- Broadcast full task records: rejected because a group with its briefs and subtasks can pass Reverb's 10,000-byte limit, and receipts and briefs would reach every subscriber without a request.
- A new task channel with its own authorization: rejected because every subscriber of `orbit` already has Gateway access and can read every task.
- Broadcast token and duration changes: rejected because `tasks:show` refreshes those values, so each show would broadcast and make every tab show the group again.
- Let the web app read workspace state from the agent channels directly: rejected because only the Gateway knows which group owns a workspace and keeps the stored counts, and the CLI and API need the same values.
- Run `git` from the agent: rejected because the agent must not run programs, and a repository's configuration can make `git` run hooks and filters.
- Watch checkouts with inotify: rejected because dirty state needs a watch on every directory of the working tree, which hits the kernel's watch limits on large repositories. A 2-second stat of the Git directory and a 30-second full read are simpler and bounded.
- Push the watch list from the Gateway over the agent's channel: rejected because the agent would then act on messages it receives, and a pull every 60 seconds costs one small request a minute for each Node.
- Grant the agent `CAP_DAC_READ_SEARCH`, or add it to the managed user's group: rejected because either reads files that the managed user keeps from other users, such as an Instance `.env`.
- Keep `ProtectHome=read-only`: rejected because the agent then reads all of `/root` and every readable file in every home.
- Read Prometheus and broadcast inside the subscriber's loop: rejected because a hung Prometheus stalled the loop 8 to 10 seconds in every 15-second cycle on Incus, close to the 15-second freshness window.
- Let the agent sample CPU and memory: rejected because Prometheus already has the values, and the agent would need a sampling loop on every Node.
- Push Process usage from a scheduled command: rejected because only the subscriber knows whether a browser watches, and the schedule runner is not guaranteed on the Gateway.
- Keep reads that gate an action on the view: rejected because the view can lag by 2 seconds, and a stale branch or receipt would make the Gateway commit on the wrong branch or send an agent a wrong reminder.

## Consequences

- An idle open tab makes one task request every 5 minutes and no Process list requests while realtime is live. Task changes show within about a second.
- A running task costs no SSH `git` command on each tick while its Node's view is fresh, and showing a group runs no `git diff`.
- The Gateway queries Prometheus on a fixed 15-second clock while any browser watches, instead of once for each tab's poll.
- Token counts on the board change with state changes and commits, not every 10 seconds. The agent thread stream still shows live tokens on the group page.
- The agent can read the world-readable files in the Instance root. It opens only the listed checkouts, and it runs no program.
- An Instance `.env` loses its world bits when Orbit configures, registers, or transfers the Instance, or converges the agent on its Node. A process that read it as another user must run as the managed user or in its group.
- Each commit and each Process sample start one short PHP process on the Gateway host.
- A compromised Gateway can learn the branch, HEAD, and line counts of any Git work tree on a Node. It can already read them over SSH.
- A compromised Node can report false Git state about its own checkouts. The tick's commit check and the stored line counts can then be wrong for that Node. Reads that gate a commit stay on SSH.
- The agent needs a release, and every Node needs a converge, before the SSH savings start.
- Browsers on `presence-node.{id}` also receive the workspace events. They hold only branch, commit, and counts that `tasks:show` already returns.

## Affects

- Components: apps/gateway, apps/web, apps/docs
- ADRs: extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb); amends [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels); extends [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state); keeps [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) and [ADR 0122](/decisions/0122-hold-task-groups-in-backlog-until-ready) unchanged
- Detail: [Realtime events](/reference/events), [Node agent](/reference/node-agent), [Web app](/reference/web-app), [Tasks](/reference/tasks)
- Verify: Gateway tests for task broadcasts and their coalescing, the workspace endpoint, the view's workspace rules, the two reads and their fallback, and the Process usage timer; agent tests in `apps/agent` for path checks, Git state, limits, and event sizes; web tests for event handling and fallback polling; an Incus proof that counts Gateway SSH commands and one tab's API calls before and after, stops an agent, and cuts realtime
