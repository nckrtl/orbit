---
title: "Tasks"
description: "How the Gateway tasks extension stores TaskGroup features, ordered Task subtasks, and MCP create, list, and show."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension stores a Commander-style feature group, its ordered subtasks, and the MCP tools that create and read them. [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the architectural choices.

The extension is off until an authorized Gateway caller enables it. There is no web UI for create. Agents create groups through the [MCP server](/reference/mcp).

## Enable the extension

Enable and disable require Gateway access: the active Gateway peer, or a Node with a grant to the Gateway.

| Operation | Route | Effect |
| --- | --- | --- |
| `tasks:enable` | `POST /api/v1/tasks/enable` | Turns the extension on. Idempotent. |
| `tasks:disable` | `POST /api/v1/tasks/disable` | Turns the extension off. Existing rows stay. Further create, add, list, and show return `tasks.disabled`. |
| `tasks:status` | `GET /api/v1/tasks/status` | Returns whether the extension is enabled. |

Create, add, list, and show refuse with `tasks.disabled` and HTTP 409 while the extension is off.

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
| `pr_url` | TaskGroup | Pull request opened at the end |
| `notify_coder` | TaskGroup | Whether settle should notify Coder |
| `implementer_model` / `reviewer_model` | TaskGroup | Defaults: `codex-luna-lite` and `claude-opus` |
| `tokens`, `line_diff`, `duration_ms` | both | Filled on settle |

Group statuses: `queued`, `reserved`, `running`, `reviewing`, `settling`, `completed`, `failed`, `cancelled`. Task statuses: `pending`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`.

v1 attaches the group to one App instance. A new decision is required before another morph target is stored.

## Create, add, list, and show

Use these operations after the extension is enabled. Create and add require Gateway access. List and show accept any authorized peer.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:add` | `POST /api/v1/task-groups/{group}/tasks` | Gateway |
| `tasks:list` | `GET /api/v1/task-groups` | Collection |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Collection |

Create requires `app_id`, `title`, and `brief`. It may include an ordered `tasks` array of `{title, brief}` objects. Add appends one subtask at the next position. List accepts optional `app_id` and `status` query filters. Show returns the group and its tasks in position order.

MCP tool names follow the API operation identifiers: `tasks-create`, `tasks-add`, `tasks-list`, `tasks-show`, `tasks-enable`, `tasks-disable`, and `tasks-status`.

## Scheduler and ceilings

After a successful create, the Gateway scheduler claims the oldest queued group that still fits the ceilings. It does not poll Nodes.

Active groups are those in `reserved`, `running`, `reviewing`, or `settling`.

| Ceiling | Limit |
| --- | --- |
| Active groups per App | 3 |
| Active groups per Node | 10 |

A group without an App instance counts toward the App ceiling only. The Node ceiling applies once `taskable` points at an App instance on that Node.

A claimed group moves from `queued` to `reserved`. InstanceProvisioning then assigns the shared App instance. AgentSpawner would start the reviewer and the first implementer. This slice ships no-op implementations. The group stays `reserved` until a provisioner implementation assigns an instance, and thread ids stay empty.

## Shared App instance

One fresh App instance belongs to the group. Every subtask reuses it.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work | Isolated checkout or worktree. No public URL or inspect Route |
| `visitable: true` | A real App | Usual subdomain so an operator can inspect |

InstanceProvisioning receives that intent. A following feature PR must honor `visitable` when it creates the App instance. On pull-request merge or instance remove, cleanup includes Routes.

Agents run on the T3 server of the Node that owns that App instance. Each subtask gets a fresh implementer. The group keeps one reviewer thread. The reviewer writes sign-off commits before the next subtask and opens the pull request at the end. After the pull request exists, a following feature PR notifies Coder, then CLEAN, then DevOps merge and verify, and stores tokens, line diff, and duration on settle.

## Out of this slice

This page describes the first slice only. The items below stay unimplemented here.

- Real T3 spawn and the wire protocol
- Non-visitable App instance create
- Commander data migration and retiring Commander
- A web UI for tasks
- Per-App model overrides
