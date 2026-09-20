---
title: "Tasks"
description: "How the Gateway tasks extension stores TaskGroup features, provisions a shared App instance, and starts T3 reviewer and implementer threads."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension stores a Commander-style feature group, provisions its shared App instance, and starts T3 agents for the ordered subtasks. [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the architectural choices.

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

A claimed group moves from `queued` to `reserved`. InstanceProvisioning then assigns the shared App instance on an active Linux `app-dev` Node that still has capacity. When that assignment fits the Node ceiling, the group becomes `running`, AgentSpawner starts the long-lived reviewer and the first implementer, and the Gateway stores the thread ids. When no eligible Node exists, or T3 refuses the spawn, the group stays `reserved` or `running` without thread ids.

## Shared App instance

One fresh App instance belongs to the group. Every subtask reuses it. The instance name and feature branch are `task-{group id}`.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work (`app.slug` is `orbit`) | Isolated checkout on the feature branch. No Route and no public URL. The instance stays `source_resolved` |
| `visitable: true` | A real App | Usual development provisioner and inspect subdomain. The instance becomes `active` |

The provisioner honors `visitable`. It does not invent a Route for a non-visitable workspace because an active AppInstance still requires exactly one Route. A following feature PR removes the instance and any visitable Routes after merge.

## T3 agents

Agents run on the T3 server of the Node that owns that App instance. The Gateway posts a flat command to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with `headers: []` on every body. `ORBIT_T3_PORT` defaults to `3773`. `ORBIT_T3_TOKEN` is an optional bearer for that Node's T3 server.

Each subtask gets a fresh implementer (`codex-luna-lite`, low effort). The group keeps one reviewer thread (`claude-opus`, high effort). When a subtask settles, the scheduler marks it `reviewing` and sends "please review" to the reviewer thread. After the reviewer signs off, the Gateway commits in the shared checkout when git can create a commit, completes that subtask, and starts the next implementer. After the last subtask, the group moves to `settling`.

## Out of this slice

This page describes the provision and spawn slice. The items below stay unimplemented here.

- Coder settle webhook and token, line diff, and duration fill
- Pull-request open and the final reviewer rollup
- Instance and Route removal on merge
- Commander data migration and retiring Commander
- A web UI for tasks
- Per-App model overrides
