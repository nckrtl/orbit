---
title: "Tasks"
description: "How the Gateway tasks extension holds TaskGroup features in Backlog and runs Todo groups on a shared Instance. Agents end turns with run receipts. Orbit commits and pushes each approved subtask, opens the pull request, and removes the workspace clone when the group ends."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension runs a Commander-style feature group. A group waits in Backlog while its branch, ADRs, documentation, and subtasks are prepared. Once the group is in Todo, the Gateway provisions its shared Instance, starts agents, and moves each task from turn to turn with run receipts and mechanical checks. It commits approved work and pushes that commit to `origin`, opens and watches the pull request, retains capacity through assistance and merge wait, and removes the workspace clone when the group is cancelled or completed.

[ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the extension boundary. [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev) owns session routing. [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) owns completion gates. [ADR 0122](/decisions/0122-hold-task-groups-in-backlog-until-ready) owns Backlog and Todo. [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner) owns planning.

[ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone) owns the push after each approval and deletion of the workspace clone. [ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask) owns the fixup that returns a settling group to running. [ADR 0171](/decisions/0171-reset-fixup-caps-after-operator-work) owns when its fixup caps reset. [ADR 0165](/decisions/0165-record-per-thread-token-metrics) owns the per-thread token split. [ADR 0172](/decisions/0172-count-every-t3-model-call-in-thread-metrics) owns complete T3 per-call collection and gap handling. [ADR 0167](/decisions/0167-resume-a-pi-turn-interrupted-by-a-server-restart) owns recovery of a Pi turn that a server restart interrupted. [ADR 0169](/decisions/0169-start-each-subtask-review-in-a-fresh-thread) owns the fresh reviewer thread for each subtask, the review packet, and the MCP search endpoint for the planner and the reviewer.

[ADR 0170](/decisions/0170-edit-todo-subtasks-after-a-group-starts) changes the rule in ADR 0122 that limits subtask editing to Backlog. It defines edits and cancellation for Todo subtasks after a group starts. It preserves [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)'s deliverable contract.

The extension is off until an authorized Gateway caller enables it. There is no web UI for create. Agents create groups through the [MCP server](/reference/mcp). The [`tasks` CLI family](/cli/tasks) runs every operation on this page from a terminal when MCP is unavailable.

[ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) defines the `AgentThread` and `AgentDriver` boundary. Orbit stores persistent conversations and delegates runtime communication to a driver. T3 is the first driver.

## Enable the extension

Enable and disable require Gateway access: the active Gateway peer, or a Node with a grant to the Gateway.

| Operation | Route | Effect |
| --- | --- | --- |
| `tasks:enable` | `POST /api/v1/tasks/enable` | Turns the extension on. Idempotent. |
| `tasks:disable` | `POST /api/v1/tasks/disable` | Turns the extension off. Existing rows stay. Further group and subtask operations return `tasks.disabled`. |
| `tasks:status` | `GET /api/v1/tasks/status` | Returns whether the extension is enabled, and every group currently asking for assistance. |

`tasks:status` returns `enabled` and `assistance`. `assistance` lists every group whose `assistance_requested` is true, in ascending group id order. Each entry has `id`, `app_id`, `app`, `project_code`, `title`, `status`, and `assistance_reason`. A group that is not asking is absent, even when it still stores an old reason. A flagged subtask does not add its group unless the group itself is asking. The list is present while the extension is off. `tasks:enable` and `tasks:disable` return only `enabled`.

Every group and subtask operation below refuses with `tasks.disabled` and HTTP 409 while the extension is off.

## Model

A **TaskGroup** is one parent feature. A **Task** is an ordered subtask. Each row stores a brief with its goal and acceptance. A subtask also stores a typed list of [deliverables](#deliverables) that Orbit checks at handoff.

| Field | Record | Meaning |
| --- | --- | --- |
| `title` | both | Short name |
| `brief` | both | Goal and acceptance |
| `deliverables` | Task | Typed items the subtask must deliver. An empty list for subtasks created before deliverables existed |
| `status` | both | Lifecycle state |
| `assistance_requested` | both | True while that record is asking for assistance |
| `assistance_reason` | both | Why it is asking. Clearing the flag can keep the last reason |
| `position` | Task | Order inside the group, starting at 1 |
| `taskable_type` / `taskable_id` | TaskGroup | Morph. v1 is an Instance only. Null until the scheduler assigns one |
| `reviewer_agent_thread_id` | TaskGroup | Reviewer for the subtask in review, or the planner thread until the first review replaces it |
| `implementer_agent_thread_id` | Task | Fresh implementer thread for that subtask |
| `fixup_problem` | Task | Identity of a Gateway fixup. Null on every other subtask |
| `pr_url` | TaskGroup | Pull request Orbit opened after the last approval |
| `notify_coder` | TaskGroup | Opt-in Coder settle webhook. Create also accepts Commander's `notify_on_settle` |
| `implementer_model` / `reviewer_model` | TaskGroup | `ORBIT_TASKS_IMPLEMENTER_MODEL` and `ORBIT_TASKS_REVIEWER_MODEL` set them for new groups. Unset, they default to `gpt-5.6-luna` (Codex instance `codex`) and `claude-opus-5` (Claude instance `claudeAgent`) |
| `tokens`, `line_diff`, `duration_ms` | both | Filled on settle and refreshed when an active group is shown |

Group statuses: `backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`, `completed`, `failed`, `cancelled`. Task statuses: `todo`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`.

`backlog` means the group is being prepared, and the scheduler never claims it. `todo` means the group is ready and waits for the scheduler. `settling` is the reviewable state: the pull request is open or the Gateway has finished the open attempt, and Coder may review. A `todo` subtask on an open settling pull request, or on a settling group with no `pr_url`, returns the group to `running`. Another assistance cause keeps it `settling`. A reason that starts with `The settling group has no reviewed pull request URL.` does not keep it `settling` when a `todo` subtask is waiting.

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

Subtask create appends one subtask at the next position with status `todo`. It works in any group status except `completed` and `cancelled`, and it accepts `deliverables`. Outside `backlog`, a new subtask needs at least one deliverable. Subtask update changes `title`, `brief`, `position`, or `deliverables`, and positions stay gapless from 1. A `deliverables` value replaces the whole list and cannot be empty.

In a `todo`, `running`, `reviewing`, or `settling` group, a `todo` subtask can change all four fields. This is the limit of ADR 0133's exception for deliverable edits outside `backlog`; `completed` and `cancelled` groups allow no subtask edits. In these permitted statuses, a position change moves the subtask only among other `todo` subtasks; it cannot place it before a started or finished subtask. A started subtask keeps today's restrictions: its title, brief, and position cannot change, and its deliverables are locked. Subtask destroy deletes the subtask and closes the gap, and works only while the group is in `backlog`.

Create does not change the group status. When the group is `settling` and its pull request is open, or when it is `settling` with no `pr_url`, the next tick returns the group to `running` and starts the subtask. Another assistance cause keeps the group `settling`. A reason that starts with `The settling group has no reviewed pull request URL.` does not keep the group `settling` when a `todo` subtask is waiting. [Fix a settling pull request](#fix-a-settling-pull-request) owns that transition.

On a `running` subtask, `tasks:subtask:update` that includes `deliverables` refuses with HTTP 409 `tasks.deliverables_locked` and leaves the stored list unchanged. It never answers success while ignoring that list. The same refusal applies to every subtask that has started.

Cancel a `todo` or `running` subtask with `tasks:subtask:cancel`, or run `orbit tasks:subtask:cancel {group} {subtask}`. A `todo` subtask can be cancelled when its group is `todo`, `running`, `reviewing`, or `settling`. Its status becomes `cancelled` and it receives `settled_at`; cancellation starts nothing and does not ask for assistance. If it was the last open subtask, the group settles as it does after a completed subtask. An open sibling includes a subtask that is reserved, running, or reviewing.

A started subtask keeps today's cancellation rules: only a `running` subtask can be cancelled, and another status returns HTTP 409 `tasks.subtask_not_running`. Cancellation interrupts that subtask's implementer and stops its running [baseline or handoff check](#project-check). It keeps the group and its Instance and starts the lowest-position `todo` subtask. When no implementer has started in the group yet, that subtask runs the baseline check first. The group moves to `settling` only when no open subtask remains, including reserved, running, or reviewing siblings.

Orbit checks the subtask status before it stops anything. It interrupts the implementer first and then stops the check. It holds no database lock while it waits for the agent or the Node, so other Gateway writes continue. When the implementer or check cannot be stopped, cancellation returns HTTP 502 `tasks.subtask_interrupt_failed` and leaves the subtask and its check `running`, so an operator can retry. When the implementer's Node stays unreachable, cancel the group instead. A `cancelled` or `failed` subtask does not block the next subtask.

When the check cannot be stopped, the implementer may already be interrupted. The subtask stays `running` without a working implementer, and the next tick may remind the implementer or fail the subtask. Retry the cancel, or cancel the group.

After both stops succeed, Orbit records the cancel only when the subtask is still `running`. When a tick moved it on while Orbit stopped it, for example to `reviewing`, that new state stands and cancellation returns HTTP 409 `tasks.subtask_not_running`. The implementer and check were still stopped. Cancel the subtask again in its new state, or cancel the group.

When cancellation of a running subtask leaves no open subtask, the group moves to `settling` without a `pr_url`. Orbit opens a pull request only after the last subtask is approved. If the group reaches `settling` with no open work and no pull request, it asks for assistance. Use `tasks:cancel` to end it. Append a `todo` subtask to continue the work. The next tick returns the group to `running` and starts that subtask.

For a group with an approved subtask, and when the Node answers, cancellation first pushes the latest stored approved commit to `task-{group id}` on `origin`. Each approval already pushes its commit; this push sends any approved commit that has not reached `origin`, so those commits stay on the branch. Open a pull request from that branch if you want to keep the work.

When the Node is unreachable, cancel still marks the group `cancelled`, as [Cancel a stuck group](#cancel-a-stuck-group) describes.

Cancellation does not reset the shared checkout. The cancelled implementer's uncommitted edits stay in the shared checkout, and the next approval commits them.

Moving a group to `todo`, by create or update, needs at least one deliverable on every subtask.

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.no_subtasks` | 422 | Create with `status: todo`, or update to `todo`, on a group without subtasks |
| `tasks.subtask_deliverables_missing` | 422 | Create with `status: todo`, or update to `todo`, while a subtask has no deliverables; or subtask create without deliverables outside `backlog`. `details` names each subtask |
| `tasks.group_closed` | 409 | Subtask create in a `completed` or `cancelled` group |
| `tasks.not_in_backlog` | 409 | Group title or brief update outside `backlog`, or subtask update or destroy where the current group status and subtask status do not permit it |
| `tasks.deliverables_locked` | 409 | Subtask deliverables update for a subtask that has started |
| `tasks.already_claimed` | 409 | Status update on a group the scheduler has already claimed |
| `tasks.plan_requires_backlog` | 422 | Create with `plan: true` and `status: todo` |
| `tasks.planner_driver_unavailable` | 409 | Create with `plan: true` when the reviewer driver is not T3 |
| `tasks.planner_node_unavailable` | 409 | Create with `plan: true` when no app-dev Node with access to itself fits |
| `tasks.planner_unavailable` | 409 | Create with `plan: true` when the T3 driver refuses the planner thread |
| `tasks.commit_failed` | 409 | Update to `todo` on a planning group when Orbit cannot commit its workspace |

A status update and a scheduler claim cannot both succeed. When the claim wins, the update returns `tasks.already_claimed`.

MCP tool names follow the API operation identifiers: `tasks-create`, `tasks-update`, `tasks-list`, `tasks-show`, `tasks-cancel`, `tasks-complete`, `tasks-subtask-create`, `tasks-subtask-update`, `tasks-subtask-destroy`, `tasks-subtask-cancel`, `tasks-comment-create`, `tasks-comment-list`, `tasks-agents`, `tasks-enable`, `tasks-disable`, and `tasks-status`. Each CLI command carries the operation's route name, such as `orbit tasks:subtask:create`.

## Deliverables

A deliverable is one item that a subtask must deliver, in a form Orbit can check. The planner or operator writes them next to the brief. The implementer confirms each one when it hands off. Orbit then verifies the mechanical ones before the reviewer starts. [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff) records the decision. [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit) records the repro-first test.

Each deliverable is an object with an `id`, a `type`, a `description`, and the fields of its type:

| Type | Fields | Orbit checks |
| --- | --- | --- |
| `file` | `path`: a path or glob from the workspace root. `change`: `created`, `modified`, or `any` | A matching path in the subtask's diff, added for `created`, modified for `modified`, either for `any` |
| `test` | `project`: the project directory, such as `apps/gateway`, or `.` for the root. `file`: one exact Pest file in that project that ends in `.php` and has no glob. `name`: a substring of the test name | The test file is added or modified in the diff, and Orbit's run of that file has at least one test whose name contains `name`, all passing |
| `command` | `command`: the command to run. `directory`: where to run it, relative to the workspace root; default `.` | Orbit's run of the command exits with 0 |
| `review` | none | The reviewer confirms it in its approval |

A `test` deliverable may set `fails_on_base` to `true`. [Reproduce a bug on the start commit](#reproduce-a-bug-on-the-start-commit) defines that check.

```json
[
  {"id": "reference-page", "type": "file", "description": "Document the export in the tasks reference", "path": "docs/reference/tasks.md", "change": "modified"},
  {"id": "export-test", "type": "test", "description": "A feature test for the export", "project": "apps/gateway", "file": "tests/Feature/ExportTest.php", "name": "exports every subtask"},
  {"id": "layout-repro", "type": "test", "description": "The home-screen test fails before the fix", "project": "apps/gateway", "file": "tests/Feature/HomeScreenTest.php", "name": "home screen layout", "fails_on_base": true},
  {"id": "web-tests", "type": "command", "description": "The web app tests pass", "command": "bun test", "directory": "apps/web"},
  {"id": "error-copy", "type": "review", "description": "Error messages name the failing subtask"}
]
```

| Rule | Limit |
| --- | --- |
| `id` | A lowercase slug such as `export-test`, at most 64 characters, unique within the subtask |
| `description` | At most 500 characters |
| `path`, `file`, `project`, `directory` | Relative paths without `..`. At most 500 characters |
| `test` `file` | One path that ends in `.php`, with no `*`, `?`, `[`, `{`, or `..` |
| `name` | At most 200 characters |
| `command` | At most 1000 characters |
| `fails_on_base` | A JSON boolean on a `test` deliverable. Omitted means `false` |
| Number | At least one and at most five per subtask. Split a subtask that needs more; the [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) skill explains how. |

A field that belongs to another type is refused. Only a `file` deliverable's `path` accepts a glob. In that `path`, `*` matches within one directory, `**` matches across directories, and `?` matches one character.

A `test` deliverable's `file` is one exact Pest test file, relative to its `project`. The path has no `*`, `?`, `[`, or `{`, has no `..`, and ends in `.php`.

Group create, subtask create, and subtask update refuse any other `file` with HTTP 422 `validation.failed`. The error names that deliverable's `id`.

The subtask's diff runs from its start commit to the working tree that Orbit's check sees, uncommitted and untracked files included. Orbit records the start commit when the subtask starts, before the implementer's first turn. When that commit is empty, the diff uses the fallback described in [Review a subtask](#review-a-subtask). Deleted and ignored files never match.

### Reproduce a bug on the start commit

A `test` deliverable may set `fails_on_base` to `true`. Omitted and `false` are the same: Orbit runs the file once, on the working tree. Show and the turn file include the boolean. An omitted input is stored as `false`.

When the value is `true`, Orbit runs that file twice at handoff.

| Run | Code under test | Passes when |
| --- | --- | --- |
| Base | The start commit, or its fallback when that commit was never recorded, plus only this test file from the working tree | At least one test whose name contains `name` fails |
| Working tree | The implementer's tree | Every test whose name contains `name` passes, and at least one such test exists |

The base run reads that test file from the working tree, including an uncommitted or untracked file. No other file from the diff is present. Dependencies already installed in the workspace stay available, so Pest can run. The base run does not change the workspace. When the recorded start commit is empty, this run uses the same fallback as the review diff: the previous subtask's approved commit, or the workspace starting commit for the first subtask.

The base run builds the start commit in a directory under the clone's `.git/orbit/`. It archives that commit and extracts the archive there. It does not register a Git worktree, and the directory is not in the apps root. The check removes the directory when the base run finishes or the check is cancelled. A base run killed with SIGKILL can leave the directory. Nothing is registered, so removing the clone removes the leftover with it.

At the start of a check, Orbit removes any `orbit-base-*` worktree this checkout registered earlier, prunes Git's worktree list, and removes a leftover base directory under `.git/orbit/`.

The base run passes when at least one test whose name contains `name` fails. A matching test that passes does not fail that run when another matching test fails. When no matching test fails and a matching test passes, the deliverable fails. The reminder says that the test does not reproduce the bug. When no matching test fails and a matching test is skipped, the reminder names the skip. When no test name contains `name`, the reminder says so. When the test file cannot be placed on the start commit, the base run does not start, and the reminder names that failure.

A JUnit `error` counts as a failure, including a missing class. The base run stops after 600 seconds. A timed-out base run counts as failing on the start commit.

Each failed case on the base run records whether it was a `failure` or an `error`, and the tail of its message, at most 4096 characters. The review request shows those lines under a lead that says an error, such as a missing class, is not an assertion failure. It also says when the base run timed out and names the 600 second limit. [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit) records the lines.

The diff check from the table above still applies. Orbit reports a miss in the diff, a miss on the base run, and a miss on the working tree together. When the base run has no failing match, that miss is not hidden by another miss.

Group create, subtask create, and subtask update accept `fails_on_base` only on a `test` deliverable. The value is the JSON boolean `true` or `false`. Any other value, and the field on another type, is HTTP 422 `validation.failed`. The error names that deliverable's `id`.

The [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) skill tells a planner when to set the field. A bug group's first code subtask carries it. That subtask is the first one that changes code. When a bug cannot be reproduced automatically, the brief says so. For example, an iOS behavior that shows up only on a device has no Pest test. That subtask adds a `review` deliverable for the manual check.

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

Before the handoff check runs the Project check, it validates every `test` deliverable. The `project` is a directory in the checkout, `.` or a path such as `apps/gateway`. The `file` exists in that project. An invalid deliverable fails the check in seconds, and the Project check does not run.

The message names the deliverable id and the bad value. A project that is not a directory fails as `Deliverable sweep-test names project gateway, which is not a directory in the checkout.` A file that does not exist fails as `Deliverable sweep-test names file tests/Feature/SweepTest.php, which does not exist in apps/gateway.` The same words name any other id and value. A bad project is reported on its own, and the file is checked only when that project is a directory. Each invalid `test` deliverable adds one message, in list order.

The group asks for assistance with each message. The implementer gets no reminder, because the implementer cannot change deliverables.

When every other item passes, Orbit runs its [Project check](#project-check) with the deliverables. After the Project's task check passes, or at once when the Project has none, the check script records the diff, runs each `test` file with `vendor/bin/pest FILE --log-junit=…` in its project, and runs each `command` in a login shell. A run that names a file turns off Pest's test impact analysis, so a cached result never counts. The check keeps the end of each command's output.

A `test` with `fails_on_base` set to `true` runs twice, as [Reproduce a bug on the start commit](#reproduce-a-bug-on-the-start-commit) describes. The base run does not change the workspace. It stops after 600 seconds, and a timed-out base run counts as failing on the start commit. The review request includes the kind and the tail of each base failure message.

The `deliverables` item fails when a confirmation is missing or a deliverable does not pass. The reminder names each failing deliverable and why, and the assistance reason repeats it. Like every item, it gets one reminder per completion attempt, then asks for assistance. An invalid `test` project or file does not use that reminder. The reviewer starts only when every deliverable passes.

A subtask with no deliverables skips these steps. Groups that left Backlog before deliverables existed keep running that way. To add deliverables to such a group, update its `todo` subtasks.

## Prepare a group in Backlog

A group in Backlog without a planner has an id but no Instance and no agents. Use that time to shape the feature before any agent runs. To shape it with an agent in T3 instead, [plan the group with a planner](#plan-a-group-with-a-planner).

1. Create the group. It starts in `backlog`.
2. In a worktree, create the branch `task-{group id}` from the Project default branch.
3. Write the feature's ADRs and documentation on that branch, following the [contributor guide](/contributor-guide). Push the branch.
4. Split the work into subtasks with the [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) skill. Each subtask has one concise goal and at most five deliverables. Its brief cites the ADRs and documentation it implements.
5. Give each subtask its [deliverables](#deliverables).
6. Move the group to `todo` with `tasks:update`.

The provisioner checks out the pushed `task-{group id}` branch for the shared Instance. The implementer and reviewer prompts name the ADRs and documentation that this branch changes as the feature's contract. The reviewer prompt also says that the implementer has no web access, asks the reviewer to confirm framework and library usage against documentation for the Project's versions, and tells the reviewer not to repeat checks the handoff already passed. [Review a subtask](#review-a-subtask) states that rule. The Gateway does not check the branch contents. A group without a pushed branch runs on a fresh branch from the default branch.

## Plan a group with a planner

A planner is a T3 thread that shapes a Backlog group with you. It writes the feature's ADRs and documentation in the group's workspace and manages the group through Orbit MCP. When the plan is ready, the planner or you moves the group to Todo. The planner thread stays the planner. [ADR 0169](/decisions/0169-start-each-subtask-review-in-a-fresh-thread) records that a review does not use it.

1. Create the group with `plan: true`. The Gateway provisions the shared Instance on `task-{group id}` and starts the planner. The thread `Orbit task #{group id} · Planner: {title}` appears in your T3 client.
2. Shape the feature with the planner in that thread. It follows the repository's instructions for feature design. In Orbit's repository, that is the `grill-with-docs` skill.
3. The planner keeps the title, brief, and subtasks current through the same operations you use.
4. The planner gives each subtask at least one [deliverable](#deliverables). Each explicit item of the brief becomes one.
5. When you agree the plan is ready, the planner moves the group to `todo`, or you do.
6. Orbit commits every workspace change on `task-{group id}` as `orbit <tasks@orbit>` with the message `Plan: {group title}`. The scheduler then claims the group in the same Instance.

The planner thread stays the planner for the life of the group. Its `task_id` stays null. Each subtask review starts a fresh reviewer thread on the group's reviewer driver, model, and effort. [Review a subtask](#review-a-subtask) describes the packet and the continued re-review.

| Rule | Behavior |
| --- | --- |
| Driver | The planner uses the reviewer's T3 driver, model, and effort |
| Placement | App-dev Nodes with access to themselves or to the Gateway; the one with the fewest active groups wins |
| MCP | Untracked `.mcp.json` points at this Gateway's `/mcp/search` and is excluded from Git |
| Node ceiling | A Backlog group does not count, with or without an Instance |
| Uncommitted work | The ADRs and documentation stay uncommitted until the move to `todo`; an empty workspace produces no commit |
| Failed start | When no Node fits, or the planner thread cannot start, create removes any Instance and stores no group |
| Failed commit | The group stays in Backlog and the update returns `tasks.commit_failed` |
| Back to Backlog | The group keeps its Instance, planner, and commits |
| Cancel | Removes the Instance and its checkout; the conversation stays in T3 |

When the planner thread cannot start and removing the Instance also fails, create still stores no group and answers `tasks.planner_unavailable`. The Instance row stays. The same holds when Orbit cannot write the planner's `.mcp.json` and removal of that Instance fails.

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

When the parent task is open, Tokens is the total for the current implementer of each subtask plus every reviewer-role thread, including the planner when the group has one. Line diff is the whole feature branch against the Project default branch. A subtask shows its current implementer's metrics. Showing an active group refreshes these values through the selected drivers and shared checkout.

The line diff comes from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh, and from `git diff --shortstat` over SSH otherwise or when the agent's diff is truncated. When the agent reports a new commit or new counts, the Gateway stores the group's line counts when the diff is complete and broadcasts `task_group.updated` in either case. Missing runtime metrics remain unknown; failed reads preserve stored values.

### Thread token metrics

Each agent thread records five fields beside `tokens`. Null means the driver did not report that field or a T3 split is partial. A reported zero is stored as zero. A failed read keeps the last stored value. Task, TaskGroup, the web task board, and the Coder settle webhook keep the cumulative `tokens` total and do not store this split. `tasks:agents` and `GET /api/v1/task-groups/{group}/agents` show it. [ADR 0165](/decisions/0165-record-per-thread-token-metrics) records the field meanings, and [ADR 0172](/decisions/0172-count-every-t3-model-call-in-thread-metrics) records complete T3 collection and partial-gap handling.

| Field | Meaning |
| --- | --- |
| `input_tokens` | Uncached input summed across model calls, including cache writes |
| `cached_input_tokens` | Input read from cache, summed across model calls. Cache writes are not included |
| `output_tokens` | Output, summed across model calls. Reasoning is already included and is not added again |
| `model_calls` | Model calls counted from the driver's per-call updates |
| `peak_context_tokens` | Largest single-call context. Context is that call's uncached input plus its cached input, so a Pi cache write is inside the peak, and output is excluded |

A T3 split that is partial sets all five fields to null; the internal accumulator retains its known lower bounds. A complete split reports the five fields, including a zero when the driver reports zero.

Average context per call is `(input_tokens + cached_input_tokens) / model_calls`. The cached share of input is `cached_input_tokens / (input_tokens + cached_input_tokens)`. Cache writes sit in that denominator with the other uncached input. The Gateway does not recompute `tokens` from the split.

For Pi, the server's `usage` object carries the sums `input`, `output`, `cacheRead`, `cacheWrite`, and `total`, plus `calls` and `peakContext`. Pi's `input` excludes cache writes. `tokens` is `total`. `input_tokens` is `input + cacheWrite`. `cached_input_tokens` is `cacheRead`. `output_tokens` is `output`. A source that is not an integer leaves that Orbit field null, and a missing `cacheWrite` leaves `input_tokens` null. `calls` counts assistant messages with numeric usage. `peakContext` is the maximum of `input + cacheRead + cacheWrite` over those messages, the same quantity as that call's uncached input plus its cached input. Both are null when the server omits the key. The session file `~/.pi/agent/orbit-sessions/*.jsonl` records the same per-call `input`, `cacheRead`, `cacheWrite`, and `output`. The Gateway reads the snapshot, not the file.

For T3, the snapshot has no thread-level usage object. Figures sit on `context-window.updated` activities: `usedTokens`, and optionally `totalProcessedTokens`, `inputTokens`, `cachedInputTokens`, `outputTokens`, `reasoningOutputTokens`, and `lastInputTokens`, `lastCachedInputTokens`, `lastOutputTokens`, and `lastReasoningOutputTokens`. `tokens` still prefers the largest `totalProcessedTokens`, then `usedTokens`. The Gateway does not open Codex rollout files.

The five fields come from the T3 thread event stream, not the bounded snapshot activity list. The Gateway durably accumulates each valid call payload with running sums, the last counted `totalProcessedTokens`, a separate last observed `totalProcessedTokens`, the greatest event sequence, and a partiality state. It applies the sums and sequence checkpoint atomically, ignores replayed sequences, and considers a payload only when its integer `totalProcessedTokens` advances past the observed watermark.

The observed watermark advances even for an invalid advancing payload. A replay at that same total is ignored, even when the replay contains valid split fields. A valid advancing payload counts once. Repeated updates at the same total do not count another call. `last*` is the latest update, not a total. `reasoningOutputTokens` is not added.

`input_tokens` adds `inputTokens - cachedInputTokens`. `cached_input_tokens` adds `cachedInputTokens`. `output_tokens` adds `outputTokens`. `model_calls` increments once per counted call. `peak_context_tokens` is the maximum `inputTokens`, which already includes cached input.

An initial or fresh stream baseline is not a call. When its cumulative total includes calls before the saved checkpoint, the Gateway advances the observed watermark, marks the split partial, and does not claim a complete split. If the Gateway misses events and cannot resume history, an observed cumulative `totalProcessedTokens` delta still updates `tokens`, but it cannot reconstruct a precise call count or token split. The Gateway retains known sums and the known call count, marks the split partial, and never reports fewer calls than it has already counted.

It does not infer calls or split categories from a total-token delta. A decreasing or unavailable cumulative baseline also marks the split partial rather than subtracting or double counting. Partial sums and peak context are lower bounds for observed calls, not complete thread metrics. Invalid call payloads are not added to the split and make it partial.

On Codex, `inputTokens` includes `cachedInputTokens`, `total_tokens` equals input plus output, and the counted calls match the rollout's `token_usage_record` rows and `thread_token_usage`. A Claude snapshot omits `cachedInputTokens`, so the five fields stay null.

Groups 109 through 125, measured on 2026-09-26, are the comparison baseline: 596 million tokens, 94 percent cached input on the share above, 118 thousand tokens of implementer context per call, 107 thousand for the reviewer, and a median implementer subtask of 5.97 million tokens.

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

An unexpected provisioning error, such as a lock timeout or a failed lookup, has the same result. The Gateway writes the error to its application log, and the group returns to `todo` with the same reason. The reason never contains the error text. A group never stays `reserved` after a failed provision, so it does not count toward the Node ceiling.

The provisioned Instance belongs to the group from then on. If the move to `running` fails after a successful provision, for example because the database is busy, the Gateway writes the error to its application log. The group returns to `todo` with the assistance reason `The group could not start after its workspace was provisioned.` and keeps its Instance. Claim processing continues with the next eligible group. The next claim reuses the kept Instance instead of provisioning a new one, and cancellation removes it. A group that returns to `todo` because the Node ceiling holds it back at start also keeps its Instance.

A process that stops between the reserve and the move to `running` can leave a group `reserved`. Each tick returns a group that has stayed `reserved` longer than `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` (default `3600`) to `todo` with the assistance reason `The group stayed reserved too long and returned to todo.`, and then claims `todo` groups as usual. The tick changes the group only while it is still `reserved` past the bound, so it never takes a group that a newer claim reserved. A claim that fails likewise returns its group to `todo` only while it still holds that reservation. The start and timeout reasons clear in the same way as the provisioning reason.

A claim can stop after it provisions the `task-{group id}` workspace and before it attaches it. That workspace keeps its name and its `task-{group id}` branch, so the next claim resumes it, and cancellation and completion find it by that name and branch when the group holds no Instance.

An Instance that only shares the name, with another branch, is never resumed or removed. Provisioning refuses it, and the group returns to `todo` with the provisioning reason.

When a group is cancelled while its claim runs, the claim removes the workspace it provisioned, or the part of it that exists when provisioning fails, and the group stays `cancelled`. When that claim stops before it can, each tick removes the workspace of a `cancelled` or `completed` group that still has one and was reserved longer ago than `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS`, or never. That workspace is the attached Instance, or the unattached `task-{group id}` checkout an interrupted claim left behind.

A tick starts no new removal after it has spent 60 seconds on them, well inside its 300-second lock, and the rest wait for the next tick. A failed removal goes to the application log, asks the group for assistance with a reason prefixed `Workspace removal failed: `, and backs off for that Instance only: 1 minute after the first failure, then 2, 5, 10, and 30 minutes. Further failures stay at 30 minutes. The sweep does not remove that workspace on every tick. Push retries use the same delays, as [Pull request and settle metrics](#pull-request-and-settle-metrics) describes.

The checkout and the Instance row stay until a retry deletes the checkout. That success clears an assistance request whose reason starts with `Workspace removal failed: ` or `Merged pull request cleanup failed: `, and leaves every other cause in place. A workspace that keeps failing therefore never blocks the removal of another one. When the Gateway cannot read or write a backoff in its cache, it logs a warning, tries the removal as if no backoff exists, and continues the sweep and the tick.

If the stopped claim created a workspace, provisioning finds it by its `task-{group id}` name and resumes it on its Node. When that Node is at the ceiling, the group waits for capacity. When that Node does not fit the group, provisioning returns no Instance. A claim whose provision outlasts the bound finds its group in `todo`. It attaches the Instance to the group and leaves the group in `todo` for the next claim.

If Nodes fit but each is at the ceiling, the group waits for capacity. It returns to `todo` without a reason. When no `app-dev` Node has capacity, claim processing stops until capacity frees. Otherwise it continues with the next eligible group.

When the assignment fits the Node ceiling, the group becomes `running`. AgentSpawner starts the first implementer through the selected driver. The reviewer for a subtask starts at that subtask's first handoff. The Gateway stores an Orbit thread ID only after creation and the opening turn succeed.

An implementer spawn that returns no thread id marks the group and the subtask `failed` and logs the refusal. This applies to the first and every later subtask, so no group stays `running` with a null thread id. A reviewer spawn that returns no thread id counts as a communication failure, and the next tick tries again. Create answers with the failed group rather than raising, so one group cannot break an unrelated create.

`tasks:tick` (`php artisan tasks:tick`) then observes those stored reviewer and implementer threads and routes them. It does not poll Nodes for capacity. It observes the reviewer for the active subtask and that subtask's implementer, excluding earlier attempts and unrelated conversations.

## Shared Instance

One fresh Instance belongs to the group. Every subtask reuses it. The instance name and feature branch are `task-{group id}`. When `origin/task-{group id}` exists, as it does after [preparation in Backlog](#prepare-a-group-in-backlog), the provisioner checks it out. When it is missing, the provisioner creates that branch from the Project `default_branch` and checks it out in the shared workspace.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work (`app.slug` is `orbit`) | Isolated checkout on the feature branch. No Route and no public URL. The instance stays `source_resolved` |
| `visitable: true` | A real Project | Usual development provisioner and inspect subdomain. The instance becomes `active` |

The provisioner honors `visitable`. It does not invent a Route for a non-visitable workspace because an active Instance still requires exactly one Route.

Doctor expects the same final state. A non-visitable task workspace is healthy in `source_resolved`, and a visitable one is healthy in `active`. Doctor reports `instance.lifecycle_not_active` for any other state, such as a workspace stuck in `reserved` or `checkout_prepared`, or a visitable workspace stuck in `source_resolved`. The [Doctor instance family](/cli/doctor#what-each-family-checks) owns the check.

## Agent viewer

The task group page shows an Agents section below Subtasks. Vertical tabs list every started thread: the planner, each subtask reviewer, and each implementer. A reserved row that has not started is left out. A subtask page shows only that subtask's implementer and that subtask's reviewer. The planner and every other reviewer stay on the group page. Finished conversations remain available. Activity and connection health have separate labels; a disconnected viewer retains the last known activity state.

`GET /api/v1/task-groups/{group}/agents` lists persisted threads, including driver, external ID, state, observation time, errors, and metrics. It leaves out a reserved row whose external id starts with `pending:`. Metrics include `tokens` and the [thread token metrics](#thread-token-metrics). Each split field is present and null when the driver did not report it. `GET /api/v1/task-groups/{group}/agents/{session}/stream` streams normalized conversation data for an Orbit thread ID. Both routes require Gateway access and an enabled tasks extension. Runtime credentials stay server-side. A missing original Node leaves the link visible but unavailable for streaming.

Snapshots replace the browser transcript. Entries merge by ID and kind, so a repeated or updated entry replaces the earlier one in place.

The browser supplies an opaque `Last-Event-ID` on reconnect. A tab that returns from the background reopens its stream with `?after_sequence=` and the last cursor it saw. T3 obtains a fresh full snapshot on each connection, then sends entry, state, and metric changes. Pi resumes after the cursor and sends only what the viewer missed; see [Pi driver](#pi-driver). Viewer connections do not write thread state or observation errors; polling owns persisted observations and rejects concurrent stale writes. Connections rotate periodically and close when the viewer is left. The external runtime owns transcripts; Orbit cannot recover a deleted remote conversation.

## Agent threads and drivers

An `AgentThread` is one persistent conversation. It records the driver, external conversation ID, original Node, task links, role, model, and effort. Task and TaskGroup thread pointers refer to Orbit thread IDs. Existing T3 session links migrate with their IDs and ownership preserved. The external runtime retains the transcript. The integer `reviewer_agent_thread_id` and `implementer_agent_thread_id` fields replace external string pointers. The migration preserves old record IDs and imports missing legacy links.

It is forward-only; reverting to an older Gateway requires restoring a database backup or a reviewed forward migration. Ownership conflicts are checked before schema changes. Take a backup before migrating. If a database without transactional DDL stops partway through a schema change, restore that backup before retrying; do not rerun against the partial schema.

Orbit reserves a row before it starts a conversation, so the opening prompt can name the Orbit thread id. That row's external id starts with `pending:` until the conversation starts. It is not a conversation yet. The agents list, the Agents section, token totals, and `agent_thread.updated` leave it out, and Orbit does not read it from the driver. When a spawn reports a failure, Orbit deletes the reservation, and a later attempt creates a new one.

If the process stops before it can clean up a failed spawn, the `pending:` row may remain. A tick deletes it only after its task or group is completed or cancelled. While the owner remains active, Orbit keeps the row and a later spawn reuses it; pending reservations do not expire based on age. See [Archive finished threads](#archive-finished-threads).

| State | Meaning |
| --- | --- |
| `Idle` | Ready without an active turn or reported outcome |
| `Working` | Executing a turn |
| `AskingForInput` | Waiting for a question or approval response |
| `Done` | Latest turn completed successfully |
| `Failed` | Latest turn failed |

Completion and failure remain visible until a new turn starts. Task completion still requires the scheduler workflow and review. Failed observations preserve the last known state and metrics and mark them unavailable. Connection health does not change a thread to idle or failed. Unavailable observations use the outage grace period and cannot advance a task from cached state.

A group records an implementer driver and a reviewer driver. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select them for new groups, and each defaults to `t3`. Placement requires a Node that allows both drivers. Existing groups and threads keep their recorded drivers. The Gateway registers drivers; callers cannot supply arbitrary runtime URLs. Unsupported driver operations fail explicitly. An unknown configured driver rejects group creation with `tasks.agent_driver_unavailable` before any group is stored.

The Gateway sends normalized conversation snapshots, entries, states, input requests, and metrics to the web app. Reconnect cursors belong to the selected driver. The browser renders Orbit data without parsing runtime-specific events. Jev currently judges brief coverage before the final approved commit.

### Archive finished threads

Orbit archives T3 threads after their work ends. When a subtask reaches `completed` or `cancelled`, Orbit archives that subtask's reviewer thread, if it has one. It never archives the planner thread while the group is open. When a group reaches `completed` or `cancelled`, Orbit archives its planner thread and any remaining T3 threads for the group.

Each archive run, including a scheduler tick or `tasks:archive-threads`, archives at most 10 eligible T3 threads, oldest Orbit thread id first. Later runs continue with the remaining threads. If an archive command fails, the failure does not block the subtask or group from reaching its terminal status. Orbit keeps the same command id and schedules retries after 1, 5, 30, then 120 minutes (and every 120 minutes thereafter). A successful retry clears that backoff. Orbit reports an archive failure at most once per thread per hour.

Ticks delete leftover `pending:` reservations only when their task or group is completed or cancelled. While the owner remains active, Orbit keeps the reservation so `TaskAgentSpawner` can retry the spawn using that row; pending rows do not expire based on age.

Orbit archives a T3 thread only after the collector completes a successful final metrics read and sets `t3_metrics_final_at`. If collection is incomplete or fails, the collector retries and archive scheduling leaves the thread available to read, even when a scheduler tick runs first.

To process finished threads without waiting for a tick, run `php artisan tasks:archive-threads`; each invocation uses the same idempotent path and archives up to 10 eligible threads for completed and cancelled subtasks and groups. Archiving does not delete the Orbit `agent_threads` row or its metrics. Pi sessions are files on the Node and are outside this cleanup.

### T3 driver

Agents run on the T3 server of the Node that owns that Instance. The Gateway posts a flat command to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with `headers: []` on every body. `ORBIT_T3_PORT` defaults to `3773`. `ORBIT_T3_TOKEN` is an optional bearer for that Node's T3 server. A successful dispatch needs a sequence. Commands that have no thread, including `project.create`, may omit `threadId`. `project.create` `defaultModelSelection` and `thread.create` `modelSelection` send options as `{id, value}` objects, never a bare map such as `{effort: high}`.

When `project.create` collides on an occupied workspace root, T3's receipt is `Active project '{uuid}' already exists for workspace root '{path}'`. HTTP dispatch may wrap that as `EnvironmentInternalError` / `orchestration_dispatch_failed` without the phrase. The Gateway parses the project id from that phrase when it appears in the error body, a nested cause, or a header, and otherwise adopts the active project for that workspace root from `GET /api/orchestration/snapshot`. After a successful `thread.create`, the Gateway starts the first turn. A refused `thread.turn.start` is retried once and logged at error. The spawn then returns null and stores no thread id.

Each subtask gets a fresh implementer (`instanceId=codex`, `model=gpt-5.6-luna`, `reasoningEffort=high`) and, on its first review, a fresh reviewer (`instanceId=claudeAgent`, `model=claude-opus-5`, `effort=high`). The group's reviewer driver, model, and effort select that reviewer. [Review a subtask](#review-a-subtask) owns the packet and the continued re-review.

The T3 provider instance is selected from the model: Claude model names use `claudeAgent`; other configured models use `codex`. Role supplies default model and effort. The instance is fixed at `thread.create`. Subtasks run in position order. At most one Task in a group is `running`. Opening starts only the first `todo` subtask. The next `todo` subtask becomes `running` only after the approval or [cancellation](#groups-and-subtasks) ends the current one and no sibling is `running`. The scheduler refuses a second running task and does not spawn another implementer.

When an implementer is idle, done, or asking for input, the Gateway reads the implementer's run receipt and `composer.json` at the workspace root, which must define a `check` script. A pending input fails on its own. When these items pass, Orbit runs the Project check itself.

Before each agent turn, the Gateway installs the run script at `.git/orbit/run`, writes `.git/orbit/turn.json` with the role of the turn, the subtask's deliverables, and the acting thread's Orbit id, and removes any earlier receipt. Git never tracks `.git/orbit/`. The agent ends its turn with `.git/orbit/run --thread=ID --outcome=OUTCOME --summary="…"`. `ID` is the Orbit thread id from that turn's instructions. An implementer uses `ready_for_review` or `blocked`. A reviewer uses `approved`, `changes_requested`, or `blocked`.

The script refuses an outcome for the other role, an empty summary, a repeated flag, and unknown arguments. It also needs a `--deliverable` confirmation for each [deliverable](#confirm-deliverables) the outcome requires. It writes `.git/orbit/run.json` atomically, including the `thread` it was given. [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) records the receipt.

The tick applies that receipt only when `thread` is the acting thread. The acting thread is the subtask's reviewer, or its implementer. A turn file that still names the previous reviewer does not apply that reviewer's receipt, and it does not drop a receipt from the acting thread.

A receipt from another reviewer, or a receipt with no thread id, is not applied when the acting thread is known. An implementer turn is bound the same way. A legacy turn file with no thread id is rewritten for that thread, and Orbit sends the bound run command instead of applying the unidentified receipt. If the turn file cannot be written for a replacement reviewer, that replacement does not start and the group pointer stays. [ADR 0169](/decisions/0169-start-each-subtask-review-in-a-fresh-thread) records the thread binding.

Implementers receive standing instructions to complete their work autonomously. They may create, modify, reset, and delete disposable fixtures within their task's allocated environment, including Routes and publications. They verify task ownership and the target environment before deletion, use the required CLI confirmation flags, and follow the environment's lease and cleanup rules. They resolve routine test prerequisites themselves. This authority does not extend to live or shared resources or another task's fixtures.

A reviewer turn is read-only. The reviewer prompt says so. The reviewer does not create, edit, reset, or delete workspace files, including disposable fixtures.

A `blocked` turn pauses the whole group until the operator answers, so it must ask one specific question: `.git/orbit/run --outcome=blocked --summary="What stops you, what you tried, and the boundary you cannot cross" --question="The question the operator must answer"`. The role prompts and reminders reserve this outcome for uncertain ownership, changes to live or shared resources beyond the task's authorization, missing required access, or a product decision that needs the operator. They tell agents to keep working when they can decide or find the answer themselves. These are agent instructions; the script enforces a non-empty summary and question, not their meaning. It refuses `blocked` without a question and refuses `--question` with any other outcome. [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question) records the decision.

When an agent stops, the tick reads the receipt over SSH, stores it as a task comment with its content hash, and removes it. A receipt read again after a crash has the same hash and is stored once. The scheduler then acts on the stored comment, so a failed send or commit is retried on the next tick without the file.

A `blocked` comment holds the summary, then the question on its own line as `Question: …`. The task asks for assistance with that text, so the assistance reason shows the question. A missing receipt, a receipt whose outcome does not fit the turn, or a `blocked` receipt without a question fails the `run_receipt` item. An unreachable workspace counts as a communication failure, not a missing receipt.

When every item and the check pass, the Gateway sets the task to `reviewing` and asks for a review. [Review a subtask](#review-a-subtask) describes the thread, the packet, and the wait while that reviewer is working.

When the Gateway sends the review request, it records the workspace HEAD and the working-tree hash. That is the hash the [Project check](#project-check) already stores: the whole working tree, uncommitted and untracked files included, without touching the Git index. The Gateway reads it at send time. The task keeps that pair for the review attempt.

When the reviewer's receipt arrives, the Gateway reads HEAD and the hash again. A difference is a reviewer change only when a newer implementer turn does not explain it and Orbit's own commit does not explain it. The `workspace_unchanged` item then fails. The Gateway keeps the receipt and does not apply `approved`, `changes_requested`, or `blocked`. The reminder tells the reviewer to revert its changes and to request changes from the implementer instead.

A workspace difference that comes from a newer implementer turn than the handoff is a new handoff, not a reviewer change. An operator talking to the implementer during the review is that case. The Gateway does not fail `workspace_unchanged`, does not remind the reviewer, and does not apply the reviewer outcome. It waits until that implementer turn stops. The subtask then needs a new receipt and a passing check before the Gateway asks the reviewer again. The next review request records the new HEAD and hash.

A commit whose response was lost is Orbit's commit, not a reviewer change. The Gateway accepts it when HEAD's parent is the HEAD recorded with the review request and HEAD's tree equals the tree recorded with that request. It stores that commit on the approval and pushes it. It does not fail `workspace_unchanged` for that commit. A reset back to the HEAD from before the approval does not have that parent, and the Gateway still refuses it.

For a reviewer change, the review sends one reminder and records that stopped turn. Another poll of the same turn is not a second change. The Gateway does not apply the stored outcome, and it does not ask for assistance. It waits until a newer reviewer turn stops, then reads the workspace again. When that turn still differs, the Gateway asks for assistance and repeats the reminder. When HEAD and the hash match, the Gateway applies the outcome. A failed read is a communication failure, not a change.

After review findings are relayed, the Gateway waits for a newer implementer turn to stop and requires a new receipt and a new passing check before handing back to the reviewer.

For `changes_requested`, the Gateway relays the summary to the implementer and returns the task to `running` only after that send succeeds. A failed send stays in `reviewing` and is retried. While the implementer is working, the relay waits until it stops.

For `approved`, the workspace must be on `task-{group id}`. Orbit commits the whole workspace, so the commit also waits while the implementer is working. The Gateway then commits every workspace change as `orbit <tasks@orbit>`, with the subtask title and the reviewer's summary as the message, and stores the commit on the approval comment. Reviewers do not change the workspace, and they do not commit. A failed commit counts as a communication failure and is retried. The Gateway then pushes that stored commit, not `HEAD`, as [Pull request and settle metrics](#pull-request-and-settle-metrics) describes.

When that commit is already stored and the push or the pull request open fails, the next attempt retries publication only while HEAD is still that commit and the recorded hash still matches. It does not commit again. Those attempts back off, as [Pull request and settle metrics](#pull-request-and-settle-metrics) describes, instead of running on every tick. A reset back to the HEAD from before the approval keeps the hash, but the Gateway refuses it and does not publish. After the last subtask's pull request is stored, the group moves to `settling` and remains active until its expected pull request is merged.

`thread.turn.start` sends the T3 0.0.42 message struct `{messageId, role: user, text, attachments: []}` plus `modelSelection`. A flat string message is rejected by T3. A resume after a server restart reuses one command id and one message id for that send. [Recover a Pi server restart](#recover-a-pi-server-restart) states when.

### Pi driver

The `pi` driver runs a thread on the [Pi server](/reference/pi-server) of the Node that owns the Instance. [ADR 0116](/decisions/0116-run-task-implementers-on-pi) records the decision. A Node allows the driver while its `pi-server` Process is active with desired state `running`.

The Gateway chooses the session ID and stores it as the external ID. It creates the session in the Instance checkout, then starts the opening turn. Each send uses a new key; a retry reuses that key, so an ambiguous failure never starts a second turn. The driver maps model names to Pi's `provider/model` form. When `ORBIT_PI_PROVIDER` is set, such as to a CLIProxyAPI provider, every plain name uses it. Otherwise `gpt-` and `o`-series names use `openai-codex`, and `grok-` names use `xai`. Claude models are refused, including through a proxy.

A restart error on this driver is resumed on the same thread. The resume key is stored before the send and reused only while that same interruption is still unresolved. [Recover a Pi server restart](#recover-a-pi-server-restart) states the limit of two resumes.

Transcripts become normalized entries. A bash result is one activity that ends with the command and `exit code N`. Other tools show their name and target, not file contents. The cumulative token total comes from Pi's session usage, and the [thread token metrics](#thread-token-metrics) record the split. Per-thread line counts are unavailable. Pi threads never report pending input, and `respond` fails as unsupported.

A tool call appears as soon as it starts: an activity labeled `Running` with text such as `Running: $ composer test`. Its result replaces that entry, with the same ID and kind. When the turn settles and a call still has no result, such as after a Pi server restart, the entry shows the call with `(stopped without a result)`.

A Pi stream cursor is `{run}.{sequence}`. On reconnect, the Gateway passes it to the Pi server, which resumes when the cursor belongs to its current run of the session. The stream then starts with a `resumed` event and continues with the entries and state after the cursor. The server sends a full snapshot on a first connection, and when the cursor is unknown, ahead of the server, or from another run, such as before a restart.

When one Pi event becomes several entries, only the last carries the cursor, so a viewer that drops between them receives all of them again. [ADR 0134](/decisions/0134-resume-pi-agent-streams-from-a-cursor) records this decision.

## Review a subtask

Each subtask review starts a fresh reviewer thread on the group's reviewer driver, model, and effort. The thread title is `Orbit task #{group id} · Review: {subtask title}`. Its role is `reviewer`, and its `task_id` is that subtask. The group's `reviewer_agent_thread_id` then points at this thread. A `changes_requested` re-review of the same subtask continues this thread. A `blocked` resolution continues it too, when that thread exists. The next subtask starts another thread. [ADR 0169](/decisions/0169-start-each-subtask-review-in-a-fresh-thread) records the decision.

When that reviewer thread does not exist yet, the resolution is not sent to the planner, to an earlier subtask's reviewer, or to a reserved reviewer row that has not started. Assistance clears, the review stays unrequested, and the next tick starts the fresh reviewer with the resolution in its opening packet.

The planner thread is not a reviewer. A planning group keeps it for planning. Its `task_id` stays null, and its title stays `Orbit task #{group id} · Planner: {title}`. Before the first review, `reviewer_agent_thread_id` points at that planner thread, which is how the group remembers it. The first review replaces the pointer with the new reviewer. The scheduler sends a review only to the thread it started for that subtask, or continues that thread. It never sends a review to the planner.

The opening turn is a review packet of at most 16,000 characters, about 4 thousand tokens at four characters per token. No part is exempt. A part under its cap leaves the spare characters for the diff body. The diff body also stops at 16,384 bytes. The two retrieval commands are reserved first, at most 1,000 characters, and are never cut. The thread id is written into the generated closing instructions before that cap. Diff text and brief text are not rewritten to add it.

### Render prompts for offline evaluation

`php artisan tasks:render-prompt {role}` renders an agent prompt from one JSON object read from standard input, without starting an agent. The role is `implementer`, `reviewer` (the opening review packet), `reviewer-continue` (the continued review turn), or `planner`. The command writes one JSON object to standard output: `{"role":"…","prompt":"…","source_commit":"…"}`. `prompt` is the exact prompt text. `source_commit` is the Gateway version reported by its status endpoint: the configured `APP_VERSION`, which defaults to `dev` when unset. It does not fall back to a Git read, so a deployment without a configured commit version reports `dev`.

The input is a single JSON object with only the following fields. Field names and types are part of the command contract; unknown fields are rejected.

| Field | Type | Meaning |
| --- | --- | --- |
| `group.id` | integer | Task group id. |
| `group.title` | string | Group title. |
| `group.brief` | string | Group brief. |
| `group.project_slug` | string | Project slug. |
| `group.project_id` | integer | Project id. |
| `group.default_branch` | string or null | Project default branch. |
| `group.task_check` | string or null | Project task check command. |
| `subtask.id` | integer | Subtask id. |
| `subtask.title` | string | Subtask title. |
| `subtask.brief` | string | Subtask brief. |
| `subtask.position` | integer | Subtask position in the group. |
| `subtask.deliverables` | array of deliverable objects | The subtask deliverables, in order. |
| `thread_id` | integer or null | Agent thread id used to render thread-specific instructions. |
| `review_packet` | object | Present only for `reviewer` and `reviewer-continue`; the opening or continued review inputs described below. |

Each deliverable object has string fields `id`, `type`, and `description`. Its type-specific fields match the task deliverable: `file` has string `path` and `change`; `test` has string `project`, `file`, and `name`, plus boolean `fails_on_base`; `command` has string `command` and `directory`; `review` has no type-specific fields.

`review_packet` contains `opens_pull_request` (boolean), `earlier_approved_subtasks` (an array of objects with string `title` and `summary`), `diff_files` (an ordered array of objects with string `path` and integer `insertions` and `deletions`), `files_complete` and `diff_available` (booleans), `diff_summary` (an object with integer `files`, `insertions`, and `deletions`), `diff_body` (string), `untracked_content` (an ordered array of objects with string `path` and `patch`), `handoff_check`, `start_commit` (string), and `held_resolution` (string or null).

`diff_summary` always contains full capture totals, even when the path list or diff body was cut. When `files_complete` is false, `diff_files` is empty and the full totals in `diff_summary` provide the stat. `diff_available` says whether a diff body was captured; a false value means `diff_body` is empty. Do not infer stat totals or capture flags from `diff_body`.

`diff_body` is the exact output of the tracked `git diff <start_commit>` command, including its original trailing newline. Each `untracked_content` entry contains the exact patch string produced for that path by `git diff --no-index -- /dev/null <path>`, including headers, mode, and trailing newline. Supply these patch strings instead of raw file contents; they preserve executable-file and symlink metadata without workspace access. The paths and order match untracked records in `diff_files` and the order returned by `git ls-files --others --exclude-standard`.

To form the production diff, concatenate `diff_body` and the untracked `patch` strings in order with no added separators, then retain the first 20,000 bytes. The remote script adds one newline after that captured prefix before the summary marker; that separator newline is part of the parsed diff body, even when the prefix already ends in a newline. If the remote command output itself is truncated, production returns an empty file list and body with `files_complete` and `diff_available` both false, while `diff_summary` retains full totals. The flags and full summary must be supplied independently; do not infer them from the captured patch strings.

`handoff_check` has string `status`, integer-or-null `exit_code`, and `evidence` (an object or null). Evidence has `diff` (an array of objects with string `status` and `path`, or null), `tests` (an object keyed by deliverable id; each value has integer `exit_code`, array `cases`, and optional base-run fields), and `commands` (an object keyed by deliverable id; each value has integer `exit_code` and string `output`).

Each test case in `cases` or `base_cases` has string `name` and `status`, and optional string `kind` and `message`. Optional base-run fields are boolean `base_placed`, integer `base_exit_code`, boolean `base_timed_out`, integer `base_timeout_seconds`, and array `base_cases` of the same test-case objects. `opens_pull_request` supplies the value passed to the reviewer instructions: when true, the reviewer must provide pull request summary and change fields; when false, those fields are not requested. The value cannot be derived from the subtask position alone. A continued review uses the same input shape, but its rendered packet omits opening-only material such as earlier approvals and the held resolution.

The renderer uses the same prompt construction as `TaskAgentSpawner` and `TaskReviewPacket`. Tests assert that a prompt rendered from these inputs is byte-identical to the prompt sent in production for the same inputs. The command never reads the database, the workspace, or the network; provide all prompt inputs in the JSON instead. This lets an offline evaluation harness render frozen cases without copying the production prompt template.

| Part | Cap | When it does not fit |
| --- | --- | --- |
| Resolution | 2,000 | The end is cut. `tasks-comment-list` returns the comment |
| Group brief | 2,000 | The end is cut. `tasks-show` returns the brief |
| Subtask brief | 2,000 | The end is cut. `tasks-show` returns the brief |
| Deliverables | 2,000 | One line each, at most 240 characters. The description is cut to 160. `tasks-show` returns every field |
| Earlier approvals | 1,500 | One line each, at most 200 characters. The oldest lines drop. `tasks-comment-list` returns the summaries |
| Diff stat | 1,500 | A summary line, then paths. Further paths are omitted. The stat command prints the rest |
| Handoff | 2,000 | Each command is cut to 160 characters. Further lines are omitted. `.git/orbit/check.log` holds the rest |
| Diff body | Remainder | Cut from the end, and at most 16,384 bytes. The diff command prints the rest |

The handoff lines name the Project task check, each deliverable `test` and `command`, and a base run when `fails_on_base` is set. When that base run fails, the line includes the failure kind. A message tail is included only while it fits in the handoff cap. [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit) records those lines. `.git/orbit/check.log` holds the command text and any tail that was cut. `.git/orbit/check.json` stores the exit codes.

The diff stat's summary line is the file count and the insertion and deletion counts, including untracked files. Dropped deliverable lines, dropped approval lines, and dropped handoff lines each leave one line that names how many were omitted. Orbit does not send a review when it cannot read the diff. That attempt is a communication failure, and the next tick tries again. It does not describe that failure as zero files changed. When the captured stat output is cut, the summary counts stay complete, the path list is left out, and the packet says the stat command prints the rest.

Any other failure while requesting that review is also a communication failure for that subtask. The tick still reviews the other groups. Orbit records the exception. After five failures the group asks for assistance. The reason is `The review could not be requested (ExceptionClass).` It names the exception class and does not include the exception message.

The diff and the stat in the packet are valid UTF-8. Orbit replaces bytes that are not before it keeps the text or cuts it.

The stat command prints the tracked stat and a stat for each untracked file. The diff command prints tracked changes and the content of each untracked file. Neither command updates the index. `git diff START` prints no untracked file, so the loop prints that content. `git status` is not used, because it prints paths only. Replace `START` with the subtask's start commit. `git diff --no-index` exits 1 when a file differs from empty, and `|| true` keeps the loop going.

Orbit records that commit when the subtask starts, before the implementer's first turn. When the read fails, Orbit does not store an empty commit. The next tick tries the read again until that turn starts. Once the turn has started, Orbit leaves the start commit empty, even if a later read succeeds. A commit recorded after that turn would drop the implementer's changes from the diff and from the base run.

When the start commit is empty, the review diff and the base run use the same fallback. A later subtask uses the previous subtask's approved commit. The first subtask uses the workspace starting commit, recorded when Orbit created the group workspace.

```bash
git diff --stat START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index --stat -- /dev/null "$path" || true; done
```

```bash
git diff START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index -- /dev/null "$path" || true; done
```

A continued re-review does not repeat the group brief, the deliverables, the earlier approval lines, or a resolution carried on the opening packet. That turn carries the new diff stat, the capped diff, both retrieval commands, and the new handoff result. The spared caps go to the diff body. When that thread cannot take a turn, Orbit starts a fresh thread and sends the full packet.

The reviewer does not re-run the Project task check or the deliverable tests and commands the handoff already passed. That includes `composer check` when it is the task check. The reviewer runs another command only to get evidence the handoff result does not give, and the review summary says why.

The reviewer prompt states this rule on the opening packet and on a continued turn. Orbit does not parse the summary to enforce it. The same prompt says the turn is read-only, that the implementer has no web access, and that the reviewer confirms framework and library usage against documentation for the Project's versions. Those checks are evidence the handoff result does not give. The opening packet also names the feature contract: the ADRs and documentation this branch changes against the Project default branch, such as `origin/main`. A continued turn does not repeat that sentence.

Orbit writes an untracked `.mcp.json` that points at `{gateway origin}/mcp/search` and excludes it from Git. That endpoint lists `search_tools` and `execute_tools`. The planner and the reviewer use this file, not the full catalogue at `/mcp`. Orbit writes the file before the planner starts, and before a reviewer starts when the workspace has no `.mcp.json`. A tracked `.mcp.json` stays unchanged. The [MCP server](/reference/mcp) describes both endpoints.

The Gateway sends no turn to a `working` thread. On a continued reviewer, the task still moves to `reviewing` and the request waits until that thread stops. Until then, the task has no review request, so no reviewer receipt is read. If sending the request fails, the next tick tries again before it reads a reviewer receipt.

While the reviewer's snapshot is still the turn from before this handoff, the Gateway waits. A fresh thread is new, so an operator talking to the planner or to an earlier reviewer does not delay it. The acting thread for a subtask in review is that subtask's reviewer. The planner thread does not defer the subtask.

## Session routing

A scheduler tick checks every in-progress task in running and reviewing groups. In-progress tasks have status `running` or `reviewing`. The tick checks the normalized AgentThread state of each attached reviewer or implementer thread, including sessions recorded only in `agent_threads`. Tasks without attached sessions are skipped. `todo`, completed, failed, and cancelled tasks do not ask Jev for decisions. The tick also reads the planner thread of every Backlog, Todo, running, and reviewing group that has one, so its state and token count stay current. That read asks Jev nothing.

AgentThread state is authoritative. The thread that acts in the task's phase defers the task while it is `working`, including a starting T3 session. That thread is the task's implementer while the task is `running`, and that subtask's reviewer while the task is `reviewing`. The Gateway then does not inspect that task's messages or pending requests, check workspace commits, or call Jev. Other snapshot fields cannot override an active status. The tick still checks the remaining sessions and other in-progress tasks.

Otherwise the tick checks for commits since the thread started. It reads the count from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh, and runs `git` over SSH otherwise.

The other thread's work does not defer the task. While the operator talks to the planner or to a reviewer that is not the acting thread, the tick still reads the implementer's receipt, asks for assistance on `blocked`, runs the handoff check, and sends reminders to the implementer. The Gateway sends no turn to the working thread until it stops. [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question) records the decision.

When the Project's task check runs the `composer check` command, not a longer command such as `composer check-platform-reqs`, the tick also reads `composer.json` at the workspace root over SSH. The `check_script` item then passes only when `scripts.check` is a non-empty command or list. For any other task check, or none, `check_script` passes. Composer resolves abbreviated command names, so without that script `composer check` runs the built-in `check-platform-reqs` command and exits 0. A missing file, invalid JSON, or a missing or empty `check` script fails `check_script`, and Orbit does not start the check.

A pending input fails `waiting_for_input` in code. A thread state the rubric does not recognize waits. The rubric makes no model call. When every item, the Project check, and the [deliverables](#verify-deliverables) pass, the Gateway sets the task to `reviewing`. [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) owns this rubric.

`assistance_requested` and `resolution` comments update the assistance flag and keep their history. Status stays the current phase for those two comments. The Gateway sends a non-empty resolution to the blocked thread: the reviewer thread whose `task_id` is this subtask when the task is `reviewing`, otherwise the task's implementer. A reserved reviewer row that has not started is not that thread. When that reviewer thread exists, the resolution counts as its next review request, so the tick does not send another.

When that reviewer thread does not exist, the Gateway does not use the group's reviewer pointer. It clears assistance without marking the review as requested, and the next tick starts a fresh reviewer whose opening packet includes the resolution.

The Gateway sends one reminder that names every failed code item. It starts and ends with fixed sentences:

| Role | Starts with | Ends with |
| --- | --- | --- |
| Implementer | "Orbit could not confirm the brief is complete." | The run script instructions that also end the implementer prompt |
| Reviewer | "Orbit could not confirm the review is complete." | The run script instructions that also end each review request |

The run script instructions name the commands for that role. The reminder installs the script again before it is sent. It does not say that the thread is blocked. An agent reports a blocker with a `blocked` receipt and a question for the operator. A receipt that the scheduler acted on is spent, so the next turn needs a new one.

The next idle evaluation asks for assistance when any item still fails. Repeated reminder-send failures ask for assistance on the fifth failure. The same pending input does not count as that next evaluation. A `Failed` thread asks for assistance without a reminder, except a Pi or T3 restart, which [Recover a Pi server restart](#recover-a-pi-server-restart) resumes.

### Recover a Pi server restart

A Pi turn that failed only because its server restarted is not a failed task. The error is `The Pi server restarted during the turn.` [Thread states](/reference/pi-server#thread-states) define it. [ADR 0167](/decisions/0167-resume-a-pi-turn-interrupted-by-a-server-restart) records the recovery.

The tick sends one message to that same thread and does not ask for assistance. The message is `Your previous turn was interrupted by a server restart. Check git status and git diff, finish the subtask, and hand off with the run script.`

The resume uses a new key, stored before the send. It does not reuse the key of the interrupted turn. A repeated key starts no turn, including after a restart. The tick repeats the stored key only while that same send is still unresolved. The tick does not read a receipt, run the rubric, or send a reminder first. It does not install the run script again. The script from the interrupted turn stays at `.git/orbit/run`.

The acting thread is the implementer while the subtask is `running`. It is the reviewer while the subtask is `reviewing`. That thread's driver is `pi` or `t3`. A planner thread is outside this rule.

T3 0.0.42 reports an equivalent failure when a provider session does not survive a server restart. Continue threads after restarts is off by default, so the usual error is `Provider session did not survive a server restart. Send a new message to continue.` When continuation is on and the continue fails, the error is `Could not continue this thread after the server restart. Send a new message to continue.` The tick resumes either error with the same message and the same limit of two. Any other T3 error asks for assistance, including the Pi restart text on a T3 thread.

One subtask gets at most two resumes. The implementer and the reviewer share that count. The subtask stores `pi_restart_resumes`, `pi_restart_key`, `pi_restart_thread_id`, `pi_restart_source_turn_id`, `pi_restart_reservation`, and `pi_restart_session_revision`. The count starts at 0. The key, the thread id, and the source turn id start null. The reservation starts null, then `pending`, `accepted`, or `superseded`. A resolution does not reset the count or these fields. A process stop does not reset them either. Show, the web board, and the agents API do not add them.

The count increases when the tick reserves a resume, before it sends. That write stores a new key, the acting thread, the interrupted turn id, and the exact `session.updatedAt` as `pi_restart_session_revision`, and sets the reservation to `pending`. The Pi driver sends that key and does not mint a different one for this send. On T3 the key is the command id and the message id. T3 0.0.42 keeps a receipt for that command id, so repeating it returns the receipt and starts no second turn.

The tick sends that stored key again only when the reservation is `pending`, the acting thread is the stored thread, and the observed turn id is still the stored source turn. That send is still unresolved. The tick does not add to the count.

When that same thread's turn id equals the stored key, Pi accepted the reservation. The tick marks it `accepted` and does not send the key again. A restart of that accepted turn is a new interruption.

T3 does not use the command id as the turn id. The tick repeats the stored command id while the reservation is pending, the turn id is the source turn, and the snapshot has no message with that command id. A message with that id means T3 accepted the command. T3 stores that message before the provider worker sets the session to `starting` and clears the previous error.

`pi_restart_session_revision` is the exact `session.updatedAt` from the observation that reserved the resume. The Gateway clock supplies the message time. The node clock supplies `session.updatedAt`. The tick does not order those two clocks, and it does not drop a fraction of a second from the stored text.

When that message is present and `session.updatedAt` is the stored revision, the restart error is the one from before the command. The tick does not send and does not reserve another resume. The session can then start without a second restart. This includes a node clock that is ahead of the message time.

When the message is present, the turn id is the source turn, and `session.updatedAt` is a different non-empty value, T3 wrote a new session error after the reservation and before it assigned a new turn id. A difference inside the same second counts. The new value can read earlier than the message. The tick marks the reservation accepted and does not send that command id again. It reserves a new command id when the count is below 2, and asks for assistance when the count is already 2. A different turn id supersedes a pending reservation.

When the observed turn id is a different turn, the tick marks a `pending` reservation `superseded` and does not send the old key. A reminder, a review relay, or a resolution can start that turn after the resume was accepted. Pi keeps the old key, so sending it again starts no turn.

A reservation stored for the implementer is not sent to the reviewer. A reservation stored for the reviewer is not sent to the implementer. The acting thread gets a new reservation when the count is below 2. When the count is already 2, the tick asks for assistance and does not send. That includes a restart during the turn a resolution started after both resumes were reserved.

The third interruption asks for assistance and does not send. While the subtask is `running`, the reason is `The implementer thread failed.` While it is `reviewing`, the reason is `The reviewer thread failed.`

Any other `failed` error asks for assistance on the first observation. A failed thread on another driver does the same. There is no resume. A restart error with no turn id asks for assistance and does not reserve a resume. A T3 thread whose error is not one of the two restart errors above asks for assistance on that first observation.

A send that throws leaves the pending reservation in place. It is a communication failure. The fifth consecutive failure asks for assistance. The next tick repeats the stored key only when that same thread still shows the same source turn. A returned send clears communication failures and does not change the count or the reservation.

A lost response does not drop the count. The next observation on that thread carries the stored key as its turn id. The tick marks the reservation `accepted` and does not spend another resume on that same acceptance.

The tick skips the resume while the subtask or the group is already asking for assistance. The stored reason stays.

A failed receipt read, script install, send, commit, or push counts as a communication failure for that task and asks for assistance on the fifth consecutive failure. The tick continues with the other tasks. The assistance reason names each remaining item.

Typed comments are the workflow record. They preserve the full body, author, timestamp, task and thread context, and reviewer attempt metadata. A stored receipt uses its outcome as the type and the role as the author. The final approval's comment also carries `pull_request`: the summary, changes, and breaking changes it proposed for the pull request. An approval that Orbit committed carries `commit_sha`. Other comments return `null` for both.

Only `assistance_requested` and `resolution` comments come through the API. They do not create a separate validation-evidence record or API. `assistance_requested` flags the task and group, retains the active slot, and is notified once. A non-empty `resolution` is sent only when that subtask is already asking for assistance. The Gateway then clears the flag and the communication failures.

While the subtask is `running`, the resolution continues the implementer and starts the next completion attempt. While it is `reviewing`, the resolution continues the reviewer thread whose `task_id` is this subtask, and that message counts as the next review request. A reserved reviewer row that has not started is not that thread. When no such reviewer thread exists, the Gateway does not send the resolution to the group's reviewer pointer. That pointer may still name the planner or an earlier subtask's reviewer.

In that case, assistance clears and the review stays unrequested. The next tick starts a fresh reviewer whose opening packet includes the resolution. A failed send to an existing thread leaves the task asking. A resolution posted before the flag is set is stored and not sent.

Each observation includes normalized activity state, availability, errors, pending request IDs, and recent assistant and user text. It also reports new workspace commits, the pull request URL, and any available CI summary. The driver resolves pending requests from its runtime data. Missing or unavailable current conversations are skipped. The scheduler waits `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` (default `120`), then escalates once per continuous outage. Recovery resets the grace period and alert marker.

Legacy scheduler actions:

| Action | Effect |
| --- | --- |
| `drain_approval` | Driver approval response accepting the current request |
| `drain_user_input` | Driver question response continuing the current brief and refusing scope expansion |
| `continue_implementer` | Driver follow-up on the implementer with its recorded model |
| `relay_review_to_implementer` | Driver follow-up on the implementer including the last reviewer excerpt |
| `mark_subtask_done` | Existing settleImplementer, acceptReview, and next-subtask spawn paths |
| `settle_group` | Existing settle path: use the verified PR, write metrics, and notify Coder when CLEAN-ready |
| `noop` | No driver action and no Coder notification |

Gateway uses `laravel/ai` Classification with its official TypeSafe provider in `config/ai.php`. The package client posts to TypeSafe. Tests use the package fake and never call the network.

Run the tick with `php artisan tasks:tick` while the extension is enabled. One lock in the Gateway cache store protects scheduled and manual ticks. A held lock skips the invocation without routing or claiming work. After current work and merge checks, the tick fills available Node capacity with the oldest `todo` groups.

A provisioning failure leaves the group in `todo` with its assistance reason visible, and the tick continues to the next eligible group. The tick tries each failing group once. A full fleet ends the claims for that tick without a reason on any group. Groups that are reserved, running, reviewing, settling, assisted, or awaiting merge count toward the limit of 10.

The Gateway registers `tasks:tick` and `tasks:collect-t3-metrics` every ten seconds when the tasks extension is enabled. The T3 collector reads at most 20 due threads per run, least recently collected first. Failed or incomplete reads use an increasing retry delay so threads outside a failing batch remain eligible on the next run. A heartbeat-only timeout is incomplete; a final collection requires a valid snapshot or event.

A new T3 turn makes its thread eligible again. A terminal thread state counts as settled only when an observation matches the current activity version; task and group terminal statuses are authoritative. T3 sends hold an owner-token lease for up to 60 seconds. The collector and thread observer ignore expired leases, and each collector run removes at most 100 expired lease rows. A late sender cleanup removes only its own lease. A settled thread is excluded only after one successful final read.

LIVE Ops must run Laravel's `php artisan schedule:work` process for this schedule to advance sessions; this feature does not provision that process or a fleet cron.

### Project check

Each Project stores a task check in `task_check`, such as `composer check`. Orbit runs it after each `ready_for_review` receipt whose items pass, and on the fresh workspace before the first implementer starts. The Gateway installs `.git/orbit/check` and starts it over SSH as a detached process group. The check records HEAD and the tree of the whole working tree, uncommitted and untracked files included, without touching the Git index. It runs the task check in a login shell in the workspace root, writes the output to `.git/orbit/check.log`, and writes `.git/orbit/check.json` when the command ends. [ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off) records the decision.

The task stays `running` during the check. On each tick the scheduler reads the check. It identifies the process by its ID and its start time, so a reused process ID does not count. There is no time limit.

| Check state | Result |
| --- | --- |
| Running | The scheduler waits. The task's `check` shows its start time. |
| Exit code 0, HEAD and tree unchanged | `passed`. Orbit [verifies the deliverables](#verify-deliverables). When they pass, the reviewer starts. |
| Exit code not 0 | `failed`. The implementer's reminder holds the exit code and the end of the output. |
| HEAD or tree changed during the run | `changed`. The check runs again once. A second change fails, and the reminder names the changed paths. |
| Killed from outside, leaving no result | `lost`. The check runs again once. A second loss asks for assistance. |
| Cancelled by an operator | `cancelled`. The implementer's reminder says so. |

The check never dies without a result. An unexpected error in the check script writes a failed result, and the output ends with the error. The task shows `failed` with the cause, not `lost`. `lost` means only that the check process was killed from outside.

A new Project gets the task check of its type unless it sends one: `composer check` for `laravel-app` and `laravel-package`, and none for `monorepo` and `node-package`. The type defaults apply to new Projects only. The upgrade sets `composer check` on every existing Project, whatever its type, because every Project ran that check before. An existing Project without a Composer `check` script, such as a `node-package` Project, does not hand off until an operator changes or clears its task check. Change it with `PATCH /api/v1/projects/{project}` or `orbit project:update <project> --task-check=COMMAND`. Clear it with `task_check: null` in the API or `--clear-task-check` in the CLI. `project:show` shows it.

When a Project has no task check, the baseline runs the setup steps and passes, and a handoff runs no command. A handoff still records the tree and verifies the deliverables. The implementer's instructions and the pull request description name the configured check, or leave it out when there is none.

Before the first implementer of a group starts, Orbit runs the setup steps and the task check on the fresh workspace. The setup steps keep the timeouts of the Project's setup list, so the [540-second step limit](/reference/instance-setup) applies to the baseline too. The baseline runs outside an API request, so no request deadline shortens the list further. Project setup runs before dependency preparation so it can configure credentials or install dependencies itself.

Orbit prepares Composer dependencies when the task check runs `composer` or references `vendor/`: it walks tracked `composer.json` files and runs `composer install --no-interaction --prefer-dist` where `vendor/autoload.php` is missing and either the file is at the repository root or a sibling lockfile exists. A root package without a lockfile, such as a Laravel package, is installed too. Orbit then removes the `composer.lock` that the install wrote, so it cannot reach the task commit. Nested manifests without a lockfile are skipped because test fixtures use them.

Orbit prepares JavaScript dependencies when the task check references Bun, npm, pnpm, Yarn, Node, Vite+, or `node_modules`: it walks tracked `package.json` files and runs `vp install --frozen-lockfile` where a supported lockfile exists and `node_modules` is missing. Both guards skip projects whose dependencies are already installed. Other task checks receive no automatic install prep. Handoff checks do not prepare dependencies.

A failed setup or dependency install asks for assistance and names that step. A failed baseline command reports `The Project baseline check failed` with its exit code and output. If command output indicates missing `vendor/` or `node_modules` dependencies, assistance reports `Project dependencies appear to be missing` rather than calling the branch broken. Fix the cause, then cancel and create the group again. A task's `check` shows the latest run, with `kind` `baseline` or `handoff` and the `failed_step`.

Call `tasks-check-cancel` with `{ "group": 123, "task": 456 }` to stop a running check. The API operation is `tasks:check:cancel`. A task without a running check answers `409` with `tasks.check_not_running`. A failed or cancelled check spends the reminder of that completion attempt, so a second failure asks for assistance. Each run is stored with its receipt, status, process, HEAD and trees, times, exit code, changed paths, and the last 16 KiB of output. The task's `check` field shows the latest run.

## Pull request and settle metrics

Orbit opens the pull request after the approval of the last subtask. That approval describes it with `--pr-summary`, at least one `--pr-change`, and at least one `--pr-breaking`, or `--pr-breaking=none`. The script accepts these flags only for that approval and refuses the approval without them. There is no limit on the number of changes.

Before Orbit commits the last subtask, Jev checks that the change list covers every subtask of the group except cancelled and failed subtasks. Jev reads the group and subtask briefs and the pull request fields. For each checked subtask, it answers whether a listed change delivers it. A subtask counts as covered when Jev gives "yes" a probability of at least one half. Jev cannot read code, so this checks coverage, not correctness. Each missing subtask fails `brief_coverage`, and the reviewer's reminder names it. A failed Jev request counts as a communication failure.

On the last subtask, the reviewer owns the pull request change list, summary, and breaking-changes list in its approval. A missing or incomplete entry is never a reason to request changes; the reviewer writes the complete entries as part of its approval.

### Jev decision records and report

Every call to Jev is stored in the Gateway's `jev_decisions` table, including failed calls. Each record captures its purpose, group, subtask, and agent-thread identifiers when the call concerns them. It records each question's type, options, and criteria, along with the input state sent with secrets redacted. Each answer stores its value, probability distribution, provider confidence when returned, and selected-answer probability separately. The record also stores the provider model identifier when returned, latency, and a sanitized error code on failure. The Gateway stores no secrets or raw provider error bodies. Records are retained indefinitely as Orbit's training and evaluation dataset.

The stored `questions`, `input_state`, and merge-time `merge_changes` snapshots are each capped at 64 KiB after redaction. An oversized `questions` or `input_state` snapshot carries truncation metadata; the Gateway preserves its structure where possible and uses a bounded marker when it cannot fit. The labeler leaves question labels unknown when either snapshot is truncated. Truncated merge-time change evidence is also incomplete for labeling.

The live `brief_coverage` questions are Boolean. Laravel AI v1.0.0 returns `P(true)` for a Boolean answer and has no separate provider-confidence value for it. Store `P(false) = 1 - P(true)`; the selected-answer probability is `P(true)` for “covered” and `P(false)` for “missing.” Choice questions instead use the provider's probability for the selected option as selected-answer probability and keep nullable provider confidence separate. If the selected-answer probability is absent or invalid, keep the answer and record a null selected-answer probability.

For `brief_coverage`, store the approved change-list snapshot from the final approval comment and the pull request's `## Changes` list at merge. The merge-time list is authoritative. Normalize text with Unicode NFKC, case-folding, punctuation-to-space replacement, and whitespace collapsing. A line names a subtask only when it contains the complete normalized title as a whole-token sequence, the title is unique in the group, and exactly one line matches. The line must appear in both the approved list and the merge-time list. Ambiguous titles or missing snapshots remain unlabeled.

Only a Jev “missing” answer with exactly one named approved line at merge is labeled `false_negative`. All other question answers are unlabeled, including a missing answer without a named line and every covered answer. Failed or unanswered calls, unmerged or closed pull requests, and incomplete or ambiguous evidence also remain unlabeled. Labels record their approval snapshot, merge snapshot, merge SHA, matching line, and rule version as provenance.

When all answers in a call said “covered” and the pull request merges, the call receives a separate call-level `correct` label. This records only the merged call outcome; it does not validate each answer against the change list. Mixed-answer calls do not receive this call-level label.

The Gateway report command `orbit:tasks:jev-report` reports calls, failures, labeled share, false-negative count and share of missing answers, false-negative confidence buckets, call-level `correct` count, and p50/p95 latency for each purpose. Labeled share counts calls with at least one labeled question or a call-level label. The Gateway produces only the `false_negative` question label; its share uses all missing answers as the denominator.

For each false negative, its selected-answer probability determines the confidence bucket. Latency percentiles include measured call durations, including failed calls; missing latency is excluded. A purpose with no missing answers has a null false-negative share, and a purpose with no measured latency has null percentiles. See [ADR 0173](/decisions/0173-record-and-label-jev-decisions) for the full contract.

After every approved commit, including the last, the Gateway pushes that stored commit to `task-{group id}` on `origin` through the [Gateway GitHub App](/reference/github-app). The push uses that repository's installation token with `Contents: write` and `Pull requests: write`, passed on the SSH process standard input, and runs `git push --quiet origin <commit_sha>:refs/heads/task-{group id}`. `<commit_sha>` is the commit stored on the approval. The push never uses `HEAD`.

That push is the same one that opens the pull request. [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone) records the decision.

A failed push of an approved commit counts as a communication failure and names git's error. The commit stays in the workspace and on the approval comment as `commit_sha`. The Gateway does not reset the branch. The subtask stays in review, so the next subtask does not start and the pull request does not open, until the push succeeds.

The tick retries the push after 1 minute, then 2, 5, 10, and 30 minutes, and further failures stay at 30 minutes. It does not push on every tick. The fifth consecutive failure asks for assistance with a reason prefixed `Approved commit publication failed: `, and the retries continue on that backoff. When the Gateway cannot read or write that backoff in its cache, it logs a warning and tries the push as if no backoff is stored.

Every failed push of an approved commit names git's error. That includes this publication, cancel, and the sweep that pushes a stored approval before it deletes a cancelled checkout. When the Orbit GitHub App lacks the Workflows permission, GitHub refuses a push that changes `.github/workflows/`. The reason says so and names `Workflows` as the permission to grant.

After the push succeeds, and after the open succeeds on the last subtask, the Gateway clears an assistance request only when its reason starts with `Approved commit publication failed: `. A request with any other cause stays on the subtask and on the group.

On the last approval, after that push, the Gateway opens the pull request against the Project's default branch. When an open pull request already has that head, the Gateway uses it. The Gateway stores the URL as the group's `pr_url` and moves the group to `settling`. A failed open counts as a communication failure, uses the same backoff and the same assistance prefix, and is retried. The open pushes the stored commit again with the same token. A branch that already points at that commit stays as it is.

The group title is the pull request title. The description holds the summary, a Changes list, a Breaking changes list or `None.`, and one line that says each delivered subtask passed the Project's task check and reviewer approval. Without a task check, the line names only reviewer approval. That line does not count cancelled or failed subtasks.

Settling watches the stored pull request through the GitHub App until it merges. A merged pull request completes the group, and a pull request that closes without merging requests assistance. A settling group without a URL and without a `todo` subtask requests assistance and remains incomplete until an operator cancels it. A `todo` subtask on that group returns it to `running` on the tick. [Fix a settling pull request](#fix-a-settling-pull-request) owns that return.

### Fix a settling pull request

[ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask) owns this response. [ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks) detects the problems.

While the pull request is open, each tick checks it. The pull request conflicts when GitHub reports it as not mergeable. A null mergeability result is not a conflict. A check fails when a run on the head commit completes with `failure`, `timed_out`, `cancelled`, `startup_failure`, or `action_required`. Without the App permission `checks: read`, the Gateway sees conflicts only.

The Gateway ignores the rollup check `Required checks` while another failed check explains the failure, so one real failure is one problem. A rollup that fails alone stays a problem.

Only `failure`, `timed_out`, and `action_required` are genuine failures. `cancelled` and `startup_failure` are infrastructure, such as a GitHub Actions outage. They never get a fixup. When they are the only problems, the group waits and re-evaluates on the backoff of 1, 2, 5, 10, and 30 minutes. If they persist after that, it asks for assistance and adds `Those checks were cancelled or could not start, and did not recover. Re-run them.` to the reason. A genuine failure next to them still gets a fixup.

A check run that has not completed is pending. When GitHub sends `started_at`, the age is that time compared with the tick. When `started_at` is null, the Gateway stores the first tick that read the run as not completed on that head. The check run id is the key. A run with no id uses its name on that head. Reading the run again does not move that stored time. The Gateway keeps it until the run completes or the head changes, and the age starts there.

While any run on the head has been pending for 60 minutes or less, the Gateway still reports each completed genuine failure. The reason is the one [ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks) defines: it starts with `The pull request needs attention: ` and has one sentence per completed problem. Coder is notified only when that text changes. The Gateway appends no check fixup during that wait. A conflict does not wait.

A run pending for more than 60 minutes is infrastructure, with `cancelled` and `startup_failure`. It never gets a fixup. The backoff of 1, 2, 5, 10, and 30 minutes starts only when every current problem is infrastructure and no run is still inside those 60 minutes. After the fifth wait, the reason adds the same re-run sentence. It also names each run that is still pending past 60 minutes: `Check {name} is still pending: {url}.` With no URL, the sentence is `Check {name} is still pending.`

A genuine failure beside that run still gets a fixup. When the run completes, the pending classification ends. `failure`, `timed_out`, and `action_required` are genuine failures. `cancelled` and `startup_failure` stay infrastructure. Every other conclusion clears the problem.

A conflict's identity is `conflict:` plus the base branch name. A failed check's identity is `check:` plus the check run name. The check URL is not part of the identity. The Gateway stores the identity on the fixup as `fixup_problem`. Show returns it. An operator subtask leaves it null, and the cap ignores that subtask.

Each cap counts only fixups created after the most recent completed subtask whose `fixup_problem` is null. With no such completed subtask since the group started, the counts include every fixup in the group, as before. Every fixup status counts, including `cancelled` and `failed`.

The non-fixup subtask is appended by a person—the operator or planner—so each reset requires a human step between fixup loops. Show and the assistance reason name the counts that applied; an assistance reason identifies the reached per-problem or group cap. For example, an identity cap adds `Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).`; the group cap remains `Orbit already appended 3 fixups to this group.` A non-fixup subtask that is not completed does not reset either cap.

Each fixup records the pull request head it was created for. The Gateway appends no new fixup while the head is still that commit. After the head changes, it appends no check fixup until every check on the new head has completed or has been pending for more than 60 minutes. A conflict does not wait for checks.

When the last fixup changed nothing, because it was never approved or its approved commit is that same head, the Gateway asks for assistance instead of a second fixup on the same result. The reason adds `Fixup subtask #{id} changed nothing, so Orbit does not try again on the same result.` When the last fixup did commit, the Gateway waits for GitHub to report the new head.

When a problem has fewer than two fixups, one tick appends one fixup and returns the group to `running`. It picks the first such problem. A conflict comes first. Then a failed check that has a reproduction row, in the order GitHub returned. Then any other failed check, in that same order. A `todo` subtask that is already waiting stays first, and that tick appends no fixup.

A conflict fixup is titled `Merge origin/{base}`. Its brief is `Merge origin/{base} into the task branch and resolve the conflicts. Do not rebase and do not force-push.` A check fixup is titled `Fix {name}`, cut off at 160 characters. Its brief is `Check {name} failed: {url}. Do not rebase and do not force-push.` With no URL, the brief is `Check {name} failed. Do not rebase and do not force-push.`

Every fixup has a `command` deliverable `composer-check`: command `composer check`, directory `.`, description `Run composer check`. When the Project slug is `orbit` and the table lists the check name, the fixup also has `reproduce-check` with that command and directory. The description is `Reproduce {name}`. The same command and directory are stored once.

| Check name | Command | Directory |
| --- | --- | --- |
| `CLI` | `composer check` | `apps/cli` |
| `Docs` | `composer check` | `apps/docs` |
| `Gateway` | `composer check` | `apps/gateway` |
| `E2E` | `composer check` | `apps/e2e` |
| `PHP SDK` | `composer check` | `packages/php-sdk` |
| `API reference` | `bin/docs-openapi --check && bin/mcp-tools --check` | `.` |
| `Web` | `copy=$(mktemp) && cp src/api/schema.d.ts "$copy" && bun run types && git diff --exit-code --no-index "$copy" src/api/schema.d.ts && bun run check && bun run test && bun run build` | `apps/web` |
| `Pi server` | `bun run check && bun run test && bun run build` | `apps/pi-server` |
| `Agent annotation` | `bun run check && bun run build && bun run test` | `packages/agent-annotation` |
| `Rust agent` | `cargo fmt --all -- --check && cargo clippy --locked --all-targets -- -D warnings && cargo test --locked` | `apps/agent` |

These rows apply only when the Project slug is `orbit`. The command is the job's check steps, not its setup. Any other slug, and any name not listed, has no reproduction command. The Web row also checks API schema freshness. It copies `src/api/schema.d.ts`, runs `bun run types`, and diffs that copy with `git diff --exit-code --no-index`. CI runs `bun run types && git diff --exit-code src/api/schema.d.ts` on a clean checkout. The copy lets an uncommitted schema that already matches the generator pass.

The fixup uses a new implementer thread and a new reviewer thread for that subtask. The handoff check runs the Project task check and the deliverable commands. After approval, Orbit commits and pushes that stored commit with the [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone) refspec. The push is not a force push. Orbit does not rebase. The open pull request takes the new commits. Orbit does not open a second pull request, and `pr_url` stays.

When `pr_url` is already stored, the reviewer does not send `--pr-summary`, `--pr-change`, or `--pr-breaking`.

Before that push, the Gateway reads the pull request state again. When the pull request has already merged or closed, Orbit does not push. It asks for assistance with a reason that starts with `An approved commit is not on the pull request: ` and names the commit. An unreadable state is a publication failure and waits out the backoff. When the group returns to `settling`, the Gateway reads the pull request once more. If it merged and its head is not the latest approved commit, the group asks for assistance naming that commit. The group then stays `settling`, and its workspace stays, until an operator completes or cancels it.

Before a resumed subtask leaves `todo`, the Gateway fetches `origin/task-{group id}` with the pull request token. When the workspace is strictly behind that ref, it fast-forwards the workspace with `git merge --ff-only`. A workspace that is level, ahead, or diverged stays as it is. The Gateway never forces. A commit pushed to the task branch by someone else then does not cause a non-fast-forward push. On a group with no `pr_url`, a missing `origin/task-{group id}` is not a failure. The workspace stays as it is and the subtask starts. When that ref exists, the same fast-forward applies.

Before a conflict fixup leaves `todo`, the Gateway also runs `git fetch --quiet origin {base}` with the pull request token. `{base}` is one argument. The fetch updates the remote-tracking ref only. A failed fetch leaves the subtask `todo`. It counts as a communication failure, and the next attempt waits out the backoff of 1, 2, 5, 10, and 30 minutes. The fifth consecutive failure asks for assistance. The implementer starts after the fetch succeeds, then merges `origin/{base}` and resolves the conflicts.

The Gateway does not merge the pull request. The coordinator reviews and merges.

A `todo` subtask on an open settling pull request, or on a settling group with no `pr_url`, returns the group to `running` on the tick. The Gateway's own fixup starts in that same tick. An operator's subtask starts on the following tick. The tick starts the lowest `todo` subtask and uses the usual baseline rule. The implementer is a new thread. The reviewer is a new thread for that subtask. When that subtask's approval is the last one and `pr_url` is not stored, Orbit opens the pull request and stores it.

The tick clears assistance when the reason starts with `The pull request needs attention: ` or `The settling group has no reviewed pull request URL.` The missing-pull-request reason does not block the start. Another cause stays, and the tick then starts nothing and appends nothing.

A check fixup or an operator subtask becomes `running` in the same commit that returns the group to `running`. The implementer starts after that commit. A conflict fixup returns the group to `running` before the base fetch, and becomes `running` only after the fetch succeeds. When a tick stops after the group is `running` and before the implementer exists, the next tick starts that same subtask and does not append another fixup.

A merged pull request completes the group and does not start a `todo` subtask. A pull request that closed without merging asks for assistance and does not start one. A settling group with no `pr_url` and no `todo` subtask asks for assistance and does not start one. The reason is `The settling group has no reviewed pull request URL. Cancel the group to push its approved commits to task-{group id} and remove its workspace.` The Gateway writes that reason once and does not replace another assistance cause.

When the group returns to `settling` and `pr_url` is already stored, Orbit refreshes settle metrics and does not post `task_group.settled` again.

When every current problem already has two fixups, the Gateway asks for assistance once. The reason starts with `The pull request needs attention: ` and has one sentence per problem, such as `It conflicts with main; merge main into the task branch and push.` or `Check Rust agent failed: <url>.` The same reason on the next tick changes nothing. Coder is notified once for each new reason. A healthy open pull request, and a merged one, clear that request only. Another cause stays. A merged group therefore completes without that request.

If completion fails after the merge, the group requests assistance with the cleanup failure as its reason, prefixed with `Merged pull request cleanup failed: `. The checkout and the Instance row stay, and the next tick retries the removal.

A failed GitHub read changes nothing. The Gateway reads the check runs of one head commit at most once a minute, so a re-run on the same commit shows within a minute and a new push shows at once.

The Gateway then writes settle metrics. Active groups also refresh these fields when an authorized caller shows the group.

| Field | Record | Source |
| --- | --- | --- |
| `tokens` | Task | Cumulative tokens reported by the current implementer's driver. Unknown until reported; failed reads preserve stored values |
| `line_diff` | Task | Reported insertions plus deletions for the current implementer. Failed reads preserve stored values |
| `lines_added`, `lines_deleted` | Task | Separate checkpoint insertion and deletion counts; null before observation |
| `duration_ms` | Task | Elapsed milliseconds from `started_at` to `settled_at`, or to now while the subtask is still open |
| `tokens` | TaskGroup | Sum of Task `tokens` plus reported tokens on every started reviewer thread, planner included, or `0` when none are stored. A reserved row is not included |
| `line_diff` | TaskGroup | Insertions plus deletions of `git diff --numstat {default_branch}...HEAD` in the shared checkout, or `0` when git cannot run. This is the whole feature branch, not the sum of subtask session diffs |
| `lines_added`, `lines_deleted` | TaskGroup | Separate branch insertion and deletion counts; null before a successful observation |
| `duration_ms` | TaskGroup | Elapsed milliseconds from `started_at` to settle, or to now while the group is still active, or `0` when `started_at` is empty |

For T3, token totals use `totalProcessedTokens` when present and otherwise `usedTokens`. Per-thread line counts come from checkpoints. Other drivers supply metrics with the same meaning or leave them unavailable. The [thread token metrics](#thread-token-metrics) are the per-call split beside that total. The scheduled T3 collector reads no more than 20 eligible threads each run. Failed or incomplete reads back off exponentially, allowing later threads to make progress; heartbeat-only timeouts do not count as a successful final read. A new T3 turn reopens metrics collection for that thread. Collector failures are reported at most once per thread and exception kind per hour.

## Coder settle webhook

When `notify_coder` is true, settle POSTs an HMAC-signed JSON body to Coder. This is Commander's `notify_on_settle` path.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_CODER_WEBHOOK_URL` | HTTPS endpoint that receives the settle POST |
| `ORBIT_CODER_WEBHOOK_SECRET` | HMAC-SHA256 secret. The Gateway never returns it |
| `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` | Seconds before one alert for an observation outage. Defaults to `120` |
| `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | Seconds a group may stay `reserved` before the tick returns it to `todo`. Defaults to `3600`, with a minimum of `60`. Keep it above the slowest workspace provision |
| `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` | Driver key for implementers of new groups. Defaults to `t3` |
| `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` | Driver key for the reviewer of new groups. Defaults to `t3` |
| `ORBIT_TASKS_IMPLEMENTER_MODEL` | Implementer model for new groups. Defaults to `gpt-5.6-luna` |
| `ORBIT_TASKS_REVIEWER_MODEL` | Reviewer model for new groups. Defaults to `claude-opus-5` |
| `ORBIT_T3_PORT` | T3 HTTP port. Defaults to `3773` |
| `ORBIT_T3_TOKEN` | Optional bearer for that Node's T3 server |
| `nodes.settings.t3.token` | Required bearer projected with each node when node-scoped T3 credentials are enabled. A projected node never falls back to `ORBIT_T3_TOKEN`; missing configuration fails closed. |
| `nodes.settings.t3.url` | Optional full base URL for that node's T3 server. When absent, the node's WireGuard address and `ORBIT_T3_PORT` are used. |
| `TYPESAFE_API_KEY` | TypeSafe key used for Jev calls, including brief-coverage checks |

The Gateway skips the webhook when the URL or secret is missing. A refused Coder response does not fail settle.

The signed payload is `{unix timestamp}.{raw JSON body}`. Senders use these headers:

| Header | Value |
| --- | --- |
| `X-Orbit-Timestamp` | Unix seconds used in the signature |
| `X-Orbit-Signature` | `sha256=` plus the hex HMAC of `timestamp.body` |
| `Content-Type` | `application/json` |

The JSON body contains `event` (`task_group.settled`), `task_group_id`, `title`, `tokens`, `line_diff`, `duration_ms`, and `pull_request_url`.

An `escalate_coder` Choice posts the same HMAC headers with `event` `task_group.escalated`. That body adds `reason`, `confidence`, `thread_id`, and the structured observation. The scheduler does not post Coder webhooks for drains, continues, relays, or noops.

An `assistance_requested` comment or unresolved workflow omission posts `event` `task_group.assistance_requested` with the task-group id, title, and reason. The task and group remain flagged until the Gateway accepts a non-empty resolution.

## Complete and cleanup

After Coder review and PR merge, an authorized Gateway caller runs `tasks:complete` (`POST /api/v1/task-groups/{group}/complete`, MCP tool `tasks-complete`). That marks the group `completed` and removes the shared Instance through the existing Instance remover, including any visitable Routes.

Cancel and complete remove the workspace clone on the Node. The forced Instance remover deletes the checkout directory recorded on the Instance and writes a removal record whose `source_finalization` step deleted that directory. This includes a non-visitable task workspace that stayed `source_resolved` because it has no Route. The Instance row is deleted only after that record is complete. A successful cancel or complete leaves no checkout at the recorded path. [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone) records the decision.

The same removal deletes that group's Incus bridge worktree on the Node. The bridge is the linked worktree `<worktree root>/task-{id}-e2e` on branch `task-{id}-e2e` of the primary checkout registered for the repository. [ADR 0135](/decisions/0135-run-incus-topologies-for-task-workspace-clones-through-a-bridge-worktree) records it. Removal deletes that worktree only when the path and the branch both match the group, including when the clone is checked out on another branch. A user's worktree stays. A missing bridge is not a failure.

Branch `task-{id}-e2e` is deleted when no worktree has it checked out. A worktree on that branch at another path stays, and so does the branch. The `refs/orbit/e2e-bridge/task-{id}` ref is deleted either way. The sweep retries this with the checkout. Removal does not release an Incus topology the bridge still holds, so release that topology before the group ends.

When `tasks:complete` cannot remove the workspace, it still marks the group `completed` and keeps the Instance attached. The group asks for assistance with `Workspace removal failed: `, and the response reports that removal failure on the completed group. A permanently lost Node does not stop the operator from completing the group. Merge cleanup that fails before the group is completed uses the reason prefix `Merged pull request cleanup failed: ` and leaves the group `settling`. Cancel, complete, and the tick's removal of a leftover workspace use `Workspace removal failed: ` once the group has ended. The checkout and the Instance row stay, so the clone is still named by a record.

The next tick's sweep retries a `cancelled` or `completed` group that still has a workspace, including a failed manual complete, and a `settling` group whose merged pull request cleanup failed. For a cancelled group that still holds an unpushed stored approval, the sweep pushes that commit before it deletes the checkout. The backoff under [Scheduler and ceilings](#scheduler-and-ceilings) applies: 1 minute, then 2, 5, 10, and 30 minutes. Repeating `tasks:cancel` or `tasks:complete` retries at once. Success clears an assistance request only when its reason starts with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. Another cause stays. The tick does not remove the workspace of a group that is still active.

Complete is the documented cleanup path. The Gateway GitHub App receives no merge webhook. A second complete of a group whose checkout is already gone is idempotent. A second complete while the Instance is still attached retries removal at once. Completing a group that is not `settling` or already `completed` returns `tasks.not_settling` (HTTP 409). Operators may still call `DELETE /api/v1/instances/{instance}` directly; that leaves the group `settling` until complete runs.

## Out of this slice

These items stay unimplemented here and need a later feature PR.

- Commander data migration and retiring Commander
- Creating or changing tasks through the web UI
- Per-Project model overrides
- Tom-on-Mini routing
 - Fleet TypeSafe key mint (Ops after CLEAN)

## Cancel a stuck group

Call `tasks-cancel` with `{ "group": 123 }`, or run `orbit tasks:cancel 123`, to cancel a `backlog`, `todo`, `reserved`, `running`, `reviewing`, or `failed` group, or a `settling` group without a `pr_url`. The API operation is `tasks:cancel`. Cancellation removes the group's workspace: the attached Instance, or the unattached `task-{group id}` workspace that an interrupted claim left behind. A group without either is only marked `cancelled`. A group `reserved` within `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` has a claim in flight, so cancellation marks it `cancelled` and leaves the workspace to that claim, which removes it. A workspace that the claim attached before the cancel is removed by the cancel. When removal succeeds, cancellation clears both taskable fields and returns the group as `cancelled`.

Repeating cancellation is safe and also cleans up an Instance still attached to a group already marked `cancelled`. Subtasks that are not completed or failed become `cancelled`. When removal succeeds, cancellation clears `assistance_requested` on the group and its subtasks and keeps the last `assistance_reason`. An unreachable Node records `Workspace removal failed: ` instead. Subtask records and agent thread identifiers stay as history.

Cancellation removes the workspace with the forced Instance remover, which deletes its checkout and cleans up its Routes, including a route-free workspace that never became active (`reserved`, `checkout_prepared`, or `source_resolved`). On success the checkout is gone. It also removes the group's bridge worktree, as [Complete and cleanup](#complete-and-cleanup) describes. Cancellation does not interrupt the external agent conversation.

When the Node is unreachable, cancel still marks the group `cancelled` and keeps the Instance attached. It asks for assistance with `Workspace removal failed: ` and returns the group in that state. It does not wait for a push the Node cannot accept. A permanently lost Node does not keep the group open: the operator's cancel ends it, and the Instance row keeps the checkout named until the sweep deletes it or the operator deletes the directory. The sweep retries removal on the backoff under [Scheduler and ceilings](#scheduler-and-ceilings).

When the Node answers, cancellation of a `settling` group with an approved subtask first pushes the latest stored approved commit to `task-{group id}` on `origin`, as `<commit_sha>:refs/heads/task-{group id}`. A failed push returns HTTP 502 with `tasks.push_failed`, names git's error, and keeps the group and its Instance, so you can retry. Uncommitted workspace changes are not pushed. The push runs outside any database transaction. A removal that fails while the Node answers also returns an error, leaves the group uncancelled, and asks for assistance with `Workspace removal failed: `.

When `origin` already has an unrelated `task-{group id}` branch, Git rejects the push, because it is not a force push. A Gateway rebuild that reuses group IDs causes this. Keep that branch under another name if you need it. Then delete `task-{group id}` on `origin` and cancel again.

A `completed` group, or a `settling` group with a `pr_url`, returns HTTP 409 with `tasks.not_cancellable` (an MCP error result). Use `tasks-complete` for a settling group after review and merge.
