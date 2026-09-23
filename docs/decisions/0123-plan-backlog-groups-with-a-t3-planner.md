---
title: "ADR 0123: Plan Backlog groups with a T3 planner"
sidebarTitle: "0123 Plan Backlog groups with a T3 planner"
description: "Proposed. A Backlog group can start a planner thread in T3 that shapes the feature, writes its ADRs and documentation, manages the group through Orbit MCP, and moves it to Todo. The planner becomes the group's reviewer."
---

# ADR 0123: Plan Backlog groups with a T3 planner

A task group can start in Backlog with a planner. The Gateway gives the group its shared Instance and starts a planner thread through the T3 driver. The operator shapes the feature with the planner in T3. The planner writes the ADRs and documentation in the workspace and manages the group's title, brief, and subtasks through Orbit MCP. When the planner or the operator moves the group to Todo, Orbit commits the planner's work and runs the group in the same workspace. The planner thread becomes the group's reviewer.

## Status

Proposed.

This extends [ADR 0122](/decisions/0122-hold-task-groups-in-backlog-until-ready), where a Backlog group has no Instance and no agents. It amends the reviewer start of [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) and uses the driver boundary of [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) and the MCP server of [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools).

## Context

ADR 0122 holds a group in Backlog so its ADRs, documentation, and subtasks can be prepared before agents run. Preparation still happens outside Orbit: someone creates `task-{group id}` in a local checkout, writes the documents, pushes, and edits the subtasks through MCP.

The operator shapes many features while talking to an agent. The operator wants that conversation to start the planning and hand it to a dedicated thread, then keep thinking about the plan in T3, away from the session that started it.

Orbit MCP identifies a caller by its WireGuard address and applies the same directed Node access as the API. A Node with access to the Gateway can call every Gateway operation. The app-dev Node that holds Orbit's task workspaces already has that access.

The reviewer thread starts at the first handoff under ADR 0121. The planner holds the most context about the feature at exactly that point.

## Decision

A planner is an opt-in T3 thread that shapes a Backlog group through Orbit MCP and becomes its reviewer.

### Starting a planner

- `tasks:create` accepts `plan: true` for a group created in `backlog`. The Gateway then provisions the group's shared Instance at once, on the feature branch `task-{group id}`, and starts the planner thread through the T3 driver on that Instance's Node. The thread title is `Orbit task #{group id} · Planner: {title}`, so it appears in the operator's T3 client like every other task thread.
- `plan: true` with `status: todo` fails with `tasks.plan_requires_backlog` (HTTP 422). A group without `plan` behaves as in ADR 0122.
- The planner uses the group's reviewer model and effort. Planning requires the T3 driver for the reviewer role. Otherwise create fails with `tasks.planner_driver_unavailable` (HTTP 409) before storing a group.
- Placement for a planning group considers only app-dev Nodes with access to the Gateway, because the planner calls Orbit MCP from its Node. When none fits, create fails with `tasks.planner_node_unavailable` (HTTP 409) before storing a group. The planner's T3 agent needs Orbit MCP configured on that Node; Orbit does not configure it.
- The planner's opening prompt holds the group ID, title, and brief. It tells the planner to shape the feature with the operator, following the repository's own instructions for feature design, to manage the group and its subtasks through Orbit MCP, and to move the group to Todo when the operator agrees the plan is ready. Orbit's repository maps feature design to the `grill-with-docs` skill.
- Planning does not count toward the Node ceiling. Backlog groups never count, with or without an Instance.

### Managing the plan

- The planner and the operator change the group through the same operations: `tasks:update` for the title, brief, and status, and the subtask operations for the subtasks. The Gateway holds the only copy of the plan, so neither side overwrites the other.
- The ADRs and documentation stay as uncommitted changes in the workspace while the group is in Backlog.

### Moving to Todo

- The planner or the operator moves the group to Todo with `tasks:update`. Orbit applies no extra condition to a planning group.
- On that move, Orbit commits every workspace change on `task-{group id}` as `orbit <tasks@orbit>` with the message `Plan: {group title}`, then the group becomes `todo`. An empty workspace produces no commit. A failed commit leaves the group in Backlog and returns the error.
- The scheduler claim reuses the group's Instance instead of provisioning one.
- A planning group moved back to Backlog keeps its Instance, its planner, and its commits.

### The planner becomes the reviewer

- The planner thread is the group's reviewer thread from the start. At the first handoff, the scheduler sends the review request to it instead of starting a new reviewer. The review request states the change of role, and `.git/orbit/turn.json` names the reviewer role, so the script accepts only reviewer outcomes from then on.
- A group without a planner starts its reviewer at the first handoff, as in ADR 0121.

### Removal

- Cancelling a planning group removes its Instance, as cancellation already does for an Instance-backed group. The T3 conversation stays in T3 as history.

### Starting from a conversation

- A repository opts in to Orbit tasks with an instruction for its agents. The default is inline: the agent implements the work in its own session. When the operator asks for the work to be implemented in Orbit, the agent calls `tasks-create` with `plan: true` and a brief that summarizes the conversation, then gives the operator the group and its planner thread.
- Orbit's repository carries this instruction as a skill. Another repository adds a skill or an explicit instruction, as the [tasks reference](/reference/tasks) describes.

## Rejected alternatives

- The MCP caller writes the documents and the Gateway commits them: it works from any client, but the operator loses a dedicated thread for thinking about the plan, and nothing in the workspace checks the documents before the move to Todo.
- A plan file in the workspace that the Gateway reads: it avoids Gateway access for the planner, but it keeps a second copy of the plan that the operator's edits and the planner's edits overwrite.
- The planner posts typed comments to the Gateway: ADR 0121 removed that channel because the agent needs IDs it cannot know.
- Only the operator may move the group to Todo: the operator already tells the planner when the plan is ready, and a second step adds no safety.
- A fresh reviewer after planning: the planner holds the context of every decision. A long transcript may weaken its reviews; the group can revisit this with evidence from real runs.
- Counting planning groups toward the Node ceiling: a Backlog group never counts, and planning is an operator conversation, not scheduled work.
- A planner for every Backlog group: most groups need no planner, and each planner holds an Instance.

## Consequences

- A feature can move from a conversation to a planned, documented group without a local checkout.
- The operator has one T3 thread for the feature from the first idea through every review.
- The implementers start on a branch that already holds the committed ADRs and documentation.
- A planning group holds an Instance and a T3 thread on a Node while it waits in Backlog. Idle planning groups use Node resources that the ceiling does not see.
- The reviewer's context includes the planning conversation. If long transcripts weaken reviews, a new decision can start a fresh reviewer.
- Every agent on a planner's Node can call every Gateway operation. That is already true of the Node that holds Orbit's task workspaces.
- A planner's Node needs Orbit MCP configured for its T3 agent, which Orbit does not check.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0122](/decisions/0122-hold-task-groups-in-backlog-until-ready); amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt); uses [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) and [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)
- Detail: [Tasks](/reference/tasks)
- Verify: Gateway tests for create with `plan`, planning placement and its refusals, the move to Todo with its commit and Instance reuse, the first handoff to the planner thread, and cancellation; `bin/mcp-tools --check`; `composer docs-lint`
