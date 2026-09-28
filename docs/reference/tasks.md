---
title: "Tasks"
description: "How the optional Gateway Tasks extension runs task groups: the model, the lifecycle, typed deliverables, the task check, agent threads, review, the pull request, fixups, metrics, and cleanup."
covers:
  - "apps/gateway/app/Domain/Tasks/**"
  - "apps/gateway/app/Infrastructure/Tasks/**"
  - "apps/gateway/app/Actions/Tasks/**"
  - "apps/gateway/app/Http/Requests/Tasks/**"
  - "apps/gateway/app/Http/Controllers/Api/{TasksController,TaskGroupsController,AgentThreadsController}.php"
  - "apps/gateway/app/Console/Commands/{TickTaskSessionsCommand,CollectT3MetricsCommand,ArchiveTaskThreadsCommand,RenderTaskPromptCommand,JevReportCommand}.php"
  - "apps/gateway/app/Models/{TaskGroup,Task,TaskComment,TaskCheck,AgentThread,JevDecision}.php"
  - "apps/gateway/resources/tasks/**"
---

# Tasks

Tasks is an optional Gateway extension. It runs planned work with coding agents. A task group is one feature or bug fix, delivered as one pull request. Its Tasks run in order in one shared task workspace. A fresh implementer builds each Task, the Project's task check verifies the handoff, and a fresh reviewer approves it. Orbit commits and pushes each approved Task. After the last approval, Orbit opens the pull request and watches it until it merges.

The engine is generic. Your agentic development environment (ADE) plans and steers the work. Orbit runs it. Each Project keeps its own task policy in its repository, as an `orbit-tasks` skill under `.agents/skills/`, and enforces it through its own task check. The Orbit repository keeps its policy in the [orbit-tasks skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) and the [contributor guide](/contributor-guide).

Agents use the Tasks tools of the [MCP server](/reference/mcp). The [`tasks` CLI family](/cli/tasks) runs the same operations from a terminal. There is no web UI to create or change a group. The API, the CLI, and the web app call a Task in a group a "subtask".

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

