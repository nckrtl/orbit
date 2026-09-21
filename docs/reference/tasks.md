---
title: "Tasks"
description: "How the Gateway tasks extension stores TaskGroup features, provisions a shared App instance, starts T3 agents, opens the pull request, notifies Coder, and removes the instance on complete."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension stores a Commander-style feature group, provisions its shared App instance, starts T3 agents, opens the pull request, notifies Coder on settle, and removes the instance after merge. [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the architectural choices.

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
| `taskable_type` / `taskable_id` | TaskGroup | Morph. v1 is an App instance only. Null until the scheduler assigns one |
| `reviewer_thread_id` | TaskGroup | Long-lived reviewer thread for the group |
| `implementer_thread_id` | Task | Fresh implementer thread for that subtask |
| `pr_url` | TaskGroup | Pull request opened after the last sign-off |
| `notify_coder` | TaskGroup | Opt-in Coder settle webhook. Create also accepts Commander's `notify_on_settle` |
| `implementer_model` / `reviewer_model` | TaskGroup | Defaults: `codex-luna-lite` and `claude-opus` |
| `tokens`, `line_diff`, `duration_ms` | both | Filled on settle |

Group statuses: `queued`, `reserved`, `running`, `reviewing`, `settling`, `completed`, `failed`, `cancelled`. Task statuses: `pending`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`.

`settling` is the reviewable state: the pull request is open or the Gateway has finished the open attempt, and Coder may review.

v1 attaches the group to one App instance. A new decision is required before another morph target is stored.

## Create, add, list, show, and complete

Use these operations after the extension is enabled. Create, add, and complete require Gateway access. List and show accept any authorized peer.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:add` | `POST /api/v1/task-groups/{group}/tasks` | Gateway |
| `tasks:list` | `GET /api/v1/task-groups` | Collection |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Collection |
| `tasks:complete` | `POST /api/v1/task-groups/{group}/complete` | Gateway |

Create requires `app_id`, `title`, and `brief`. It may include an ordered `tasks` array of `{title, brief}` objects and either `notify_coder` or `notify_on_settle`. Add appends one subtask at the next position. List accepts optional `app_id` and `status` query filters. Show returns the group and its tasks in position order. Complete marks a `settling` group `completed` and removes its App instance.

MCP tool names follow the API operation identifiers: `tasks-create`, `tasks-add`, `tasks-list`, `tasks-show`, `tasks-complete`, `tasks-enable`, `tasks-disable`, and `tasks-status`.

## Scheduler and ceilings

After a successful create, the Gateway scheduler claims the oldest queued group that still fits the ceilings. It does not poll Nodes.

Active groups are those in `reserved`, `running`, `reviewing`, or `settling`.

| Ceiling | Limit |
| --- | --- |
| Active groups per App | 3 |
| Active groups per Node | 10 |

A group without an App instance counts toward the App ceiling only. The Node ceiling applies once `taskable` points at an App instance on that Node.

A claimed group moves from `queued` to `reserved`. InstanceProvisioning then assigns the shared App instance on an active Linux `app-dev` Node that still has capacity. When that assignment fits the Node ceiling, the group becomes `running`, AgentSpawner starts the long-lived reviewer and the first implementer, and the Gateway stores the thread ids. When no eligible Node exists, or T3 refuses `project.create` or `thread.create`, the group stays `reserved` or `running` without thread ids. After a successful `thread.create`, a refused `thread.turn.start` still stores the thread id.

## Shared App instance

One fresh App instance belongs to the group. Every subtask reuses it. The instance name and feature branch are `task-{group id}`. When `origin/task-{group id}` is missing, the provisioner creates that branch from the Project `default_branch` and checks it out in the shared workspace.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work (`app.slug` is `orbit`) | Isolated checkout on the feature branch. No Route and no public URL. The instance stays `source_resolved` |
| `visitable: true` | A real App | Usual development provisioner and inspect subdomain. The instance becomes `active` |

The provisioner honors `visitable`. It does not invent a Route for a non-visitable workspace because an active AppInstance still requires exactly one Route.

## T3 agents

Agents run on the T3 server of the Node that owns that App instance. The Gateway posts a flat command to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with `headers: []` on every body. `ORBIT_T3_PORT` defaults to `3773`. `ORBIT_T3_TOKEN` is an optional bearer for that Node's T3 server. A successful dispatch needs a sequence. Commands that have no thread, including `project.create`, may omit `threadId`. `project.create` `defaultModelSelection` and `thread.create` `modelSelection` send options as `{id, value}` objects, never a bare map such as `{effort: high}`.

When `project.create` collides on an occupied workspace root, T3's receipt is `Active project '{uuid}' already exists for workspace root '{path}'`. HTTP dispatch may wrap that as `EnvironmentInternalError` / `orchestration_dispatch_failed` without the phrase. The Gateway parses the project id from that phrase when it appears in the error body, a nested cause, or a header, and otherwise adopts the active project for that workspace root from `GET /api/orchestration/snapshot`. After a successful `thread.create`, the Gateway starts the first turn. A refused `thread.turn.start` is retried once and logged. The spawn still returns the created thread id.

Each subtask gets a fresh implementer (`codex-luna-lite`, low effort). The group keeps one reviewer thread (`claude-opus`, high effort). When a subtask settles, the scheduler marks it `reviewing` and sends "please review" to the reviewer thread. After the reviewer signs off, the Gateway commits in the shared checkout when git can create a commit, completes that subtask, and starts the next implementer. After the last subtask, the group moves to `settling`.

## Pull request and settle metrics

After the last reviewer sign-off the Gateway opens the GitHub pull request and stores `pr_url`.

1. From the reviewer workspace it runs `gh pr create` on the instance-owning Node when `gh` can authenticate.
2. Otherwise it POSTs to the GitHub HTTP API when `ORBIT_TASKS_GITHUB_TOKEN` covers the App repository.

The head branch is the instance branch (`task-{group id}`). The base branch is the App default branch. A refused open leaves `pr_url` empty and keeps the group `settling`.

The Gateway then writes settle metrics on the group:

| Field | Source |
| --- | --- |
| `tokens` | Sum of Task `tokens` values, or `0` when none are stored |
| `line_diff` | Insertions plus deletions of `git diff --numstat {default_branch}...HEAD` in the shared checkout, or `0` when git cannot run |
| `duration_ms` | Elapsed milliseconds from `started_at` to settle, or `0` when `started_at` is empty |

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

After Coder review and PR merge, an authorized Gateway caller runs `tasks:complete` (`POST /api/v1/task-groups/{group}/complete`, MCP tool `tasks-complete`). That marks the group `completed` and removes the shared App instance through the existing App instance remover, including any visitable Routes.

Complete is the documented cleanup path. The Gateway GitHub App receives no merge webhook. A second complete is idempotent. Completing a group that is not `settling` or already `completed` returns `tasks.not_settling` (HTTP 409). Operators may still call `DELETE /api/v1/instances/{instance}` directly; that leaves the group `settling` until complete runs.

## Out of this slice

These items stay unimplemented here and need a later feature PR.

- Commander data migration and retiring Commander
- A web UI for tasks
- Per-App model overrides
