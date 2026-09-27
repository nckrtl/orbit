---
title: "ADR 0170: Edit Todo subtasks after a group starts"
sidebarTitle: "0170 Edit Todo subtasks after start"
description: "Proposed. Operators can correct or cancel Todo subtasks after a group leaves Backlog, while started subtasks keep their existing rules."
---

# ADR 0170: Edit Todo subtasks after a group starts

Operators can correct the remaining plan after a task group leaves Backlog. In `todo`, `running`, `reviewing`, or `settling` groups, a `todo` subtask can have its title, brief, position, and deliverables changed, or be cancelled. Started subtasks keep their existing rules.

## Status

Proposed.

This amends ADR 0122's rule that limits subtask updates to groups in Backlog. It retains that ADR's rule that limits subtask deletion to Backlog. It also retains [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)'s exception to Backlog-only deliverable edits, but limits that exception to `todo`, `running`, `reviewing`, and `settling` groups. An operator can change a `todo` subtask's deliverables in those four statuses only. The exception does not permit edits in `completed` or `cancelled` groups; those groups remain read-only. ADR 0133's completed-subtask handoff contract does not change.

## Context

ADR 0122 lets operators edit subtask titles, briefs, and positions, or delete subtasks, only while a group is in Backlog. After a group started, the Gateway allowed changes only to deliverables on a `todo` subtask. Operators had no supported way to correct its title, brief, or place in the plan, or to cancel it. Cancellation applied only to a `running` subtask. This made an incorrect remaining plan difficult to repair without direct database edits.

A started subtask may already have an agent, review, or recorded work tied to its brief and deliverables. Editing or deleting it changes the record of work in progress. A `todo` subtask has not started, so operators can correct it without changing started work.

## Decision

- In a `todo`, `running`, `reviewing`, or `settling` group, operators can update a `todo` subtask's title, brief, position, and deliverables. Deliverables cannot be changed to an empty list.
- A position change reorders only `todo` subtasks. It cannot place a `todo` subtask before any started or finished subtask. Positions remain gapless.
- An update to a started subtask keeps today's rules: its title, brief, and position do not change, and its deliverables remain locked.
- Operators can cancel a `todo` subtask in those same group statuses. Cancellation sets the subtask to `cancelled` and records `settled_at`. It starts no subtask and does not ask for assistance.
- When cancelling a `todo` subtask leaves no other open subtask, including one that is reserved, running, or reviewing, the group settles as it does after a completed subtask.
- The existing Backlog editing rule remains: subtasks may be updated or destroyed in Backlog. Subtask creation remains available in all group statuses except `completed` and `cancelled`.
- Groups in `completed` or `cancelled` remain read-only.

## Rejected alternatives

- Keep the Backlog-only update rule: rejected because operators must be able to correct an unstarted part of the plan after work begins.
- Allow changes to started subtasks: rejected because their work and review may already depend on the stored plan.
- Move a Todo subtask across started subtasks: rejected because that would make the execution order differ from the plan's position order.
- Treat cancellation of a Todo subtask like cancellation of a running one: rejected because no implementer or check needs to be interrupted, and cancellation must not start another subtask immediately.

## Consequences

- Operators can repair the remaining plan through the supported task operations instead of direct database edits.
- Position changes preserve the boundary between the finished or started work and the remaining Todo plan.
- Cancellation can finish a group when it removes its last open subtask, without assistance or a new start.
- The update and cancel interfaces must distinguish an unstarted `todo` subtask from a started subtask and enforce the permitted group statuses.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: amends [ADR 0122](/decisions/0122-hold-task-groups-in-backlog-until-ready); preserves the deliverables rule in [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)
- Detail: [Tasks](/reference/tasks), [tasks CLI](/cli/tasks)
- Verify: Gateway and CLI tests for subtask update, position ordering, cancellation, and group settlement; `composer docs-lint`
