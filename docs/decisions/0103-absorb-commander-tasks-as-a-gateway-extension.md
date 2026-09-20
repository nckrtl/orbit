---
title: "ADR 0103: Absorb Commander tasks as a Gateway extension"
sidebarTitle: "0103 Absorb Commander tasks as a Gateway extension"
description: "Proposed. Commander feature work becomes a Gateway extension that stores TaskGroup and Task records, schedules them under concurrency ceilings, provisions a shared App instance, starts T3 agents, opens the pull request, notifies Coder on settle, and removes the instance on complete."
---

# ADR 0103: Absorb Commander tasks as a Gateway extension

Commander feature work moves into Orbit as the enable-gated Gateway extension `tasks`. A TaskGroup is one parent feature with an ordered list of Task subtasks. The Gateway owns persistence, concurrency, the MCP create, list, show, and complete surface, shared App instance provisioning, T3 spawn on the instance-owning Node, pull-request open, the opt-in Coder settle webhook, and instance removal after merge.

## Status

Proposed.

## Context

Commander owns feature decomposition, implementer and reviewer threads, and pull request handoff outside Orbit. Orbit already owns Apps, App instances, Routes, node access, and the MCP catalogue generated from the Gateway API ([ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)). Absorbing Commander work without a new forever-on core surface would either bury an experimental scheduler in every Gateway or force a second control plane to keep polling Nodes.

What matters is an enable-gated boundary, one App instance shared by a feature's subtasks, binary Gateway access for create, and concurrency that the Gateway can enforce without node-side polling.

This record does not reopen the product choices locked on 2026-09-20: the `tasks` extension, polymorphic `taskable` with App instance as the only v1 target, TaskGroup plus ordered Task, one fresh App instance per group, agents on the T3 server of the instance-owning Node, per-subtask implementer plus one long-lived reviewer thread, default models, post-PR Coder notify, and Gateway-owned ceilings of three active groups per App and ten per Node.

Slice 1 persisted records and enforced enablement and ceilings. Slice 2 provisioned the shared workspace and started T3 threads. This slice opens the pull request after the last reviewer sign-off, fills settle metrics, posts the opt-in Coder webhook, and removes the instance when an authorized caller completes the group.

## Decision

