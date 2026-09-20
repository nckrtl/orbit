---
title: "ADR 0103: Absorb Commander tasks as a Gateway extension"
sidebarTitle: "0103 Absorb Commander tasks as a Gateway extension"
description: "Proposed. Commander feature work becomes a Gateway extension that stores TaskGroup and Task records, schedules them under concurrency ceilings, and exposes create, list, and show as MCP tools."
---

# ADR 0103: Absorb Commander tasks as a Gateway extension

Commander feature work moves into Orbit as the enable-gated Gateway extension `tasks`. A TaskGroup is one parent feature with an ordered list of Task subtasks. The Gateway owns persistence, concurrency, and the MCP create, list, and show surface. Agent spawn and non-visitable App instance create stay behind interfaces in this slice.

## Status

Proposed.

## Context

Commander owns feature decomposition, implementer and reviewer threads, and pull request handoff outside Orbit. Orbit already owns Apps, App instances, Routes, node access, and the MCP catalogue generated from the Gateway API ([ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)). Absorbing Commander work without a new forever-on core surface would either bury an experimental scheduler in every Gateway or force a second control plane to keep polling Nodes.

What matters is an enable-gated boundary, one App instance shared by a feature's subtasks, binary Gateway access for create, and concurrency that the Gateway can enforce without node-side polling.

This record does not reopen the product choices locked on 2026-09-20: the `tasks` extension, polymorphic `taskable` with App instance as the only v1 target, TaskGroup plus ordered Task, one fresh App instance per group, agents on the T3 server of the instance-owning Node, per-subtask implementer plus one long-lived reviewer thread, default models, post-PR Coder notify, and Gateway-owned ceilings of three active groups per App and ten per Node.

## Decision

- `tasks` is a Gateway extension, not a core forever-on family. Enable and disable persist on the Gateway settings scope. Create, add, list, and show refuse with `tasks.disabled` while the extension is off. Enable, disable, and status stay available so an operator can turn the surface on.
- A TaskGroup is the parent feature. It stores a brief (deliverables and acceptance), status, optional reviewer thread id, optional pull-request URL, a notify-Coder flag, default implementer and reviewer model names, and settle metrics (tokens, line diff, duration). Ordered Task rows are the subtasks. Each Task stores its own brief, status, optional implementer thread id, position, and settle metrics.
- `taskable_type` and `taskable_id` are a Laravel morph on the TaskGroup. v1 accepts only `App\Models\AppInstance`. A new decision is required before another morph target is stored. A group may exist with a null taskable until InstanceProvisioning assigns the shared App instance.
- One fresh App instance belongs to the group. Subtasks reuse it. Orbit monorepo groups use a non-visitable instance: an isolated checkout or worktree with no public URL. Real apps keep a subdomain so an operator can inspect. InstanceProvisioning receives that intent. This slice documents the shape and ships a no-op. A following feature PR extends App instance create. On pull-request merge or instance remove, cleanup includes Routes.
- Agents run on the T3 server of the Node that owns that App instance. Each subtask gets a fresh implementer. The group keeps one long-lived reviewer thread that receives "subtask done, review" handoffs. The reviewer creates commits as the sign-off receipt before the next subtask and opens the pull request with a final rollup. Default models are Codex Luna Lite (low) for the implementer and Claude Opus (high) for the reviewer. Per-App configuration is a follow-up decision. AgentSpawner is the boundary; this slice ships a no-op.
- After the pull request exists, a webhook notifies Coder, then CLEAN, then DevOps merge, deploy, and verify. The Gateway stores tokens, line diff, and duration on settle. The webhook path is out of this slice.
- The Gateway scheduler claims the next queued group that fits the ceilings: three active groups per App and ten active groups per Node. Active statuses are reserved, running, reviewing, and settling. A group without an App instance counts toward the App ceiling only. The Node ceiling applies once the morph points at an App instance. After MCP create, the Gateway claims immediately. There is no node-side poll and no web UI for create.
- MCP tools are the generated API tools for enable, disable, status, create, add, list, and show ([ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)). Create and add require Gateway access (`ServingNode::Gateway`): the caller is the active Gateway peer or a Node with a grant to the Gateway. List and show use collection access so any authorized peer can read. The MCP layer adds no extra identity.

## Rejected alternatives

- Core forever-on task tables: rejected because the scheduler, T3 spawn, and Commander cutover remain unfinished and must be switched off without a Gateway downgrade.
- A dedicated Taskable table or a required App instance foreign key: rejected because a second owner such as a Node-only chore needs the same morph, and a group must be stored before InstanceProvisioning assigns the instance.
- Node-side polling for capacity: rejected because the Gateway already knows App and Node placement and can claim in the create request.
- Web UI create: rejected because agents create through MCP, and a UI would invite a second unauthorized path.
- Queued Laravel jobs for claim: rejected because the Gateway keeps infrastructure work synchronous ([Gateway application boundaries](https://github.com/nckrtl/orbit/blob/main/apps/gateway/.ai/rules/app.md)). Claim runs in the create request and through a focused scheduler class.
- CLI-local extension only, with always-on Gateway routes: rejected because that Herdr pattern would leave task writes available to every authorized peer as soon as the API exists.

## Consequences

- An operator enables `tasks` on the Gateway, then an agent with Gateway access creates a group through MCP. The Gateway persists the group and claims it when ceilings allow.
- Slice 1 persists records, enforces enablement and ceilings, and no-ops instance create and T3 spawn. Reserved groups wait until a provisioner implementation assigns an instance. That is intentional.
- Non-visitable App instance create is a required follow-up. The InstanceProvisioning intent carries `visitable`. False means isolated worktree and no public Route. True means the usual inspect subdomain.
- Commander data migration and retiring the Commander app stay out of this decision.
- Authorization stays binary node access. This decision does not add Laravel policies or granular grants.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools), [ADR 0036](/decisions/0036-support-only-appinstances)
- Detail: [Tasks](/reference/tasks)
- Verify: `apps/gateway/tests/Feature/Api/TasksRoutesTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSchedulerTest.php`, `apps/gateway/tests/Feature/Database/TaskTablesMigrationTest.php`