A **TaskGroup** is one feature. A **Task** is one ordered unit of work in it. Both store a `brief` with the goal and the acceptance. A Task also stores a typed list of [deliverables](#deliverables) that Orbit checks at handoff.

| Field | Record | Meaning |
| --- | --- | --- |
| `title`, `brief` | both | Short name, and the goal and acceptance |
| `status` | both | Lifecycle state |
| `assistance_requested`, `assistance_reason` | both | Whether the record asks an operator for help, and why. Clearing the flag can keep the last reason |
| `position` | Task | Order in the group, gapless from 1 |
| `deliverables` | Task | The typed items the Task must deliver |
| `check` | Task | The latest [task check](#project-check) run |
| `fixup_problem` | Task | The problem a [Gateway fixup](#fix-a-settling-pull-request) repairs. Null on every other Task |
| `implementer_agent_thread_id` | Task | The Task's implementer thread |
| `taskable_type`, `taskable_id` | TaskGroup | The task workspace Instance. Null until the scheduler provisions it |
| `reviewer_agent_thread_id` | TaskGroup | The current reviewer thread |
| `implementer_model`, `reviewer_model` | TaskGroup | The models of the group's threads |
| `pr_url` | TaskGroup | The pull request Orbit opened |
| `notify_coder` | TaskGroup | Whether settle posts the [Coder webhook](#coder-settle-webhook) |
| `execution_mode` | TaskGroup | `managed` for every group on this page |
| `tokens`, `line_diff`, `lines_added`, `lines_deleted`, `duration_ms` | both | [Metrics](#settle-metrics) |

An [annotation](/reference/agent-annotation) creates a group with `execution_mode` `existing_thread`. That group sends work to a thread that already exists. The operations on this page refuse it with `tasks.external_execution` (HTTP 409), and the scheduler never claims it.

Typed comments record the workflow. A stored run receipt is a comment whose type is its outcome: `ready_for_review`, `blocked`, `changes_requested`, or `approved`. An operator posts `assistance_requested` and `resolution` comments. Each comment keeps its full body, author, time, and attempt. An approval that Orbit committed carries `commit_sha`. The approval of the last Task also carries `pull_request`: the summary, changes, and breaking changes it proposed.

### Group lifecycle

A group moves through these statuses from preparation to its end.

| Status | Meaning |
| --- | --- |
| `backlog` | Being prepared. It has no workspace, and the scheduler never claims it. |
| `todo` | Ready. It waits for the scheduler. |
| `reserved` | The scheduler claimed it and provisions its workspace. |
| `running` | A Task is running: its baseline check, its implementer, or its handoff check. |
| `reviewing` | A Task waits for its reviewer, or Orbit publishes its approved commit. |
| `settling` | Every Task has ended. Orbit watches the pull request until it merges. |
| `completed` | The pull request merged, or an operator completed the group. The workspace is removed. |
| `failed` | An agent could not start. |
| `cancelled` | An operator cancelled the group. |

Task statuses are `todo`, `running`, `reviewing`, `completed`, `failed`, and `cancelled`. At most one Task in a group runs at a time. The next `todo` Task starts only after every earlier Task has ended.

## Groups and subtasks

List and show accept any authorized peer. Update and the subtask create, update, and destroy operations are served by the Node that holds the group's workspace, or by the Gateway for a group without one. The other operations require Gateway access.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:update` | `PATCH /api/v1/task-groups/{group}` | Workspace Node |
| `tasks:list` | `GET /api/v1/task-groups` | Any peer |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Any peer |
| `tasks:complete` | `POST /api/v1/task-groups/{group}/complete` | Gateway |
| `tasks:cancel` | `POST /api/v1/task-groups/{group}/cancel` | Gateway |
| `tasks:subtask:create` | `POST /api/v1/task-groups/{group}/tasks` | Workspace Node |
| `tasks:subtask:update` | `PATCH /api/v1/task-groups/{group}/tasks/{task}` | Workspace Node |
| `tasks:subtask:destroy` | `DELETE /api/v1/task-groups/{group}/tasks/{task}` | Workspace Node |
| `tasks:subtask:cancel` | `POST /api/v1/task-groups/{group}/tasks/{task}/cancel` | Gateway |
| `tasks:check:cancel` | `POST /api/v1/task-groups/{group}/tasks/{task}/check/cancel` | Gateway |
| `tasks:comment:create` | `POST /api/v1/task-groups/{group}/tasks/{task}/comments` | Gateway |
| `tasks:comment:list` | `GET /api/v1/task-groups/{group}/tasks/{task}/comments` | Gateway |
| `tasks:agents` | `GET /api/v1/task-groups/{group}/agents` | Gateway |

Each MCP tool name is the operation name with hyphens, such as `tasks-subtask-create`.

Create requires `app_id`, `title` (at most 160 characters), and `brief` (at most 8,000 characters). It accepts an ordered `tasks` array of at most 50 `{title, brief, deliverables}` objects, a `status` of `backlog` or `todo`, and `notify_coder`. The status defaults to `backlog`. Create with `status: todo` asks the scheduler to claim at once. List accepts `app_id` and `status` filters. Show returns the group and its Tasks in position order.

Update changes a group's `title`, `brief`, or `status`. Title and brief change only in `backlog`. The status moves between `backlog` and `todo` in either direction, and a move to `todo` asks the scheduler to claim. A status update and a claim cannot both succeed. When the claim wins, the update returns `tasks.already_claimed`.

A group moves to `todo`, by create or update, only when it has Tasks and every Task has at least one deliverable.

### Change subtasks

Subtask create appends a `todo` Task at the next position. It works in every group status except `completed` and `cancelled`. Outside `backlog`, the new Task needs at least one deliverable.

Subtask update changes `title`, `brief`, `position`, or `deliverables`. A `deliverables` value replaces the whole list. Positions stay gapless from 1.

| Group status | What can change |
| --- | --- |
| `backlog` | Every field of every Task. Subtask destroy deletes a Task and closes the gap. |
| `todo`, `running`, `reviewing`, `settling` | Every field of a `todo` Task. Its deliverables cannot become empty. Its position moves only among the `todo` Tasks after the last started or ended Task. |
| `completed`, `cancelled` | Nothing |

A Task that has started keeps its title, brief, position, and deliverables. A deliverables update on it returns `tasks.deliverables_locked` and leaves the stored list as it is.

A `todo` Task appended to a `settling` group returns the group to `running` on the next tick, as [Fix a settling pull request](#fix-a-settling-pull-request) describes.

### Cancel a subtask

`tasks:subtask:cancel` cancels a `todo` or a `running` Task.

- A `todo` Task in a `todo`, `running`, `reviewing`, or `settling` group becomes `cancelled`. Nothing starts, and nobody is asked for assistance.
- A `running` Task stops first. Orbit interrupts its implementer, then stops its running [task check](#project-check).

Orbit holds no database lock while it stops a `running` Task. When a stop fails, the call returns HTTP 502 `tasks.subtask_interrupt_failed`. The Task then stays `running`, so retry, or cancel the group.

After both stops, Orbit cancels the Task only while it is still `running`. A tick can move the Task on in the meantime. Its new state then stands, and the call returns `tasks.subtask_not_running`.

The lowest `todo` Task then starts. When no Task is open, the group moves to `settling` as after an approval.

Cancel does not reset the workspace. Uncommitted edits of the cancelled implementer stay, and the next approval commits them.

### Errors

The group and subtask operations return these errors.

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.no_subtasks` | 422 | A move to `todo` for a group without Tasks |
| `tasks.subtask_deliverables_missing` | 422 | A move to `todo` while a Task has no deliverables, or a Task without deliverables outside `backlog`. `details` names each Task |
| `tasks.group_closed` | 409 | Subtask create in a `completed` or `cancelled` group |
| `tasks.not_in_backlog` | 409 | A title or brief update outside `backlog`, or a subtask update or destroy that the table above does not permit |
| `tasks.deliverables_locked` | 409 | A deliverables update on a Task that has started |
| `tasks.already_claimed` | 409 | A status update on a group the scheduler already claimed |
| `tasks.subtask_not_running` | 409 | A subtask cancel that the rules above do not permit |
| `tasks.subtask_interrupt_failed` | 502 | Orbit could not stop the implementer or the check |
| `tasks.agent_driver_unavailable` | 409 | The configured agent driver is unknown. No group is stored |
| `tasks.external_execution` | 409 | A managed operation on an annotation group |
| `validation.failed` | 422 | An invalid field, such as a deliverable or a position outside the `todo` Tasks |

## Deliverables

A deliverable is one item that a Task must deliver, in a form Orbit can check. The ADE or an operator writes the deliverables next to the brief. The implementer confirms each one at handoff. Orbit verifies the mechanical ones before the reviewer starts.

Each deliverable has an `id`, a `type`, a `description`, and the fields of its type.

| Type | Fields | Orbit checks |
| --- | --- | --- |
| `file` | `path`: a path or glob from the workspace root. `change`: `created`, `modified`, or `any` | A matching path in the Task's diff, added for `created`, modified for `modified`, either for `any` |
| `test` | `project`: a directory, or `.`. `file`: one Pest test file in that project. `name`: a test-name substring | The file is added or modified in the diff, and every test whose name contains `name` passes. At least one such test exists |
| `command` | `command`. `directory`, relative to the workspace root, default `.` | Orbit's run of the command exits with 0 |
| `review` | none | The reviewer confirms it in its approval |

```json
[
  {"id": "reference-page", "type": "file", "description": "Document the export", "path": "docs/reference/export.md", "change": "modified"},
  {"id": "export-test", "type": "test", "description": "A feature test for the export", "project": "apps/gateway", "file": "tests/Feature/ExportTest.php", "name": "exports every subtask"},
  {"id": "layout-repro", "type": "test", "description": "The layout test fails before the fix", "project": "apps/gateway", "file": "tests/Feature/HomeScreenTest.php", "name": "home screen layout", "fails_on_base": true},
  {"id": "web-tests", "type": "command", "description": "The web app tests pass", "command": "bun test", "directory": "apps/web"},
  {"id": "error-copy", "type": "review", "description": "Error messages name the failing subtask"}
]
```

| Rule | Limit |
| --- | --- |
| Number | One to five per Task |
| `id` | A lowercase slug such as `export-test`, at most 64 characters, unique in the Task |
| `description` | At most 500 characters |
| `path`, `file`, `project`, `directory` | Relative paths without `..`, at most 500 characters |
| `test` `file` | One exact path that ends in `.php`, with no `*`, `?`, `[`, `{`, or `..` |
| `name` | At most 200 characters |
| `command` | At most 1,000 characters |
| `fails_on_base` | The JSON boolean `true` or `false`, on a `test` deliverable only. Omitted means `false` |

A field of another type is refused with HTTP 422 `validation.failed`, and the error names the deliverable's `id`. Only a `file` deliverable's `path` accepts a glob: `*` matches in one directory, `**` matches across directories, and `?` matches one character.

A Task's diff runs from its start commit to the working tree that the check sees, uncommitted and untracked files included. Deleted and ignored files never match. Orbit records the start commit when the Task starts, before the implementer's first turn. When that read fails, the next tick tries again until the first turn starts. After that, the start commit stays empty, and the diff uses a fallback base: the previous Task's approved commit, or the workspace starting commit for the first Task.

### Reproduce a bug on the start commit

A `test` deliverable with `fails_on_base: true` proves that the test reproduces a bug. Orbit then runs the test file twice at handoff.

| Run | Code under test | Passes when |
| --- | --- | --- |
| Base | The start commit, or its fallback base, plus only this test file from the working tree | At least one test whose name contains `name` fails |
| Working tree | The implementer's tree | Every test whose name contains `name` passes, and at least one exists |

The base run extracts an archive of the start commit into a directory under the workspace's `.git/orbit/`. It copies the test file there, shares the installed `vendor/` as a copy, and runs `vendor/bin/pest FILE --log-junit=...`. It does not change the workspace and registers no Git worktree. The check removes the directory when the run ends, and the next check removes a directory that a killed run left behind.

A JUnit `error`, such as a missing class, counts as a failure. The base run stops after 600 seconds, and a timed-out run counts as failing. When no matching test fails on the base, the deliverable fails. The reminder names the cause: the test passed, the test was skipped, no test name contains `name`, or the file could not be placed on the start commit. Orbit reports misses in the diff, on the base, and on the working tree together.

The review request lists each failed base case with its kind and the tail of its message, at most 4,096 characters, and says when the run timed out.

### Confirm deliverables

The Gateway writes the Task's deliverables into `.git/orbit/turn.json` before each turn. The agent confirms each one in its [run receipt](#run-receipt) with `--deliverable=ID=evidence`, where the evidence says where or how the deliverable is met.

| Turn | Needs |
| --- | --- |
| Implementer `ready_for_review` | A confirmation for every deliverable |
| Reviewer `approved` | A confirmation for every `review` deliverable. Other IDs are allowed |

The run script refuses a missing confirmation, an unknown ID, an ID given twice, empty evidence, and `--deliverable` with any other outcome. The Gateway stores the confirmations on the receipt's comment.

### Verify deliverables

The [handoff check](#project-check) first validates every `test` deliverable. The `project` must be a directory in the checkout, and the `file` must exist in it. An invalid deliverable fails the check at once, before the task check runs, with a message such as `Deliverable sweep-test names file tests/Feature/SweepTest.php, which does not exist in apps/gateway.` The group then asks for assistance with that message. The implementer gets no reminder, because it cannot change deliverables.

When the task check passes, the check records the diff, runs each `test` file with Pest by its path, and runs each `command` in a login shell in its directory. Pest runs a named file with test impact analysis off, so a replayed result never counts. The `deliverables` rubric item fails when a confirmation is missing or a deliverable does not pass. Its reminder names each failing deliverable and why. The reviewer starts only when every deliverable passes.

## Prepare a group in Backlog

Prepare a group in Backlog before any agent runs. A Backlog group has no workspace.

1. Create the group. It starts in `backlog`.
2. Optionally, create the branch `task-{group id}` from the Project default branch, commit the feature's contract to it, and push it.
3. Add the ordered Tasks with their deliverables, following the Project's task policy.
4. Move the group to `todo` with `tasks:update`.

The Gateway does not check the branch contents. When `origin/task-{group id}` exists, the workspace checks it out. Otherwise the workspace starts a new branch from the default branch. The implementer and reviewer prompts name the documentation and decision records that this branch changes against the default branch as the feature's contract.

## Scheduler

The scheduler command `tasks:tick` does all work of the extension. The Gateway's Laravel schedule runs it and `tasks:collect-t3-metrics` every 10 seconds while the extension is enabled. The Gateway host must run `php artisan schedule:work`, or no group advances. One cache lock, held for up to 300 seconds, protects scheduled and manual ticks. A tick that finds the lock held does nothing.

Each tick runs these steps in order:

1. Watch each `settling` group's pull request, and start a waiting `todo` Task. See [Pull request](#pull-request-and-settle-metrics).
2. Advance each `running` and `reviewing` Task. See [Session routing](#session-routing).
3. Return groups that stayed `reserved` too long to `todo`.
4. Remove the workspaces of ended groups. See [Complete and cleanup](#complete-and-cleanup).
5. Claim `todo` groups while Node capacity lasts.

### Claim and provision

A claim takes the oldest `todo` group that fits and moves it to `reserved`. The provisioner then creates the [task workspace](#shared-instance) on a Node that fits:

- an active Linux Node with an active `app-dev` role and a WireGuard address;
- not excluded from the Project by a [development node exclusion](/reference/development-node-exclusions);
- allowed by both of the group's agent drivers: T3 needs an active `t3-code` Process, and Pi an active `pi-server` Process, each with desired state `running`;
- with fewer than 10 active groups. Active groups are `reserved`, `running`, `reviewing`, and `settling`.

Among the Nodes that fit, the one with the fewest active groups wins. There is no per-Project limit, and the scheduler never polls Nodes for capacity.

When the workspace is ready, the group becomes `running`, and its first Task starts. When a claim fails, the group returns to `todo`, and the claim continues with the next group. A tick tries each failing group once.

| Cause | Result |
| --- | --- |
| Every fitting Node is full | The group waits without a reason. When no `app-dev` Node has capacity, claims stop until the next tick. |
| No Node fits, the Project lacks a valid default branch or repository, or provisioning throws | Reason `Workspace provisioning did not return an instance.` The error goes to the Gateway log. |
| The move to `running` fails after provisioning | Reason `The group could not start after its workspace was provisioned.` The group keeps its workspace. |
| The group stays `reserved` longer than `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | Reason `The group stayed reserved too long and returned to todo.` |

These reasons clear when the group starts, waits for capacity, or moves to `backlog`. Each release applies only while the claim still holds that reservation, so a claim never overwrites a newer claim or a cancel.

A claim that stops after it created the workspace leaves the `task-{group id}` Instance behind. The next claim finds it by name and branch and resumes it on its Node. Another Instance with that name but another branch is never adopted. When the group was cancelled while its claim ran, the claim removes the workspace it created.

### Start a Task

A Task starts in this order:

1. Orbit records its start commit.
2. When no implementer has started in the group, Orbit runs the [baseline check](#baseline-check). The first implementer starts only after it passes.
3. Orbit reserves an [agent thread](#agent-threads) row, writes the turn file, and starts the implementer with its opening prompt.

When the implementer cannot start, the Task and the group become `failed`, and the Gateway logs which spawn failed. A group created in `todo` can therefore answer create as `failed`.

## Shared Instance

The task workspace is one fresh Instance that every Task of the group shares. Its name and its branch are `task-{group id}`. It lives in the Node's apps root like any development Instance.

| Project | Workspace |
| --- | --- |
| Slug `orbit` | Not visitable. The checkout has no Route and stays in the lifecycle state `source_resolved`. |
| Any other slug | Visitable. The usual development provisioner gives it an inspect subdomain, and it becomes `active`. |

[Doctor](/cli/doctor) treats `source_resolved` as the healthy state of a workspace that is not visitable, and `active` for a visitable one.

Orbit writes an untracked `.mcp.json` into the workspace before a reviewer starts, unless the repository tracks one. It points at `{gateway origin}/mcp/search`, which lists only `search_tools` and `execute_tools`. The file is excluded from Git. The [MCP server](/reference/mcp) describes both endpoints.

## Agent threads

An **agent thread** is one persistent conversation with a coding agent. It records the driver, the external conversation id, the Node, the group and Task, the role, the model, and the effort. The external runtime keeps the transcript. Orbit keeps the link and the metrics.

Each Task gets a fresh implementer thread. Each Task's first review starts a fresh reviewer thread. Thread titles are `Orbit task #{group} / subtask #{task} · Implementer: {title}` and `Orbit task #{group} · Review: {title}`.

Orbit reserves the thread row before it starts the conversation, so the opening prompt can name the Orbit thread id. The row's external id starts with `pending:` until the conversation starts. A reserved row is not listed, measured, or broadcast. When the start fails, Orbit deletes the row. A tick deletes a leftover reserved row once its Task or group is completed or cancelled. While the owner is active, a later start reuses it.

| State | Meaning |
| --- | --- |
| `idle` | Ready, with no active turn and no reported outcome |
| `working` | Running a turn |
| `asking_for_input` | Waiting for an answer to a question or an approval |
| `done` | The latest turn completed |
| `failed` | The latest turn failed. The error is stored |

`done` and `failed` stay until a new turn starts. A failed read keeps the last known state and marks it unavailable. It never makes a thread idle or failed.

### Drivers

A driver translates Orbit's thread operations for one agent runtime. A group records an implementer driver and a reviewer driver when it is created. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select them, and each defaults to `t3`. The Gateway registers the `t3` and `pi` drivers. A caller never supplies a runtime URL. An unsupported operation fails explicitly.

| Role | Default model | Effort |
| --- | --- | --- |
| Implementer | `gpt-5.6-luna`, or `ORBIT_TASKS_IMPLEMENTER_MODEL` | `high` |
| Reviewer | `claude-opus-5`, or `ORBIT_TASKS_REVIEWER_MODEL` | `high` |

**T3.** The `t3` driver runs threads on the T3 server of the workspace's Node. It sends commands to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with the bearer `ORBIT_T3_TOKEN`. A Node whose settings hold a `t3` object uses its own `t3.token`, and its `t3.url` as the base URL when set. Such a Node never falls back to `ORBIT_T3_TOKEN`, and a missing token fails closed. A Claude model runs on T3's `claudeAgent` provider instance, and any other model on `codex`. After a thread is created, a refused opening turn is retried once.

**Pi.** The `pi` driver runs threads on the [Pi server](/reference/pi-server) of the workspace's Node. The Gateway chooses the session id. Each send carries a key, and a retry reuses it, so an ambiguous failure never starts a second turn. The driver maps a model name to Pi's `provider/model` form. With `ORBIT_PI_PROVIDER` set, every plain name uses that provider. Otherwise `gpt-` and `o`-series names use `openai-codex`, and `grok-` names use `xai`. Claude models are refused. Pi threads never ask for input, and they report no per-thread line counts.

### Archive finished threads

Orbit archives a T3 thread after its work ends: a reviewer thread when its Task is completed or cancelled, and every thread when its group is completed or cancelled. It archives a thread only after one successful final metrics read. Each tick, and each run of `php artisan tasks:archive-threads`, archives at most 10 threads, oldest first. A failed archive retries after 1, 5, 30, and then every 120 minutes, and it never blocks a Task or group from ending. Archiving keeps the Orbit thread row and its metrics. Pi sessions stay as files on the Node.

## Session routing

Each tick advances every `running` and `reviewing` Task of a `running`, `reviewing`, or `settling` group. A Task or group that asks for assistance is skipped until an operator resolves it. Only the publication of an already approved commit still retries.

The **acting thread** is the Task's implementer while the Task is `running`, and that Task's reviewer while it is `reviewing`. While the acting thread is `working`, the tick skips the Task. The other thread does not defer it. So an operator can talk to a reviewer while the implementer hands off. The Gateway never sends a turn to a `working` thread. It waits until that thread stops.

### Run receipt

An agent ends each turn with one command:

```bash
.git/orbit/run --thread=ID --outcome=OUTCOME --summary="What was done, or what stops the work"
```

Before each turn, the Gateway installs the run script at `.git/orbit/run`, writes `.git/orbit/turn.json` with the role, the deliverables, and the acting thread's Orbit id, and removes any earlier receipt. `ID` is that Orbit thread id. Git never tracks `.git/orbit/`. The script needs `php` on the Node.

| Role | Outcomes |
| --- | --- |
| Implementer | `ready_for_review`, `blocked` |
| Reviewer | `approved`, `changes_requested`, `blocked` |

The script refuses an outcome of the other role, an empty summary, a repeated flag, and an unknown argument. `blocked` pauses the group, so it needs `--question="One question the operator must answer"`. The script refuses `--question` with any other outcome. The approval of the Task that opens the pull request also needs `--pr-summary`, at least one `--pr-change`, and at least one `--pr-breaking`, or `--pr-breaking=none`. The script writes `.git/orbit/run.json` atomically.

When the acting thread stops, the tick reads the receipt over SSH. It applies the receipt only when its `thread` is the acting thread. It stores the receipt as a comment with its content hash, then removes the file. A receipt read again after a crash has the same hash and is stored once. The scheduler then acts on the stored comment, so a failed send or commit is retried without the file.

### Rubric and reminders

When the acting thread is `idle`, `done`, or `asking_for_input`, the tick checks named rubric items in code. No model is asked.

| Item | Passes when |
| --- | --- |
| `run_receipt` | A receipt for this turn and role exists. A `blocked` receipt needs a question |
| `waiting_for_input` | The thread has no pending question or approval |
| `check_script` | The task check does not run `composer check`, or `composer.json` at the workspace root defines a non-empty `check` script |
| `deliverables` | The receipt confirms the required deliverables, and every deliverable passes |
| `check_passed` | The [task check](#project-check) passed |
| `workspace_unchanged` | The reviewer left the workspace as it was. See [Review a subtask](#review-a-subtask) |
| `branch` | On approval, the workspace is on `task-{group id}` |
| `pull_request_fields` | The approval that opens the pull request describes it |
| `brief_coverage` | The change list covers every Task. See [Pull request](#pull-request-and-settle-metrics) |

When items fail, the Gateway sends one reminder to the acting thread. It names every failed item and ends with the role's run-script instructions. The implementer's reminder starts with "Orbit could not confirm the brief is complete." The reviewer's starts with "Orbit could not confirm the review is complete." The Gateway installs the run script again before it sends. Each attempt gets one reminder. When an item still fails at the next stop, the Task asks for assistance, and the reason names each remaining item. The same pending input does not count as that next stop.

A `blocked` receipt asks for assistance at once, with the summary and the question as the reason. A `failed` acting thread asks for assistance at once, unless it is a [server restart](#recover-a-pi-server-restart).

A failed read, send, commit, push, or script install is a communication failure. The tick retries it and moves on to other Tasks. The fifth consecutive failure asks for assistance with the last error.

When a driver cannot observe a thread, the tick waits `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` (default 120). Then it posts one `task_group.escalated` webhook for that outage. A successful observation resets the wait. A thread state from before the outage never advances a Task.

Each observation also reports whether the workspace has commits since its starting commit. It reads the count from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh, and runs `git` over SSH otherwise.

### Assistance and resolution

A Task that asks for assistance keeps its status and its Node slot. The flag and the reason show on the Task and on the group. Orbit posts the Coder `task_group.assistance_requested` webhook once. An operator can also post an `assistance_requested` comment, which flags the Task and the group at once.

A `resolution` comment with a non-empty body resumes a Task that asks for assistance. Orbit sends the body to the blocked thread at once: the implementer while the Task is `running`, and that Task's reviewer while it is `reviewing`. It then clears the flag on the Task and the group, clears the communication failures, and starts a new attempt. A resolution to a reviewer counts as that reviewer's next review request.

When the Task has no started reviewer yet, Orbit clears the flag and holds the resolution. The next tick starts a fresh reviewer whose opening packet includes it. A failed send keeps the Task flagged. A resolution posted while nothing is asked is stored and not sent. Every comment stays as history.

### Recover a Pi server restart

A turn that failed only because its agent server restarted is not a failed Task. The tick resumes it on the same thread with one message: `Your previous turn was interrupted by a server restart. Check git status and git diff, finish the subtask, and hand off with the run script.` It does not ask for assistance and does not read a receipt first.

| Driver | Restart errors |
| --- | --- |
| `pi` | `The Pi server restarted during the turn.` |
| `t3` | `Provider session did not survive a server restart. Send a new message to continue.` and `Could not continue this thread after the server restart. Send a new message to continue.` |

One Task gets at most two resumes, shared by its implementer and reviewer. A resolution does not reset that count. The third restart asks for assistance with `The implementer thread failed.` or `The reviewer thread failed.` Any other error, and a restart error without a turn id, asks for assistance at once.

The tick reserves each resume before it sends. The reservation stores a new send key, the acting thread, the interrupted turn id, and the thread's `session.updatedAt`, and it counts the resume. The Pi driver sends that key. On T3, the key is the command id and the message id.

The tick repeats the same key only while the reservation is pending and the thread still shows the interrupted turn. A repeated key starts no second turn.

A Pi thread whose turn id is the key has accepted the reservation. On T3, a message with that id means T3 accepted the command, and the tick sends nothing more. When T3's `session.updatedAt` then differs from the stored value, T3 reported a new error, and the tick counts a new interruption. A thread that shows another turn supersedes the reservation. A reservation made for one role is never sent to the other.

## Project check

Each Project stores one task check command in `task_check`. Orbit runs it on the fresh workspace before the first implementer starts, and after each `ready_for_review` receipt whose other items pass. Change it with `orbit project:update <project> --task-check=COMMAND`, or clear it with `--clear-task-check`. A new Project gets the default of its type: `composer check` for `laravel-app` and `laravel-package`, and none for `monorepo` and `node-package`.

The Gateway installs `.git/orbit/check` and starts it over SSH as a detached process group. The check records HEAD and a hash of the whole working tree, uncommitted and untracked files included, without touching the Git index. It runs the command in a login shell at the workspace root, writes the output to `.git/orbit/check.log`, and writes `.git/orbit/check.json` when the command ends. The Task stays `running` while the check runs. There is no time limit.

| Check state | Result |
| --- | --- |
| `running` | The tick waits. The Task's `check` shows the start time. |
| `passed` | Exit code 0, and HEAD and the tree did not change. Orbit [verifies the deliverables](#verify-deliverables), and the reviewer starts. |
| `failed` | The exit code is not 0, or an invalid deliverable or an error in the check script stopped it. The reminder holds the exit code and the end of the output. |
| `changed` | HEAD or the tree changed during the run. The check runs again once. A second change fails, and the reminder names the changed paths. |
| `lost` | The process was killed from outside and left no result. The check runs again once. A second loss asks for assistance. |
| `cancelled` | An operator cancelled it. The reminder says so. |

The scheduler identifies the process by its id and its start time, so a reused process id does not count. Without a task check, a handoff runs no command, but still compares the tree and verifies the deliverables. The implementer's instructions and the pull request description name the configured command.

`tasks:check:cancel` stops a running check. A Task without one answers HTTP 409 `tasks.check_not_running`. Each run is stored with its receipt, kind, status, process, HEAD and trees, times, exit code, changed paths, and the last 16 KiB of output.

### Baseline check

Before the first implementer of a group starts, the check runs on the fresh workspace, with `kind` `baseline`. It first runs the Project's [setup steps](/reference/instance-setup) with their own timeouts. Then it prepares dependencies:

- When the task check runs `composer` or names `vendor/`, it runs `composer install --no-interaction --prefer-dist` where a tracked `composer.json` has no `vendor/autoload.php`.
- When the task check names Bun, npm, pnpm, Yarn, Node, Vite+, or `node_modules`, it runs `vp install --frozen-lockfile` for each tracked `package.json` with a lockfile and without `node_modules`.

The Composer step skips a nested `composer.json` without a lockfile. After a root install without a lockfile, it removes the new `composer.lock`. Each install step times out after 600 seconds. Handoff checks install nothing. The Orbit repository's own check seeds its caches from a registered main cache store, as [Feature delivery](/reference/implementation-loop#seed-a-checkout) describes. A failed setup step, install, or check asks for assistance with the step, the exit code, and the output. When the output shows missing `vendor/` or `node_modules` files, the reason says that Project dependencies appear to be missing. Fix the cause, then cancel and create the group again.

## Review a subtask

When the handoff check and the deliverables pass, the Task moves to `reviewing`. Its first review starts a fresh reviewer thread on the group's reviewer driver, model, and effort. The group's `reviewer_agent_thread_id` then points at it. A `changes_requested` re-review continues that thread. When the continued thread cannot take a turn, Orbit starts a fresh one with a full packet. The next Task starts another fresh reviewer.

A failure while requesting a review is a communication failure. After five, the group asks for assistance with `The review could not be requested (ExceptionClass).` Orbit sends no review when it cannot read the diff.

### Review packet

The opening turn is a review packet of at most 16,000 characters, about 4,000 tokens. No part is exempt. A part under its cap leaves the spare characters for the diff body.

| Part | Cap | When it does not fit |
| --- | --- | --- |
| Retrieval commands | 1,000, reserved first | Never cut |
| Held resolution | 2,000 | The end is cut. `tasks-comment-list` returns it |
| Group brief | 2,000 | The end is cut. `tasks-show` returns it |
| Task brief | 2,000 | The end is cut. `tasks-show` returns it |
| Deliverables | 2,000 | One line each, at most 240 characters, with the description cut to 160 |
| Earlier approvals | 1,500 | One line each, at most 200 characters. The oldest lines drop |
| Diff stat | 1,500 | A summary line with every file, insertion, and deletion, then paths until the cap |
| Handoff result | 2,000 | One line per command the check ran, with the command cut to 160 characters. `.git/orbit/check.log` holds the rest |
| Diff body | The rest, and at most 16,384 bytes | Cut from the end |

Dropped lines leave one line that says how many were omitted. The diff and the stat replace bytes that are not valid UTF-8. The packet names the feature contract: the documentation and decision records that the branch changes against the default branch. A continued turn sends only the new diff stat, the diff body, the retrieval commands, and the new handoff result.

The retrieval commands print what the caps cut, including untracked files, without updating the index. The packet puts the Task's start commit in place of `START`:

```bash
git diff --stat START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index --stat -- /dev/null "$path" || true; done
```

```bash
git diff START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index -- /dev/null "$path" || true; done
```

The reviewer prompt says the turn is read-only. It says not to run the task check or the deliverable tests and commands again, because the handoff result already reports them. It says to confirm framework and library usage against the documentation for the Project's versions.

`php artisan tasks:render-prompt {role}` renders the implementer prompt, the opening review packet (`reviewer`), or a continued review turn (`reviewer-continue`) from one JSON object on standard input. It prints `{"role", "prompt", "source_commit"}`, where `source_commit` is the Gateway's `APP_VERSION`, or `dev`. It uses the production prompt code and reads no database, workspace, or network, so an offline evaluation can render frozen cases. It rejects unknown input fields.

### Reviewer outcomes

When the Gateway sends the review request, it records the workspace HEAD and the working-tree hash. When the reviewer's receipt arrives, it reads them again.

- **Unchanged.** The Gateway applies the outcome.
- **A newer implementer turn changed it.** That is a new handoff, for example after an operator talked to the implementer. The Gateway waits for a new receipt and a passing check.
- **Orbit's own commit changed it.** HEAD's parent is the recorded HEAD, and HEAD's tree is the recorded tree. The Gateway stores that commit on the approval and publishes it.
- **The reviewer changed it.** `workspace_unchanged` fails, and the outcome is not applied. The reminder asks the reviewer to revert and to request the changes. A second change asks for assistance.

`changes_requested` sends the findings to the implementer and returns the Task to `running`, once the implementer has stopped. The implementer then needs a new receipt and a passing check.

`approved` waits until the implementer has stopped, because Orbit commits the whole workspace. The Gateway commits every change as `orbit <tasks@orbit>`, with the Task title and the reviewer's summary as the message, and stores the commit on the approval. Then it [publishes](#pull-request-and-settle-metrics) that commit. When publication fails, the next attempt publishes the stored commit again only while HEAD is still that commit and the tree still matches. A reset back to the HEAD before the approval is refused.

## Pull request and settle metrics

Orbit publishes through the Project's [GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request) installation. Agents never receive a token.

After each approval, the Gateway pushes the stored commit, never `HEAD`, with `git push --quiet origin <commit_sha>:refs/heads/task-{group id}`. The push is never forced. Then the next Task starts. On the Task that opens the pull request, the Gateway then opens it against the Project's default branch, or uses an open pull request with that head. It stores `pr_url` and moves the group to `settling`.

A failed push or open keeps the Task in `reviewing` and keeps its commit. It retries after 1 minute, then 2, 5, 10, and 30 minutes, and then every 30 minutes. The fifth failure asks for assistance with a reason that starts with `Approved commit publication failed: `. The reason names Git's error. When GitHub refuses a push that changes `.github/workflows/`, it names the missing `Workflows` permission. A later success clears only that reason.

The group title is the pull request title. The description holds the summary, a Changes list, a Breaking changes list or `None.`, and one line that says each delivered Task passed the task check and reviewer approval. Cancelled and failed Tasks are not counted.

Before Orbit commits the approval that opens the pull request, Jev checks the change list. Jev is Orbit's TypeSafe classifier, called through Laravel AI with `TYPESAFE_API_KEY`. For each Task that is not cancelled or failed, it answers whether a listed change delivers that Task. A Task without a "yes" fails `brief_coverage`, and the reviewer's reminder names it. Jev reads briefs and the change list, not code, so it checks coverage, not correctness. A failed Jev call is a communication failure.

### Settling

Each tick reads the pull request of every `settling` group through the GitHub App.

| Pull request | Result |
| --- | --- |
| Merged | Orbit completes the group and removes its workspace. A failed removal leaves the group `settling` with a reason that starts with `Merged pull request cleanup failed: `, and the sweep retries it. |
| Closed without merging | The group asks for assistance with `The expected pull request closed without merging.` |
| Open with a conflict or a failed check | See [Fix a settling pull request](#fix-a-settling-pull-request). |
| Open and healthy | Orbit clears its own pull request reason. |
| Unreadable | Nothing changes. |

A `settling` group without `pr_url` and without a `todo` Task asks for assistance with `The settling group has no reviewed pull request URL. Cancel the group to push its approved commits to task-{group id} and remove its workspace.` This happens when a subtask cancel ends the last open Task before any pull request exists.

### Fix a settling pull request

While the pull request is open, the Gateway repairs it with a fixup: a Task that it appends itself.

A pull request **conflicts** when GitHub reports it as not mergeable, or its mergeable state is `dirty`. A null result is not a conflict. The Gateway reads the check runs of the head commit at most once a minute, with a token that holds only `checks: read`. Without that permission, it sees conflicts only.

| Check conclusion | Kind |
| --- | --- |
| `failure`, `timed_out`, `action_required` | Genuine failure. It can get a fixup. |
| `cancelled`, `startup_failure` | Infrastructure. It never gets a fixup. |
| Not completed for more than 60 minutes | Infrastructure |
| Not completed for 60 minutes or less | Pending. No check fixup starts yet, but a completed genuine failure is reported. |

A run's age starts at its `started_at`, or at the first tick that saw it pending. The rollup check `Required checks` is ignored while another failed check explains the failure. When only infrastructure problems remain, the group waits and looks again after 1, 2, 5, 10, and 30 minutes. Then it asks for assistance and adds `Those checks were cancelled or could not start, and did not recover. Re-run them.`

Each problem has an identity: `conflict:` plus the base branch, or `check:` plus the check name. One tick appends at most one fixup, for the first problem that still has one left. A conflict comes first. A group gets at most two fixups for one identity and at most three in total. These caps count every fixup appended after the last completed operator Task. An operator Task is one with no `fixup_problem`. So each new window needs a human step.

| Fixup | Title | Brief |
| --- | --- | --- |
| Conflict | `Merge origin/{base}` | `Merge origin/{base} into the task branch and resolve the conflicts. Do not rebase and do not force-push.` |
| Failed check | `Fix {name}` | `Check {name} failed: {url}. Do not rebase and do not force-push.` |

Every fixup has the `command` deliverable `composer-check`, which runs `composer check` in `.`. For the Project with slug `orbit`, a fixup for a known CI check name also has a `reproduce-check` deliverable that runs that job's check steps.

A fixup records the head it was created for. No new fixup starts while the head is still that commit. When the last fixup changed nothing, the group asks for assistance and adds `Fixup subtask #{id} changed nothing, so Orbit does not try again on the same result.` When every problem is at its cap, the group asks for assistance with a reason that starts with `The pull request needs attention: ` and has one sentence per problem. Coder is notified only when that reason changes.

A `todo` Task on a `settling` group, a fixup or an operator's Task, returns the group to `running`. This works when the pull request is open, and when the group has no `pr_url`. Another assistance cause keeps the group `settling`. Before the Task starts, the Gateway fetches `origin/task-{group id}` and fast-forwards the workspace when it is strictly behind. It never forces. A conflict fixup also fetches `origin/{base}`. A failed fetch keeps the Task `todo`, retries on the same backoff, and asks for assistance on the fifth failure.

The fixup runs like any Task, with a fresh implementer and a fresh reviewer. Its approval needs no pull request fields, and its push updates the open pull request. Orbit does not rebase, does not force-push, does not open a second pull request, and does not merge. Before each push to a stored pull request, the Gateway reads its state again. When it already merged or closed, Orbit does not push and asks for assistance with a reason that starts with `An approved commit is not on the pull request: `. When the group returns to `settling`, it refreshes its metrics and does not post `task_group.settled` again.

### Jev decision records

The Gateway stores every Jev call in its `jev_decisions` table, failed calls included, and keeps the records as Orbit's evaluation dataset. A record holds the purpose, the group, Task, and thread ids, each question with its options and criteria, the input with secrets redacted, each answer with its probabilities, the model, the latency, and an error code on failure. It holds no secrets and no raw provider error. The question and input snapshots are each capped at 64 KiB.

After a pull request merges, the Gateway labels its `brief_coverage` records by rule, without a model. A "missing" answer is a `false_negative` when exactly one line of both the approved change list and the merged pull request's `## Changes` list names that Task's full, unique title. A call whose answers were all "covered" gets the call label `correct` when its pull request merges. Every other answer stays unlabeled. `php artisan orbit:tasks:jev-report` reports calls, failures, labeled share, false negatives, confidence buckets, `correct` calls, and p50 and p95 latency for each purpose.

### Settle metrics

Settle stores the group's metrics. Showing an active group refreshes them.

| Field | Record | Source |
| --- | --- | --- |
| `tokens` | Task | The implementer thread's cumulative tokens. Unknown until reported |
| `lines_added`, `lines_deleted`, `line_diff` | Task | The implementer thread's line counts, when the driver reports them |
| `duration_ms` | Task | From `started_at` to `settled_at`, or to now |
| `tokens` | TaskGroup | The sum of Task `tokens` and every started reviewer thread |
| `lines_added`, `lines_deleted`, `line_diff` | TaskGroup | The whole branch against the Project default branch |
| `duration_ms` | TaskGroup | From `started_at` to now while active, or to settle |

A failed read keeps the stored value. A missing value stays unknown, not zero.

### Tokens and line diff

The group's line counts come from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh. Otherwise, and when the agent's diff is truncated, they come from `git diff --shortstat {default branch}...HEAD` over SSH. When the agent reports a new commit or new counts, the Gateway stores the counts and broadcasts `task_group.updated`.

For T3, a thread's `tokens` is its largest `totalProcessedTokens`, or else `usedTokens`, and its line counts come from T3 checkpoints. For Pi, `tokens` is the session usage `total`.

### Thread token metrics

Each agent thread also records five split fields. `tasks:agents` and the agents API show them. They stay on the thread and are not copied to the Task, the group, the board, or the webhook.

| Field | Meaning |
| --- | --- |
| `input_tokens` | Uncached input summed over the model calls, cache writes included |
| `cached_input_tokens` | Input read from cache, summed over the calls |
| `output_tokens` | Output summed over the calls, reasoning included |
| `model_calls` | The number of model calls |
| `peak_context_tokens` | The largest context of one call: its uncached plus its cached input |

Null means the driver did not report the field, or the split is partial. A reported zero is zero. The average context per call is `(input_tokens + cached_input_tokens) / model_calls`, and the cached share of input is `cached_input_tokens / (input_tokens + cached_input_tokens)`.

**Pi.** The server's `usage` object holds `input`, `output`, `cacheRead`, `cacheWrite`, `total`, `calls`, and `peakContext`. `input_tokens` is `input + cacheWrite`, `cached_input_tokens` is `cacheRead`, `output_tokens` is `output`, `model_calls` is `calls`, and `peak_context_tokens` is `peakContext`.

**T3.** The Gateway counts each `context-window.updated` payload from the thread's event stream once. It keeps running sums, the event sequence, and the highest counted `totalProcessedTokens` in a durable checkpoint, so replays and restarts never count a call twice. A payload counts only when its `totalProcessedTokens` advances.

`input_tokens` adds `inputTokens - cachedInputTokens`, `cached_input_tokens` adds `cachedInputTokens`, `output_tokens` adds `outputTokens`, and `peak_context_tokens` is the largest `inputTokens`. The fields stay null until the first call is counted. When the Gateway misses events, cannot resume the stream, or reads an invalid payload, the split is partial, and all five fields read null. `tokens` still follows the cumulative total. A Claude thread reports no cached input, so its split stays null.

`tasks:collect-t3-metrics` reads at most 20 due T3 threads per run, least recently collected first. A failed or incomplete read waits longer before each retry. A new turn makes a thread due again. A thread whose work has ended gets one successful final read.

## Web task board

**Tasks** in the web navigation shows every group on a board with Backlog, Todo, In progress, and Done lanes. In progress holds `reserved`, `running`, `reviewing`, and `settling` groups. Done holds `completed`, `failed`, and `cancelled` groups with their outcome visible. Each card shows the Project code and the group id, such as `ORB-13`, its line counts, its status, and its duration. A group page shows the brief, the metrics, a board of its Tasks, and an Agents section. A Task page shows that Task's implementer and reviewer. The board is read-only. The [web app](/reference/web-app#live-tasks) keeps it current from task events.

## Agent viewer

The Agents section lists every started thread of the group. `GET /api/v1/task-groups/{group}/agents` returns each thread with its driver, external id, state, observation time, errors, and metrics. `GET /api/v1/task-groups/{group}/agents/{session}/stream` streams the thread's normalized conversation to the browser. Both need Gateway access and an enabled extension. Runtime credentials stay in the Gateway.

A snapshot replaces the browser transcript. Entries merge by id and kind, so an updated entry replaces the earlier one. On reconnect, the browser sends its last cursor. A T3 stream starts every connection with a full snapshot. A Pi stream resumes after the cursor and sends only what the viewer missed. When one Pi event becomes several entries, only the last carries the cursor. The viewer writes no thread state. When a remote runtime deletes a conversation, Orbit cannot restore it.

## Coder settle webhook

The Gateway posts signed events to Coder when `ORBIT_CODER_WEBHOOK_URL` and `ORBIT_CODER_WEBHOOK_SECRET` are both set. A refused or failed post changes nothing in Orbit.

| Event | When | Body adds |
| --- | --- | --- |
| `task_group.settled` | A group with `notify_coder` first reaches `settling` with a pull request | `tokens`, `line_diff`, `duration_ms`, `pull_request_url` |
| `task_group.assistance_requested` | A group or Task starts asking for assistance | `reason` |
| `task_group.escalated` | A thread stays unobservable past the grace period | `reason`, `confidence`, `thread_id`, `observation` |

Every body holds `event`, `task_group_id`, and `title`. The Gateway signs `{unix timestamp}.{raw body}` with HMAC-SHA256 and sends the headers `X-Orbit-Timestamp`, `X-Orbit-Signature: sha256={hex}`, and `Content-Type: application/json`.

## Cancel a stuck group

`tasks:cancel` ends a group in any status except `completed`, and except `settling` with a `pr_url`. Those return HTTP 409 `tasks.not_cancellable`. Complete a settling group instead.

Cancel removes the group's workspace, then marks the group and its open Tasks `cancelled`. Tasks, comments, and thread links stay as history. Cancel does not stop the agent conversations. Cancelling again is safe, and it retries a removal that failed.

- **Settling without a pull request.** Cancel first pushes the latest approved commit to `task-{group id}`, so you can open a pull request from it. A failed push returns HTTP 502 `tasks.push_failed` and keeps the group.
- **Node unreachable.** Cancel still ends the group and keeps the Instance attached. The group asks for assistance with `Workspace removal failed: The Node is unreachable.` The sweep removes the workspace later.
- **Removal refused.** Cancel returns the error and keeps the group. The group asks for assistance with `Workspace removal failed: `.
- **Claim in flight.** A group `reserved` within `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` becomes `cancelled`, and the claim removes the workspace it provisions.

Uncommitted changes are never pushed. Git refuses the push when `origin` holds an unrelated `task-{group id}` branch, for example after a Gateway rebuild reused the id. Rename that branch on `origin`, then cancel again.

After a successful removal, cancel clears the assistance flags on the group and its Tasks and keeps the last reasons.

## Complete and cleanup

A merged pull request completes its group on the next tick. `tasks:complete` completes a `settling` group by hand. Any other status returns HTTP 409 `tasks.not_settling`. Completing a `completed` group retries the removal when the workspace is still attached, and changes nothing otherwise.

Cancel, complete, and the sweep remove a workspace the same way. The forced Instance remover deletes the recorded checkout and the workspace's Routes. It writes a removal record, and it deletes the Instance row only after the checkout is gone. First, the removal deletes the group's Incus bridge worktree: the linked worktree `task-{id}-e2e` of the primary checkout, only when its path and branch both match the group. It deletes the branch `task-{id}-e2e` when no worktree has it checked out, and the ref `refs/orbit/e2e-bridge/task-{id}`. See [Incus topologies](/reference/incus-topologies#task-workspace-clones). Release the Incus topology the bridge holds before the group ends.

When a manual complete cannot remove the workspace, the group still becomes `completed` and keeps its Instance. It asks for assistance with `Workspace removal failed: `.

Each tick sweeps workspaces that still exist:

- of a `cancelled` or `completed` group, attached or found by the `task-{group id}` name and branch. A workspace that a live claim still owns waits.
- of a `settling` group whose merged pull request cleanup failed.

For a cancelled group, the sweep first pushes the latest approved commit. A failed removal asks for assistance and waits for that Instance only: 1 minute, then 2, 5, 10, and 30 minutes, and then every 30 minutes. A tick starts no removal after 60 seconds of removals. A success clears only a reason that starts with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. The sweep never removes the workspace of an active group. When the Gateway cannot read or write a retry delay in its cache, it logs a warning and tries at once.

## Configuration

These Gateway environment keys configure the extension.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER`, `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` | The drivers of new groups. Default `t3` |
| `ORBIT_TASKS_IMPLEMENTER_MODEL`, `ORBIT_TASKS_REVIEWER_MODEL` | The models of new groups. Defaults `gpt-5.6-luna` and `claude-opus-5` |
| `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` | The wait before one escalation for an observation outage. Default `120` |
| `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | How long a group may stay `reserved`. Default `3600`, at least `60`. Keep it above the slowest workspace provision |
| `ORBIT_T3_PORT`, `ORBIT_T3_TOKEN` | The T3 server port, default `3773`, and its bearer token |
| `ORBIT_PI_PORT`, `ORBIT_PI_TOKEN`, `ORBIT_PI_PROVIDER` | The Pi server port, default `3774`, its bearer token, and the provider for plain model names |
| `ORBIT_CODER_WEBHOOK_URL`, `ORBIT_CODER_WEBHOOK_SECRET` | The Coder webhook endpoint and its HMAC secret. The Gateway never returns the secret |
| `TYPESAFE_API_KEY` | The key for Jev calls |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### An optional, generic extension

Tasks is an extension, so an operator can switch it off without a Gateway downgrade. The engine knows groups, Tasks, deliverables, one task check, and a lifecycle. Each Project's own policy and task check decide how it plans and verifies work, so any Project can use Tasks without Orbit's workflow. The ADE plans, because planning needs the conversation with you. A web form to create groups would be a second path beside MCP and the API.

### Backlog before Todo

A group needs an id before its branch `task-{group id}` can hold the contract, and it must not run while that contract is written. So a group starts in Backlog and runs only when someone moves it to Todo. A draft flag on a Todo group would give one lifecycle fact two fields.

### The Gateway claims, not the Nodes

The Gateway already knows every Instance and Node, so it counts active groups itself. Node-side polling would add a second loop and a second source of truth. A claim reserves the group first and provisions afterwards, so a slow checkout never holds a lock.

### One Task at a time on one branch

Each approved Task becomes one commit on the shared branch before the next Task starts. So every Task builds on reviewed work, and the pull request reads as a sequence of reviewed steps.

### The run receipt ends a turn

An agent states its outcome with one command, the same for every driver. The Gateway does not infer outcomes from transcripts, and agents need no Gateway access or ids of their own. The receipt only marks the end of a turn. Code checks decide whether the work moves on. A hand-written JSON file would need extra turns to fix.

### The Gateway runs the check

One check decides for every driver, because it does not depend on tool output. It runs detached, and the process state shows whether it still runs. A time limit would fail a slow check that is not broken, so an operator cancels a check that hangs. The workspace is not copied, because nobody edits it between handoff and review, and the tree comparison catches an edit.

### Deliverables are checked, not read

Orbit cannot check prose, so a Task names typed items. The check script runs the named test file itself, because a test that impact analysis replays looks the same as one that ran. The Gateway verifies against its own run, because the agent controls the workspace and could change a script that verified itself. A `test` file is one exact path, because a glob could match a file made to satisfy it.

### A bug test must fail first

A test that only passes on the fixed code does not prove it covers the bug. So a bug test runs on the start commit with only the test file added. The base tree is an extracted archive inside `.git/orbit/`, not a registered worktree, because a killed run would leave a registered worktree that blocks removal of the clone.

### One reminder, then a person

An agent can repair a named list of failures in one turn, so the first failure gets one reminder that names every failed item. A second failure asks for assistance, because unlimited reminders hide a stuck Task. A blocked agent must ask one specific question, because a vague block costs the operator a round trip.

### Only the acting thread pauses a Task

When any working thread paused a Task, an operator who talked to the reviewer stalled the implementer's handoff. So only the thread that acts in the Task's phase defers it. Every send still waits for its target to stop, so no turn lands in the middle of another.

### A fresh reviewer for each Task

A long-lived reviewer carried the context of every earlier review into each new one. Most of its tokens were that inherited context. A fresh thread with a capped packet reviews only this Task, and the retrieval commands print what the caps cut. A re-review continues the same thread, so the reviewer keeps its own findings.

### The reviewer does not edit

The approval commit must hold only the work that the implementer handed off. So a reviewer that edits the workspace gets a reminder to revert and to request the change. A silent revert would hide the edit.

### Orbit commits and pushes

Orbit holds the branch, the receipts, and the GitHub App, so it commits after approval and publishes itself. It pushes the stored commit, not `HEAD`, because `HEAD` can move after the approval. It pushes after every approval, so a lost clone loses no approved work. Retries back off, so a failing Node or GitHub is not called every 10 seconds.

### Fixups are bounded

The workspace and a reviewer can repair a conflict or a failed check, so the first problem gets a fixup instead of a person. Each fixup is a normal Task on the same pull request, never a rebase or a force push, because the open pull request is the review.

The caps stop a loop: two per problem, three per group, and a new window only after a person's Task completes. A problem's identity ignores the check URL and the base commit, so a moving `main` does not look like a new problem. Infrastructure failures get no fixup, because the branch did not cause them.

### Resume a restarted turn

The checkout still holds the work after an agent server restarts, and the same thread can finish it. So the tick resumes the turn instead of asking for assistance. Two resumes cover one upgrade and one retry. The resume is counted before it is sent, because the send can succeed while its response is lost.

### Metrics stay on the thread

The thread spent the tokens, so the split lives there. A total alone does not show whether the prompt grew, the cache missed, or the output grew. T3 counts calls from the event stream, because the snapshot keeps only a bounded list of recent calls. A split with a gap reads null, because a partial sum would look complete.

### Jev only checks coverage

Code decides every fact that code can check. Jev answers only whether the change list covers each Task, because the reviewer writes that list and code cannot compare prose. Every call is stored with its input and later labeled by rule from the merged pull request, so the checks can be measured without a second model.
