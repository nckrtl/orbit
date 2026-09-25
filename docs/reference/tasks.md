---
title: "Tasks"
description: "How the Gateway tasks extension holds TaskGroup features in Backlog and runs Todo groups on a shared Instance. Agents end turns with run receipts, and Orbit commits approved work and opens the pull request."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension runs a Commander-style feature group. A group waits in Backlog while its branch, ADRs, documentation, and subtasks are prepared. Once the group is in Todo, the Gateway provisions its shared Instance, starts agents, and moves each task from turn to turn with run receipts and mechanical checks. It commits approved work, opens and watches the pull request, retains capacity through assistance and merge wait, and removes the instance after completion.

[ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the extension boundary. [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev) owns session routing. [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) owns completion gates. [ADR 0122](/decisions/0122-hold-task-groups-in-backlog-until-ready) owns Backlog and Todo. [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner) owns planning.

The extension is off until an authorized Gateway caller enables it. There is no web UI for create. Agents create groups through the [MCP server](/reference/mcp). The [`tasks` CLI family](/cli/tasks) runs every operation on this page from a terminal when MCP is unavailable.

[ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) defines the `AgentThread` and `AgentDriver` boundary. Orbit stores persistent conversations and delegates runtime communication to a driver. T3 is the first driver.

## Enable the extension

Enable and disable require Gateway access: the active Gateway peer, or a Node with a grant to the Gateway.

| Operation | Route | Effect |
| --- | --- | --- |
| `tasks:enable` | `POST /api/v1/tasks/enable` | Turns the extension on. Idempotent. |
| `tasks:disable` | `POST /api/v1/tasks/disable` | Turns the extension off. Existing rows stay. Further group and subtask operations return `tasks.disabled`. |
| `tasks:status` | `GET /api/v1/tasks/status` | Returns whether the extension is enabled. |

Every group and subtask operation below refuses with `tasks.disabled` and HTTP 409 while the extension is off.

## Model

A **TaskGroup** is one parent feature. A **Task** is an ordered subtask. Each row stores a brief with its goal and acceptance. A subtask also stores a typed list of [deliverables](#deliverables) that Orbit checks at handoff.

| Field | Record | Meaning |
| --- | --- | --- |
| `title` | both | Short name |
| `brief` | both | Goal and acceptance |
| `deliverables` | Task | Typed items the subtask must deliver. An empty list for subtasks created before deliverables existed |
| `status` | both | Lifecycle state |
| `position` | Task | Order inside the group, starting at 1 |
| `taskable_type` / `taskable_id` | TaskGroup | Morph. v1 is an Instance only. Null until the scheduler assigns one |
| `reviewer_agent_thread_id` | TaskGroup | Long-lived reviewer thread for the group |
| `implementer_agent_thread_id` | Task | Fresh implementer thread for that subtask |
| `pr_url` | TaskGroup | Pull request Orbit opened after the last approval |
| `notify_coder` | TaskGroup | Opt-in Coder settle webhook. Create also accepts Commander's `notify_on_settle` |
| `implementer_model` / `reviewer_model` | TaskGroup | `ORBIT_TASKS_IMPLEMENTER_MODEL` and `ORBIT_TASKS_REVIEWER_MODEL` set them for new groups. Unset, they default to `gpt-5.6-luna` (Codex instance `codex`) and `claude-opus-5` (Claude instance `claudeAgent`) |
| `tokens`, `line_diff`, `duration_ms` | both | Filled on settle and refreshed when an active group is shown |

Group statuses: `backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`, `completed`, `failed`, `cancelled`. Task statuses: `todo`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`.

`backlog` means the group is being prepared, and the scheduler never claims it. `todo` means the group is ready and waits for the scheduler. `settling` is the reviewable state: the pull request is open or the Gateway has finished the open attempt, and Coder may review.

v1 attaches the group to one Instance. A new decision is required before another morph target is stored.

## Groups and subtasks

Use these operations after the extension is enabled. List and show accept any authorized peer. Update and the subtask operations are served by the Node that holds the group's Instance, or by the Gateway for a group without one. The other operations require Gateway access.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:update` | `PATCH /api/v1/task-groups/{group}` | Group workspace Node |
| `tasks:list` | `GET /api/v1/task-groups` | Collection |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Collection |
| `tasks:complete` | `POST /api/v1/task-groups/{group}/complete` | Gateway |
| `tasks:subtask:create` | `POST /api/v1/task-groups/{group}/tasks` | Group workspace Node |
| `tasks:subtask:update` | `PATCH /api/v1/task-groups/{group}/tasks/{task}` | Group workspace Node |
| `tasks:subtask:destroy` | `DELETE /api/v1/task-groups/{group}/tasks/{task}` | Group workspace Node |
| `tasks:subtask:cancel` | `POST /api/v1/task-groups/{group}/tasks/{task}/cancel` | Gateway |
| `tasks:cancel` | `POST /api/v1/task-groups/{group}/cancel` | Gateway |
| `tasks:comment:create` | `POST /api/v1/task-groups/{group}/tasks/{task}/comments` | Gateway |
| `tasks:comment:list` | `GET /api/v1/task-groups/{group}/tasks/{task}/comments` | Gateway |

Create requires `app_id`, `title`, and `brief`. It may include an ordered `tasks` array of `{title, brief, deliverables}` objects, a `status` of `backlog` or `todo`, `plan: true` to start a [planner](#plan-a-group-with-a-planner), and either `notify_coder` or `notify_on_settle`. The status defaults to `backlog`. List accepts optional `app_id` and `status` query filters. Show returns the group and its tasks in position order. The group and each subtask include `assistance_requested` and `assistance_reason`, so a stalled group shows why it waits. Complete marks a `settling` group `completed` and removes its Instance.

Update changes a group's `title`, `brief`, or `status`. Title and brief change only while the group is in `backlog`. The status moves between `backlog` and `todo` in either direction. Moving to `todo` asks the scheduler to claim, as create does.

Subtask create appends one subtask at the next position with status `todo`. It works in any group status, and it accepts `deliverables`. Outside `backlog`, a new subtask needs at least one deliverable. Subtask update changes `title`, `brief`, `position`, or `deliverables`, and the other subtasks shift to keep positions gapless from 1. A `deliverables` value replaces the whole list. Subtask destroy deletes the subtask and closes the gap. Subtask update and destroy work only while the group is in `backlog`, with one exception: the deliverables of a `todo` subtask can change in any group status.

Cancel a `running` subtask with `tasks:subtask:cancel`, or run `orbit tasks:subtask:cancel {group} {subtask}`. Only a `running` subtask can be cancelled; another status returns HTTP 409 `tasks.subtask_not_running`. Cancellation interrupts that subtask's implementer and stops its running [baseline or handoff check](#project-check). It keeps the group and its Instance and starts the lowest-position `todo` subtask. When no implementer has started in the group yet, that subtask runs the baseline check first. When no `todo` subtask remains, the group moves to `settling`.

Orbit checks the subtask status before it stops anything. It interrupts the implementer first and then stops the check. It holds no database lock while it waits for the agent or the Node, so other Gateway writes continue. When the implementer or check cannot be stopped, cancellation returns HTTP 502 `tasks.subtask_interrupt_failed` and leaves the subtask and its check `running`, so an operator can retry. When the implementer's Node stays unreachable, cancel the group instead. A `cancelled` or `failed` subtask does not block the next subtask.

When the check cannot be stopped, the implementer may already be interrupted. The subtask stays `running` without a working implementer, and the next tick may remind the implementer or fail the subtask. Retry the cancel, or cancel the group.

After both stops succeed, Orbit records the cancel only when the subtask is still `running`. When a tick moved it on while Orbit stopped it, for example to `reviewing`, that new state stands and cancellation returns HTTP 409 `tasks.subtask_not_running`. The implementer and check were still stopped. Cancel the subtask again in its new state, or cancel the group.

When cancellation leaves no `todo` subtask, the group moves to `settling` without a `pr_url`. Orbit opens a pull request only after the last subtask is approved, so the group asks for assistance. Use `tasks:cancel` to end it. For a group with an approved subtask, cancellation first pushes the workspace HEAD to `task-{group id}` on `origin`, so the approved commits stay on the branch. Open a pull request from that branch if you want to keep the work.

Cancellation does not reset the shared checkout. The cancelled implementer's uncommitted edits stay in the shared checkout, and the next approval commits them.

Moving a group to `todo`, by create or update, needs at least one deliverable on every subtask.

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.no_subtasks` | 422 | Create with `status: todo`, or update to `todo`, on a group without subtasks |
| `tasks.subtask_deliverables_missing` | 422 | Create with `status: todo`, or update to `todo`, while a subtask has no deliverables; or subtask create without deliverables outside `backlog`. `details` names each subtask |
| `tasks.not_in_backlog` | 409 | Group title or brief update, or subtask title, brief, or position update or destroy, outside `backlog` |
| `tasks.deliverables_locked` | 409 | Subtask deliverables update outside `backlog` for a subtask that has started |
| `tasks.already_claimed` | 409 | Status update on a group the scheduler has already claimed |
| `tasks.plan_requires_backlog` | 422 | Create with `plan: true` and `status: todo` |
| `tasks.planner_driver_unavailable` | 409 | Create with `plan: true` when the reviewer driver is not T3 |
| `tasks.planner_node_unavailable` | 409 | Create with `plan: true` when no app-dev Node with access to itself fits |
| `tasks.planner_unavailable` | 409 | Create with `plan: true` when the T3 driver refuses the planner thread |
| `tasks.commit_failed` | 409 | Update to `todo` on a planning group when Orbit cannot commit its workspace |

A status update and a scheduler claim cannot both succeed. When the claim wins, the update returns `tasks.already_claimed`.

MCP tool names follow the API operation identifiers: `tasks-create`, `tasks-update`, `tasks-list`, `tasks-show`, `tasks-cancel`, `tasks-complete`, `tasks-subtask-create`, `tasks-subtask-update`, `tasks-subtask-destroy`, `tasks-subtask-cancel`, `tasks-comment-create`, `tasks-comment-list`, `tasks-agents`, `tasks-enable`, `tasks-disable`, and `tasks-status`. Each CLI command carries the operation's route name, such as `orbit tasks:subtask:create`.

## Deliverables

A deliverable is one item that a subtask must deliver, in a form Orbit can check. The planner or operator writes them next to the brief. The implementer confirms each one when it hands off. Orbit then verifies the mechanical ones before the reviewer starts. [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff) records the decision.

Each deliverable is an object with an `id`, a `type`, a `description`, and the fields of its type:

| Type | Fields | Orbit checks |
| --- | --- | --- |
| `file` | `path`: a path or glob from the workspace root. `change`: `created`, `modified`, or `any` | A matching path in the subtask's diff, added for `created`, modified for `modified`, either for `any` |
| `test` | `project`: the project directory, such as `apps/gateway`, or `.` for the root. `file`: the Pest test file in that project. `name`: a substring of the test name | The test file is added or modified in the diff, and Orbit's run of that file has at least one test whose name contains `name`, all passing |
| `command` | `command`: the command to run. `directory`: where to run it, relative to the workspace root; default `.` | Orbit's run of the command exits with 0 |
| `review` | none | The reviewer confirms it in its approval |

```json
[
  {"id": "reference-page", "type": "file", "description": "Document the export in the tasks reference", "path": "docs/reference/tasks.md", "change": "modified"},
  {"id": "export-test", "type": "test", "description": "A feature test for the export", "project": "apps/gateway", "file": "tests/Feature/ExportTest.php", "name": "exports every subtask"},
  {"id": "web-tests", "type": "command", "description": "The web app tests pass", "command": "bun test", "directory": "apps/web"},
  {"id": "error-copy", "type": "review", "description": "Error messages name the failing subtask"}
]
```

| Rule | Limit |
| --- | --- |
| `id` | A lowercase slug such as `export-test`, at most 64 characters, unique within the subtask |
| `description` | At most 500 characters |
| `path`, `file`, `project`, `directory` | Relative paths without `..`. At most 500 characters |
| `name` | At most 200 characters |
| `command` | At most 1000 characters |
| Number | At least one and at most five per subtask. Split a subtask that needs more; the [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) skill explains how. |

A field that belongs to another type is refused. In a `path`, `*` matches within one directory, `**` matches across directories, and `?` matches one character.

The subtask's diff runs from its start commit to the working tree that Orbit's check sees, uncommitted and untracked files included. Orbit records the start commit when the subtask starts. Deleted and ignored files never match.

### Confirm deliverables

The Gateway writes the subtask's deliverables into `.git/orbit/turn.json` before each turn. The agent confirms each one with `--deliverable=ID=evidence`, where the evidence says where or how it is met:

```bash
.git/orbit/run --outcome=ready_for_review --summary="Added the export" \
  --deliverable=reference-page="Export section in docs/reference/tasks.md" \
  --deliverable=export-test="tests/Feature/ExportTest.php covers every subtask"
```

| Turn | Needs |
| --- | --- |
| Implementer `ready_for_review` | A confirmation for every deliverable |
| Reviewer `approved` | A confirmation for every `review` deliverable. Other IDs are allowed |

The script refuses a missing confirmation, an unknown ID, an ID given twice, empty evidence, and `--deliverable` with any other outcome. The receipt stores the confirmations as `deliverables`, and the Gateway stores them on the receipt's comment. The implementer prompt and each review request list the subtask's deliverables.

### Verify deliverables

When every other item passes, Orbit runs its [Project check](#project-check) with the deliverables. After the Project's task check passes, or at once when the Project has none, the check script records the diff, runs each `test` file with `vendor/bin/pest FILE --log-junit=…` in its project, and runs each `command` in a login shell. A run that names a file turns off Pest's test impact analysis, so a cached result never counts. The check keeps the end of each command's output.

The `deliverables` item fails when a confirmation is missing or a deliverable does not pass. The reminder names each failing deliverable and why, and the assistance reason repeats it. Like every item, it gets one reminder per completion attempt, then asks for assistance. The reviewer starts only when every deliverable passes.

A subtask with no deliverables skips these steps. Groups that left Backlog before deliverables existed keep running that way. To add deliverables to such a group, update its `todo` subtasks.

## Prepare a group in Backlog

A group in Backlog without a planner has an id but no Instance and no agents. Use that time to shape the feature before any agent runs. To shape it with an agent in T3 instead, [plan the group with a planner](#plan-a-group-with-a-planner).

1. Create the group. It starts in `backlog`.
2. In a worktree, create the branch `task-{group id}` from the Project default branch.
3. Write the feature's ADRs and documentation on that branch, following the [contributor guide](/contributor-guide). Push the branch.
4. Split the work into subtasks with the [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) skill. Each subtask has one concise goal and at most five deliverables. Its brief cites the ADRs and documentation it implements.
5. Give each subtask its [deliverables](#deliverables).
6. Move the group to `todo` with `tasks:update`.

The provisioner checks out the pushed `task-{group id}` branch for the shared Instance. The implementer and reviewer prompts name the ADRs and documentation that this branch changes as the feature's contract. The reviewer prompt also says that the implementer has no web access, and asks the reviewer to confirm framework and library usage against current documentation for the Project's versions. The Gateway does not check the branch contents. A group without a pushed branch runs on a fresh branch from the default branch.

## Plan a group with a planner

A planner is a T3 thread that shapes a Backlog group with you. It writes the feature's ADRs and documentation in the group's workspace and manages the group through Orbit MCP. When the plan is ready, it becomes the group's reviewer.

1. Create the group with `plan: true`. The Gateway provisions the shared Instance on `task-{group id}` and starts the planner. The thread `Orbit task #{group id} · Planner: {title}` appears in your T3 client.
2. Shape the feature with the planner in that thread. It follows the repository's instructions for feature design. In Orbit's repository, that is the `grill-with-docs` skill.
3. The planner keeps the title, brief, and subtasks current through the same operations you use.
4. The planner gives each subtask at least one [deliverable](#deliverables). Each explicit item of the brief becomes one.
5. When you agree the plan is ready, the planner moves the group to `todo`, or you do.
6. Orbit commits every workspace change on `task-{group id}` as `orbit <tasks@orbit>` with the message `Plan: {group title}`. The scheduler then claims the group in the same Instance.

At the first review handoff, the scheduler sends the review request to the planner thread instead of starting a new reviewer. You keep one thread for the feature from the first idea through every review.

| Rule | Behavior |
| --- | --- |
| Driver | The planner uses the reviewer's T3 driver, model, and effort |
| Placement | App-dev Nodes with access to themselves or to the Gateway; the one with the fewest active groups wins |
| MCP | Orbit writes an untracked `.mcp.json` for the Gateway's MCP server into the workspace and excludes it from Git; a tracked `.mcp.json` stays unchanged |
| Node ceiling | A Backlog group does not count, with or without an Instance |
| Uncommitted work | The ADRs and documentation stay uncommitted until the move to `todo`; an empty workspace produces no commit |
| Failed start | When no Node fits or the planner thread cannot start, create removes any Instance and stores no group |
| Failed commit | The group stays in Backlog and the update returns `tasks.commit_failed` |
| Back to Backlog | The group keeps its Instance, planner, and commits |
| Cancel | Removes the Instance and its checkout; the conversation stays in T3 |

A Node holds planners once it has access to itself, for example after [`node:access:add`](/cli/node#orbit-nodeaccessadd) from the Node to itself. Every agent on that Node can then change the task groups whose workspace it holds.

### Start planning from a conversation

Agents implement work in their own session unless you ask for Orbit. Give a repository's agents that rule with a skill or an explicit instruction, such as:

> Implement work inline in this session by default. When the operator asks to implement something in Orbit, call `tasks-create` through Orbit MCP with this Project's `app_id`, a short title, a brief that summarizes the conversation, and `plan: true`. Then tell the operator the group ID and the planner thread title, and stop working on the feature here.

Orbit's repository carries this rule as the `implementing-in-orbit` skill.

## Web task board

Open **Tasks** in the web navigation to see all tracked task groups. Each card shows its title and the Project’s saved code of three capital letters, followed by the task number, such as `ORB-13`.

Codes are unique across Projects. Edit a code in the Project properties; changing it updates card labels without changing task IDs or URLs.

Cards show separate added and deleted line counts when available, an uppercase status outside Backlog and Todo, and elapsed duration in minutes and hours.

Select a card to read the task brief, its status, tokens, line diff, duration, and its subtasks. Subtasks use their own Todo, In progress, and Done board. `todo` subtasks appear in Todo; reserved, running, and reviewing subtasks appear in In progress. Completed, failed, and cancelled subtasks appear in Done with their outcomes visible. Cards retain their sequence numbers and briefs. Subtask cards show that subtask's tokens and line diff when the Gateway has observed them.

Select a subtask to open its own detail page with its title, brief, status, Project, shared Instance, tokens, line diff, and duration. The subtask detail omits the subtasks board. Use the parent task breadcrumb to return to the board.

The board stays current from [task events](/reference/web-app#live-tasks) while realtime is live, with a refetch every 5 minutes as a safety net, and polls every 30 seconds while realtime is not live. Backlog contains groups that are still being prepared. Todo contains groups that wait for the scheduler. In progress contains reserved, running, reviewing, and settling groups. Settling means awaiting completion after review and merge. Done contains completed, failed, and cancelled groups; each card keeps its outcome visible. Failed and cancelled do not mean successful completion.

The board is read-only. Move a group from Backlog to Todo with `tasks:update`. The Gateway still owns scheduling and concurrency. When the extension is disabled, the page explains that tasks are unavailable. Request errors remain visible instead of appearing as an empty board.

### Tokens and line diff

When the parent task is open, Tokens is the total for the current implementer of each subtask plus the shared reviewer. Line diff is the whole feature branch against the Project default branch. A subtask shows its current implementer's metrics. Showing an active group refreshes these values through the selected drivers and shared checkout.

The line diff comes from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh, and from `git diff --shortstat` over SSH otherwise or when the agent's diff is truncated. When the agent reports a new commit or new counts, the Gateway stores the group's line counts when the diff is complete and broadcasts `task_group.updated` in either case. Missing runtime metrics remain unknown; failed reads preserve stored values.

## Scheduler and ceilings

After a create or update stores a `todo` group, the Gateway scheduler claims the oldest `todo` group that still fits the Node ceiling. If provisioning fails, that group returns to `todo` with a visible assistance reason and the scheduler continues with the next eligible `todo` group. A group that waits for Node capacity returns to `todo` without a reason.

It never claims a `backlog` group. It does not poll Nodes and it does not apply a per-Project ceiling.

Active groups are those in `reserved`, `running`, `reviewing`, or `settling`.

| Ceiling | Limit |
| --- | --- |
| Active groups per Node | 10 |

The Node ceiling applies once `taskable` points at an Instance on that Node. A group without an Instance is not held by a Project ceiling.

A fitting claimed group moves from `todo` to `reserved`. InstanceProvisioning assigns the shared Instance on an active Linux `app-dev` Node with capacity and a WireGuard address. Both of the group's drivers must allow the Node. T3 requires an active `t3-code` Process, and Pi requires an active `pi-server` Process, each with desired state `running`. This recorded state is the placement signal, not an HTTP health probe. A [development node exclusion](/reference/development-node-exclusions) removes that Node from the choice before the driver checks and the ceiling.

If no remaining Node fits, provisioning returns no Instance. The group returns to `todo` with the assistance reason `Workspace provisioning did not return an instance.`, and claim processing continues with the next eligible group. The reason clears when the group moves to `running` or `backlog`, or when it later waits for capacity.

If Nodes fit but each is at the ceiling, the group waits for capacity. It returns to `todo` without a reason. When no `app-dev` Node has capacity, claim processing stops until capacity frees. Otherwise it continues with the next eligible group.

When the assignment fits the Node ceiling, the group becomes `running`. AgentSpawner starts the first implementer through the selected driver. The shared reviewer starts at the first handoff. The Gateway stores an Orbit thread ID only after creation and the opening turn succeed.

An implementer spawn that returns no thread id marks the group and the subtask `failed` and logs the refusal. This applies to the first and every later subtask, so no group stays `running` with a null thread id. A reviewer spawn that returns no thread id counts as a communication failure, and the next tick tries again. Create answers with the failed group rather than raising, so one group cannot break an unrelated create.

`tasks:tick` (`php artisan tasks:tick`) then observes those stored reviewer and implementer threads and routes them. It does not poll Nodes for capacity. It observes the current reviewer and active subtask implementer, excluding earlier attempts and unrelated conversations.

## Shared Instance

One fresh Instance belongs to the group. Every subtask reuses it. The instance name and feature branch are `task-{group id}`. When `origin/task-{group id}` exists, as it does after [preparation in Backlog](#prepare-a-group-in-backlog), the provisioner checks it out. When it is missing, the provisioner creates that branch from the Project `default_branch` and checks it out in the shared workspace.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work (`app.slug` is `orbit`) | Isolated checkout on the feature branch. No Route and no public URL. The instance stays `source_resolved` |
| `visitable: true` | A real Project | Usual development provisioner and inspect subdomain. The instance becomes `active` |

The provisioner honors `visitable`. It does not invent a Route for a non-visitable workspace because an active Instance still requires exactly one Route.

Doctor expects the same final state. A non-visitable task workspace is healthy in `source_resolved`, and a visitable one is healthy in `active`. Doctor reports `instance.lifecycle_not_active` for any other state, such as a workspace stuck in `reserved` or `checkout_prepared`, or a visitable workspace stuck in `source_resolved`. The [Doctor instance family](/cli/doctor#what-each-family-checks) owns the check.

## Agent viewer

The task group page shows an Agents section below Subtasks. Vertical tabs select the shared reviewer or an implementer. A subtask page shows its implementer conversations and the shared reviewer. Finished conversations remain available. Activity and connection health have separate labels; a disconnected viewer retains the last known activity state.

`GET /api/v1/task-groups/{group}/agents` lists persisted threads, including driver, external ID, state, observation time, errors, and metrics. `GET /api/v1/task-groups/{group}/agents/{session}/stream` streams normalized conversation data for an Orbit thread ID. Both routes require Gateway access and an enabled tasks extension. Runtime credentials stay server-side. A missing original Node leaves the link visible but unavailable for streaming.

Snapshots replace the browser transcript. Entries merge by ID and kind, so a repeated or updated entry replaces the earlier one in place.

The browser supplies an opaque `Last-Event-ID` on reconnect. A tab that returns from the background reopens its stream with `?after_sequence=` and the last cursor it saw. T3 obtains a fresh full snapshot on each connection, then sends entry, state, and metric changes. Pi resumes after the cursor and sends only what the viewer missed; see [Pi driver](#pi-driver). Viewer connections do not write thread state or observation errors; polling owns persisted observations and rejects concurrent stale writes. Connections rotate periodically and close when the viewer is left. The external runtime owns transcripts; Orbit cannot recover a deleted remote conversation.

## Agent threads and drivers

An `AgentThread` is one persistent conversation. It records the driver, external conversation ID, original Node, task links, role, model, and effort. Task and TaskGroup thread pointers refer to Orbit thread IDs. Existing T3 session links migrate with their IDs and ownership preserved. The external runtime retains the transcript. The integer `reviewer_agent_thread_id` and `implementer_agent_thread_id` fields replace external string pointers. The migration preserves old record IDs and imports missing legacy links.

It is forward-only; reverting to an older Gateway requires restoring a database backup or a reviewed forward migration. Ownership conflicts are checked before schema changes. Take a backup before migrating. If a database without transactional DDL stops partway through a schema change, restore that backup before retrying; do not rerun against the partial schema.

| State | Meaning |
| --- | --- |
| `Idle` | Ready without an active turn or reported outcome |
| `Working` | Executing a turn |
| `AskingForInput` | Waiting for a question or approval response |
| `Done` | Latest turn completed successfully |
| `Failed` | Latest turn failed |

Completion and failure remain visible until a new turn starts. Task completion still requires the scheduler workflow and review. Failed observations preserve the last known state and metrics and mark them unavailable. Connection health does not change a thread to idle or failed. Unavailable observations use the outage grace period and cannot advance a task from cached state.

A group records an implementer driver and a reviewer driver. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select them for new groups. Each defaults to `ORBIT_TASKS_AGENT_DRIVER`, which defaults to `t3`. Placement requires a Node that allows both drivers. Existing groups and threads keep their recorded drivers. The Gateway registers drivers; callers cannot supply arbitrary runtime URLs. Unsupported driver operations fail explicitly. An unknown configured driver rejects group creation with `tasks.agent_driver_unavailable` before any group is stored.

The Gateway sends normalized conversation snapshots, entries, states, input requests, and metrics to the web app. Reconnect cursors belong to the selected driver. The browser renders Orbit data without parsing runtime-specific events. Laravel AI continues to select scheduler actions through Jev.

### T3 driver

Agents run on the T3 server of the Node that owns that Instance. The Gateway posts a flat command to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with `headers: []` on every body. `ORBIT_T3_PORT` defaults to `3773`. `ORBIT_T3_TOKEN` is an optional bearer for that Node's T3 server. A successful dispatch needs a sequence. Commands that have no thread, including `project.create`, may omit `threadId`. `project.create` `defaultModelSelection` and `thread.create` `modelSelection` send options as `{id, value}` objects, never a bare map such as `{effort: high}`.

When `project.create` collides on an occupied workspace root, T3's receipt is `Active project '{uuid}' already exists for workspace root '{path}'`. HTTP dispatch may wrap that as `EnvironmentInternalError` / `orchestration_dispatch_failed` without the phrase. The Gateway parses the project id from that phrase when it appears in the error body, a nested cause, or a header, and otherwise adopts the active project for that workspace root from `GET /api/orchestration/snapshot`. After a successful `thread.create`, the Gateway starts the first turn. A refused `thread.turn.start` is retried once and logged at error. The spawn then returns null and stores no thread id.

Each subtask gets a fresh implementer (`instanceId=codex`, `model=gpt-5.6-luna`, `reasoningEffort=high`). The group keeps one reviewer thread (`instanceId=claudeAgent`, `model=claude-opus-5`, `effort=high`). The T3 provider instance is selected from the model: Claude model names use `claudeAgent`; other configured models use `codex`. Role supplies default model and effort. The instance is fixed at `thread.create`. Subtasks run in position order. At most one Task in a group is `running`. Opening starts only the first `todo` subtask. The next `todo` subtask becomes `running` only after the approval or [cancellation](#groups-and-subtasks) ends the current one and no sibling is `running`. The scheduler refuses a second running task and does not spawn another implementer.

When an implementer is idle, done, or asking for input, the Gateway reads the implementer's run receipt and `composer.json` at the workspace root, which must define a `check` script. A pending input fails on its own. When these items pass, Orbit runs the Project check itself.

Before each agent turn, the Gateway installs the run script at `.git/orbit/run`, writes `.git/orbit/turn.json` with the role of the turn and the subtask's deliverables, and removes any earlier receipt. Git never tracks `.git/orbit/`. The agent ends its turn with `.git/orbit/run --outcome=OUTCOME --summary="…"`. An implementer uses `ready_for_review` or `blocked`. A reviewer uses `approved`, `changes_requested`, or `blocked`. The script refuses an outcome for the other role, an empty summary, a repeated flag, and unknown arguments. It also needs a `--deliverable` confirmation for each [deliverable](#confirm-deliverables) the outcome requires. It writes `.git/orbit/run.json` atomically. [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) records the decision.

Implementers and reviewers receive standing instructions to complete their work autonomously. They may create, modify, reset, and delete disposable fixtures within their task's allocated environment, including Routes and publications. They verify task ownership and the target environment before deletion, use the required CLI confirmation flags, and follow the environment's lease and cleanup rules. They resolve routine test prerequisites themselves. This authority does not extend to live or shared resources or another task's fixtures.

A `blocked` turn pauses the whole group until the operator answers, so it must ask one specific question: `.git/orbit/run --outcome=blocked --summary="What stops you, what you tried, and the boundary you cannot cross" --question="The question the operator must answer"`. The role prompts and reminders reserve this outcome for uncertain ownership, changes to live or shared resources beyond the task's authorization, missing required access, or a product decision that needs the operator. They tell agents to keep working when they can decide or find the answer themselves. These are agent instructions; the script enforces a non-empty summary and question, not their meaning. It refuses `blocked` without a question and refuses `--question` with any other outcome. [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question) records the decision.

When an agent stops, the tick reads the receipt over SSH, stores it as a task comment with its content hash, and removes it. A receipt read again after a crash has the same hash and is stored once. The scheduler then acts on the stored comment, so a failed send or commit is retried on the next tick without the file.

A `blocked` comment holds the summary, then the question on its own line as `Question: …`. The task asks for assistance with that text, so the assistance reason shows the question. A missing receipt, a receipt whose outcome does not fit the turn, or a `blocked` receipt without a question fails the `run_receipt` item. An unreachable workspace counts as a communication failure, not a missing receipt.

When every item and the check pass, the Gateway sets the task to `reviewing` and asks for a review. At the first handoff it starts the reviewer with the group brief and the review request, or sends the request to the planner thread of a [planning group](#plan-a-group-with-a-planner). Later handoffs send the request to the same reviewer. If that fails, the next tick tries again before it reads a reviewer receipt. While the reviewer's snapshot is still the turn from before the handoff, the Gateway waits.

The Gateway never sends a turn to a `working` thread. The shared reviewer can be working when a handoff passes, for example while the operator talks to it. The task still moves to `reviewing`, but the review request waits. The first tick after the reviewer stops working sends it. Until then, the task has no review request, so no reviewer receipt is read.

After review findings are relayed, the Gateway waits for a newer implementer turn to stop and requires a new receipt and a new passing check before handing back to the reviewer.

For `changes_requested`, the Gateway relays the summary to the implementer and returns the task to `running` only after that send succeeds. A failed send stays in `reviewing` and is retried. While the implementer is working, the relay waits until it stops.

For `approved`, the workspace must be on `task-{group id}`. Orbit commits the whole workspace, so the commit also waits while the implementer is working. The Gateway then commits every workspace change as `orbit <tasks@orbit>`, with the subtask title and the reviewer's summary as the message, and stores the commit on the approval comment. Reviewers do not commit. A failed commit counts as a communication failure and is retried. After the last subtask, the group moves to `settling` and remains active until its expected pull request is merged.

`thread.turn.start` sends the T3 0.0.42 message struct `{messageId, role: user, text, attachments: []}` plus `modelSelection`. A flat string message is rejected by T3.

### Pi driver

The `pi` driver runs a thread on the [Pi server](/reference/pi-server) of the Node that owns the Instance. [ADR 0116](/decisions/0116-run-task-implementers-on-pi) records the decision. A Node allows the driver while its `pi-server` Process is active with desired state `running`.

The Gateway chooses the session ID and stores it as the external ID. It creates the session in the Instance checkout, then starts the opening turn. Each send uses a new key; a retry reuses that key, so an ambiguous failure never starts a second turn. The driver maps model names to Pi's `provider/model` form. When `ORBIT_PI_PROVIDER` is set, such as to a CLIProxyAPI provider, every plain name uses it. Otherwise `gpt-` and `o`-series names use `openai-codex`, and `grok-` names use `xai`. Claude models are refused, including through a proxy.

Transcripts become normalized entries. A bash result is one activity that ends with the command and `exit code N`. Other tools show their name and target, not file contents. Tokens come from Pi's cumulative usage. Per-thread line counts are unavailable. Pi threads never report pending input, and `respond` fails as unsupported.

A tool call appears as soon as it starts: an activity labeled `Running` with text such as `Running: $ composer test`. Its result replaces that entry, with the same ID and kind. When the turn settles and a call still has no result, such as after a Pi server restart, the entry shows the call with `(stopped without a result)`.

A Pi stream cursor is `{run}.{sequence}`. On reconnect, the Gateway passes it to the Pi server, which resumes when the cursor belongs to its current run of the session. The stream then starts with a `resumed` event and continues with the entries and state after the cursor. The server sends a full snapshot on a first connection, and when the cursor is unknown, ahead of the server, or from another run, such as before a restart.

When one Pi event becomes several entries, only the last carries the cursor, so a viewer that drops between them receives all of them again. [ADR 0134](/decisions/0134-resume-pi-agent-streams-from-a-cursor) records this decision.

## Session routing

A scheduler tick checks every in-progress task in running and reviewing groups. In-progress tasks have status `running` or `reviewing`. The tick checks the normalized AgentThread state of each attached reviewer or implementer thread, including sessions recorded only in `agent_threads`. Tasks without attached sessions are skipped. `todo`, completed, failed, and cancelled tasks do not ask Jev for decisions. The tick also reads the planner thread of every Backlog and Todo group, so its state and token count stay current while the operator plans. That read asks Jev nothing.

AgentThread state is authoritative. The thread that acts in the task's phase defers the task while it is `working`, including a starting T3 session. That thread is the task's implementer while the task is `running`, and the group's reviewer while the task is `reviewing`. The Gateway then does not inspect that task's messages or pending requests, check workspace commits, or call Jev. Other snapshot fields cannot override an active status. The tick still checks the remaining sessions and other in-progress tasks.

Otherwise the tick checks for commits since the thread started. It reads the count from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh, and runs `git` over SSH otherwise.

The other thread's work does not defer the task. While the operator talks to the shared reviewer, the tick still reads the implementer's receipt, asks for assistance on `blocked`, runs the handoff check, and sends reminders to the implementer. The Gateway sends no turn to the working thread until it stops. [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question) records the decision.

When the Project's task check runs `composer check`, the tick also reads `composer.json` at the workspace root over SSH. The `check_script` item then passes only when `scripts.check` is a non-empty command or list. For any other task check, or none, `check_script` passes. Composer resolves abbreviated command names, so without that script `composer check` runs the built-in `check-platform-reqs` command and exits 0. A missing file, invalid JSON, or a missing or empty `check` script fails `check_script`, and Orbit does not start the check.

A pending input fails `waiting_for_input` in code. A thread state the rubric does not recognize waits. The rubric makes no model call. When every item, the Project check, and the [deliverables](#verify-deliverables) pass, the Gateway sets the task to `reviewing`. [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) owns this rubric.

`assistance_requested` and `resolution` comments update the assistance flag and keep their history. Status stays the current phase for those two comments. The Gateway sends a non-empty resolution to the blocked thread: the group's reviewer when the task is `reviewing`, otherwise the task's implementer. For the reviewer, the resolution counts as its next review request, so the tick does not send another.

The Gateway sends one reminder that names every failed code item. It starts and ends with fixed sentences:

| Role | Starts with | Ends with |
| --- | --- | --- |
| Implementer | "Orbit could not confirm the brief is complete." | The run script instructions that also end the implementer prompt |
| Reviewer | "Orbit could not confirm the review is complete." | The run script instructions that also end each review request |

The run script instructions name the commands for that role. The reminder installs the script again before it is sent. It does not say that the thread is blocked. An agent reports a blocker with a `blocked` receipt and a question for the operator. A receipt that the scheduler acted on is spent, so the next turn needs a new one.

The next idle evaluation asks for assistance when any item still fails. Repeated reminder-send failures ask for assistance on the fifth failure. The same pending input does not count as that next evaluation. A `Failed` thread asks for assistance without a reminder.

A failed receipt read, script install, send, or commit counts as a communication failure for that task and asks for assistance on the fifth consecutive failure. The tick continues with the other tasks. The assistance reason names each remaining item.

Typed comments are the workflow record. They preserve the full body, author, timestamp, task and thread context, and reviewer attempt metadata. A stored receipt uses its outcome as the type and the role as the author. The final approval's comment also carries `pull_request`: the summary, changes, and breaking changes it proposed for the pull request. An approval that Orbit committed carries `commit_sha`. Other comments return `null` for both.

Only `assistance_requested` and `resolution` comments come through the API. They do not create a separate validation-evidence record or API. `assistance_requested` flags the task and group, retains the active slot, and is notified once. A non-empty `resolution` comment preserves the history, resets the completion and communication attempts, and continues the blocked AgentThread idempotently; failed delivery leaves the task visibly blocked.

Each observation includes normalized activity state, availability, errors, pending request IDs, and recent assistant and user text. It also reports new workspace commits, the pull request URL, and any available CI summary. The driver resolves pending requests from its runtime data. Missing or unavailable current conversations skip classification. The scheduler waits `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` (default `120`), then escalates once per continuous outage. Recovery resets the grace period and alert marker.

Legacy scheduler actions:

| Action | Effect |
| --- | --- |
| `drain_approval` | Driver approval response accepting the current request |
| `drain_user_input` | Driver question response continuing the current brief and refusing scope expansion |
| `continue_implementer` | Driver follow-up on the implementer with its recorded model |
| `relay_review_to_implementer` | Driver follow-up on the implementer including the last reviewer excerpt |
| `mark_subtask_done` | Existing settleImplementer, acceptReview, and next-subtask spawn paths |
| `settle_group` | Existing settle path: use the verified PR, write metrics, and notify Coder when CLEAN-ready |
| `escalate_coder` | HMAC Coder webhook with the observation and the low-confidence or failed Choice |
| `noop` | No driver action and no Coder notification |

Confidence below `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` (default `0.75`) becomes `escalate_coder`. A missing `TYPESAFE_API_KEY` fails closed with a clear error and never invents a next action.

Gateway uses `laravel/ai` Classification with its official TypeSafe provider in `config/ai.php`. The package client posts to TypeSafe. Tests use the package fake and never call the network.

Run the tick with `php artisan tasks:tick` while the extension is enabled. One Gateway lock protects scheduled and manual ticks. A held lock skips the invocation without routing or claiming work. After current work and merge checks, the tick fills available Node capacity with the oldest `todo` groups.

A provisioning failure leaves the group in `todo` with its assistance reason visible, and the tick continues to the next eligible group. The tick tries each failing group once. A full fleet ends the claims for that tick without a reason on any group. Groups that are reserved, running, reviewing, settling, assisted, or awaiting merge count toward the limit of 10.

The Gateway registers `tasks:tick` every ten seconds when the tasks extension is enabled. LIVE Ops must run Laravel's `php artisan schedule:work` process for this schedule to advance sessions; this feature does not provision that process or a fleet cron.

### Project check

Each Project stores a task check in `task_check`, such as `composer check`. Orbit runs it after each `ready_for_review` receipt whose items pass, and on the fresh workspace before the first implementer starts. The Gateway installs `.git/orbit/check` and starts it over SSH as a detached process group. The check records HEAD and the tree of the whole working tree, uncommitted and untracked files included, without touching the Git index. It runs the task check in a login shell in the workspace root, writes the output to `.git/orbit/check.log`, and writes `.git/orbit/check.json` when the command ends. [ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off) records the decision.

The task stays `running` during the check. On each tick the scheduler reads the check. It identifies the process by its ID and its start time, so a reused process ID does not count. There is no time limit.

| Check state | Result |
| --- | --- |
| Running | The scheduler waits. The task's `check` shows its start time. |
| Exit code 0, HEAD and tree unchanged | `passed`. Orbit [verifies the deliverables](#verify-deliverables). When they pass, the reviewer starts. |
| Exit code not 0 | `failed`. The implementer's reminder holds the exit code and the end of the output. |
| HEAD or tree changed during the run | `changed`. The check runs again once. A second change fails, and the reminder names the changed paths. |
| The process is gone without a result | `lost`. The check runs again once. A second loss asks for assistance. |
| Cancelled by an operator | `cancelled`. The implementer's reminder says so. |

A new Project gets the task check of its type unless it sends one: `composer check` for `laravel-app` and `laravel-package`, and none for `monorepo` and `node-package`. The upgrade gives existing Projects the same value by type. Change it with `PATCH /api/v1/projects/{project}` or `orbit project:update <project> --task-check=COMMAND`. Clear it with `task_check: null` in the API or `--clear-task-check` in the CLI. `project:show` shows it.

When a Project has no task check, the baseline runs the setup steps and passes, and a handoff runs no command. A handoff still records the tree and verifies the deliverables. The implementer's instructions and the pull request description name the configured check, or leave it out when there is none.

Before the first implementer of a group starts, Orbit runs the setup steps and the task check on the fresh workspace. Project setup runs before dependency preparation so it can configure credentials or install dependencies itself.

Orbit prepares Composer dependencies when the task check references Composer or `vendor/`: it walks tracked `composer.json` files and runs `composer install --no-interaction --prefer-dist` where `vendor/autoload.php` is missing and either the file is at the repository root or a sibling lockfile exists. A root package without a lockfile, such as a Laravel package, is installed too. Nested manifests without a lockfile are skipped because test fixtures use them.

Orbit prepares JavaScript dependencies when the task check references Bun, npm, pnpm, Yarn, Node, Vite+, or `node_modules`: it walks tracked `package.json` files and runs `vp install --frozen-lockfile` where a supported lockfile exists and `node_modules` is missing. Both guards skip projects whose dependencies are already installed. Other task checks receive no automatic install prep. Handoff checks do not prepare dependencies.

A failed setup or dependency install asks for assistance and names that step. A failed baseline command reports `The Project baseline check failed` with its exit code and output. If command output indicates missing `vendor/` or `node_modules` dependencies, assistance reports `Project dependencies appear to be missing` rather than calling the branch broken. Fix the cause, then cancel and create the group again. A task's `check` shows the latest run, with `kind` `baseline` or `handoff` and the `failed_step`.

Call `tasks-check-cancel` with `{ "group": 123, "task": 456 }` to stop a running check. The API operation is `tasks:check:cancel`. A task without a running check answers `409` with `tasks.check_not_running`. A failed or cancelled check spends the reminder of that completion attempt, so a second failure asks for assistance. Each run is stored with its receipt, status, process, HEAD and trees, times, exit code, changed paths, and the last 16 KiB of output. The task's `check` field shows the latest run.

## Pull request and settle metrics

Orbit opens the pull request after the approval of the last subtask. That approval describes it with `--pr-summary`, at least one `--pr-change`, and at least one `--pr-breaking`, or `--pr-breaking=none`. The script accepts these flags only for that approval and refuses the approval without them. There is no limit on the number of changes.

Before Orbit commits the last subtask, Jev checks that the change list covers every subtask of the group except cancelled and failed subtasks. Jev reads the group and subtask briefs and the pull request fields. For each checked subtask, it answers whether a listed change delivers it. A subtask counts as covered when Jev gives "yes" a probability of at least one half. Jev cannot read code, so this checks coverage, not correctness. Each missing subtask fails `brief_coverage`, and the reviewer's reminder names it. A failed Jev request counts as a communication failure.

After the commit, the Gateway pushes the workspace HEAD to `task-{group id}` on `origin` and opens the pull request against the Project's default branch through the [Gateway GitHub App](/reference/github-app). When an open pull request already has that head, the Gateway uses it. The Gateway stores the URL as the group's `pr_url` and moves the group to `settling`. A failed push or request counts as a communication failure and is retried.

The group title is the pull request title. The description holds the summary, a Changes list, a Breaking changes list or `None.`, and one line that says each delivered subtask passed the Project's task check and reviewer approval. Without a task check, the line names only reviewer approval. That line does not count cancelled or failed subtasks.

Settling watches the stored pull request through the GitHub App until it merges. A merged pull request completes the group, and a pull request that closes without merging requests assistance. A settling group without a URL requests assistance and remains incomplete until an operator cancels it.

While the pull request is open, each tick also checks it for problems ([ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks)). The pull request conflicts when GitHub reports it as not mergeable. A check fails when a check run on the head commit completes with `failure`, `timed_out`, `cancelled`, `startup_failure`, or `action_required`. Each problem adds one sentence to an assistance reason that starts with `The pull request needs attention: `, such as `It conflicts with main; merge main into the task branch and push.` or `Check Rust agent failed: <url>.`

The Gateway notifies Coder once for each new reason, not on every tick. When the pull request has no problems again, or when it merges, the Gateway clears the request, but only when its reason starts with that prefix. A merged group therefore completes without that request. It never clears or replaces an assistance request with another cause. If completion fails after the merge, the group requests assistance with the cleanup failure as its reason.

A failed GitHub read changes nothing. The Gateway reads the check runs of one head commit at most once a minute, so a re-run on the same commit shows within a minute and a new push shows at once. Without the App permission `checks: read`, the Gateway reports conflicts only.

The Gateway then writes settle metrics. Active groups also refresh these fields when an authorized caller shows the group.

| Field | Record | Source |
| --- | --- | --- |
| `tokens` | Task | Cumulative tokens reported by the current implementer's driver. Unknown until reported; failed reads preserve stored values |
| `line_diff` | Task | Reported insertions plus deletions for the current implementer. Failed reads preserve stored values |
| `lines_added`, `lines_deleted` | Task | Separate checkpoint insertion and deletion counts; null before observation |
| `duration_ms` | Task | Elapsed milliseconds from `started_at` to `settled_at`, or to now while the subtask is still open |
| `tokens` | TaskGroup | Sum of Task `tokens` values plus the reviewer thread's reported tokens, or `0` at settle when none are stored |
| `line_diff` | TaskGroup | Insertions plus deletions of `git diff --numstat {default_branch}...HEAD` in the shared checkout, or `0` when git cannot run. This is the whole feature branch, not the sum of subtask session diffs |
| `lines_added`, `lines_deleted` | TaskGroup | Separate branch insertion and deletion counts; null before a successful observation |
| `duration_ms` | TaskGroup | Elapsed milliseconds from `started_at` to settle, or to now while the group is still active, or `0` when `started_at` is empty |

For T3, token totals use `totalProcessedTokens` when present and otherwise `usedTokens`. Per-thread line counts come from checkpoints. Other drivers supply metrics with the same meaning or leave them unavailable.

## Coder settle webhook

When `notify_coder` is true, settle POSTs an HMAC-signed JSON body to Coder. This is Commander's `notify_on_settle` path.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_CODER_WEBHOOK_URL` | HTTPS endpoint that receives the settle POST |
| `ORBIT_CODER_WEBHOOK_SECRET` | HMAC-SHA256 secret. The Gateway never returns it |
| `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` | Seconds before one alert for an observation outage. Defaults to `120` |
| `ORBIT_TASKS_AGENT_DRIVER` | Default driver key for both roles of new groups. Defaults to `t3` |
| `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` | Driver key for implementers of new groups. Defaults to `ORBIT_TASKS_AGENT_DRIVER` |
| `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` | Driver key for the reviewer of new groups. Defaults to `ORBIT_TASKS_AGENT_DRIVER` |
| `ORBIT_TASKS_IMPLEMENTER_MODEL` | Implementer model for new groups. Defaults to `gpt-5.6-luna` |
| `ORBIT_TASKS_REVIEWER_MODEL` | Reviewer model for new groups. Defaults to `claude-opus-5` |
| `ORBIT_T3_PORT` | T3 HTTP port. Defaults to `3773` |
| `ORBIT_T3_TOKEN` | Optional bearer for that Node's T3 server |
| `nodes.settings.t3.token` | Required bearer projected with each node when node-scoped T3 credentials are enabled. A projected node never falls back to `ORBIT_T3_TOKEN`; missing configuration fails closed. |
| `nodes.settings.t3.url` | Optional full base URL for that node's T3 server. When absent, the node's WireGuard address and `ORBIT_T3_PORT` are used. |
| `TYPESAFE_API_KEY` | TypeSafe Jev key for task-session Classification. Missing key fails closed |
| `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` | Minimum Choice confidence before execute. Defaults to `0.75`, measured as the margin between the two choice probabilities. Below this, the tick escalates |

The Gateway skips the webhook when the URL or secret is missing. A refused Coder response does not fail settle.

The signed payload is `{unix timestamp}.{raw JSON body}`. Senders use these headers:

| Header | Value |
| --- | --- |
| `X-Orbit-Timestamp` | Unix seconds used in the signature |
| `X-Orbit-Signature` | `sha256=` plus the hex HMAC of `timestamp.body` |
| `Content-Type` | `application/json` |

The JSON body contains `event` (`task_group.settled`), `task_group_id`, `title`, `tokens`, `line_diff`, `duration_ms`, and `pull_request_url`.

An `escalate_coder` Choice posts the same HMAC headers with `event` `task_group.escalated`. That body adds `reason`, `confidence`, `thread_id`, and the structured observation. The scheduler does not post Coder webhooks for drains, continues, relays, or noops.

An `assistance_requested` comment or unresolved workflow omission posts `event` `task_group.assistance_requested` with the task-group id, title, and reason. The task and group remain flagged until a non-empty resolution is delivered.

## Complete and cleanup

After Coder review and PR merge, an authorized Gateway caller runs `tasks:complete` (`POST /api/v1/task-groups/{group}/complete`, MCP tool `tasks-complete`). That marks the group `completed` and removes the shared Instance through the existing Instance remover, including any visitable Routes.

Complete is the documented cleanup path. The Gateway GitHub App receives no merge webhook. A second complete is idempotent. Completing a group that is not `settling` or already `completed` returns `tasks.not_settling` (HTTP 409). Operators may still call `DELETE /api/v1/instances/{instance}` directly; that leaves the group `settling` until complete runs.

## Out of this slice

These items stay unimplemented here and need a later feature PR.

- Commander data migration and retiring Commander
- Creating or changing tasks through the web UI
- Per-Project model overrides
- Tom-on-Mini routing
 - Fleet TypeSafe key mint (Ops after CLEAN)

## Cancel a stuck group

Call `tasks-cancel` with `{ "group": 123 }`, or run `orbit tasks:cancel 123`, to cancel a `backlog`, `todo`, `reserved`, `running`, `reviewing`, or `failed` group, or a `settling` group without a `pr_url`. The API operation is `tasks:cancel`. A `backlog` or `todo` group has no Instance, so cancellation only marks it `cancelled`. For other groups, cancellation removes the shared Instance and clears both taskable fields before returning the group as `cancelled`. Repeating cancellation is safe and also cleans up an Instance still attached to a group already marked `cancelled`. Subtasks that are not completed or failed become `cancelled`. Cancellation clears `assistance_requested` on the group and its subtasks and keeps the last `assistance_reason`. Subtask records and agent thread identifiers stay as history.

A route-free Instance in `source_resolved` uses the Ops database cleanup contract: delete the Instance row and retain its checkout on disk. Other Instances use the existing forced Instance remover, including Route cleanup. Removal errors propagate and leave the group attached for retry. Cancellation does not interrupt the external agent conversation.

Before it removes the Instance of a `settling` group with an approved subtask, cancellation pushes the workspace HEAD to `task-{group id}` on `origin`. A failed push returns HTTP 502 with `tasks.push_failed` and keeps the group and its Instance, so you can retry. Uncommitted workspace changes are not pushed. The push runs outside any database transaction.

A group whose push keeps failing cannot be cancelled. Two causes do not clear on their own.

When the workspace Node is gone, restore the Node at its recorded WireGuard address and cancel again. Without the Node, the approved commits are lost and the group stays `settling`.

When `origin` already has an unrelated `task-{group id}` branch, Git rejects the push, because it is not a force push. A Gateway rebuild that reuses group IDs causes this. Keep that branch under another name if you need it. Then delete `task-{group id}` on `origin` and cancel again.

A `completed` group, or a `settling` group with a `pr_url`, returns HTTP 409 with `tasks.not_cancellable` (an MCP error result). Use `tasks-complete` for a settling group after review and merge.