- `tasks` is a Gateway extension, not a core forever-on family. Enable and disable persist on the Gateway settings scope. Create, add, list, show, and complete refuse with `tasks.disabled` while the extension is off. Enable, disable, and status stay available so an operator can turn the surface on.
- A TaskGroup is the parent feature. It stores a brief (deliverables and acceptance), status, optional reviewer thread id, optional pull-request URL (`pr_url`), a notify-Coder flag (`notify_coder`, Commander's `notify_on_settle`), default implementer and reviewer model names, and settle metrics (tokens, line diff, duration). Ordered Task rows are the subtasks. Each Task stores its own brief, status, optional implementer thread id, position, and settle metrics.
- `taskable_type` and `taskable_id` are a Laravel morph on the TaskGroup. v1 accepts only `App\Models\AppInstance`. A new decision is required before another morph target is stored. A group may exist with a null taskable until InstanceProvisioning assigns the shared App instance.
- One fresh App instance belongs to the group. Subtasks reuse it. `TaskWorkspaceProvisioner` implements InstanceProvisioning. It places the instance on an active Linux Node that has an active `app-dev` role and is under the Node ceiling. Orbit monorepo groups (`app.slug === 'orbit'`) use a non-visitable instance: an isolated checkout on a feature branch with no Route. That instance stays `source_resolved` because an active AppInstance still requires exactly one Route. Real apps stay visitable: the usual development provisioner creates the inspect subdomain and activates the instance. When no eligible Node exists, or source defaults are incomplete, the provisioner returns null and the group stays `reserved`.
- Agents run on the T3 server of the Node that owns that App instance. `T3AgentSpawner` implements AgentSpawner. It talks to that Node over WireGuard with stock T3 HTTP: `POST /api/orchestration/dispatch`. The body is a flat command (`type` at the top level, not nested) and always includes `headers: []`. Each subtask gets a fresh implementer. The group keeps one long-lived reviewer thread that receives "please review" handoffs. The reviewer creates a git commit in the shared checkout as the sign-off receipt before the next subtask. Default models are Codex Luna Lite (low) for the implementer and Claude Opus (high) for the reviewer. Per-App configuration is a follow-up decision.
- After the last subtask is signed off, the group moves to `settling`. That is the reviewable state: the Gateway opens the GitHub pull request, stores `pr_url`, writes tokens, line diff, and duration, and posts the Coder webhook when `notify_coder` is true. The Gateway opens the PR from the reviewer workspace with `gh pr create` when `gh` can, and otherwise through the GitHub HTTP API when `ORBIT_TASKS_GITHUB_TOKEN` covers the App repository. A refused open leaves `pr_url` empty and keeps the group `settling` so create does not fail closed.
- The opt-in Coder webhook mirrors Commander's `notify_on_settle`. Create accepts `notify_coder` or `notify_on_settle`. The Gateway POSTs a JSON body to `ORBIT_CODER_WEBHOOK_URL` and signs `timestamp.body` with HMAC-SHA256 using `ORBIT_CODER_WEBHOOK_SECRET`. The headers are `X-Orbit-Timestamp` and `X-Orbit-Signature: sha256=…`. The body carries tokens, line diff, duration, and the pull-request URL. A missing URL or secret, or a refused Coder response, skips notify and does not fail settle.
- `tasks:complete` is the merge and explicit-complete path. An authorized Gateway caller marks a `settling` group `completed` and removes its App instance through the existing App instance remover, including any visitable Routes. A second complete is idempotent. The Gateway App receives no GitHub merge webhook ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
- The Gateway scheduler claims the next queued group that fits the ceilings: three active groups per App and ten active groups per Node. Active statuses are reserved, running, reviewing, and settling. A group without an App instance counts toward the App ceiling only. The Node ceiling applies once the morph points at an App instance. After MCP create, the Gateway claims immediately. Provision and T3 HTTP run after the reserve write so a long checkout or dispatch does not hold the claim lock. There is no node-side poll and no web UI for create.
- MCP tools are the generated API tools for enable, disable, status, create, add, list, show, and complete ([ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)). Create, add, and complete require Gateway access (`ServingNode::Gateway`): the caller is the active Gateway peer or a Node with a grant to the Gateway. List and show use collection access so any authorized peer can read. The MCP layer adds no extra identity.

## Rejected alternatives

- Core forever-on task tables: rejected because the scheduler, T3 spawn, and Commander cutover remain unfinished and must be switched off without a Gateway downgrade.
- A dedicated Taskable table or a required App instance foreign key: rejected because a second owner such as a Node-only chore needs the same morph, and a group must be stored before InstanceProvisioning assigns the instance.
- Node-side polling for capacity: rejected because the Gateway already knows App and Node placement and can claim in the create request.
- Web UI create: rejected because agents create through MCP, and a UI would invite a second unauthorized path.
- Queued Laravel jobs for claim: rejected because the Gateway keeps infrastructure work synchronous ([Gateway application boundaries](https://github.com/nckrtl/orbit/blob/main/apps/gateway/.ai/rules/app.md)). Claim runs in the create request and through a focused scheduler class.
- CLI-local extension only, with always-on Gateway routes: rejected because that Herdr pattern would leave task writes available to every authorized peer as soon as the API exists.
- Marking a non-visitable instance `active` without a Route: rejected because SQLite still enforces one Route on every active AppInstance.
- Wrapping T3 dispatch in a nested `command` object: rejected because stock T3 expects a flat dispatch body and an explicit `headers` array.
- Opening pull requests through the Gateway-owned GitHub App: rejected because that App is read-only ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)). Writes stay with `gh` in the reviewer workspace or a dedicated `ORBIT_TASKS_GITHUB_TOKEN`.
- An inbound GitHub merge webhook for cleanup: rejected because the Gateway App receives no webhooks. Complete is an explicit MCP and API path that reuses App instance removal.

## Consequences

- An operator enables `tasks` on the Gateway, then an agent with Gateway access creates a group through MCP. The Gateway persists the group and claims it when ceilings allow.
- A claimed group leaves `reserved` when the provisioner assigns an instance on a Node that still has capacity and T3 accepts the reviewer and first implementer spawn. Missing Nodes, incomplete App source defaults, or a refused T3 dispatch leave the group `reserved` or `running` without thread ids so create does not fail closed on a down T3 server.
- After the last sign-off the group is `settling` and ready for Coder review. A successful PR open stores `pr_url`. Opt-in Coder notify uses the documented HMAC headers and environment keys.
- `tasks:complete` removes the instance and any visitable Routes after merge. Non-visitable Orbit workspaces have no public URL.
- Commander data migration and retiring the Commander app stay out of this decision.
- Authorization stays binary node access. This decision does not add Laravel policies or granular grants.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools), [ADR 0036](/decisions/0036-support-only-appinstances), [ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)
- Detail: [Tasks](/reference/tasks)
- Verify: `apps/gateway/tests/Feature/Api/TasksRoutesTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSchedulerTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskWorkspaceProvisionerTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/T3AgentSpawnerTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/CompleteTaskGroupActionTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/HttpT3DispatcherTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/HttpCoderSettleNotifierTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/HttpGitHubTaskPullRequestOpenerTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/RemoteTaskPullRequestOpenerTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/RemoteTaskWorkspaceDiffReaderTest.php`, `apps/gateway/tests/Feature/Database/TaskTablesMigrationTest.php`
