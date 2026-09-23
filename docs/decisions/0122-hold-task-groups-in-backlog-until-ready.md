---
title: "ADR 0122: Hold task groups in Backlog until they are ready"
sidebarTitle: "0122 Hold task groups in Backlog until ready"
description: "Proposed. A task group starts in Backlog, where its branch, ADRs, documentation, and subtasks are prepared. The scheduler claims only Todo groups. The queued and pending statuses become todo."
---

# ADR 0122: Hold task groups in Backlog until they are ready

A new task group starts in Backlog. The operator and an agent prepare its branch, ADRs, documentation, and subtasks there. The scheduler never claims a Backlog group. Moving the group to Todo makes it ready, and the scheduler claims it as before. The group status `queued` and the subtask status `pending` become `todo`, so each board lane and its status use the same word.

## Status

Proposed.

This amends the claim rule in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), which claims a group immediately after create. It applies the Backlog and Todo meaning from [ADR 0010](/decisions/0010-record-decisions-before-implementation-issues) to task groups and follows the verb vocabulary in [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk).

## Context

The [contributor guide](/contributor-guide) orders feature work as architecture, then documentation, then implementation. A task group skips that order. `tasks:create` stores the group and its subtasks and claims it in the same request. Agents start on a branch that the provisioner creates from the default branch. Nobody checks the decomposition before the first implementer runs, and the agent prompts do not mention ADRs or documentation. The operator first sees the result when the group reaches `settling` with a pull request.

A subtask for ADRs and documentation does not fix this. The low-effort implementer would write the decisions, and nothing stops the group between that subtask and the code.

The operator needs a place where a group exists but does not run. The group id must exist in that place, because the shared branch is named `task-{group id}`. The provisioner already checks out `origin/task-{group id}` when that branch exists.

ADR 0010 already defines these states for issues. Backlog records a request whose contract is incomplete. Todo records a complete contract that is ready to implement. The task board shows Todo, but the backend stores `queued` for groups and `pending` for subtasks.

## Decision

- A task group has the status `backlog` before it is ready. The scheduler never claims a `backlog` group.
- The group status `queued` becomes `todo`. The subtask status `pending` becomes `todo`. A forward migration rewrites stored rows. The API does not accept the old values as aliases.
- `tasks:create` accepts an optional `status` of `backlog` or `todo`. It defaults to `backlog`. Create claims a group only when it stores it as `todo`.
- A group enters `todo` only with at least one subtask. Otherwise the request fails with `tasks.no_subtasks` (HTTP 422).
- `tasks:update` changes a group's `title`, `brief`, or `status`. Title and brief change only while the group is in `backlog`. Otherwise the request fails with `tasks.not_in_backlog` (HTTP 409).
- `tasks:update` moves a group between `backlog` and `todo` in either direction. Moving to `todo` runs a claim, as create does today. A group that the scheduler has claimed cannot move, and the request fails with `tasks.already_claimed` (HTTP 409). A move and a claim cannot both succeed.
- Subtasks follow the create, update, and destroy verbs of ADR 0071 because the Gateway brings them into existence. `tasks:subtask:create` replaces `tasks:add`. `tasks:subtask:update` changes a subtask's `title`, `brief`, or `position`. `tasks:subtask:destroy` deletes a subtask. Positions stay a gapless sequence starting at 1.
- `tasks:subtask:update` and `tasks:subtask:destroy` work only while the group is in `backlog`. Otherwise they fail with `tasks.not_in_backlog`. `tasks:subtask:create` keeps today's rule and appends to a group in any status.
- `tasks:cancel` also cancels a `backlog` group. The group has no Instance to remove.
- The operator prepares the feature on `task-{group id}` in a worktree and pushes it before moving the group to Todo. The Gateway does not check the branch contents. A group without a pushed branch still runs on a fresh branch from the default branch.
- The implementer and reviewer opening prompts name the ADRs and documentation that the branch changes against the default branch as the feature's contract. The reviewer checks each subtask against them.
- The web board adds a Backlog lane before Todo. Todo shows `todo` groups. The subtask board keeps Todo, In progress, and Done, and Todo shows `todo` subtasks. The board stays read-only. Moving a group is an API and MCP operation.

## Rejected alternatives

- Claim on create and prepare nothing: rejected because agents build before the ADRs, documentation, and decomposition exist, and the operator sees the plan only as a pull request.
- A first subtask for ADRs and documentation: rejected because a low-effort implementer writes the decisions and nothing stops the group before the code.
- A draft flag on a Todo group: rejected because two fields would describe one lifecycle fact. A running group would carry a flag that means nothing, the scheduler would read two fields, and a draft card would sit in Todo without being claimed.
- Keep `queued` and `pending` and relabel the lanes: rejected because the lane, the API filter, and the database would use different words for the same state.
- A separate start verb: rejected because the move is a partial change to the group, which ADR 0071 names `update`.
- Keep `tasks:add` for subtasks: rejected because ADR 0071 reserves add for associations between things that exist independently, and a subtask exists only in its group.
- Require a pushed branch before Todo: rejected because small groups need no ADR or documentation change, and the Gateway cannot judge whether a branch is ready.

## Consequences

- Nothing starts by accident. A caller that wants an immediate run passes `status: todo` with its subtasks.
- The operator can fix titles, briefs, order, and the subtask list before any agent runs.
- Agents start on a branch that already holds the feature's ADRs and documentation, so implementation follows the contributor guide order.
- Board lanes, list filters, and stored statuses use the same words.
- Callers that pass `status=queued` to `tasks:list` or call `tasks:add` break. The callers are this repository's agents and the MCP catalogue, which regenerates from the API.
- The migration is forward-only. Returning to an older Gateway requires a database backup.
- A Todo group whose branch holds no preparation runs as today. Readiness is the operator's judgment.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension); applies [ADR 0010](/decisions/0010-record-decisions-before-implementation-issues) and [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk)
- Detail: [Tasks](/reference/tasks)
- Verify: Gateway tests for create, update, subtask create, update, destroy, cancel, the claim rule, and the status migration; `apps/web/src/api/tasks.test.ts`; `bin/docs-openapi`; `bin/mcp-tools --check`; `composer docs-lint`
