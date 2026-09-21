---
title: "Tasks"
description: "How the Gateway tasks extension stores TaskGroup features, provisions a shared Instance, starts T3 agents, opens the pull request, notifies Coder, and removes the instance on complete."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension stores a Commander-style feature group, provisions its shared Instance, starts T3 agents, opens the pull request, notifies Coder on settle, and removes the instance after merge. [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the architectural choices.

The extension is off until an authorized Gateway caller enables it. There is no web UI for create. Agents create groups through the [MCP server](/reference/mcp).

## Enable the extension

Enable and disable require Gateway access: the active Gateway peer, or a Node with a grant to the Gateway.

| Operation | Route | Effect |
| --- | --- | --- |
| `tasks:enable` | `POST /api/v1/tasks/enable` | Turns the extension on. Idempotent. |
| `tasks:disable` | `POST /api/v1/tasks/disable` | Turns the extension off. Existing rows stay. Further create, add, list, show, and complete return `tasks.disabled`. |
| `tasks:status` | `GET /api/v1/tasks/status` | Returns whether the extension is enabled. |

Create, add, list, show, and complete refuse with `tasks.disabled` and HTTP 409 while the extension is off.

## Model

A **TaskGroup** is one parent feature. A **Task** is an ordered subtask. Each row stores a brief with deliverables and acceptance.

| Field | Record | Meaning |
| --- | --- | --- |
| `title` | both | Short name |
| `brief` | both | Deliverables and acceptance |
| `status` | both | Lifecycle state |
| `position` | Task | Order inside the group, starting at 1 |
| `taskable_type` / `taskable_id` | TaskGroup | Morph. v1 is an Instance only. Null until the scheduler assigns one |
| `reviewer_thread_id` | TaskGroup | Long-lived reviewer thread for the group |
| `implementer_thread_id` | Task | Fresh implementer thread for that subtask |
| `pr_url` | TaskGroup | Pull request opened after the last sign-off |
| `notify_coder` | TaskGroup | Opt-in Coder settle webhook. Create also accepts Commander's `notify_on_settle` |
| `implementer_model` / `reviewer_model` | TaskGroup | Defaults: `gpt-5.6-luna` and `claude-opus-5` |
| `tokens`, `line_diff`, `duration_ms` | both | Filled on settle and refreshed when an active group is shown |

Group statuses: `queued`, `reserved`, `running`, `reviewing`, `settling`, `completed`, `failed`, `cancelled`. Task statuses: `pending`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`.

`settling` is the reviewable state: the pull request is open or the Gateway has finished the open attempt, and Coder may review.

v1 attaches the group to one Instance. A new decision is required before another morph target is stored.

## Create, add, list, show, and complete

Use these operations after the extension is enabled. Create, add, and complete require Gateway access. List and show accept any authorized peer.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:add` | `POST /api/v1/task-groups/{group}/tasks` | Gateway |
| `tasks:list` | `GET /api/v1/task-groups` | Collection |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Collection |
| `tasks:complete` | `POST /api/v1/task-groups/{group}/complete` | Gateway |

Create requires `app_id`, `title`, and `brief`. It may include an ordered `tasks` array of `{title, brief}` objects and either `notify_coder` or `notify_on_settle`. Add appends one subtask at the next position. List accepts optional `app_id` and `status` query filters. Show returns the group and its tasks in position order. Complete marks a `settling` group `completed` and removes its Instance.

MCP tool names follow the API operation identifiers: `tasks-create`, `tasks-add`, `tasks-list`, `tasks-show`, `tasks-complete`, `tasks-enable`, `tasks-disable`, and `tasks-status`.

## Web task board

Open **Tasks** in the web navigation to see all tracked task groups. Each card shows its title and the Project’s saved code of three capital letters, followed by the task number, such as `ORB-13`.

Codes are unique across Projects. Edit a code in the Project properties; changing it updates card labels without changing task IDs or URLs.

Cards show separate added and deleted line counts when available, an uppercase status outside Todo, and elapsed duration in minutes and hours.

Select a card to read the task brief, its status, tokens, line diff, duration, and its subtasks. Subtasks use their own Todo, In progress, and Done board. Pending subtasks appear in Todo; reserved, running, and reviewing subtasks appear in In progress. Completed, failed, and cancelled subtasks appear in Done with their outcomes visible. Cards retain their sequence numbers and briefs. Subtask cards show that subtask's tokens and line diff when the Gateway has observed them.

Select a subtask to open its own detail page with its title, brief, status, Project, shared Instance, tokens, line diff, and duration. The subtask detail omits the subtasks board. Use the parent task breadcrumb to return to the board.

The board refreshes every ten seconds. Todo contains queued groups waiting for the scheduler. In progress contains reserved, running, reviewing, and settling groups. Settling means awaiting completion after review and merge. Done contains completed, failed, and cancelled groups; each card keeps its outcome visible. Failed and cancelled do not mean successful completion.

The board is read-only. The Gateway still owns scheduling and concurrency. When the extension is disabled, the page explains that tasks are unavailable. Request errors remain visible instead of appearing as an empty board.

### Tokens and line diff

When the parent task is open, Tokens is the total of every T3 session attached to the group: each implementer thread plus the long-lived reviewer thread. Line diff is the whole feature branch against the Project default branch. When a subtask is open, Tokens and Line diff are that implementer's T3 session only. If the group is still active, showing it refreshes those numbers from T3 thread snapshots and the shared checkout.

## Scheduler and ceilings

After a successful create, the Gateway scheduler claims the oldest queued group that still fits the ceilings. It does not poll Nodes.

Active groups are those in `reserved`, `running`, `reviewing`, or `settling`.

| Ceiling | Limit |
| --- | --- |
| Active groups per Project | 3 |
| Active groups per Node | 10 |

A group without an Instance counts toward the Project ceiling only. The Node ceiling applies once `taskable` points at an Instance on that Node.

A claimed group moves from `queued` to `reserved`. InstanceProvisioning then assigns the shared Instance on an active Linux `app-dev` Node that still has capacity. When that assignment fits the Node ceiling, the group becomes `running`, AgentSpawner starts the long-lived reviewer and the first implementer, and the Gateway stores the thread ids. When no eligible Node exists, or T3 refuses `project.create` or `thread.create`, the group stays `reserved` or `running` without thread ids. After a successful `thread.create`, a refused `thread.turn.start` still stores the thread id.

## Shared Instance

One fresh Instance belongs to the group. Every subtask reuses it. The instance name and feature branch are `task-{group id}`. When `origin/task-{group id}` is missing, the provisioner creates that branch from the Project `default_branch` and checks it out in the shared workspace.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work (`app.slug` is `orbit`) | Isolated checkout on the feature branch. No Route and no public URL. The instance stays `source_resolved` |
| `visitable: true` | A real Project | Usual development provisioner and inspect subdomain. The instance becomes `active` |

The provisioner honors `visitable`. It does not invent a Route for a non-visitable workspace because an active Instance still requires exactly one Route.

## Agent sessions

The task group page shows an Agents section below Subtasks. Vertical tabs select the shared reviewer or an implementer. A subtask page shows its implementer sessions and the group reviewer. Finished sessions stay available. A thread with no runtime session is shown as not started, even when the task is marked running.

The Gateway stores each session's group, optional subtask, role, Node, and T3 thread ID independently of the workspace. Existing thread links are imported when the session table is created. If the original Node cannot be resolved, the link remains visible but cannot stream. New T3 thread titles and opening prompts include Orbit task identifiers.

`GET /api/v1/task-groups/{group}/agents` lists recorded sessions. `GET /api/v1/task-groups/{group}/agents/{session}/stream` relays the selected thread from T3's `orchestration.subscribeThread` WebSocket as server-sent events. Both require Gateway access and an enabled tasks extension. T3 credentials stay server-side. The browser reconnects using the last event sequence; snapshots replace local state. Connections rotate periodically and close when the viewer is left. T3 remains the transcript store, so deleted T3 threads cannot be recovered from Orbit.

## T3 agents

Agents run on the T3 server of the Node that owns that Instance. The Gateway posts a flat command to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with `headers: []` on every body. `ORBIT_T3_PORT` defaults to `3773`. `ORBIT_T3_TOKEN` is an optional bearer for that Node's T3 server. A successful dispatch needs a sequence. Commands that have no thread, including `project.create`, may omit `threadId`. `project.create` `defaultModelSelection` and `thread.create` `modelSelection` send options as `{id, value}` objects, never a bare map such as `{effort: high}`.

`thread.turn.start` sends `message` as an object with `messageId`, `role` `user`, `text`, and `attachments`. A flat `message` string decodes to an empty turn, and the agent then sits idle with nothing to work on. Every turn also repeats the thread's `modelSelection`, because T3 binds a thread to its provider instance at create time and `meta.update` cannot move it.

When `project.create` collides on an occupied workspace root, T3's receipt is `Active project '{uuid}' already exists for workspace root '{path}'`. HTTP dispatch may wrap that as `EnvironmentInternalError` / `orchestration_dispatch_failed` without the phrase. The Gateway parses the project id from that phrase when it appears in the error body, a nested cause, or a header, and otherwise adopts the active project for that workspace root from `GET /api/orchestration/snapshot`. After a successful `thread.create`, the Gateway starts the first turn. A refused `thread.turn.start` is retried once and logged. The spawn still returns the created thread id.

Each subtask gets a fresh implementer (`gpt-5.6-luna`, low effort). The group keeps one reviewer thread (`claude-opus-5`, high effort). When a subtask settles, the scheduler marks it `reviewing` and sends "please review" to the reviewer thread. After the reviewer signs off, the Gateway commits in the shared checkout when git can create a commit, completes that subtask, and starts the next implementer. After the last subtask, the group moves to `settling`.

### Model catalog

A `modelSelection` names the T3 provider instance, not the model. The Gateway resolves the stored model slug to its catalog entry, so operators never patch these values on a running Gateway.

| Role | `instanceId` | `model` | Effort option |
| --- | --- | --- | --- |
| Implementer | `codex` | `gpt-5.6-luna` | `reasoningEffort`, `low` |
| Reviewer | `claudeAgent` | `claude-opus-5` | `effort`, `high` |

Each provider names its reasoning option itself. Codex reads `reasoningEffort` and ignores a plain `effort`, which leaves the model on its medium default. Claude reads `effort`.

Older groups still store `codex-luna-lite` or `claude-opus`. The Gateway reads those slugs as `gpt-5.6-luna` and `claude-opus-5`, so their agents spawn on the same catalog entries as a new group.

## Pull request and settle metrics

After the last reviewer sign-off the Gateway opens the GitHub pull request and stores `pr_url`.

1. From the reviewer workspace it runs `gh pr create` on the instance-owning Node when `gh` can authenticate.
2. Otherwise it POSTs to the GitHub HTTP API when `ORBIT_TASKS_GITHUB_TOKEN` covers the Project repository.

The head branch is the instance branch (`task-{group id}`). The base branch is the Project default branch. A refused open leaves `pr_url` empty and keeps the group `settling`.

The Gateway then writes settle metrics. Active groups also refresh these fields when an authorized caller shows the group.

| Field | Record | Source |
| --- | --- | --- |
| `tokens` | Task | Cumulative T3 session tokens for that subtask's implementer thread (`totalProcessedTokens` when present, otherwise `usedTokens`), from `GET /api/orchestration/threads/{threadId}` on the instance-owning Node. Unchanged when T3 refuses the snapshot |
| `line_diff` | Task | Insertions plus deletions on that implementer thread's T3 checkpoints. Unchanged when T3 refuses the snapshot |
| `lines_added`, `lines_deleted` | Task | Separate checkpoint insertion and deletion counts; null before observation |
| `duration_ms` | Task | Elapsed milliseconds from `started_at` to `settled_at`, or to now while the subtask is still open |
| `tokens` | TaskGroup | Sum of Task `tokens` values plus the reviewer thread's T3 session tokens, or `0` at settle when none are stored |
| `line_diff` | TaskGroup | Insertions plus deletions of `git diff --numstat {default_branch}...HEAD` in the shared checkout, or `0` when git cannot run. This is the whole feature branch, not the sum of subtask session diffs |
| `lines_added`, `lines_deleted` | TaskGroup | Separate branch insertion and deletion counts; null before a successful observation |
| `duration_ms` | TaskGroup | Elapsed milliseconds from `started_at` to settle, or to now while the group is still active, or `0` when `started_at` is empty |

Commander collected the same session totals from Codex App Server. T3 replaces that observer: each stored thread id is one T3 session.

## Coder settle webhook

When `notify_coder` is true, settle POSTs an HMAC-signed JSON body to Coder. This is Commander's `notify_on_settle` path.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_CODER_WEBHOOK_URL` | HTTPS endpoint that receives the settle POST |
| `ORBIT_CODER_WEBHOOK_SECRET` | HMAC-SHA256 secret. The Gateway never returns it |
| `ORBIT_TASKS_GITHUB_TOKEN` | Optional GitHub token with pull-request write access when `gh` on the Node cannot open the PR |
| `ORBIT_T3_PORT` | T3 HTTP port. Defaults to `3773` |
| `ORBIT_T3_TOKEN` | Optional bearer for that Node's T3 server |

The Gateway skips the webhook when the URL or secret is missing. A refused Coder response does not fail settle.

The signed payload is `{unix timestamp}.{raw JSON body}`. Senders use these headers:

| Header | Value |
| --- | --- |
| `X-Orbit-Timestamp` | Unix seconds used in the signature |
| `X-Orbit-Signature` | `sha256=` plus the hex HMAC of `timestamp.body` |
| `Content-Type` | `application/json` |

The JSON body contains `event` (`task_group.settled`), `task_group_id`, `title`, `tokens`, `line_diff`, `duration_ms`, and `pull_request_url`.

## Complete and cleanup

After Coder review and PR merge, an authorized Gateway caller runs `tasks:complete` (`POST /api/v1/task-groups/{group}/complete`, MCP tool `tasks-complete`). That marks the group `completed` and removes the shared Instance through the existing Instance remover, including any visitable Routes.

Complete is the documented cleanup path. The Gateway GitHub App receives no merge webhook. A second complete is idempotent. Completing a group that is not `settling` or already `completed` returns `tasks.not_settling` (HTTP 409). Operators may still call `DELETE /api/v1/instances/{instance}` directly; that leaves the group `settling` until complete runs.

## Out of this slice

These items stay unimplemented here and need a later feature PR.

- Commander data migration and retiring Commander
- Creating or changing tasks through the web UI
- Per-Project model overrides
