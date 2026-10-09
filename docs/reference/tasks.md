---
title: "Tasks"
description: "How the optional Gateway Tasks extension runs tasks and stores each Project's task definitions. It covers the model, definition fields, validation, the lifecycle, typed deliverables, the task check, agent threads, review, the pull request, fixups, metrics, cleanup, and the outer loop that files recurring problems."
covers:
  - "apps/gateway/app/{Domain/{Tasks,Problems},Infrastructure/Tasks}/**"
  - "apps/gateway/app/Actions/Tasks/**"
  - "apps/gateway/app/Http/Requests/Tasks/**"
  - "apps/gateway/app/Http/Controllers/Api/{TasksController,TaskGroupsController,TaskDefinitionsController,AgentThreadsController,TaskQuestionsController}.php"
  - "apps/gateway/app/Console/Commands/{TickTaskSessionsCommand,CollectT3MetricsCommand,CollectProblemsCommand,FileProblemsCommand,ArchiveTaskThreadsCommand,RenderTaskPromptCommand,JevReportCommand,TaskGitHubReviewsCommand}.php"
  - "apps/gateway/app/Models/{Task,TaskDefinition,TaskComment,TaskCheck,TaskQuestion,TaskGitHubReviewConsumption,TaskGitHubReviewObservation,TaskReviewedCommit,AgentThread,JevDecision,ProblemFingerprint,ProblemCollectorState}.php"
  - "apps/{gateway/resources/tasks/**,e2e/resources/proofs/*}"
  - "apps/gateway/database/migrations/*_{merge_task_groups_into_tasks,create_task_github_review_consumptions_table,create_task_github_review_observations_table,convert_test_deliverables_to_commands,add_continuation_source_to_tasks,create_task_definitions_table,create_problem_fingerprints,clear_assistance_on_ended_tasks,add_assistance_kind_to_tasks,create_task_questions,add_model_and_effort_to_task_agent_sessions,add_watched_pr_url_to_tasks,add_review_and_merge}.php"
---

# Tasks

Tasks is an optional Gateway extension. It runs planned work with coding agents. A task is one feature or bug fix, delivered as one pull request. Its subtasks run in order in one shared task workspace. A fresh implementer builds each subtask, the Project's task check verifies the handoff, and a fresh reviewer approves it. Orbit commits and pushes each approved subtask. After the last approval, Orbit opens the pull request and watches it until it merges.

While a subtask is open, Orbit also watches a pull request on `task-{id}`. It stops starting subtasks when that pull request merges or closes.

A Project can opt in to [review and merge](#review-and-merge). Orbit then reviews the whole branch before it pushes anything, reviews the pull requests that listed authors open, and merges a reviewed head when CI passes.

The engine is generic. Your agentic development environment (ADE) plans and steers the work. Orbit runs it. Each Project keeps its own task policy in its repository, as an `orbit-tasks` skill under `.agents/skills/` and in its other instructions, and enforces it through its own task check. Agents read that policy from the repository, not from the shared prompts. The Orbit repository keeps its policy in the [orbit-tasks skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) and the [contributor guide](/contributor-guide).

Agents use the Tasks tools of the [MCP server](/reference/mcp). The [`tasks` CLI family](/cli/tasks) runs the same operations from a terminal. There is no web UI to create or change a task. The API, the CLI, and the web app call a child task a subtask.

## Extension switch and status

Enable and disable the extension with `orbit extension:enable tasks` and `orbit extension:disable tasks`. Both need Gateway access. While the switch is off, the `tasks` commands, MCP tools, and web pages are hidden, except `tasks:status` and the `tasks-status` tool. Every other task operation, including the [definition operations](#definition-operations), refuses with HTTP 409 `extension.disabled` and changes nothing. Stored tasks, subtasks, and task definitions stay. [`extension`](/cli/extension) describes the switch.

`tasks:status` is an assistance and status view, not a switch. Its route returns `enabled`, `assistance`, `last_tick_at`, and `merges`. `merges` lists the open tasks of [review-and-merge](#records-and-status) Projects. `assistance` lists every task whose `assistance_requested` is true, in ascending task id order. Each entry has `id`, `project_id`, `project`, `project_code`, `title`, `status`, `assistance_kind`, `assistance_question`, and `assistance_reason`. A completed or cancelled task never asks for assistance and keeps its last reason. A task that is not asking is absent, even when it still stores an old reason. A flagged subtask does not add its task unless the task itself is asking. The view remains available while tasks is disabled.

## Model

A task is one row in the `tasks` table. A row with no `parent_id` is a top-level task: the Tasks board shows it, and it is one feature or bug fix delivered as one pull request. A row with `parent_id` is a subtask of that parent. A subtask has no children. [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions#one-task-model) records why one table holds both levels.

A top-level task holds the task workspace, the branch, the reviewed pull request, the watched pull request, the current reviewer thread, and the settle metrics. A subtask holds its position, its deliverables, its implementer thread, its task checks, and its turn receipts. Both levels store a title, a brief, a status, assistance, comments, and metrics. The Gateway rejects a value in a column that the level does not use.

| Field | Level | Meaning |
| --- | --- | --- |
| `title`, `brief` | both | Short name, and the goal and acceptance |
| `status` | both | Lifecycle state |
| `parent_id` | subtask | The top-level task. Null on a top-level task |
| `assistance_requested`, `assistance_reason` | both | Whether the record asks an operator for help, and why. A completed or cancelled task or subtask never asks for assistance and keeps its last reason |
| `assistance_kind`, `assistance_question` | both | `direction` when the record needs the operator's direction, with its one question. `failure` for every other cause, with no question. See [Direction requests](#direction-requests) |
| `position` | subtask | Order under the parent, gapless from 1 |
| `deliverables` | subtask | The typed items the subtask must deliver |
| `check` | subtask | The latest [task check](#project-check) run |
| `fixup_problem` | subtask | The problem a [Gateway fixup](#fix-a-settling-pull-request) repairs. Null on every other subtask |
| `implementer_agent_thread_id` | subtask | The subtask's implementer thread |
| `reviewer_agent_thread_id` | task | The current reviewer thread. A shared reviewer thread is stored here, and [Review a subtask](#review-a-subtask) points it at the fresh reviewer |
| `taskable_type`, `taskable_id` | task | The task workspace Instance. Null until the scheduler provisions it |
| `implementer_model`, `reviewer_model` | task | The models used for the task's threads |
| `pr_url` | task | The reviewed pull request Orbit opened on the last subtask, or the [incoming pull request](#incoming-pull-requests) Orbit reviews |
| `pr_branch` | task | The incoming pull request's head branch, which Orbit fetches and pushes. Null for a task whose branch is `task-{id}` |
| `merge_status`, `merge_reason`, `merged_sha` | task | The [merge gate](#merge-on-green) result: `waiting`, `refused`, or `merged`, its reason, and the merge commit |
| `type` | subtask | `implementation`, or `final_review` for a [final review](#final-review) |
| `watched_pr_url` | task | The pull request on `task-{id}` found by the [branch watch](#watch-the-branch-while-subtasks-are-open). Null until that list finds one. Not `pr_url` |
| `watched_pr_number` | task | The watched pull request's number. Null until the branch watch finds one |
| `watched_pr_state` | task | The last watched state: `open`, `merged`, or `closed`. Null until the branch watch finds one |
| `watched_pr_completion` | task | `merged` or `closed` after `tasks:complete` confirms the end. Null until then. Resume does not call GitHub |
| `ended_pr_notice_key` | subtask | Stable send key for the one ended-pull-request notice. Null when that subtask has no notice |
| `ended_pr_notice_thread_id` | subtask | The implementer or reviewer thread that notice belongs to |
| `ended_pr_notice_state` | subtask | `pending` or `delivered` |
| `notify_coder` | task | Whether settle posts the [Coder webhook](#coder-settle-webhook) |
| `execution_mode` | task | `managed` for every task on this page |
| `tokens`, `line_diff`, `lines_added`, `lines_deleted`, `duration_ms` | both | [Settle metrics](#settle-metrics). Settle stores task tokens as subtask tokens plus every started reviewer thread, and the task line diff as the whole branch against the default branch |

Clearing the assistance flag can keep the last reason.

An [annotation](/reference/agent-annotation) creates a task with `execution_mode` `existing_thread`. That task sends work to a thread that already exists. The lifecycle operations refuse it with `tasks.external_execution` (HTTP 409): update, cancel, complete, and the subtask create, update, destroy, and cancel operations. List, show, the comment operations, `tasks:check:cancel`, and `tasks:agents` accept it. The scheduler never claims it.

Typed comments record the workflow. A stored turn receipt is a comment whose type is its outcome: `ready_for_review`, `blocked`, `changes_requested`, or `approved`. An operator posts `assistance_requested` and `resolution` comments. Each comment keeps its full body, author, time, and attempt. An approval that Orbit committed carries `commit_sha`. The approval of the last subtask also carries `pull_request`: the summary, changes, and breaking changes it proposed.

`GET /api/v1/task-groups/{group}/tasks/{task}/comments` and the MCP tool `tasks-comment-list` accept optional `type` and `limit` query inputs. `type` must be a task comment type, such as `resolution` or `assistance_requested`. `limit` must be an integer from 1 to 100. The endpoint returns comments newest first, filters by type before applying the limit, and preserves each comment's full body and response shape. With neither input, it returns all comments as before. An unknown type or an invalid limit returns HTTP 422 with `validation.failed`. Use `type=resolution` and `limit=1` to read the newest resolution without returning the full comment history; the limit bounds the number of comments, not the byte size of an individual body.

`GET /api/v1/task-groups` and `GET /api/v1/task-groups/{group}`, and their MCP tools `tasks-list` and `tasks-show`, accept an optional boolean `compact` query input. With `compact=true`, each group omits `brief`, `assistance_question`, and `assistance_reason`; each subtask omits those fields plus `completion_summary`, `deliverables`, and `fixup_problem`. The latest check keeps its metadata but omits `output`. Omitted keys are absent, not null. Ids, titles, statuses, positions, assistance flags and kinds, and counters remain available. Without `compact`, or with `compact=false`, the response is unchanged. Invalid boolean input returns HTTP 422 with `validation.failed`. Use compact reads to avoid returning long text in the MCP output; this reduces the response size but does not impose a byte limit or paginate the results.

### Task lifecycle

A task moves through these statuses from preparation to its end.

| Status | Meaning |
| --- | --- |
| `backlog` | Being prepared. It has no workspace, and the scheduler never claims it. |
| `todo` | Ready. It waits for the scheduler. |
| `reserved` | The scheduler claimed it and provisions its workspace. |
| `running` | A subtask is running: its baseline check, its implementer, or its handoff check. |
| `reviewing` | A subtask waits for its reviewer, or Orbit publishes its approved commit. |
| `settling` | Every subtask has ended. Shared tasks watch the pull request here; VM tasks wait here until publication is recorded. |
| `waiting_for_review` | A VM task has published its pull request and waits for human review and CI. VM power is separate from this status. |
| `completed` | The pull request merged, or an operator completed the task. The workspace is removed. |
| `failed` | An agent could not start. |
| `cancelled` | An operator cancelled the task. |

Subtask statuses are `todo`, `running`, `reviewing`, `completed`, `failed`, and `cancelled`. At most one subtask in a task runs at a time. The next `todo` subtask starts only after every earlier subtask has ended.

## Task definitions

A task definition belongs to one Project and is a Gateway record. It stores ordered subtask definitions, the routes on their outcomes, and the parameters it declares. The Gateway knows the kinds and the validation rules. It does not hard-code any Project's definitions. [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions#task-definitions) is the contract.

Creating, replacing, or deleting a definition does not start a task. These operations do not create a task, a workspace, or a pull request. The repository `orbit-tasks` skill remains the guidance an agent reads while it works. A definition is the Project's stored plan, not a copy of that skill.

The [web app](/reference/web-app#task-definitions) lists definitions on the Tasks page and on each Project page, and it draws one definition from the live API.

### Fields

A definition has these fields.

| Field | Contract |
| --- | --- |
| `name` | Unique in the Project. 1 to 63 lowercase ASCII letters or digits, with hyphens only between them |
| `title`, `brief` | The title and brief of a task from this definition. Either can include a declared parameter as `{parameter}` |
| `parameters` | Required ordered list of parameters. At most 50. An empty list is valid |
| `status` | `backlog` or `todo`: the status a task from this definition begins in |
| `schedule` | Optional. A five-field cron expression in UTC, and at most 100 parameter values for that schedule |
| `phases` | Optional ordered phases. At most 50. A phase groups subtasks for the drawing only |
| `subtasks` | Ordered subtask definitions. At least one and at most 100 |

A phase is `{key, title, brief, repeat}`. A stored schedule does not create a task.

### Parameters

Each parameter is `{name, type, required, default}`. `type` is `text`, `app`, or `subtasks`. The field is required, and an empty list is valid. Omitting it returns HTTP 422 `validation.failed`.

A `{parameter}` in the title or brief names a parameter in `parameters`. Parameter names are unique, and a duplicate name is refused. The definition declares at most one parameter whose type is `subtasks`.

A schedule value names a parameter the definition declares. The schedule includes a value for each required parameter.

### Subtask definitions

Each subtask definition has `key`, `title`, and `kind`. It may also have `brief`, `phase`, `deliverables`, and `routes`. `key` is unique in the definition. The names `complete` and `fail` are reserved for [route ends](#routes), so a subtask cannot use them. `deliverables` follow the [deliverables](#deliverables) contract. A `phase` is a key in `phases`. The subtasks of one phase sit next to each other.

The kind adds fields and declares the outcomes a route may name.

| Kind | Fields | Outcomes |
| --- | --- | --- |
| `agent` | Optional `implementer_model` and `reviewer_model` | `passed`, `skipped`, `failed` |
| `check` | At least one `command` deliverable | `passed`, `skipped`, `failed` |
| `merge` | None | `passed`, `skipped`, `failed` |
| `action` | `operation` and `arguments`, with at most 50 arguments | `passed`, `failed` |
| `decide` | `question`, `options`, `evidence`, and optional `min_probability` | One outcome for each option |

An `action` `operation` is an OpenAPI operation marked `x-orbit-task-action: true`. Orbit marks `instance:deploy` and `instance:rollback`. The Gateway reads those names from the list `bin/mcp-tools` generates, and `bin/mcp-tools --check` keeps that list current. Marking another operation needs its own decision. A `decide` subtask's `evidence` names earlier subtasks by `key`. `min_probability` is from 0 to 1 and defaults to 0.8.

A write refuses an empty `implementer_model` or `reviewer_model`. It does not check either name against the [ProxyCli model list](/reference/proxycli#models), because that list changes over time. When that list is available, the definition view reports a model that no driver can run. A model is known when ProxyCli offers it through a provider Pi runs. A Claude model is not known, and neither is a listed model whose provider Pi does not run, such as `claude` or `google`. When the model list is missing, empty, or refused, the view says that the model list is unavailable and reports no driver findings.

### Routes

A subtask's `routes` map each declared outcome to one target. The target is the `key` of a later subtask, `complete`, or `fail`. `complete` and `fail` are reserved, so they are never subtask keys, and the Gateway and the drawing read every route the same way. A route cannot target the same subtask or an earlier subtask.

An outcome with no route uses this default. A `decide` subtask has no defaults. Its routes name a target for every option.

| Outcome | Default target |
| --- | --- |
| `passed` | The next subtask, or `complete` after the last subtask |
| `skipped` | `complete` |
| `failed` | `fail` |

### Validation

The Gateway validates a definition on every write. An invalid definition is not stored.

| Rule | The write is refused when |
| --- | --- |
| Keys | A subtask key is duplicated, or it is the reserved name `complete` or `fail` |
| Kind | The kind is unknown |
| Fields | The kind does not declare a field, a field the kind requires is missing, or a model name is empty |
| Route outcome | A route names an outcome the kind does not declare |
| Route target | A route names an unknown key, the same subtask, or an earlier subtask |
| Decide routes | A `decide` subtask has no route for an option |
| Reachability | No path from the first subtask reaches a subtask |
| Phases | A subtask `phase` is not in `phases`, or one phase's subtasks are not adjacent |
| Phase keys | A phase key is duplicated |
| Action | The `operation` is not marked as a task action |
| Parameters | A `{parameter}` is not declared, a parameter name is duplicated, or more than one parameter has type `subtasks` |
| Schedule names | A schedule value names an undeclared parameter |
| Cron | The cron expression is not five valid fields |
| Schedule values | The schedule omits a value for a required parameter |
| Bounds | More than 100 subtasks, 50 parameters, 50 phases, 50 arguments on one subtask, or 100 schedule values |

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.definition_invalid` | 422 | The definition breaks a rule above. `details.rules` lists one `{rule, subtask}` for each failure |
| `tasks.definition_exists` | 409 | The Project already uses the name |

`rule` is `keys`, `kind`, `fields`, `route_outcome`, `route_target`, `decide_routes`, `reachability`, `phases`, `phase_keys`, `action`, `parameters`, `bounds`, `schedule_names`, `cron`, or `schedule_values`. `subtask` is the subtask key, or null when the rule concerns the whole definition. A `bounds` failure for one subtask's arguments names that subtask.

### Definition operations

Five operations read and write definitions. None of them starts a task.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:definition:list` | `GET /api/v1/task-definitions` | Any authorized peer |
| `tasks:definition:show` | `GET /api/v1/projects/{project}/task-definitions/{name}` | Any authorized peer |
| `tasks:definition:create` | `POST /api/v1/projects/{project}/task-definitions` | Gateway |
| `tasks:definition:update` | `PUT /api/v1/projects/{project}/task-definitions/{name}` | Gateway |
| `tasks:definition:destroy` | `DELETE /api/v1/projects/{project}/task-definitions/{name}` | Gateway |

List accepts an optional `project_id` filter. Update replaces the whole definition, so an agent reads it, changes it, and writes it back. The update body may omit `name`. The Gateway uses the name in the path. MCP does this, because the path argument is not repeated in the body. A body `name` that is present and different from the path is refused. Only Gateway access can write a definition, so a definition cannot grant a caller more authority than that caller already has.

The [CLI commands](/cli/tasks#orbit-tasksdefinitionlist) for create and update take the definition as a JSON file. The [MCP tools](/reference/mcp) are generated from these operations. While the tasks extension is off, each operation refuses with HTTP 409 `extension.disabled` and changes nothing.

## Tasks and subtasks

List, show, and `tasks:question:list` accept any authorized peer. Update and the subtask create, update, and destroy operations are served by the Node that holds the task's workspace, or by the Gateway for a task without one. The other operations require Gateway access.

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
| `tasks:question:list` | `GET /api/v1/task-questions` | Any peer |
| `tasks:question:close` | `POST /api/v1/task-questions/{question}/close` | Gateway |
| `tasks:agents` | `GET /api/v1/task-groups/{group}/agents` | Gateway |

Each MCP tool name is the operation name with hyphens, such as `tasks-subtask-create`. The paths keep the `task-groups` segment. `{group}` is the top-level task id, and `{task}` is the subtask id.

Create requires `project_id`, `title` (at most 160 characters), and `brief` (at most 8,000 characters). The Project must read its repository through the GitHub App. A Project with [`source_access: gh_cli`](/reference/projects#source-access) cannot start a task, because Orbit publishes only through the App. It accepts an ordered `tasks` array of at most 50 `{title, brief, deliverables}` objects, a `status` of `backlog` or `todo`, and `notify_coder`. The status defaults to `backlog`. Create with `status: todo` asks the scheduler to claim at once. List accepts `project_id` and `status` filters. Show returns the task and its subtasks in position order.

Update changes a task's `title`, `brief`, or `status`. Title and brief change only in `backlog`. The status moves between `backlog` and `todo` in either direction, and a move to `todo` asks the scheduler to claim. A status update and a claim cannot both succeed. When the claim wins, the update returns `tasks.already_claimed`.

A task moves to `todo`, by create or update, only when it has subtasks and every subtask has at least one deliverable.

### Change subtasks

Subtask create appends a `todo` subtask at the next position. It works in every task status except `completed` and `cancelled`. Outside `backlog`, the new subtask needs at least one deliverable.

Subtask update changes `title`, `brief`, `position`, or `deliverables`. A `deliverables` value replaces the whole list. Positions stay gapless from 1.

| Task status | What can change |
| --- | --- |
| `backlog` | Every field of every subtask. Subtask destroy deletes a subtask and closes the gap. |
| `todo`, `running`, `reviewing`, `settling` | Every field of a `todo` subtask. Its deliverables cannot become empty. Its position moves only among the `todo` subtasks after the last started or ended subtask. |
| `reserved`, `failed` | Nothing. Subtask create still appends |
| `completed`, `cancelled` | Nothing |

A subtask that has started keeps its title, brief, position, and deliverables. A deliverables update on it returns `tasks.deliverables_locked` and leaves the stored list as it is, except for the one correction after `invalid_deliverable` described below.

A `todo` subtask appended to a `settling` task returns the task to `running` on the next tick, as [Fix a settling pull request](#fix-a-settling-pull-request) describes.

### Cancel a subtask

`tasks:subtask:cancel` cancels a `todo` or a `running` subtask.

- A `todo` subtask in a `todo`, `running`, `reviewing`, or `settling` task becomes `cancelled`. Nothing starts, and nobody is asked for assistance.
- A `running` subtask stops first. Orbit interrupts its implementer, then stops its running [task check](#project-check).

Orbit holds no database lock while it stops a `running` subtask. When a stop fails, the call returns HTTP 502 `tasks.subtask_interrupt_failed`. The subtask then stays `running`, so retry, or cancel the task.

After both stops, Orbit cancels the subtask only while it is still `running`. A tick can move the subtask on in the meantime. Its new state then stands, and the call returns `tasks.subtask_not_running`.

The lowest `todo` subtask then starts. When no subtask is open, the task moves to `settling` as after an approval.

Cancel does not reset the workspace. Uncommitted edits of the cancelled implementer stay, and the next approval commits them.

### Errors

The task and subtask operations return these errors.

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.no_subtasks` | 422 | A move to `todo` for a task without subtasks |
| `tasks.subtask_deliverables_missing` | 422 | A move to `todo` while a subtask has no deliverables, and then `details` names each subtask. Also a subtask without deliverables outside `backlog` |
| `tasks.group_closed` | 409 | Subtask create in a `completed` or `cancelled` task |
| `tasks.not_in_backlog` | 409 | A title or brief update outside `backlog`, or a subtask update or destroy that the table above does not permit |
| `tasks.deliverables_locked` | 409 | A deliverables update on a subtask that has started |
| `tasks.deliverable_base_unavailable` | 422 | The repository base or its complete file tree could not be read for path validation |
| `tasks.already_claimed` | 409 | A status update on a task the scheduler already claimed |
| `tasks.subtask_not_running` | 409 | A subtask cancel that the rules above do not permit |
| `tasks.subtask_interrupt_failed` | 502 | Orbit could not stop the implementer or the check |
| `tasks.agent_driver_unavailable` | 409 | The configured agent driver is unknown. No task is stored |
| `tasks.agent_transcript_unavailable` | 409 | A transcript request for a stored `t3` task thread. The row stays, and no stream opens |
| `tasks.github_app_required` | 422 | Create for a Project with `source_access: gh_cli`. No task is stored |
| `tasks.external_execution` | 409 | A lifecycle operation on an annotation task |
| `validation.failed` | 422 | An invalid field, such as a deliverable or a position outside the `todo` subtasks |

## Deliverables

A deliverable is one item that a subtask must deliver, in a form Orbit can check. The ADE or an operator writes the deliverables next to the brief. The implementer confirms each one at handoff. Orbit verifies the mechanical ones before the reviewer starts.

Each deliverable has an `id`, a `type`, a `description`, and the fields of its type.

| Type | Fields | Orbit checks |
| --- | --- | --- |
| `file` | `path`: a path or glob from the workspace root. `change`: `created`, `modified`, or `any` | A matching path in the subtask's diff, added for `created`, modified for `modified`, either for `any` |
| `command` | `command`. `directory`, relative to the workspace root, default `.`. Optional `fails_on_base` and `paths` | The command exits 0 on the working tree. With `fails_on_base`, it also exits nonzero on the start commit |
| `review` | none | The reviewer confirms it in its approval |

```json
[
  {"id": "reference-page", "type": "file", "description": "Document the export", "path": "docs/reference/export.md", "change": "modified"},
  {"id": "export-tests", "type": "command", "description": "The export tests pass", "command": "vendor/bin/pest tests/Feature/ExportTest.php", "directory": "apps/gateway"},
  {"id": "layout-repro", "type": "command", "description": "The home-screen regression fails before the fix", "command": "vendor/bin/pest tests/Feature/HomeScreenTest.php", "directory": "apps/gateway", "fails_on_base": true, "paths": ["apps/gateway/tests/Feature/HomeScreenTest.php"]},
  {"id": "web-tests", "type": "command", "description": "The web app tests pass", "command": "bun test", "directory": "apps/web"},
  {"id": "error-copy", "type": "review", "description": "Error messages name the failing subtask"}
]
```

| Rule | Limit |
| --- | --- |
| Number | At most five per subtask. At least one outside `backlog` and for a move to `todo` |
| `id` | A lowercase slug such as `export-tests`, at most 64 characters, unique in the subtask |
| `description` | At most 500 characters |
| `path`, `directory` | Relative paths without `..`, at most 500 characters |
| `command` | At most 1,000 characters |
| `fails_on_base` | The JSON boolean `true` or `false`, on a `command` deliverable only. Omitted means `false`. `true` needs at least one path |
| `paths` | A list of at most 100 canonical repository-relative file paths on a `command` deliverable. Each path is at most 500 characters |

A field of another type is refused with HTTP 422 `validation.failed`. The error names the field path, such as `deliverables.0.path`. The `fails_on_base` and `paths` errors also name the deliverable's `id`. Only a `file` deliverable's `path` accepts a glob: `*` matches in one directory, `**` matches across directories, `?` matches one character, and `{a,b}` is a non-nested alternative, including a single choice such as `{php}`. Alternatives may contain slashes and the same `*`, `**`, and `?` rules. `paths` is not a glob.

A canonical command path has no leading `/` and no `..`, `.`, or empty segment. The handoff check reads `paths` as stored and refuses those segments, so plan time refuses them too. For example, `./tests/FooTest.php` and `tests//FooTest.php` return HTTP 422 `validation.failed`. The error names the canonical form, `tests/FooTest.php`.

Task create, subtask create, and subtask update validate deliverable paths against a selected base commit. A resolved subtask base uses the recorded start commit, the base of a continuation's source subtask, the previous approved commit, or the workspace starting commit, in that order. When no base resolves, validation uses the Project's default-branch HEAD SHA at request time as a provisional base. It does not consult the default branch when a resolved base exists. Existing groups still validate subtask deliverables if the Project later switches to GitHub CLI source access; creating a new group still requires the GitHub App.

Orbit reads the base tree from the task workspace when the group has one. In a [review-and-merge](#review-and-merge) Project, an approval is committed but not pushed, so only the workspace has it. Without a workspace, Orbit reads the commit from the Project repository. It also reads the Project repository when the workspace cannot list the commit. When neither source has the commit, the request fails with `tasks.deliverable_base_unavailable`.

Before each implementer spawn, including retries and the next subtask after approval or cancellation, Orbit rechecks deliverable paths against the resolved review base after recording the subtask's start commit. It never falls back to the default branch at this gate. Missing paths request assistance instead of starting the agent; the reason names each failing deliverable id, path, and base SHA. An unresolved base or an unreadable base tree also requests assistance without starting the agent. This prevents a plan accepted against a provisional default-branch commit from reaching an agent on a release seed or task branch that lacks its paths.

Every reason from this gate starts with `Deliverable path validation`. No implementer exists yet, so recovery does not go through an agent:

1. Fix the cause.
2. For missing paths, replace the subtask's `deliverables` with subtask update.
3. Post a `resolution` comment on the subtask.
4. The next scheduler tick runs the gate again.

A `deliverables` update is accepted while the gate holds the subtask. It must pass the same base-path validation. Task activity records the old and new lists. This update does not use the one correction after `invalid_deliverable`.

The resolution clears the subtask's assistance. It also clears the task's assistance when no other subtask asks for it. Task activity records `resolution queued deliverable gate retry`. When the gate passes on the next tick, the implementer starts. When it fails, the subtask asks for assistance again with the new reason.

A file path must exist on that base, and a file glob must match at least one base file, unless `change` is `created`. File patterns and created-file companion patterns use the same relative-path normalization as handoff, including removal of leading `./`. Errors retain the submitted path.

Every command `paths` entry must exist on the base unless a sibling file deliverable with `change: created` covers it, literally or through a glob. A sibling with `change: modified` or `change: any` is not a new-file marker. With `fails_on_base: true`, each command path must also be a test file: under a `tests/` directory, or ending in `Test.php`, `.test.ts`, `.spec.ts`, or `_test.go`. This prevents a base run from copying the implementation fix. A violation returns HTTP 422 `validation.failed`; its field error names the deliverable id, path, base SHA, and `base_kind=resolved` or `base_kind=provisional`.

There is no `test` deliverable type. A migration converts stored `test` deliverables in tasks that are not completed, failed, or cancelled, and it leaves `task_check` unchanged. Each stored `test` deliverable names a Pest file and a test-name substring. The migration normalizes the project and file paths, then runs `vendor/bin/pest` from that project directory with the file and `--colors=never`. The name match is a case-sensitive substring, and regex characters in the name are escaped so they stay literal.

It carries over `fails_on_base`. When the base run is on, `paths` lists the workspace-relative test file. The migration also adds a `file` deliverable with `change: any` for that file. The command and the file stay together, and each id stays unique and at most 64 characters.

When the converted list would exceed five deliverables, the extra pairs go on continuation subtasks placed directly after the source subtask. The migration writes each source task and the continuation rows it adds in one database transaction. A continuation uses the source subtask's start commit for its diff and its base run, including when the source subtask has committed its fixes.

A subtask's diff runs from its start commit to the working tree that the check sees, uncommitted and untracked files included. Deleted and ignored files never match. Orbit records the start commit when the subtask starts, before the implementer's first turn. When that read fails, the next tick tries again until the first turn starts. After that, the start commit stays empty, and the diff uses a fallback base: the previous subtask's approved commit, or the workspace starting commit for the first subtask.

### Prove a command fails on the start commit

A `command` deliverable with `fails_on_base: true` proves that the command fails before the fix. Omitted and `false` are the same: Orbit runs the command once, on the working tree. With `true`, Orbit runs it twice at handoff. The deliverable passes only when the base command exits nonzero and the working-tree command exits 0. A failing base command is the expected evidence.

| Run | Code under test | Passes when |
| --- | --- | --- |
| Base | The start commit, or its fallback base, plus the files in `paths` from the working tree | The command exits nonzero |
| Working tree | The implementer's tree | The command exits 0 |

The base run extracts an archive of the start commit into a directory under the workspace's `$(git rev-parse --git-path orbit)/bases/`. It copies installed `vendor` and `node_modules` directories from the workspace, and no other dependency directory, then copies each file in `paths`, including an uncommitted or untracked file. It runs the command there with `bash -lc`. It does not change the workspace and registers no Git worktree. The check removes the directory when the run ends, and the next check removes a directory that a killed run left behind.

A command that needs another installed tree, such as a Python virtualenv or a Rust `target` directory, can fail on that base tree only because the tree is missing. That nonzero exit satisfies `fails_on_base` and is not evidence that the command reproduced a bug. This copy is retained. A follow-up has to widen it or stop counting a missing dependency as reproduction evidence.

The base run stops after 600 seconds, and a timed-out run counts as failing on the start commit. Exit 126 or 127 means the command did not run, so the deliverable fails. When the base command exits 0, the deliverable fails because the command does not reproduce the failure. The check stores the exit code and the tail of the output, at most 4,096 characters. It records `base_started`, `base_exit_code`, and `base_output`. A timed-out run also records `base_timed_out` and `base_timeout_seconds`. The engine does not read test names or runner output. The Project's command does that. Show and the turn file include `fails_on_base`. An omitted input is stored as `false`.

Task create, subtask create, and subtask update accept `fails_on_base` and `paths` only on a `command` deliverable. `fails_on_base` is the JSON boolean `true` or `false`, and `paths` is a list of strings. Any other value is HTTP 422 `validation.failed`. The error names that deliverable's `id`.

The Orbit Project's [task policy skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) requires repro-first bug work where a command can reproduce it. A bug task's first code subtask carries that command. When a bug cannot be reproduced by a command, the brief says so and that subtask adds a `review` deliverable for the manual check. [`bin/bug-repro`](/reference/delivery-line#binbug-repro) runs that command against current main without filing a task. [`bin/task-group-check`](/reference/delivery-line#bintask-group-check) validates the create payload, including `fails_on_base`, without adding a proof field.

### Confirm deliverables

The Gateway writes the subtask's deliverables into `$(git rev-parse --git-path orbit)/turn.json` before each turn. The agent confirms each one in its [turn receipt](#turn-receipt) with `--deliverable=ID=evidence`, where the evidence says where or how the deliverable is met.

| Turn | Needs |
| --- | --- |
| Implementer `ready_for_review` | A confirmation for every deliverable |
| Reviewer `approved` | A confirmation for every `review` deliverable. Other IDs are allowed |

The turn command refuses a missing confirmation, an unknown ID, an ID given twice, empty evidence, and `--deliverable` with any other outcome. The Gateway stores the confirmations on the receipt's comment.

### Verify deliverables

The [handoff check](#project-check) first rejects a command whose directory is outside the checkout, and a base run whose `paths` are missing or are not files in the workspace. An invalid deliverable fails the check at once, before the task check runs, with a message such as `Deliverable layout-repro names invalid overlay path apps/gateway/tests/Feature/HomeScreenTest.php.` The task then asks for assistance with that message. The implementer gets no reminder.

While assistance is requested and the subtask and group are still running, the operator can use the existing subtask update flow (`PATCH /api/v1/task-groups/{group}/tasks/{task}`, or `tasks-subtask-update`) to replace only `deliverables` once after the latest handoff check fails with `failed_step: invalid_deliverable`. The replacement cannot be empty and must pass the same base-path validation, including test-only paths for `fails_on_base`. Invalid requests do not consume the correction.

Task activity history records the failed check id and the old and new lists. The task stores consumption separately, in the same transaction as the correction and audit, so Activity cleanup cannot reopen recovery. A second correction returns `tasks.deliverables_locked`, even if another handoff fails with `invalid_deliverable`. Title, brief, position, and topology stay locked.

After the correction, post a `resolution` comment through the existing task comment flow. Orbit stores the first resolution's delivery key, implementer thread, and full corrected contract before remote work. It refreshes the implementer's turn file and sends the complete corrected deliverable fields, including file paths and changes and command directories and commands. The same thread resumes. The next `ready_for_review` receipt runs a new handoff check. The workspace, start commit, and implementer's work stay in place; there is no cancel or recreate step.

An ended-pull-request reason on either the subtask or group stops correction recovery. Orbit checks fresh reasons before preparing turn metadata, before sending the correction, and before committing delivery. It preserves the pending correction identity, completion attempt, and ended-pull-request holds.

A failed or interrupted correction resume stays pending. The scheduler retries it before the assistance hold blocks progress. Metadata preparation and the agent send share the stored delivery identity. Replaying preparation after a lost reply preserves an already resumed turn and its receipt. Replaying a send reconciles remote acceptance instead of starting a second turn. Orbit clears assistance and records delivery together after acceptance. This recovery applies only to the correction's first resolution; other resolutions keep their existing flow.

A newer direction request pauses correction recovery. If direction arrives before the first correction resolution, Orbit still reserves that resolution and its authenticated caller, but direction owns the delivery. When the reviewer answers that direction, Orbit includes the corrected contract in the implementer's continuation and records the pending correction as superseded in the same transaction that finishes the direction delivery. The old correction key is not prepared or sent again, so it cannot replace the newer turn or erase its receipt.

When the task check passes, the check records the diff and runs each command in a login shell in its directory. A base run, when `fails_on_base` is set, runs on the start commit before the working-tree command. The Gateway checks each `file` deliverable against that diff. The `deliverables` rubric item fails when a confirmation is missing or a deliverable does not pass. Its reminder names each failing deliverable and why. The reviewer starts only when every deliverable passes.

## Prepare a task in Backlog

Prepare a task in Backlog before any agent runs. A Backlog task has no workspace.

1. Create the task. It starts in `backlog`.
2. Optionally, create the branch `task-{id}` from the Project default branch, commit the feature's contract to it, and push it.
3. Add the ordered subtasks with their deliverables, following the Project's task policy.
4. Move the task to `todo` with `tasks:update`.

The Gateway does not check the branch contents. When `origin/task-{id}` exists, the workspace checks it out. Otherwise the workspace starts a new branch from the default branch. The implementer and reviewer prompts are project-neutral. They do not add an ADR contract sentence or Orbit-specific policy such as Incus or lease instructions. A prompt names the Project task-check command only when one is configured.

When the workspace starting commit is 40 or 64 hexadecimal characters, both prompts add `The task started at <sha>.` and `git diff --stat <sha>..HEAD`. The review packet places those lines after its stat and diff commands. The lines name no Project, branch, or policy. Any other value is left out.

## Outer loop

The Gateway files a Backlog task when the same production problem keeps returning. An operator edits that task and moves it to Todo. The scheduler does not claim it before that move.

The loop reads Doctor, Activity, the Gateway log, and assistance reasons. A release command also pushes its [release alerts](/reference/gateway-recovery#release-alerts) into the loop. It does not read the `schedules` table. It does not wait for an external alert manager.

### Fingerprints

Each signal updates one row in `problem_fingerprints`. The fingerprint is unique.

| Column | Meaning |
| --- | --- |
| `fingerprint` | Stable key, at most 255 characters |
| `source` | `doctor`, `activity`, `log`, `assist`, or `release` |
| `first_seen`, `last_seen` | Signal time of the first accepted signal, and of the latest |
| `occurrences` | How many 5-minute windows were counted, not how many log lines |
| `evidence` | A small JSON sample |
| `task_group_id` | Top-level task filed for this key, or null |
| `muted_until` | Filing stays off until this time, or null |
| `filed_at` | When this episode was filed, or null. The operator cannot edit it |

A key longer than 255 characters keeps the source prefix, then `#`, then the first 12 hex characters of the SHA-256 of the full key.

The sample holds at most five request ids, five Activity ids, and five Activity paths. It holds one log excerpt of at most 500 characters, the latest Doctor expected and observed values, the latest Doctor summary, the assistance reason before normalization, the newest 20 occurrences, and up to 200 open assistance task ids. A release alert adds its summary as the summary, the release repository and release id, and up to five evidence links.

Each occurrence stores the UTC time of the first signal in its 5-minute window and how many signals fell in that window. A log row also stores its app frame path as `source_path`, including when the fingerprint is shortened. The summary and the assistance reason are cut at 1,000 characters. The excerpt and an Activity error message pass through the Gateway log redactor before they are stored. Expected and observed stay the bounded Doctor values. The sample does not store a raw Doctor report.

| Source | Key |
| --- | --- |
| Doctor | `doctor\|code\|resource_type\|resource_id` |
| Activity | `activity\|command\|error_code` |
| Log | `log\|exception class\|first app frame` |
| Assistance | `assist\|normalized reason` |
| Release alert | `release\|kind\|target\|sha` |

A null Doctor resource id uses `none`. An Activity row with a nonzero exit code and no error code uses `exit` as the error code segment. The resource id stays out of the Activity key. It lives only in `properties.path`, and that path is evidence.

An Activity row counts only when it is server-class. A row is server-class in any of these cases:

| Test | Example |
| --- | --- |
| The error code is `gateway.unhandled` or `activity.interrupted` | An unhandled Gateway error |
| The error code ends in `_failed` or `.unavailable` | `instance.clone_failed` |
| The error code is `http.` plus a status of 500 or more | `http.500` |
| `exit_code` is nonzero | A command that exited 1 |

`validation.failed`, `http.404`, `http.409`, and `http.422` do not count unless they also match a test above. A code that ends in `_failed` still counts when the HTTP status is below 500.

A log record counts when its level is ERROR or higher, it names an exception class, and the trace has a frame under the Gateway `app/` directory. The frame in the key is that path relative to the Gateway root, a colon, and the function, with no line number. An `HttpExceptionInterface` whose status is below 500 is left out. `ValidationException` is left out. A trace with no app frame is left out. The excerpt keeps the `request_id` from the log context.

An assistance reason is trimmed and lowercased. Each UUID, and each run of digits, becomes `#`. Whitespace collapses to one space. One open request on a task counts once. The same task counts again only after `assistance_requested` has cleared and a new request is stored.

### Occurrence windows

A signal that passes the source tests above increments `occurrences` only when that fingerprint has no counted signal in the same UTC block of 5 minutes. The block index is the signal's Unix time divided by 300, rounded down. A second signal in that block keeps the occurrence time already stored, adds one to that occurrence's signal count, and can still add request ids and the other bounded sample fields. It does not add an occurrence, and it does not raise `occurrences`. It does move `last_seen` to its own time.

The signal time is the time on the signal, not the time the collector reads the source. A log record uses the bracketed timestamp at the start of its header, read in the Gateway application timezone. An Activity row uses its `created_at`. Doctor and assistance use the collector clock when it accepts the signal. A release alert uses the Gateway clock when the alert is raised. The block uses that time in UTC.

Readiness counts these occurrences and their times. It does not count log lines. Many log lines in one block are one occurrence. The sample keeps the newest 20.

### When a fingerprint is ready

The tests below use only the current episode. That episode is the occurrence history stored on the row: the time and the signal count of each 5-minute window.

Doctor is ready after two of those times at least 10 minutes apart. A miss does not delete the row, and it does not reset the episode.

A release alert is ready after one occurrence. A release command raises it once for a deliberate verdict, so there is no noise to wait out.

Activity, the log, and assistance are ready when either test below is true for those same times. The count in both tests is `occurrences`, the number of windows, not the number of log lines.

| Test | Ready when |
| --- | --- |
| Burst | `occurrences` is 10 or more |
| Spread | `occurrences` is 3 or more, and the occurrence times cover two UTC quarter hours or two UTC dates |

A quarter hour is the UTC block of 15 minutes that contains the time. The block index is the Unix time divided by 900, rounded down. Ten log lines in one 5-minute window do not meet the burst test.

Filing a task clears that occurrence history after the brief is built. Hits while that task is still open start another episode. The filer clears that episode in the same write as `muted_until`, when the linked task ends. Only a hit after the task ended can make the key ready once the mute ends. A hit after the merge and before the deploy still counts, and the operator cancels that draft.

### Suppression

The collector and the filer honor two lists in [`apps/gateway/config/orbit.php`](https://github.com/nckrtl/orbit/blob/main/apps/gateway/config/orbit.php), in a `problems` array beside `tasks`.

| Key | Match |
| --- | --- |
| `suppressed_fingerprints` | The whole fingerprint, exact and case-sensitive. Ships as an empty list |
| `suppressed_path_prefixes` | The start of a source path. Ships with `app/Infrastructure/Tasks/T3/` |

A log record's source path is the app frame path, the path before the colon in the fingerprint frame. An Activity source path is a path taken from `properties.path`. Doctor and assistance have no source path. A path prefix matches that source path, not the fingerprint string. An empty prefix matches nothing.

The collector copies a log row's source path into the sample as `source_path`. The copy is independent of the fingerprint string. A key longer than 255 characters is shortened to the source prefix, `#`, and 12 hex characters, and that shortened key has no frame path. An accepted log signal writes `source_path` when the sample does not already have one, including a signal that stays in an open 5-minute window and does not increment `occurrences`.

A listed fingerprint, or a source path that starts with a listed prefix, is suppressed. The collector does not count that signal, and it does not create or update a fingerprint row for it. It still advances that source's cursor past the signal.

Each filer run reads the current lists. A row counted before a prefix was configured is still skipped when its stored fingerprint is listed, or when its stored `source_path` or an Activity path starts with a current prefix. For a log row with no `source_path`, the filer reads the frame path only when that path is still in the fingerprint.

A shortened log fingerprint with no `source_path` has no recoverable frame path. The filer does not invent one from the hash. While `suppressed_path_prefixes` is non-empty, the filer skips that row and leaves it unchanged: no task, no mute, and no episode clear. A later accepted signal stores `source_path`, and a later run applies the current lists. An empty prefix list does not block filing. These lists are separate from the mute below, and both apply.

The filer does not open another task for a key while `muted_until` has not passed. It also waits while the linked task has any status in this list: `backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`.

| Linked task | Deadline written once, from `updated_at` |
| --- | --- |
| `completed` or `failed` | 7 days, only when `muted_until` is empty |
| `cancelled` | 14 days, only when `muted_until` is empty |

A deadline that is already stored stays as it is. A missing linked task uses the 7-day deadline, measured from the run that notices the gap. `failed` uses the same wait as `completed`, because that task never ran and must not take another slot in the same hour.

Filing a new task clears `muted_until` and sets `filed_at`. It sets `occurrences` to 0 and clears `first_seen`, `last_seen`, and the occurrence history. It also clears the request ids, Activity ids, paths, evidence links, the log excerpt, and `source_path`. Open assistance task ids stay, so a request that is still open is not counted again. The brief is built from the episode before that clear.

The first time the filer writes `muted_until` for a `completed`, `failed`, `cancelled`, or missing task, that same write clears the episode again. It sets `occurrences` to 0 and clears `first_seen`, `last_seen`, the occurrence history, the request ids, Activity ids, paths, evidence links, the log excerpt, and `source_path`. Open assistance task ids stay. A crash stores neither the deadline nor the clear.

### What gets filed

`problems:file` runs every hour. It files at most three new tasks per day, using the Gateway application timezone. It takes release alerts first, then the highest `occurrences`. Equal counts use the earlier `first_seen`, then the fingerprint string. Each run loads at most 50 ready rows that are not muted, not tied to an open task, and not skipped by [Suppression](#suppression).

The cap counts fingerprint rows whose `filed_at` falls on today's date in that timezone. An operator edit to the brief does not change the count. A missing Orbit Project files nothing.

The filer inserts the task and its subtasks, then updates the fingerprint, in one database transaction. The update sets `task_group_id` and `filed_at`, clears `muted_until`, and resets the episode as [Suppression](#suppression) describes. A crash rolls every one of those writes back, so the next run does not file a duplicate.

Each task belongs to the Project whose slug is `orbit`, and the task starts in `backlog`. The first line of the brief is `Filed by the outer loop.`

The rest of the brief is eight sections, in this order: Symptom, Fingerprint, First seen, Last seen, Count, Occurrences, Evidence, and Suspected entry point. Times use UTC. Symptom is the Doctor summary, the redacted Activity error message, the redacted log message, the assistance reason before normalization, or the release alert's redacted summary.

Count is `occurrences`, the number of windows. Occurrences lists one line per window, oldest first, at most the newest 20. Each line is the first signal's time, formatted `YYYY-MM-DD HH:MM:SS UTC`, a space, and the signal count in that window, such as `2026-10-01 12:00:01 UTC 129`. Evidence includes a `Request ids:` line when the sample has any, and omits that line when none are known. The suspected entry point is its own section.

| Section | Bound |
| --- | --- |
| Symptom | 1,000 characters, then `...` |
| Fingerprint | 255 characters |
| First seen, Last seen, Count | One line each |
| Occurrences | The newest 20 windows, one line each |
| Evidence | The sample caps. Expected and observed are cut at 200 characters |
| Suspected entry point | 500 characters, then `...` |

The finished brief is at most 8,000 characters. The Evidence and Occurrences headings are always present. When either section has no lines, it says `none`. If the brief is still longer, the filer drops Evidence lines until it fits, and both headings stay. The occurrence lines stay. `tasks:create` refuses a longer brief with `validation.failed`. If create still fails, the filer skips that row and leaves `filed_at` unset. The row does not count toward the cap. The filer continues with the next row. Each subtask brief copies the cut symptom and stays under 8,000 characters.

| Source | Title | Suspected entry point |
| --- | --- | --- |
| Doctor | `Doctor {code} on {type} {id}` | Resource type, id, and code |
| Activity | `{command} failed with {error_code}` | The command name |
| Log | `{exception class} at {frame}` | The app frame, or the stored `source_path` when the key is shortened |
| Assistance | The normalized reason | The open task ids in the sample |
| Release alert | `Release failed`, `Release paused`, or `Rollout halted`, then `for {target} at {first 12 characters of the sha}` | `{repository}@{sha}`, and the release id when there is one |

A title longer than 160 characters is cut to 157 characters plus `...`.

The task has two subtasks. The docs subtask is first. Its deliverable id is `docs`, its type is `review`, and the description says the owning page matches the fix, or that no page changes. The operator can replace that deliverable while the task is in Backlog.

The second subtask reproduces the failure and then fixes it. Its deliverable id is `test` and its type is `review`. There is no `test` deliverable type. The description is `Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.` The deliverable has no `command`, `directory`, `fails_on_base`, or `paths` field. The filer never writes a whole-suite command, including `vendor/bin/pest` with no test file. The operator replaces that review with a `command` deliverable for one scoped test, sets `fails_on_base` to true, and names the test files in `paths`, before moving the task to Todo. [Prepare a task in Backlog](#prepare-a-task-in-backlog) is that edit.

### Collection

`problems:collect` runs every 10 minutes. Both commands run only while the Tasks extension is enabled. `TaskSchedule` registers both. Each uses an overlap lock. The collector lock expires after 15 minutes, and the filer lock expires after 30 minutes.

| Source | Bound per run |
| --- | --- |
| Doctor | 200 issues, then the next run resumes in fingerprint order |
| Activity | 500 rows with `id` above the stored cursor |
| Log | 1 MiB, stopping at the end of a whole record |
| Assistance | 200 open rows |

Doctor runs through `RunDoctorAction` for every Node and every family. A peer access grant does not drop Nodes from that fleet. The collector records only `drift` and `unverifiable` issues. It skips `informational` issues, such as unregistered packages, because Doctor health ignores them too. One `problem_collector_state` row stores the Activity cursor, the log path, the file inode, the byte offset, and the Doctor resume key. A Doctor pass that handles fewer than 200 issues clears the resume key.

The first collector run sets the Activity cursor to the current maximum id, and the log offset to the end of the current file. It does not count those past rows. The log file is `storage/logs/laravel.log` when that path is a regular file. Otherwise it is the newest `storage/logs/laravel-*.log`. The collector finishes unread bytes in a rotated file before it switches.

Each source commits its fingerprint updates and its cursor in one database transaction. For Activity that cursor is the last id. For the log it is the path, inode, and offset. For Doctor it is the resume key. For assistance it is the open task ids. A crash rolls that source back, so the same rows are not counted twice. The collector applies [Suppression](#suppression) and the [occurrence window](#occurrence-windows) before it increments `occurrences`.

A failure in one source does not skip the others. The same exception class for one command is reported at most once an hour. The command still exits nonzero when any source failed.

## Scheduler

The scheduler command `tasks:tick` does all work of the extension. The Gateway's Laravel schedule runs it every 10 seconds, `problems:collect` every 10 minutes, and `problems:file` every hour, while the extension is enabled. The Gateway host must run `php artisan schedule:work`, or no task advances. One cache lock, held for up to 300 seconds, protects scheduled and manual ticks. A tick that finds the lock held does nothing.

A tick that takes the lock records that time in the Gateway cache, and [`tasks:status`](/cli/tasks#orbit-tasksstatus) reports it as `last_tick_at`. A ticking scheduler moves it forward about every 10 seconds. Clearing the cache forgets it until the next tick. [`bin/gateway-smoke`](/reference/delivery-line#bingateway-smoke) reads it to prove the scheduler runs after a Gateway release.

Each tick runs these steps in order:

1. Watch the task pull request and start a waiting subtask. See [Pull request](#pull-request-and-settle-metrics).
2. Advance each `running` and `reviewing` subtask. See [Session routing](#session-routing).
3. Return tasks that stayed `reserved` too long to `todo`.
4. Remove the workspaces of ended tasks. See [Complete and cleanup](#complete-and-cleanup).
5. Claim `todo` tasks while Node capacity lasts.

### Claim and provision

A claim takes the oldest `todo` task that fits and moves it to `reserved`. The provisioner then creates the [task workspace](#shared-instance) on a Node that fits:

- an active Linux Node with an active `app-dev` role and a WireGuard address;
- not excluded from the Project by a [development node exclusion](/reference/development-node-exclusions);
- an active `pi-server` Process with desired state `running`;
- not the Node of a [task VM](/reference/compute-drivers#task-vms) that is not `destroyed`;
- with fewer than 10 active tasks. Active tasks are `reserved`, `running`, `reviewing`, and `settling`.

Among the Nodes that fit, the one with the fewest active tasks wins. There is no per-Project limit, and the scheduler never polls Nodes for capacity.

A group of a web Project with `task_compute: vm` skips this selection. It gets its own task VM, and its workspace goes on that VM's Node. See [Task VM workspace](#task-vm-workspace).

When the workspace is ready, the task becomes `running`, and its first subtask starts. When a claim fails, the task returns to `todo`, and the claim continues with the next task. A tick tries each failing task once.

| Cause | Result |
| --- | --- |
| Every fitting Node is full | The task waits without a reason. When no `app-dev` Node has capacity, claims stop until the next tick. |
| No Node fits, source defaults are invalid, or provisioning throws | Count consecutive failures per group in cache. Request `failure` assistance at `ORBIT_TASKS_PROVISIONING_FAILURE_THRESHOLD` (default `3`, minimum `1`). |
| Provisioning failure with a known cause | Prefix `Workspace provisioning did not return an instance.` followed by the constraint or exception class, step, and message, such as `app-instance-source-prepare` for an existing checkout. |
| Workspace creation exception | Log the exception through Laravel `report()` before returning a typed failure. |
| Provisioner returns null | Use the fixed reason `Workspace provisioning did not return an instance.` |
| Successful start after provisioning failures | Clear the claim-failure reason and reset the consecutive-failure counter. |
| The move to `running` fails after provisioning | Reason `The task could not start after its workspace was provisioned.` The task keeps its workspace. |
| The task stays `reserved` longer than `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | Reason `The task stayed reserved too long and returned to todo.` |

These reasons and their failure assistance clear when the task starts, waits for capacity, or moves between `backlog` and `todo`. Direction requests stay open. Each release applies only while the claim still holds that reservation, so a claim never overwrites a newer claim or a cancel.

The scheduler records a successful workspace start in the activity log within the transaction that moves the group to `running`. That activity ID identifies the next counter generation. A rollback keeps the old generation and streak. A committed start resets the streak even if the Gateway stops before deleting the old cache entry. Cache reads, writes, and cleanup failures are logged; an unavailable counter means no prior failures, and never stops the tick.

A new task workspace reservation records a UUID `source_prepare_id` before source preparation, just like an ordinary development Instance. Preparation uses that ID to write an ownership receipt for later reclaim and removal. Older reservations can lack the ID; reclaim still checks their source identity.

A claim that stops after it created the workspace leaves the `task-{id}` Instance behind. The next claim finds it by name and branch and resumes it on its Node. If the Instance is still `reserved`, preparation permits its existing checkout even when no starting commit was recorded. Preparation still verifies the repository, checkout ownership, linked worktree registration, and any recorded source preparation receipt before resolving the task branch. Another Instance with that name but another branch is never adopted. When the task was cancelled while its claim ran, the claim removes the workspace it created.

### Start a subtask

A subtask starts in this order:

1. Orbit records its start commit.
2. When no implementer has started in the task, Orbit runs the [baseline check](#baseline-check). The first implementer starts only after it passes.
3. Orbit reserves an [agent thread](#agent-threads) row, writes the turn file, and starts the implementer with its opening prompt.

The opening prompt tells the implementer to finish the assigned work on its own. It may change disposable fixtures in its allocated environment, and it resolves routine test prerequisites. It does not name Orbit lease, Route, or publication rules. It ends with `Follow this repository's task instructions.`

When the implementer cannot start, the subtask and the task become `failed`, and the Gateway logs which spawn failed. A task created in `todo` can therefore answer create as `failed`.

## Shared Instance

The task workspace is one fresh Instance that every subtask of the task shares. Its name and its branch are `task-{id}`. It lives in the Node's apps root like any development Instance. Its Project setup steps [copy dependencies](/domains/applications#dependency-copy) from the successful `default` release on the same Node.

Before returning the workspace or activating its Route, Orbit inspects its source and repairs worker and managed-user ACLs. A linked worktree also needs access to its private Git administration directory and the shared refs and objects. An inspection failure leaves the workspace unexposed and the claim fails. Project setup owns dependency copies; the [task check](#project-check) runs as the managed user and shares entries it creates with the worker before returning.

A visitable Laravel task workspace inherits the configured web root; a nested root such as `apps/site/public` uses the shared [application directory](/reference/projects#application-directory) for environment and runtime consumers. Preparation and inspection still verify Git identity, checkout ownership, task metadata, and recursive ACLs over the whole repository. Unrouted workspaces skip application classification; inspection does not discover a nested app.

The checkout directory stays owned by the Node's managed user and group. `ORBIT_TASKS_WORKER_USER` selects the worker account, normally `orbit-worker`. When it is unset or that account is absent, prepare leaves checkout access unchanged. Otherwise, prepare and inspect grant both users `rwX` access and default ACLs on the checkout, including `.git`.

Default ACLs are installed before worker write access, so a partial grant cannot expose a directory without inheritance. Files the worker creates inherit the managed user's access, so removal can delete them without changing the checkout owner. Inspection repairs ACLs on entries the managed user owns. Entries the worker owns keep the ACLs they inherited. The grant does not cover either user's home.

A worker command can leave a directory the managed user cannot enter, such as a check receipt that Python's `mkdtemp` created with mode `0700`. Prepare and inspect skip such a directory instead of failing. The worker owns everything below it, so no managed-user entry misses its grant. Linked worktrees share one Git common directory, so one such directory would otherwise stop every new workspace.

`.git/orbit` is mode `0775`. `.git/config` and `.git/hooks` are read-only for the worker, but the writable checkout root means that protection is not a trust boundary. Git creates `index.lock` in `.git`. Git 2.55 also refuses the tree because the owner is the managed user. Prepare adds the absolute path to `safe.directory` in `orbit-worker`'s global Git config. [Checkout access](/reference/instance-setup#checkout-access) states both. [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) explains the account and ACL choices.

| Project setting | New workspace |
| --- | --- |
| `task_workspace_routed: false` | Not visitable. The checkout has no Route and stays in the lifecycle state `source_resolved`. |
| `task_workspace_routed: true` | Visitable. The usual development provisioner gives it an inspect subdomain, and it becomes `active`. |

The setting defaults to true and does not depend on the Project slug. Provisioning records the selected mode on the workspace. Changing the Project setting affects future workspaces; it neither creates nor removes Routes on an existing workspace. Doctor uses the recorded mode when it checks that workspace. See [Task workspace routing](/reference/projects#task-workspace-routing).

[Doctor](/cli/doctor) treats `source_resolved` as the healthy state of a workspace that is not visitable, and `active` for a visitable one. An ordinary development Instance, including a monorepo `default` without a Route, settles at `active` instead. It runs the Project's create-time setup list and can supply dependencies to task workspaces without serving an endpoint. See [Provision the application endpoint](/domains/applications#provision-the-application-endpoint).

Orbit writes an untracked `.mcp.json` into the workspace before a reviewer starts, unless the workspace already has one, tracked or not. It points at `{gateway origin}/mcp/search`, which lists only `search_tools` and `execute_tools`. The file is excluded from Git. The [MCP server](/reference/mcp) describes both endpoints.

## Agent threads

An **agent thread** is one persistent conversation with a coding agent. It records the driver, the external conversation id, the Node, the task and subtask, the role, the model, and the effort. The external runtime keeps the transcript. Orbit keeps the link and the metrics.

Each subtask gets a fresh implementer thread. Each subtask's first review starts a fresh reviewer thread. Thread titles are `Orbit task #{task} / subtask #{subtask} · Implementer: {title}` and `Orbit task #{task} · Review: {title}`.

Orbit reserves the thread row before it starts the conversation, so the opening prompt can name the Orbit thread id. The row's external id starts with `pending:` until the conversation starts. A reserved row is not listed, measured, or broadcast. When the start fails, Orbit deletes the row. A tick deletes a leftover reserved row once its subtask or task is completed or cancelled. While the owner is active, a later start reuses it.

| State | Meaning |
| --- | --- |
| `idle` | Ready, with no active turn and no reported outcome |
| `working` | Running a turn |
| `asking_for_input` | Waiting for an answer to a question or an approval |
| `done` | The latest turn completed |
| `failed` | The latest turn failed. The error is stored |

`done` and `failed` stay until a new turn starts. A failed read keeps the last known state and marks it unavailable. It never makes a thread idle or failed.

### Drivers

A driver translates Orbit's thread operations for one agent runtime. Task agents, the implementer and the reviewer, run on the `pi` driver only. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select the two roles, and both default to `pi`. A new task stores those values. The Gateway registers `pi` and no other task-agent driver. Any other value returns `tasks.agent_driver_unavailable` and stores no task.

A managed task whose recorded driver is not `pi` does not start or resume an agent turn. A caller never supplies a runtime URL. An unsupported operation fails explicitly. [Task agents run on Pi](#task-agents-run-on-pi) explains why. Annotations are not task agents: they stay on the operator's T3 threads, and [Agent annotation](/reference/agent-annotation) owns that behavior.

| Role | Default model | Effort |
| --- | --- | --- |
| Implementer | `gpt-5.6-luna`, or `ORBIT_TASKS_IMPLEMENTER_MODEL` | `high`, or `ORBIT_TASKS_IMPLEMENTER_EFFORT` |
| Reviewer | `gpt-5.6-luna`, or `ORBIT_TASKS_REVIEWER_MODEL` | `high`, or `ORBIT_TASKS_REVIEWER_EFFORT` |

Set `ORBIT_TASKS_IMPLEMENTER_EFFORT` and `ORBIT_TASKS_REVIEWER_EFFORT` in the Gateway's `.env` to choose each role's reasoning effort. Unset or empty keeps `high`. For example, `ORBIT_TASKS_IMPLEMENTER_EFFORT=medium` sets new implementer threads to `medium`.

The Gateway reads effort when it creates a thread, not when it creates the group. A change applies to the next thread of every open group. An existing thread keeps its stored `effort`. The Gateway passes the value to the driver unchanged; the agent runtime validates it.

**Pi.** The `pi` driver runs threads on the [Pi server](/reference/pi-server) of the workspace's Node. The Gateway chooses the session id. Each send carries a key, and a retry reuses it, so an ambiguous failure never starts a second turn.

The driver maps a model name to Pi's `provider/model` form. With `ORBIT_PI_PROVIDER` set, every plain name uses that provider. Otherwise `gpt-` and `o`-series names use `openai-codex`, and `grok-` names use `xai`. The driver refuses a Claude model, including a name that starts with `claude` and a `provider/model` whose provider is `anthropic`, and the turn does not start on another runtime. Pi threads never ask for input, and they report no per-thread line counts.

### Archive finished threads

Task-agent threads are Pi sessions. Those sessions stay as files on the Node. Orbit keeps the thread row and its metrics after the work ends, and it does not archive the session. The Gateway has no `tasks:archive-threads` command, and the tick does not archive threads.

## Session routing

Each tick advances every `running` and `reviewing` subtask of a `running`, `reviewing`, or `settling` task. A subtask or task that asks for assistance is skipped until an operator resolves it. Only the publication of an already approved commit still retries.

The **acting thread** is the subtask's implementer while the subtask is `running`, and that subtask's reviewer while it is `reviewing`. During a [consult](#consult-the-reviewer), the reviewer is the acting thread of a `running` subtask. While the acting thread is `working`, the tick skips the subtask. The other thread does not defer it. So an operator can talk to a reviewer while the implementer hands off. The scheduler never sends a turn to a `working` thread. It waits until that thread stops.

An operator's [resolution](#assistance-and-resolution) is not a scheduler send. On a failure it goes to the blocked thread at once, whatever its state. On a direction request it goes to the reviewer at once. Sending it clears the assistance flag, so the tick is not skipped, and the reviewer is the acting thread until the relay receipt. That relay does not count toward the consult limit.

### Fetch before a turn

Before every agent turn, the Gateway fetches `origin` in the task workspace. The fetch runs before the message is sent. That message is the opening prompt, a reminder, a review, a resumed turn, or an operator message that starts a turn.

The command is `git fetch --no-tags`. It uses the repository [read token](/reference/github-app#how-orbit-reads-a-repository), not the token that publishes the pull request. The read token is `contents: read` for that one repository. It is passed through the environment of that one command, as for any other read. It never appears in the origin URL, the arguments, `.git/config`, or a file on the Node.

The fetch asks for the Project's default branch and for `task-{id}`. When the pull request base is not the default branch, the fetch asks for that base too. A missing `task-{id}` ref is not a failure of this fetch, with or without a pull request. That exemption belongs to the turn. [Resumed preparation](#fix-a-settling-pull-request) decides a missing task branch on its own. The fetch updates remote-tracking refs and does not move `HEAD`. It does not check out, merge, or rebase.

When the fetch fails, the turn still starts. Its message says the fetch failed and warns that `origin/*` may be stale. This note is not the blocking retry for [resumed preparation](#fix-a-settling-pull-request). The agent holds no GitHub token. Every turn prompt says that the agent must not fetch and must not push. Orbit fetches, and it [publishes](#pull-request-and-settle-metrics) the approved commit itself.

### Turn receipt

An agent ends each turn with the command `"$(git rev-parse --git-path orbit)/turn"`:

```bash
"$(git rev-parse --git-path orbit)/turn" --thread=ID --outcome=OUTCOME --summary="What was done, or what stops the work"
```

Before each turn, the Gateway installs that command, writes `$(git rev-parse --git-path orbit)/turn.json` with the role, the deliverables, and the acting thread's Orbit id, and removes any earlier turn receipt. `ID` is that Orbit thread id. Git never tracks `$(git rev-parse --git-path orbit)/`. The command and the [task check](#project-check) both need `python3` on the Node. `$(git rev-parse --git-path orbit)/turn.json` is the turn input. It is not the receipt.

Before each review turn, opening or continued, the Gateway also writes `$(git rev-parse --git-path orbit)/context.md` in that directory. The file holds the full task brief, the subtask brief, the deliverables, the earlier approval bodies, and the held resolution. It is the same file on every driver. The [review packet](#review-packet) names it in every cut note. The file replaces the `tasks-show` and `tasks-comment-list` references.

| Role | Outcomes |
| --- | --- |
| Implementer | `ready_for_review`, `blocked` |
| Reviewer | `approved`, `changes_requested`, `blocked`, `topology_requested` |
| Reviewer in a consult | `answered`, `blocked`, `topology_requested` |
| Reviewer in a relay | `answered`, `blocked`, `topology_requested` |

The command refuses an outcome of the other role, an empty summary, a repeated flag, and an unknown argument. `blocked` needs `--question="One specific question"`. An implementer's question goes to its reviewer first, and a reviewer's question goes to the operator. The command refuses `--question` with any outcome other than `blocked`.

`answered` is valid in a consult and in a relay. A relay is not a consult. A reviewer's `answered` and `blocked`, in a consult or a relay, need `--cause=CAUSE`, one of the [question causes](#questions). The review outcome that answers a direction resolution also needs `--cause`, and that value becomes the question's cause. `topology_requested` is a resource request, not an answer: it refuses `--cause` even in a consult, relay, or review following a direction resolution. The resumed reviewer supplies the cause when it answers normally. Every other turn refuses `--cause`.

A blocked relay creates no second question record. The same direction record stays `escalated`. Its `question` becomes the reviewer's `--question`, and its `cause` becomes that turn's `--cause`. That receipt sets `assistance_requested`, `assistance_kind` `direction`, and `assistance_question` on the subtask and the task. The subtask asks for direction again.

The approval of the subtask that opens the pull request also needs `--pr-summary`, at least one `--pr-change`, and at least one `--pr-breaking`, or `--pr-breaking=none`. `none` cannot be combined with another `--pr-breaking`. The command refuses the three pull request flags on every other turn. On success it writes the turn receipt to `$(git rev-parse --git-path orbit)/receipt.json` atomically. A second call overwrites that file. The command stays in place.

When the acting thread stops, the tick reads `$(git rev-parse --git-path orbit)/receipt.json` over SSH. It applies the receipt only when its `thread` is the acting thread. It stores the receipt as a comment with its content hash, then removes the receipt file. It does not remove `"$(git rev-parse --git-path orbit)/turn"`. A receipt read again after a crash has the same hash and is stored once. The scheduler then acts on the stored comment, so a failed send or commit is retried without the file.

### Task VM workspace

A group of a Project other than `orbit`, with `task_compute: vm`, runs in its own [task VM](/reference/compute-drivers#task-vms). The claim creates the VM and waits until it is `ready`. Until then, the task returns to `todo` with a [`Task VM:` reason](/reference/compute-drivers#from-claim-to-workspace), and the next tick tries again. Then Orbit creates the workspace on the VM's Node, as for a shared group: the Instance `task-{id}`, and its private Route when the workspace is routed.

Inside the VM, everything runs as the managed user `orbit`, which has passwordless sudo. There is no `orbit-worker`, so the workspace needs no ACLs and no `safe.directory` entry. Implementers and reviewers run on the VM's own [Pi server](/reference/pi-server#run-pi-on-a-task-vm). The baseline and handoff checks run over SSH as `orbit`. The Gateway fetches and pushes over SSH with the token on standard input, as for a shared group. No GitHub token enters the VM.

When the task ends, Orbit removes the workspace and then destroys the VM. See [Destroy a task VM](/reference/compute-drivers#destroy-a-task-vm).

### Request a topology

Orbit task workspaces have no Incus topology by default. Provisioning does not acquire one. A reviewer that needs discovery ends its review, consult, or relay turn through the existing receipt command:

```bash
"$(git rev-parse --git-path orbit)/turn" --thread=ID --outcome=topology_requested --summary="Why this group needs a topology"
```

Orbit VM groups start with an operator and a private test Gateway. Their initial source fetch uses the template's [public DNS upstreams](/reference/compute-drivers#prepare-source-inside-the-guest) while the cloned private network waits for retargeting. A failed fetch leaves source unresolved and prevents agent admission. After retargeting, pair preparation refreshes both Agents and the private Gateway’s Caddy and DNS projections through native convergence. Failed preparation remains retryable, and fresh doctor health still gates dispatch.

Their reviewer fallback adds `app-dev` and `app-prod` through the owned compute driver. It preserves declared workload nodes and waits for capacity, enrollment, and fresh doctor readiness before resuming the reviewer. See [Declared workload nodes](/reference/compute-drivers#declared-workload-nodes). Shared workspaces use the discovery topology below.

Before starting Pi, preparation configures the operator's [private Pi ingress and return route](/reference/compute-drivers#pi-proxy-on-an-incus-host) for the live Gateway. The policy returns at boot after parking and keeps the private topology's WireGuard routes. Failed network preparation prevents agent admission.

Only a reviewer turn may use `topology_requested`. The command refuses it from an implementer turn and tells the implementer to ask the reviewer through the [existing consult](#consult-the-reviewer). There is no separate agent CLI or API acquisition command. Agents run as `orbit-worker` without sudo; acquisition changes host firewall rules.

Orbit consumes this receipt, acquires the group's one `TASK-<group>` topology as the managed user, and resumes the requesting reviewer with the acquisition result or failure. An existing group topology is reused, so requests never allocate a second topology. The request does not approve or reject the subtask. Orbit resumes the same reviewer thread in its original review, consult, or relay context with the acquisition result or failure. Acquisition failure or absence of a topology never prevents approval: topologies are for discovery, not required proofs.

During a consult, the pending consult stays open while Orbit acquires the topology. The implementer remains paused. The resource request does not answer the consult, change its cause, or escalate it to the operator, even when acquisition fails. The resumed reviewer can then answer the implementer normally with `answered` or ask for direction with `blocked`. A relay or unresolved direction resolution likewise stays pending until the reviewer records its normal answer or review outcome.

`topology_requested` requires a summary but refuses `--question`, `--cause`, and pull request flags. It leaves question records and assistance flags as they were; it does not create or resolve a direction request. Orbit records the requesting turn before sending the reply. A lost send response or a crash after the send never changes that source turn: Orbit reconciles an accepted or later turn instead of sending the resource reply again. If the resumed turn stops without a usable receipt, the normal missing-receipt reminder applies. The original context's outcome and cause rules apply again after resumption.

The topology is shared by the group's subtasks and review turns. Orbit releases it when it removes the group's workspace, including any web session and loopback publication. [Incus topologies](/reference/incus-topologies#topologies-on-the-reviewers-request) owns the guest and command contract.

### Rubric and reminders

When the acting thread is `idle`, `done`, or `asking_for_input`, the tick checks named rubric items in code. No model is asked.

| Item | Passes when |
| --- | --- |
| `turn_receipt` | A turn receipt for this turn and role exists. A `blocked` receipt needs a question |
| `waiting_for_input` | The thread has no pending question or approval |
| `deliverables` | The receipt confirms the required deliverables, and every deliverable passes |
| `check_passed` | The [task check](#project-check) passed |
| `workspace_unchanged` | The reviewer left the workspace as it was. See [Review a subtask](#review-a-subtask) |
| `branch` | On approval, the workspace is on `task-{id}` |
| `pull_request_fields` | The approval that opens the pull request describes it |
| `brief_coverage` | The change list covers every subtask. See [Pull request](#pull-request-and-settle-metrics) |

When items fail, the Gateway sends one reminder to the acting thread. It names every failed item and ends with the role's turn-command instructions. The implementer's reminder starts with "Orbit could not confirm the brief is complete." The reviewer's starts with "Orbit could not confirm the review is complete." The Gateway installs the turn command again before it sends. Each attempt gets one reminder. When an item still fails at the next stop, the subtask asks for assistance, and the reason names each remaining item. The same pending input does not count as that next stop.

An implementer's `blocked` receipt starts a [consult](#consult-the-reviewer). A reviewer's `blocked` receipt asks for direction at once, with the summary and the question as the reason. A `failed` acting thread asks for assistance at once, unless it is a [server restart](#recover-a-pi-server-restart).

A failed read, send, commit, push, or script install is a communication failure. The tick retries it and moves on to other subtasks. The fifth consecutive failure asks for assistance with the last error.

When a driver cannot observe a thread, the tick waits `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` (default 120). Then it posts one `task_group.escalated` webhook for that outage. A successful observation resets the wait. A thread state from before the outage never advances a subtask.

Each observation also reports whether the workspace has commits since its starting commit. It reads the count from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh, and runs `git` over SSH otherwise.

### Consult the reviewer

When an implementer hands off `blocked`, Orbit sends the summary and the question to the subtask's reviewer. Orbit starts that reviewer when the subtask has none yet, and the review that follows uses the same thread. The subtask stays `running`, and nobody is asked for assistance. This turn is a consult. Its reviewer may [request a topology](#request-a-topology) before answering. The consult remains open and the implementer remains paused until the same reviewer resumes with the acquisition result or failure and answers normally.

The reviewer answers from the brief, the ADRs, the documentation, the code, and the task history. It hands off `answered` with the answer as its summary, and Orbit sends that answer to the implementer, which continues the same attempt. A question about scope, priorities, access, money, or a resource that only the operator controls cannot be answered from the contract. The reviewer then hands off `blocked` with one question for the operator, and the subtask asks for direction.

Orbit consults the reviewer at most twice in one implementer attempt. The limit counts the consult records whose `attempt` is the subtask's current `completion_attempt`. A third `blocked` in that attempt asks for direction at once. Its question is the implementer's question, and its reason includes both earlier answers.

Orbit records that consult, keyed to the blocked receipt, before it sends the turn. A fresh reviewer's conversation id is reserved before that opening turn, so a lost response or a failed id write reconnects to the same conversation. An accepted send is not repeated, and the reviewer's answer to that send is kept.

While the reviewer is answering, a failed thread, a server restart, a stopped turn with no receipt, and an observation outage follow the same rules as a review. A system failure asks for assistance with kind `failure`. Only a reviewer who cannot answer from the contract asks for direction.

When Orbit starts a fresh reviewer for a subtask whose earlier reviewer answered consults, that reviewer's opening packet includes those questions and answers. The review that follows a consult uses the same reviewer thread, so that thread already holds them and a continued turn does not repeat them. The packet keeps each answered consult on one line. A line that would pass 400 characters keeps a prefix of the question and a prefix of the answer, and the oldest lines drop once that section passes 2,000 characters. A cut field or an omitted line says that `tasks-question-list` returns each question and answer.

#### Questions

Orbit stores one question record in `task_questions` for each consult and each direction request. A consult the reviewer escalates is that same record moving from `open` to `escalated`, not a second row. A subtask should be specific enough that an implementer builds it in one go, so every question marks a brief, a contract, or a scope that left something open. The records let the operator count those questions and trace each one to its brief.

| Field | Meaning |
| --- | --- |
| `id`, `task_id`, `subtask_id`, `attempt` | The record, and where the question was asked |
| `asked_by` | `implementer`, `reviewer`, or `operator` |
| `question` | The one question, from `--question` or the comment body |
| `status` | `open` while the reviewer consults, `escalated` while the operator answers, then `answered`. A question nobody still has to answer ends `superseded` |
| `answered_by`, `answer` | `reviewer` or `operator`, and the answer |
| `cause` | Why the question arose. The reviewer sets it with `--cause`, and it is empty until then |
| `asked_at`, `escalated_at`, `answered_at` | When each step happened |

The reviewer gives one cause with `--cause` on each `answered` and `blocked` turn.

| Cause | Meaning |
| --- | --- |
| `brief_unclear` | The brief or its deliverables allow more than one reading |
| `contract_gap` | The ADRs and the documentation do not decide it |
| `scope` | The work needs something outside the subtask, or the subtask is too large |
| `environment` | A resource, an access grant, or infrastructure that the implementer cannot control |
| `missed_contract` | The brief or the contract already answers it |

A consult the reviewer escalates keeps `asked_by` `implementer`. Its `question` becomes the reviewer's `--question`, and its `cause` is that turn's `--cause`. `attempt` is the subtask's `completion_attempt` when the implementer asks, and its `review_attempt` when the reviewer asks during a review. An operator comment uses the attempt of the current phase: `review_attempt` while the subtask is `reviewing`, and `completion_attempt` otherwise.

A reviewer's `blocked` during a review creates an `escalated` record with `asked_by` `reviewer`. A third implementer block in one attempt creates an `escalated` record with `asked_by` `implementer`, the implementer's question, and no cause yet. Its assistance reason includes both earlier answers. An operator's `assistance_requested` comment creates an `escalated` record with `asked_by` `operator`, the comment body as its question, and no cause yet.

When the reviewer answers a consult, that record becomes `answered` with `answered_by` `reviewer`, the summary as the answer, and the `--cause`. A relay `answered` receipt sets the direction record to `answered` with `answered_by` `operator`, the resolution body as the answer, and `cause` from that turn's `--cause`. It does not count toward the consult limit and does not start a new implementer attempt. The cause stays empty until a reviewer hands off with `--cause`, so an open question, an escalated question, and a migrated record may have no cause yet.

Each record change is keyed to the stored comment that caused it: a turn receipt, an operator `assistance_requested` comment, or a `resolution` comment. Orbit writes that change in one transaction with `assistance_requested`, `assistance_kind`, and `assistance_question` on the subtask and the task. A tick that applies the same comment again creates no second record and does not count a second consult.

The consult limit counts consult records for the current `completion_attempt`. A consult record is the row created when an implementer's `blocked` receipt starts a consult. A relay, a third block, a reviewer's `blocked` during a review, and an operator comment are not consult records. A `topology_requested` receipt creates no question record and consumes no additional consult; the existing consult remains open until answered or escalated normally.

[`tasks:question:list`](/cli/tasks#orbit-tasksquestionlist) is `GET /api/v1/task-questions`. Any authorized peer can call it. The filters are `project_id`, `cause`, `status`, and `since`. `since` is an ISO 8601 date or time, and the list holds questions asked at or after it, newest first.

##### Close a question

A resolution answers a question only through the reviewer's next receipt. When the subtask stops asking first, the record can stay `open` or `escalated`. Orbit closes those records in two ways, so `tasks:question:list --status=escalated` shows only questions someone still has to answer.

When a subtask becomes `completed` or `cancelled`, Orbit marks its `open` and `escalated` questions `superseded` in the same write. The answer is `Subtask completed.` or `Subtask cancelled.`, `answered_at` is the time, and `answered_by` stays empty. When a task becomes `completed` or `cancelled`, Orbit does the same for every question of the task, with `Task completed.` or `Task cancelled.` This covers subtask cancel, task cancel, task complete, and every subtask that completes. A record that is already `answered` keeps its answer.

[`tasks:question:close`](/cli/tasks#orbit-tasksquestionclose) is `POST /api/v1/task-questions/{question}/close`. It needs Gateway access. The body holds `status`, `answered` or `superseded`, and `reason`, 1 to 2,000 characters after trimming. The question must be `open` or `escalated`. In one transaction, Orbit posts a `question_closed` comment on the question's subtask, sets the status, stores the reason as the answer with `answered_by` `operator`, links the comment as `answered_comment_id`, and logs a `question closed` activity. It returns the question. Closing an `answered` or `superseded` question returns HTTP 409 `tasks.question_closed`. The same status and reason again return the question unchanged. Another status or an empty reason returns 422.

Closing a question never delivers a resolution, never sets or clears assistance, and never counts as a consult. A `question_closed` comment is not a `resolution`, so the scheduler never sends it to an agent. `escalated_at` stays set, so `questions` and `escalations` still count the record.

### Assistance and resolution

A subtask that asks for assistance keeps its status and its Node slot. The flag, the kind, the question, and the reason show on the subtask and on the task. Orbit posts the Coder `task_group.assistance_requested` webhook once. When the kind is `direction`, it also posts that event to [OpsBot](#opsbot-direction-webhook) so OpsBot wakes immediately. An operator can also post an `assistance_requested` comment, which flags the subtask and the task at once as a direction request, with the comment body as its question.

#### Direction requests

Every assistance request has a kind, `direction` or `failure`. The task and the subtask store `assistance_kind` and `assistance_question` beside `assistance_requested` and `assistance_reason`.

| Kind | Cause | Question |
| --- | --- | --- |
| `direction` | A reviewer's `blocked` in a consult or a review, a third implementer block in one attempt, or an operator's `assistance_requested` comment | The one question for the operator |
| `failure` | Every other cause, such as a failed push, check, thread, or webhook | Null |

The task takes the kind and the question of the subtask that asks. While a task asks for direction, a later failure does not replace that request. `tasks:status` lists direction requests first in its table. An unsure `decide` subtask still asks for assistance as `failure`. [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions) keeps that rule until it says otherwise.

#### Migrate open requests

`2026_10_07_000000_create_task_questions` creates the empty `task_questions` table. `2026_10_07_000001_add_assistance_kind_to_tasks` runs after it, because Laravel applies migration files in timestamp order, and that second file writes the rows.

The migration classifies each open subtask row. It does not read the task row's reason, because that reason repeats the subtask. A reason that starts with `The implementer is blocked: ` or `The reviewer is blocked: ` becomes `direction` on that subtask. `assistance_question` is the stored question: the text after the last `Question: ` in that reason, or the text after the prefix when `Question: ` is absent. Every other open subtask becomes `failure` with a null question.

An open task row with no asking subtask becomes `failure` with a null question and no question record. A closed pull request, an orphaned commit, and a failed workspace removal are such task-only requests.

It writes one `escalated` question record for each `direction` subtask and none for a `failure` subtask or for the task row. The task row receives only that subtask's `assistance_kind` and `assistance_question`. When more than one subtask asks, a `direction` subtask supplies the task row, and a `failure` subtask does not replace it.

The record's `task_id` is the parent task id and its `subtask_id` is the asking subtask id. `asked_by` comes from the prefix. `attempt` is the subtask's `completion_attempt` for the implementer prefix and its `review_attempt` for the reviewer prefix. The rows do not store the original ask time, so `asked_at` and `escalated_at` are both the time `add_assistance_kind_to_tasks` runs, and `answered_at` is null. The cause is null. Closed requests get no records, so `questions` and `escalations` start with this change.

#### Resolve a request

A reason that starts with `Watched pull request ended: ` is the exception. Orbit stores the resolution comment and does not send it. It does not clear the flag, and it does not start a subtask. [Watch the branch while subtasks are open](#watch-the-branch-while-subtasks-are-open) defines that reason and the one notice Orbit sends.

A `resolution` comment with a non-empty body resumes a subtask that asks for assistance. On a direction request, the route depends on the subtask. None of these routes starts a new implementer attempt, so the consult records of the current `completion_attempt` still count.

When the subtask is `running` and its reviewer has started, Orbit sends the resolution as a relay and clears `assistance_requested` on the subtask and the task in that send. The question record stays `escalated`. The reviewer is then the acting thread, so the tick reads the relay receipt. An `answered` receipt marks the record `answered` and leaves the flag clear. A `blocked` receipt sets the flag, the kind, and the question again, as the [turn receipt](#turn-receipt) states.

When the subtask is `reviewing`, Orbit does not send an `answered` turn to the implementer. This covers a reviewer who asked during the review, and an operator `assistance_requested` comment posted during the review. Orbit delivers the resolution, clears the assistance flag in that send, and continues the review. The question stays `escalated` until the next review receipt.

A `topology_requested` receipt leaves that resolution and question pending, needs no cause, and resumes the same reviewer in this context after acquisition. The subsequent normal review receipt needs `--cause`. It marks the question `answered`, with `answered_by` `operator`, the resolution body as the answer, and that cause. The delivery counts as that reviewer's next review request. A `blocked` outcome also creates the new direction record a review block always creates.

When the subtask is `running` and no reviewer has started, Orbit starts the reviewer, as a consult does, and sends the resolution as a relay. The message is that relay, not an opening review packet, because the implementer has not handed off. The relay rules apply: `answered` and `blocked` require `--cause`, while `topology_requested` refuses it and leaves the resolution pending until the resumed reviewer answers normally.

Clearing the flag on send is keyed to the `resolution` comment and does not change the question record. The receipt that follows is keyed to its turn-receipt comment. A failed send keeps the flag set and leaves the record unchanged.

On a failure, Orbit sends the body to the blocked thread at once: the implementer while the subtask is `running`, and that subtask's reviewer while it is `reviewing`. In both cases Orbit then clears the flag on the subtask and the task, clears the communication failures, and starts a new attempt. When a failure resolution must reach a reviewer and none has started, Orbit clears the flag and holds the resolution. The next tick starts a fresh reviewer whose opening packet includes it.

A resolution posted while nothing is asked is stored and not sent. Every comment stays as history. An assistance request, a delivered resolution, a held resolution, and a failed delivery each also write an Activity entry with the comment's author as the actor.

### Recover a Pi server restart

A turn that failed only because its agent server restarted is not a failed subtask. The tick resumes it on the same thread with one message: `Your previous turn was interrupted by a server restart. Check git status and git diff, finish the subtask, and hand off with the turn command.` It does not ask for assistance and does not read a receipt first.

| Driver | Restart errors |
| --- | --- |
| `pi` | `The Pi server restarted during the turn.` |

One subtask gets at most two resumes, shared by its implementer and reviewer. A resolution does not reset that count. The third restart asks for assistance with `The implementer thread failed.` or `The reviewer thread failed.` Any other error, and a restart error without a turn id, asks for assistance at once.

The tick reserves each resume before it sends. The reservation stores a new send key, the acting thread, and the interrupted turn id, and it counts the resume. The Pi driver sends that key.

The tick repeats the same key only while the reservation is pending and the thread still shows the interrupted turn. A repeated key starts no second turn.

A Pi thread whose turn id is the key has accepted the reservation. A thread that shows another turn supersedes the reservation. A reservation made for one role is never sent to the other.

## Project check

Each Project stores one task check command in `task_check`. Orbit runs it on the fresh workspace before the first implementer starts, and after each `ready_for_review` receipt whose other items pass. Change it with `orbit project:update <project> --task-check=COMMAND`, or clear it with `--clear-task-check`. A new Project stores no task check until one is configured, for every type. Existing stored checks remain unchanged.

The Gateway installs `$(git rev-parse --git-path orbit)/check` and starts it over SSH as a detached process group. The check records HEAD and a hash of the whole working tree, uncommitted and untracked files included, without touching the Git index. It runs the command in a login shell at the workspace's repository root, even when the Laravel [application directory](/reference/projects#application-directory) is nested, writes the output to `$(git rev-parse --git-path orbit)/check.log`, and writes `$(git rev-parse --git-path orbit)/check.json` when the command ends. The subtask stays `running` while the check runs. There is no time limit.

The check process exports `VP_HOME` to the Node's [resolved Vite+ store](/reference/tools#tool-managers). Setup, the Project check, and command deliverables inherit that value, including when they invoke project-local `vp` without a login shell. An absent, conflicting, or unreadable store makes check start a communication failure, before the check launches. Orbit releases the unstarted baseline claim so the next tick can retry. Status, cancellation, and workspace snapshots do not need another Vite+ store probe.

When the workspace Instance has an [assigned SSR port](/reference/assigned-ssr-ports), the check process also exports `ORBIT_SSR_PORT` and `INERTIA_SSR_URL`. Setup, the Project check, and command deliverables inherit them, so browser and SSR gates in two workspaces on one Node reach their own SSR servers. The check still runs in the workspace on its Node.

Setup steps, baseline checks, handoff checks, and command deliverables inherit a host `TMPDIR` owned by the managed user: `/tmp/orbit-check-<uid>-<random>`. That directory is unique to the check and is removed when the check ends, including when an operator cancels it. Agent bash commands and documentation lookup processes use `<absolute-workspace-git-dir>/orbit/tmp/agent-<uid>` instead. The separate directories prevent restrictive tool caches created by either Unix user from blocking the other role.

The check directory has mode `0711` and no inherited sharing ACL, so another user can traverse to a child that grants it access while temporary files can retain private permissions. With a listable `/tmp`, a local user who learns the directory name can open a child created with the default umask; files a tool writes as private stay private. The agent directory has mode `0700` and no inherited sharing ACL.

Checkout inspection and the access grants before and after a check skip the resolved workspace temp subtrees, including those in linked-worktree common metadata. The parent retains the workspace's sharing ACL. Agent temp files stay outside the tracked tree and disappear with the workspace. The check `TMPDIR` lives under `/tmp`, outside the ACL-shared checkout, and is not reused as the agent directory. Orbit does not change host-wide caches or application PHPStan configuration.

Tests that switch Unix users need fixtures with traversable ancestors; granting access on a fixture cannot open a private `0700` `TMPDIR` parent. The check `TMPDIR` is already traversable.

Gateway test bootstrap gives each test process a fresh canonical `orbit-gateway-tests-<pid>-<random>` fixture root with mode `0755` and replaces `TMPDIR` only inside that test process. The root is under the inherited `TMPDIR`, or under `/tmp` when the process inherits a workspace role directory or that check directory. A process that a test starts reuses the root it inherits. Pi's cross-user test uses a fresh `/tmp/pi-shared-fixture-<random>` root instead of its inherited agent `TMPDIR`. Tests grant access on their own fixtures and clean them up. Tool caches outside those test processes still use the private role directories.

The check process runs as the Node's managed user, the account the Gateway connects as. A Project check can need that account's passwordless sudo, ACL tools, or access to the `caddy` account. The Gateway writes metadata only into administration directories owned by the managed user, without following symbolic links. It validates a linked worktree's `.git` pointer and its return pointer before opening that worktree's private administration directory. Status, cancel, and the workspace snapshot run as the same user. [The candidate gate runs as the managed user](/reference/pi-server#the-candidate-gate-runs-as-the-managed-user) explains the choice and its cost.

When `ORBIT_TASKS_WORKER_USER` names an account on the Node, normally `orbit-worker`, the check shares what it created with that worker before it writes `$(git rev-parse --git-path orbit)/check.json`. It grants the worker and the managed user `rwX` on every checkout entry the managed user owns, with default ACLs on directories first, as [workspace inspection](/reference/instance-setup#checkout-access) does. `.git/config` and `.git/hooks` keep their read-only worker access. The grant skips directories that the managed user cannot enter, such as private directories that the worker created. Their owner already has access.

A linked worktree's Git common directory is outside the checkout. There, the check grants the same entries only in `<git-common-dir>/orbit-checks`, where `bin/review-check` writes its reports. Setting the named entries recalculates the ACL mask. This repairs a report directory that a private mode left with `mask::---`, which disables every named entry. `bin/review-check` creates each `review-*` directory with mode `0750`, so its mask keeps inherited named entries readable while other users stay closed out.

Before setup and the command, the check does the reverse. It runs `find` and `setfacl` as the worker with `sudo -n -u orbit-worker -H`, because only an entry's owner can change its ACL. It grants the managed user `rwX` on every checkout entry the worker owns, and on the worker's entries in a linked worktree's `<git-common-dir>/orbit-checks`, with default ACLs on directories first. It skips `.git/config`, `.git/hooks`, and directories the worker cannot enter.

Registration reads the whole common directory for its source digest, so a worker report directory with `mask::---` would otherwise block it. A package manager that the agent ran can leave directories with mode `0755`, whose ACL mask hides the managed user's write. After this grant, the check can replace them.

When sudo cannot switch, the check fails with `check_error` and the reason `The managed user cannot run commands as orbit-worker.` When the grant fails, the check fails with `check_error` and the log shows the error.

The worker's next turn can then read and change the check log, `check.json`, and reports such as `.git/orbit-checks`, even when a command created them with a private mode. A cancelled check also shares before it exits. A check killed from outside shares nothing. When the grant fails, the check fails with `check_error`, and the log names the failed command. When the account does not exist, the check runs and changes no ACL.

Managed writes of the check, turn command, turn context, and MCP configuration use verified directory descriptors and exclusive, no-follow file descriptors. Replacing a candidate or metadata directory cannot redirect writes into another file. The Gateway reads receipts and updates Git's exclude file without following links, and refuses unsafe entries.

Gateway `git` commands in the workspace pass `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false`. The Project check, baseline setup commands, and deliverable commands, including their start-commit runs, run in that process. Orbit workspace commits and fast-forward merges run as the worker. The Gateway keeps token-bearing network reads under the managed account; a merge and any checkout filter it starts receive no credential environment. For commits and merges, an unset worker keeps the managed-user behavior during rollout; a configured worker never falls back to it.

| Check state | Result |
| --- | --- |
| `running` | The tick waits. The subtask's `check` shows the start time. |
| `passed` | Exit code 0, and HEAD and the tree did not change. Orbit [verifies the deliverables](#verify-deliverables), and the reviewer starts. |
| `failed` | The exit code is not 0, or an invalid deliverable or an error in the check script stopped it. The reminder holds the exit code and the end of the output. |
| `changed` | HEAD or the tree changed during the run. The check runs again once. A second change fails, and the reminder names the changed paths. |
| `lost` | The process was killed from outside and left no result. The check runs again once. A second loss asks for assistance. |
| `cancelled` | An operator cancelled it. The reminder says so. |

The scheduler identifies the process by its id and its start time, so a reused process id does not count. Without a task check, a handoff runs no command, but still compares the tree and verifies the deliverables. When a task check is configured, the implementer's instructions and the pull request description name that command. With no task check, both omit it.

`tasks:check:cancel` stops a running check. A subtask without one answers HTTP 409 `tasks.check_not_running`. When Orbit marks the check cancelled but cannot stop its process, the call answers HTTP 502 `tasks.check_unreachable`. Each run is stored with its receipt, kind, status, process, HEAD and trees, times, exit code, changed paths, and the last 16 KiB of output.

### Baseline check

Before the first implementer of a task starts, the check runs on the fresh workspace, with `kind` `baseline`.

A subtask runs at most one baseline check at a time. Moving a task to Todo and the scheduler tick can both reach the start. Only the first one starts the check. The other finds the running check and waits for its result. The verdict comes from that check, never from a duplicate.

The start records that claim before the process exists. The tick waits while the claim has no process. If the claim is still unstarted after the SSH command timeout of 900 seconds, the start was interrupted. Orbit asks for assistance and does not start another check, because one may still be running in the workspace. Cancelling during that start stops the process once the start returns.

The check runs only the Project's ordered [setup steps](/reference/instance-setup), with their configured timeouts, and then its configured task check. It runs setup even when no task check is configured. Without a task check, it runs no check command. The engine neither inspects manifests nor infers install commands from the check text. The Project must record any dependency installation it needs as setup steps. Handoff checks run no setup.

Setup commands run in a login shell from a private temporary script file, which the check removes when the step ends or times out. Large cache payloads do not enter shell arguments or depend on the operating system's argument-size limit.

The Orbit repository's own check seeds its caches from a registered main cache store, as [Feature delivery](/reference/implementation-loop#seed-a-checkout) describes.

A failed setup step or check asks for assistance at once, without a reminder. The reason names the step and the exit code, and the subtask's `check` shows the output. The engine keeps the command output as evidence and does not classify missing dependencies from its text. A cancelled baseline, a second `changed` run, a second `lost` run, and an interrupted start also ask for assistance. The interrupted-start reason says that the baseline start was interrupted and a check may still run in the workspace.

When a baseline check fails and no implementer has started in the task, fix the cause and post an operator `resolution` on that subtask to retry the baseline. Before retrying, the engine moves the untouched workspace to the current `origin/<default branch>` and records the new start commit. The baseline then runs again before the first implementer starts. There is no need to cancel and recreate the task.

Orbit records the retry request with the resolution before moving the workspace. If the reset reply is lost or the Gateway stops before recording the new start commit, a later tick finishes the reset and bookkeeping without another resolution. Assistance stays set until that preparation succeeds.

The reset refuses a workspace that holds manual work: another branch checked out, a commit that is not on the default branch, or a tracked change in the index or working tree. The checks and the reset run in one command. The retry then keeps assistance and records a communication failure.

When main was red, Orbit retries without a resolution. The failed baseline ran on a commit where the Project's `merge_check`, or `Required checks` without one, failed. Once the default branch tip strictly descends from that commit and the check passed on the tip, the tick posts a `resolution` by `orbit` that names both commits and queues the same retry. The check runs follow the [green-commit rules](/reference/github-app#find-the-newest-green-commit).

Orbit reads GitHub at most every five minutes per failed baseline, and never while a database transaction is open. It does not retry while the tip is red or pending, after an implementer started, or while assistance is a direction request. Every retry runs under the task execution lock, which stops it once the watched pull request merged or closed.

## Review a subtask

When the handoff check and the deliverables pass, the subtask moves to `reviewing`. Its first review starts a fresh reviewer thread with the task's reviewer driver and model and the current configured effort. The task's `reviewer_agent_thread_id` then points at it. A `changes_requested` re-review continues that thread. When the continued thread cannot take a turn, Orbit starts a fresh one with a full packet. The next subtask starts another fresh reviewer.

A failure while requesting a review is a communication failure. After five, the task asks for assistance with `The review could not be requested (ExceptionClass).` Orbit sends no review when it cannot read the diff.

### Review packet

The opening turn is a review packet of at most 16,000 characters, about 4,000 tokens. No part is exempt. A part under its cap leaves the spare characters for the diff body.

| Part | Cap | When it does not fit |
| --- | --- | --- |
| Retrieval block | 1,000, reserved first | Never cut |
| Held resolution | 2,000 | The end is cut. `$(git rev-parse --git-path orbit)/context.md` holds the full resolution |
| Task brief | 2,000 | The end is cut. `$(git rev-parse --git-path orbit)/context.md` holds the full brief |
| Subtask brief | 2,000 | The end is cut. `$(git rev-parse --git-path orbit)/context.md` holds the full brief |
| Deliverables | 2,000 | One line each, at most 240 characters, with the description cut to 160. `$(git rev-parse --git-path orbit)/context.md` holds every field |
| Earlier approvals | 1,500 | One line each, at most 200 characters. The oldest lines drop. `$(git rev-parse --git-path orbit)/context.md` holds each approval body |
| Answered consults | 2,000 | Opening packet only: one line each, at most 400 characters. The oldest lines drop. `$(git rev-parse --git-path orbit)/context.md` holds every question and answer |
| Diff stat | 1,500 | A summary line with every file, insertion, and deletion, then paths until the cap. The stat command prints the rest. The cut note names `$(git rev-parse --git-path orbit)/context.md` |
| Handoff result | 2,000 | One line per command the check ran, with the command cut to 160 characters. `$(git rev-parse --git-path orbit)/check.log` holds the rest. The cut note names `$(git rev-parse --git-path orbit)/context.md` |
| Diff body | The rest, and at most 16,384 bytes | Cut from the end. The diff command prints the rest. The cut note names `$(git rev-parse --git-path orbit)/context.md` |

Before each review turn, opening or continued, Orbit writes `$(git rev-parse --git-path orbit)/context.md` with the full task brief, subtask brief, deliverables, earlier approval bodies, held resolution, and answered consults. Every cut note names that file. The file replaces the `tasks-show` and `tasks-comment-list` references, and it works on every driver.

Dropped lines leave one line that says how many were omitted. The diff and the stat replace bytes that are not valid UTF-8. The packet does not name a feature contract. A continued turn keeps the review rules, the subtask brief, the new diff stat, the new handoff result, the diff body, the retrieval block, and the closing instructions. It leaves out the task brief, the deliverables, the earlier approvals, the held resolution, and the answered consults. `$(git rev-parse --git-path orbit)/context.md` still holds those parts.

The review packet includes untracked symlinks as link targets, without reading the files or directories they point to. Its diff reader uses a temporary copy of the Git index and leaves the workspace index unchanged. The copy preserves the index timestamp, so Git also finds edits of the same size when file timestamps match cached values.

The retrieval commands print the diff the caps cut, including untracked files, without updating the index. The packet puts the subtask's start commit in place of `START`:

```bash
git diff --stat START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index --stat -- /dev/null "$path" || true; done
```

```bash
git diff START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index -- /dev/null "$path" || true; done
```

When the workspace starting commit is 40 or 64 hexadecimal characters, the retrieval block adds `The task started at <sha>.` and `git diff --stat <sha>..HEAD` after those commands. A continued turn includes them too. The lines name no Project, branch, or policy.

The implementer prompt asks the implementer to finish the brief and pass the Project task check. When a check is configured, it adds that Orbit runs the check again at handoff with access the agent does not have, such as sudo. A failure that comes only from that missing access does not block the handoff.

The reviewer prompt says the turn is read-only. It says not to re-run the Project task check or deliverable commands the handoff already passed. When a task check is configured, it names that command, and it cuts a command past 160 characters. It says to run another command only for evidence the handoff result does not give, and to say why in the summary. It says to confirm framework and library usage against the documentation for the Project's versions. A continued turn repeats these rules. The shared prompt adds no Project policy.

`php artisan tasks:render-prompt {role}` renders the implementer prompt, the opening review packet (`reviewer`), or a continued review turn (`reviewer-continue`) from one JSON object on standard input. It prints `{"role", "prompt", "source_commit"}`, where `source_commit` is the Gateway's `APP_VERSION`, or `dev`. It uses the production prompt code and reads no database, workspace, or network, so an offline evaluation can render frozen cases. It rejects unknown input fields. `task.start_commit` is the workspace starting commit, or null when none was recorded. The prompts name it only when it is 40 or 64 hexadecimal characters.

### Reviewer outcomes

When the Gateway sends the review request, it records the workspace HEAD and the working-tree hash. When the reviewer's receipt arrives, it reads them again.

- **Unchanged.** The Gateway applies the outcome.
- **A newer implementer turn changed it.** That is a new handoff, for example after an operator talked to the implementer. The Gateway waits for a new receipt and a passing check.
- **Orbit's own commit changed it.** HEAD's parent is the recorded HEAD, and HEAD's tree is the recorded tree. The Gateway stores that commit on the approval and publishes it.
- **The reviewer changed it.** `workspace_unchanged` fails, and the outcome is not applied. The reminder asks the reviewer to revert and to request the changes.

After that reminder, the Gateway waits for a newer stopped reviewer turn. When that turn still leaves the workspace changed, the subtask asks for assistance. A failed workspace read is a communication failure, not a change.

`changes_requested` sends the findings to the implementer and returns the subtask to `running`, once the implementer has stopped. The implementer then needs a new receipt and a passing check.

`approved` waits until the implementer has stopped, because Orbit commits the whole workspace. The Gateway commits every change as `orbit <tasks@orbit>`, with the subtask title and the reviewer's summary as the message, and stores the commit on the approval. Then it [publishes](#pull-request-and-settle-metrics) that commit. When publication fails, the next attempt publishes the stored commit again only while HEAD is still that commit and the tree still matches. A reset back to the HEAD before the approval is refused.

## Pull request and settle metrics

Orbit publishes through the Project's [GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request) installation. Agents hold no GitHub token and never fetch or push. [What the App does not cover](/reference/github-app#what-the-app-does-not-cover) states how that is enforced. A task whose Project changes to `source_access: gh_cli` fails to publish and asks for assistance.

After each approval, the Gateway pushes the stored commit, never `HEAD`, with `git push --quiet origin <commit_sha>:refs/heads/task-{id}`. The push is never forced. Then the next subtask starts. In a [review-and-merge](#review-and-merge) task, an approval commits and does not push: only a final review's approval pushes. On the subtask that opens the pull request, the Gateway then opens it against the Project's default branch, or uses an open pull request with that head.

Publication then requests the GitHub logins in `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` as reviewers so the fleet reviewer wakes. It skips the pull request author, because GitHub rejects that request. Unset or empty logins request no one. A failed reviewer request is logged and does not block publication. It stores `pr_url` and moves a shared task to `settling`, or a VM task to `waiting_for_review`. Both statuses use the same pull request watch, fixup, and completion rules.

A failed push or open keeps the subtask in `reviewing` and keeps its commit. It retries after 1 minute, then 2, 5, 10, and 30 minutes, and then every 30 minutes. The fifth failure asks for assistance with a reason that starts with `Approved commit publication failed: `. The reason names Git's error. When GitHub refuses a push that changes `.github/workflows/`, it names the missing `Workflows` permission. A later success clears only that reason.

The task title is the pull request title. The description holds the summary, a Changes list, a Breaking changes list or `None.`, and one line that says each delivered subtask passed the task check and reviewer approval. Cancelled and failed subtasks are not counted.

Before Orbit commits the approval that opens the pull request, Orbit checks the change list against each subtask that is not cancelled or failed. First, a change covers a subtask when the change starts with the subtask's exact title, after both are trimmed.

Case and punctuation count, so `Publish preparatory PR (not CLEAN) with report: ...` covers the subtask `Publish preparatory PR (not CLEAN) with report`. The title must end the change, or be followed by a character that is not a letter or a digit, so `Route` does not cover a change that starts with `Routes`. Then Jev checks the subtasks that no change covers this way. When every subtask is covered by its title, Orbit does not call Jev.

Jev is Orbit's TypeSafe classifier, called through Laravel AI with `TYPESAFE_API_KEY`. Without that key, the call fails with `TypeSafe Jev is not configured. Set TYPESAFE_API_KEY.` For each remaining subtask, it answers whether a listed change delivers that subtask, and it counts a probability of at least one half as "yes". Its input lists only the remaining subtasks. A subtask without a "yes" fails `brief_coverage`, and the reviewer's reminder names it. Jev reads briefs and the change list, not code, so it checks coverage, not correctness. A failed Jev call is a communication failure.

### Watch the branch while subtasks are open

While a task has a subtask in `todo`, `running`, or `reviewing`, Orbit looks for a pull request whose head is `task-{id}`, in any state. The look runs at most once a minute, even though `tasks:tick` runs every 10 seconds. It uses the [list-by-head read](/reference/github-app#how-orbit-watches-a-task-pull-request). The tick does this look before it starts a `todo` subtask and before it advances a `running` or `reviewing` subtask.

The list can contain more than one pull request. Orbit watches the first open pull request in GitHub's default order. When the list has no open pull request, Orbit watches the first pull request on the page. It stores the URL, number, and state in `watched_pr_url`, `watched_pr_number`, and `watched_pr_state`. These fields appear on the task in the API and `tasks:show --json`. It does not write `pr_url`. An empty list or an unreadable list leaves `watched_pr_url` and the assistance flag as they are, and the task keeps starting subtasks.

`pr_url` remains the pull request Orbit opens on the last subtask. That approval still requires the pull request description, and Jev still checks `brief_coverage`. Cancel treats a `settling` or `waiting_for_review` task with `pr_url` as published. `watched_pr_url` does not change those rules.

When the watched pull request is `merged` or `closed` and a subtask is still open, Orbit starts no new subtask and asks for assistance. The task keeps its status, and this tick does not complete it.

The reason is `Watched pull request ended: {url} is {state}. Open subtasks: {list}.` `{url}` is the watched pull request URL. `{state}` is `merged` or `closed`. Merged means `merged_at` is set. Closed means GitHub state `closed` and no `merged_at`.

`{list}` names each subtask in `todo`, `running`, or `reviewing`, in position order, as `#{id} {title}`, separated by commas. The flag and the reason show on the task and on the subtask that is `running` or `reviewing`. This reason replaces an assistance reason that was already set.

While the reason starts with `Watched pull request ended: `, Orbit does not start a subtask, a reviewer, or a push.

Orbit does not interrupt a running agent. It does not stop the turn, and it does not call the driver interrupt. Each running implementer or reviewer gets one notice. The notice is that reason.

Orbit stores one pending notice on that subtask before it sends. The row holds `ended_pr_notice_thread_id`, one new `ended_pr_notice_key`, and `ended_pr_notice_state` `pending`. The key is created once for that thread. A thread that already has a `pending` or `delivered` notice does not get a second key. The notice does not clear the assistance flag and is not a resolution comment.

Orbit sends it the way it delivers a [resolution](#assistance-and-resolution), and only after that thread's turn has stopped. While the turn is running, the tick leaves the pending notice in place and does not send. A failed send, a lost response, or a crash after the pending row is committed leaves the state `pending`. The next tick sends the same key.

A repeated key does not deliver a second notice. Pi returns `duplicate: true` when it already accepted the key. On T3 the key is the command id and the message id, and T3 returns the existing receipt. When the send is accepted, Orbit sets `ended_pr_notice_state` to `delivered`. A delivered notice is not sent again. A sent notice and a failed send each write an Activity entry. A task with no such thread stores no pending notice.

The Gateway tests inject these notice failures:

- Commit the pending notice, then stop before the driver send. The next tick sends the stored key once.
- The driver send throws. The notice stays `pending`, assistance stays set, and the next tick sends the same key.
- The driver accepts the key and the process stops before the notice is marked `delivered`. The retry sends the same key and does not deliver a second notice.
- The turn is still running. Orbit does not send and does not interrupt.

After this reason is set, later list results do not replace `watched_pr_url` and do not clear the assistance. A resolution comment is stored and is not sent. The operator runs `tasks:complete` or cancels the task and starts a new one. There is no `tasks:continue` command.

The [Incus proof](https://github.com/nckrtl/orbit/blob/main/apps/e2e/resources/proofs/ended-pull-request.sh) runs a task whose pull request merges during a later subtask. It exercises the scheduler, assistance, one notice on a real Pi thread, and workspace removal through `tasks:complete` on a disposable lease. It substitutes GitHub state in-process and uses a local deterministic model to hold the real Pi turn open. It does not exercise a live GitHub merge; Gateway feature tests cover the HTTP reads.

### Settling

For Orbit's own task pull requests, a Tasks engine subtask approval publishes that subtask's commit. It is not the final review of the whole pull request, and it does not merge. The [final DevOps review](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request) submits a formal GitHub approval for the exact head commit.

When the maintainer has delegated review and merge, the reviewer verifies that approval and that `Required checks` succeeded on that head, then merges that commit through the maintainer's GitHub CLI profile. A plain comment alone does not satisfy the gate. This repository workflow runs outside the generic Tasks engine. Outside a [review-and-merge](#review-and-merge) Project, the Gateway does not merge the pull request. It watches pull request state, conflicts, CI, and configured GitHub review feedback. Reading an approval is an observation, not permission to merge. The maintainer's merge identity still has admin bypass; Orbit adds no runtime merge gate.

Each tick reads the pull request of every `settling` task through the GitHub App, using `pr_url`. `watched_pr_url` does not replace that read. When a subtask is `todo`, `running`, or `reviewing`, a merged or closed result follows the [branch watch](#watch-the-branch-while-subtasks-are-open) instead of the table.

| Pull request | Result |
| --- | --- |
| Merged | Orbit completes the task and removes its workspace. A failed removal leaves the task `settling` with a reason that starts with `Merged pull request cleanup failed: `, and the sweep retries it. |
| Closed without merging | The task asks for assistance with `The expected pull request closed without merging.` |
| Open with a conflict, a failed check, or eligible trusted requested changes | See [Fix a settling pull request](#fix-a-settling-pull-request). |
| Open and healthy | Orbit clears only its own recovered pull request reason. A review-read failure is not a healthy review result. |
| Unreadable | Nothing changes. |

A merged task that asks for assistance with `An approved commit is not on the pull request: ` is not completed.

A `settling` task without `pr_url` and without a `todo` subtask asks for assistance with `The settling task has no reviewed pull request URL. Cancel the task to push its approved commits to task-{id} and remove its workspace.` This happens when a subtask cancel ends the last open subtask before any pull request exists.

#### Trusted GitHub feedback

The Gateway operator sets `ORBIT_TASKS_GITHUB_REVIEWERS` in the Gateway's `.env`. Each entry maps a `github.com` repository name, `owner/repository`, to a comma-separated list of positive GitHub numeric account IDs. Semicolons separate entries. For example, `ORBIT_TASKS_GITHUB_REVIEWERS=acme/widgets:123456,789012;acme/api:123456` trusts those accounts for those repositories only. The Gateway lower-cases repository names. Unset or empty creates no work from GitHub feedback until the operator opts in. The value is Gateway configuration, `orbit.tasks.github_reviewers`, not a Project input, Instance environment variable, task definition, CLI option, or repository file read from the task branch. Rebuild the Gateway's configuration cache and reload the Gateway and scheduler after a change.

Review requests, logins, repository roles, `author_association`, and App ownership confer no trust.

Use the numeric `user.id` from GitHub's account API, not a login or an App ID. A renamed account keeps its trust; its login is display information only. A malformed repository key or ID list disables feedback for that repository and asks for configuration assistance. There is no wildcard or login fallback. Removing an account stops new consumption. It does not undo a fixup already created. This allowlist authorizes bounded repair work, not final-review or merge authority. The designated final reviewer remains part of the [repository's final-review workflow](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request).

For an open pull request, Orbit reads the complete bounded review list. It groups records by trusted account ID and selects that account's latest submitted **decisive** record across the whole pull request, ordered by `submitted_at`, then numeric review ID. API arrival order is irrelevant.

`APPROVED`, `CHANGES_REQUESTED`, and `DISMISSED` are decisive states. `COMMENTED` and `PENDING` never replace a decisive record. A selected `DISMISSED` record is neutral: an older approval or request does not become effective again. A selected record on another head is stale; Orbit does not fall back to an older record for the current head. An unknown state or malformed selection field makes the review result unreadable, not an approval or an empty list. A `PENDING` record may have null `submitted_at` and `commit_id`; those fields are required only for submitted decisive records.

| Effective review | Observation and action |
| --- | --- |
| Trusted `CHANGES_REQUESTED`, `commit_id` equals the current PR head | Candidate for one bounded findings fixup. |
| Trusted `APPROVED`, `commit_id` equals the current PR head | A durable [approval observation](#inspect-approval-observations), not a fixup, internal approval, completion, or merge. |
| `COMMENTED` | Informational, even with blocking-sounding prose. No automatic work. Ask the reviewer to submit requested changes, or append an operator subtask. |
| `DISMISSED`, `PENDING`, stale head, or untrusted account | No automatic work. Dismissal does not revive an older decision. |

An approval from one account does not cancel another account's effective requested changes. Among eligible requests from different trusted accounts, Orbit considers the oldest effective request first, using `submitted_at` and review ID. Selection runs again on each fresh read, so a later decisive review supersedes an older request even if GitHub returns it out of order. Ordinary issue comments and thread replies are not review decisions.

Review consumption extends the existing CI/conflict watcher; it does not replace the formal-approval workflow. [Trusted reviews are input, not merge authority](#trusted-reviews-are-input-not-merge-authority) explains the authority boundary and the alternatives.

#### Inspect approval observations

Approval observations are durable Gateway records, separate from internal `approved` receipts and the consumption ledger. A complete uncached review scan records every trusted submitted `APPROVED` review it contains, including approvals for past heads. The unique key is task group, repository, PR number, and review ID. Repeated reads update the same record. Its immutable source snapshot contains reviewer account ID, display login, review ID and URL, `commit_id`, `submitted_at`, and first-observed time. Keep that source even after a rename, dismissal, supersession, trust removal, or head change; do not invent approvals that were never observed.

Each record also stores the latest observed login and review state and latest selected decisive review ID for that account. It stores the observed PR head/state, trust membership, last-successful-check time, and status/reason. `current` means the review remains the account's effective trusted `APPROVED` decision on the open PR's observed exact head. `historical` means known stale head, dismissal, supersession, removed trust, a closed/merged PR, or absence from a complete scan. Reasons distinguish those conditions; a newer `COMMENTED` review does not supersede an approval. Past observations remain inspectable and never fall back to current because another review was dismissed.

Persist scan state on the group: repository/PR, last attempt, last successful scan, observed head/state, trust revision, and `read_status` (`complete`, `unreadable`, or `disabled`). Apply each complete scan and its record changes atomically. A persisted scan sequence prevents an older response from overwriting a newer observation. Incomplete or failed reads never confirm current approval or erase source evidence. Known head/trust changes can mark a record `historical` without a complete review list; otherwise failed reads or a successful scan older than 60 seconds make its reported status `unverified`, preserving its last confirmed status and reason.

On the Gateway, run `php artisan orbit:tasks:github-reviews <group-id> --json` to inspect the stored result. It reads local state only, never GitHub, and changes no task. JSON includes group/repository/PR identity, read status, observed head/state, scan times and age, and records ordered by reviewer ID then review ID. Each record contains the source and latest fields above, reported status, last confirmed status, and reasons. No records is an explicit empty list, not approval. An unknown group fails; a disabled or unreadable scan and unverified records are visible, not a successful approval verdict.

The command reports evidence **as of the stored scan**, not GitHub's live truth. It returns no merge-ready flag, aggregate approval verdict, or designated-final-reviewer assertion. It cannot approve a subtask, complete a group, clear findings, or authorize a merge. Operators still perform fresh identity, exact-head, required-check, and delegation checks in the external final-review workflow. The report is not that workflow's gate.

### Retry an empty workspace reservation

When an interrupted claim leaves only a `reserved` Instance on a full Node, Orbit checks that its checkout path is absent before releasing the database reservation and selecting another Node. It never deletes workspace files. A prepared checkout, source identity, Route, or attached task keeps its placement. If Orbit cannot prove the old path is empty, the group reports the reservation and reason instead of waiting silently on that Node.

### Fix a settling pull request

A [review-and-merge](#every-push-is-reviewed) task skips the next step: a conflict gets a merge fixup at once, and a branch that is only behind still merges.

When an open pull request falls behind its base or appears to conflict, the Gateway first asks GitHub to update the branch with a merge commit. It sends the observed head SHA to `PUT /repos/{owner}/{repo}/pulls/{number}/update-branch`; it never rebases or force-pushes. An accepted update waits for CI on the new head and appends no subtask. Only a confirmed merge-conflict response (HTTP 422) appends `Merge origin/{base}`. A stale head, denied permission, or unavailable API waits for a fresh observation and reports the reason. Failed CI on the updated head can append a check fixup.

A pull request **conflicts** when GitHub reports it as not mergeable, or its mergeable state is `dirty`. A null result is not a conflict. The Gateway reads the check runs of the head commit at most once a minute, with a token that holds only `checks: read`. It reads one page of at most 100 check runs, so a failed check beyond that page is not reported. Without that permission, it sees conflicts only.

| Check conclusion | Kind |
| --- | --- |
| `failure`, `timed_out`, `action_required` | Genuine failure. It can get a fixup. |
| `cancelled`, `startup_failure` | Infrastructure. It never gets a fixup. |
| Not completed for more than 60 minutes | Infrastructure. The reason adds `Check {name} is still pending: {url}.` |
| Not completed for 60 minutes or less | Pending. No check fixup starts yet, but a completed genuine failure is reported. |

A run's age starts at its `started_at`, or at the first tick that saw it pending. The rollup check `Required checks` is ignored while another failed check explains the failure. When only infrastructure problems remain, the task waits and looks again after 1, 2, 5, 10, and 30 minutes. Then it asks for assistance and adds `Those checks were cancelled or could not start, and did not recover. Re-run them.`

Each problem has an identity: `conflict:` plus the base branch, `check:` plus the check name, or `review:` plus the trusted reviewer's numeric account ID. A review ID is the consumption key, not the cap identity: submitting another review cannot evade the per-reviewer cap. One tick appends at most one fixup, for the first eligible problem that still has one left. A conflict comes first. Failed checks follow in GitHub's order. Eligible review requests follow in the order described above.

Project slugs and CI job names do not change that order. A task gets at most two fixups for one identity and at most three in total. These caps count every fixup appended after the last completed operator subtask. An operator subtask is one with no `fixup_problem`. So each new window needs a human step.

| Fixup | Title | Brief |
| --- | --- | --- |
| Conflict | `Merge origin/{base}` | `Merge origin/{base} into the task branch and resolve the conflicts. Do not rebase and do not force-push.` |
| Failed check | `Fix {name}` | `Check {name} failed: {url}. Do not rebase and do not force-push.` |
| Trusted requested changes | `Address GitHub review {review_id}` | The immutable findings packet below, with the source head, reviewer identity, review URL, and bounded scope. No rebase or force-push. |

The base may already fix a failed check. Before Orbit appends a check fixup, it reads the tip of the pull request base. When that tip is strictly ahead of its merge base with the head, and the Project's `merge_check` passed on it, the brief starts with `Merge origin/{base} first; base may already fix this.` Without a `merge_check`, Orbit reads `Required checks`. The check runs follow the [green-commit rules](/reference/github-app#find-the-newest-green-commit). The title, identity, and caps do not change. A failed read leaves the brief as shown above.

Conflict and check fixups use the Project's task check as configured when Orbit creates the fixup. When it exists, the fixup has one `command` deliverable, `project-check`, which runs that exact command in `.`. Without a configured check, the fixup has one `review` deliverable, `fixup-review`, that asks the reviewer to confirm the conflict or failed check is resolved from the available evidence. The Gateway adds no CI reproduction command. Changing the Project check later does not rewrite an existing fixup's deliverables; subsequent handoffs use the current Project check as usual.

A fixup records the head it was created for. No new fixup starts while the head is still that commit. These guards and the shared caps apply to feedback, CI, and conflict fixups together. Completing an operator subtask resets the cap window, but never resets review consumption.

#### Retrieve the findings

Orbit retrieves findings only for the next eligible, unconsumed request that has room under the caps. The GitHub App reads `GET /repos/{owner}/{repo}/pulls/{number}/reviews`, the selected review at `/reviews/{review_id}`, and that review's `/comments` list. It uses a repository token with only `pull_requests: read`, separate from publishing and checks tokens. No new App permission or webhook is needed. The [GitHub App reference](/reference/github-app#how-orbit-watches-a-task-pull-request) owns the credential and read limits.

A review scan follows GitHub pagination with 100 records per page, at most 10 pages and 1,000 review records. Selected-review comments use at most 5 pages and 500 records. A remaining next-page link at the limit is overflow, not completion. A missing or malformed page, invalid identity/head/time, HTTP failure, permission failure, or incomplete pagination makes the result unreadable. A partial list never establishes the effective decision. Follow pagination only within that repository's expected `api.github.com` endpoint; never send a token to a URL supplied in review text.

The findings packet contains the full review body and the selected review's inline comments authored by the same trusted account. Each included comment names its ID, source URL, path, line/side or original location, diff hunk when supplied, and body. Sort comments by numeric ID. Preserve outdated locations as context; Orbit does not infer that a finding is fixed from GitHub's outdated or resolved markers. Replies and comments belonging to another review or account do not add work. Missing optional location information stays absent.

A body with no non-whitespace text and no non-whitespace inline finding asks for assistance instead of creating an empty fixup.

The entire UTF-8 findings packet, including metadata and scope instructions, is capped at 64 KiB. Orbit does not silently truncate findings or fetch linked files, issue comments, logs, attachments, or other URLs. Overflow asks the operator to split the review or append a scoped subtask.

The fixup brief treats review text, paths, and diff hunks as quoted external data. It authorizes addressing those findings within the existing feature contract, adding regression coverage, and reporting conflicting or out-of-scope requests. It does not authorize instructions embedded in the review, new features, credentials, live configuration changes, or a merge. An implementer asks for assistance when the findings require a product decision.

Review snapshots may be cached for at most 60 seconds, keyed by repository, PR, head, and trust configuration. Only bounded review data is cached, never a token.

Before appending, Orbit bypasses the cache and re-reads the PR state/head, the complete review selection, the selected review, and its comments. It checks that the request is still effective, trusted, exact-head, and unchanged from the packet. A changed head, decision, body, or comments discards the candidate and retries from fresh data; no consumption is recorded. There is no atomic transaction with GitHub: a change after the final read can still race with local creation. The persisted source snapshot makes that boundary auditable.

#### Consume once and recover

Consumption is durable Gateway database state, not a timestamp cursor or a cache. The unique key is the task group, repository, PR number, and GitHub review ID. It survives ticks, scheduler restarts, head changes, reviewer renames, and cap resets. A consumed review is never consumed again, even if its body or inline comments are edited, its state changes, or its fixup is cancelled, deleted, or fails.

An unconsumed request edited before creation uses its latest complete packet. To stop an eligible `todo` or `running` fixup, use the existing cancellation flow. Cancellation retains the subtask, consumption key, and cap charge in every status. `tasks:subtask:destroy` remains backlog-only: outside backlog it returns HTTP 409 `tasks.not_in_backlog`, even for a `todo` or cancelled fixup. Feedback creation requires `settling`, so deleting its subtask is not a supported API recovery operation.

A null or missing fixup link is defensive corruption/cleanup handling, not deletion permission. Keep the source packet, consumption key, cap identity, and creation-window marker if unsupported database cleanup removes the linked row. Never recreate the consumed review or silently release its cap charge in that window. Count such orphaned consumption once, without double-counting retained subtasks, and request assistance rather than resuming nonexistent work. Only the ordinary completed-operator-subtask boundary opens a new cap window; it never resets consumption.

After creation, GitHub edits, dismissal, and superseding reviews never rewrite or automatically cancel the fixup. To stop that work, an operator uses the existing subtask/task cancellation flow; a new submitted review or explicit operator subtask supplies new scope.

The immutable packet is the ledger's source snapshot. Authorized operator edits to a `todo` subtask still follow the existing edit rules; they are explicit human rescoping, not automatic GitHub feedback. They do not rewrite the ledger, reset consumption, or reset the cap window. Replacing generated deliverables is an operator override, not proof that the original findings were resolved. Started fixups keep the normal locked fields. Prefer cancelling and appending a scoped operator subtask when the work needs a different contract.

Orbit creates the consumption record and its single `todo` fixup in one database transaction under the existing managed-group and subtask locks. It rechecks that the group is still `settling`, has no busy or waiting subtask, has no unrelated assistance, and has room under both caps.

The record stores the source repository/PR, review ID, reviewer ID and login, submitted time, exact head, review URL, immutable packet and its digest, linked fixup ID, cap identity, and completed operator subtask ID that identifies its creation window. A uniqueness conflict returns the already-created work when it still exists; it never creates another subtask. No GitHub call or agent start holds these database locks.

A crash before commit leaves neither consumption nor work. A crash after commit leaves both; the next tick resumes the existing fixup through the ordinary waiting/stranded-subtask path. A failed workspace preparation, agent start, check, review, or push retries that same fixup rather than creating another one. Once-only means at most one automatic fixup per review, not guaranteed resolution or delivery. No ledger entry is committed for stale, superseded, untrusted, incomplete, empty, over-cap, or unreadable candidates.

Review-read failures retry after 1, 2, 5, 10, and 30 minutes and then request review-read assistance. A successful complete read resets the failure count and clears only that cause. Deterministic overflow, empty findings, and invalid trust configuration request assistance immediately. Reasons name the repository/PR and review ID when known, never credentials or raw remote errors. These reasons are separate from CI/conflict assistance and unrelated operator assistance, so a healthy CI read cannot clear a review-read problem. Review-read failure leaves existing consumption and work unchanged and does not disable ordinary CI/conflict repair.

#### Review fixup lifecycle

Feedback fixups wait while CI has young pending checks. They also wait while infrastructure checks use their existing recovery backoff or request assistance; a requested review does not justify a speculative source repair of broken CI infrastructure. A conflict can still proceed immediately. Genuine failed checks retain priority over feedback. Capped identities can be skipped for another eligible problem, but every automatic fixup shares the two-per-identity and three-per-window limits. Cap assistance names any unhandled effective request and its review URL without consuming it.

A feedback fixup always has a `review` deliverable, `review-findings`, requiring the fresh internal reviewer to confirm that every snapshotted finding was addressed or explicitly resolved within the contract. When a Project task check is configured, it also has the `project-check` command deliverable with that command snapshotted in `.`. Without one, only `review-findings` remains. Internal approval does not stand in for GitHub re-review. The reviewer reads the complete packet in `.git/orbit/context.md` when the compact review prompt cuts the brief.

In Gateway API and MCP results, the existing `fixup_problem` carries `review:{reviewer_id}`. The generated `brief` carries source provenance and findings through API, SDK, CLI, and MCP results. The SDK and CLI keep their existing brief/deliverable fields; they need no new fixup-identity property. There is no new Gateway API field, input, endpoint, or merge command. Response fixtures and generated contracts must still verify the Gateway identity, preserved packet, and unchanged schema.

The fresh implementer, Project check, fresh internal reviewer, commit, and push run through the existing lifecycle on the same branch and pull request. After the push, the group returns to `settling`. The old request is consumed and now stale; Orbit does not treat it as an approval and does not post a GitHub review, comment, dismissal, or re-review request. The external reviewer reads the new head and submits a new formal decision.

Only a fresh exact-head request can create another automatic fixup, subject to the same caps. Outside a review-and-merge Project, only the designated final reviewer's fresh exact-head approval can satisfy Orbit's repository merge workflow, which runs outside the Gateway. In a review-and-merge Project, an effective trusted request for changes blocks [the merge](#merge-on-green) until that account approves or the review is dismissed.

When the last fixup changed nothing, the task asks for assistance and adds `Fixup subtask #{id} changed nothing, so Orbit does not try again on the same result.` When no problem can get a fixup, the task asks for assistance with a reason that starts with `The pull request needs attention: ` and has one sentence per problem. The reason names the cap that applied: `Orbit reached the cap of 2 fixups for {identity} in the current window ({n} counted).`, or `Orbit already appended 3 fixups to this task.` Coder is notified only when that reason changes.

A `todo` subtask on a `settling` task, a fixup or an operator's subtask, returns the task to `running`. This works when the pull request is open, and when the task has no `pr_url`. Another assistance cause keeps the task `settling`.

Before a conflict fixup starts an implementer, the engine reads the pull request's mergeability again. For example, a fixup whose `fixup_problem` is `conflict:main` may be stale because the maintainer merged main into the task branch. When the current mergeability shows no conflict, the engine cancels the fixup with a recorded reason, starts no implementer for it, and returns the task to `settling`.

A merged or closed pull request also cancels an unstarted conflict fixup and returns the task to settling, where merge cleanup or closed-pull-request assistance applies. An approved commit that missed the merge still asks for assistance and keeps its workspace. An unavailable result or unknown mergeability waits without cancelling or starting an implementer.

Before that subtask starts, the Gateway prepares the workspace. It reuses the [fetch before a turn](#fetch-before-a-turn) instead of fetching again. That fetch already updates `origin/task-{id}` and, when the pull request base is not the default branch, `origin/{base}`. The Gateway then fast-forwards the workspace to `origin/task-{id}` when the workspace is strictly behind that ref. It never forces. A workspace that is level, ahead, or diverged stays unchanged.

When the task has no pull request and `task-{id}` is not on `origin`, there is nothing to fast-forward, and that absence is not a failure of this preparation. A failed fetch or fast-forward keeps the subtask `todo`, retries on the same backoff, and asks for assistance on the fifth failure. That blocking retry is only for this preparation. An ordinary agent turn still starts when its own fetch fails, and its message warns that `origin/*` may be stale.

The fixup runs like any subtask, with a fresh implementer and a fresh reviewer. Its approval needs no pull request fields, and its push updates the open pull request. Orbit does not rebase, does not force-push, does not open a second pull request, and does not merge. In a review-and-merge task, a final review follows the fixup and its approval pushes.

Before each push to a stored pull request, the Gateway reads its state again. When it already merged or closed, Orbit does not push and asks for assistance with a reason that starts with `An approved commit is not on the pull request: `. When the task returns to `settling` and its pull request already merged without the latest approved commit, it asks for assistance with the same prefix, and its workspace stays. When the task returns to `settling`, it refreshes its metrics and does not post `task_group.settled` again.

Before removing a merged task's workspace, every tick checks the latest approval against the pull request head again. If the Gateway stopped after recording `settling` but before recording the missed-approval hold, the next tick restores that hold and keeps the workspace.

### Jev decision records

The Gateway stores every Jev call in its `jev_decisions` table, failed calls included, and keeps the records as Orbit's evaluation dataset. A record holds the purpose, the task, subtask, and thread ids, each question with its options and criteria, the input with secrets redacted, each answer with its probabilities, the model, the latency, and an error code on failure. It holds no secrets and no raw provider error. The question and input snapshots are each capped at 64 KiB.

After a pull request merges, the Gateway labels its `brief_coverage` records by rule, without a model. A "missing" answer is a `false_negative` when exactly one line of both the approved change list and the merged pull request's `## Changes` list names that subtask's full, unique title. A call whose answers were all "covered" gets the call label `correct` when its pull request merges. Every other answer stays unlabeled. `php artisan orbit:tasks:jev-report` reports calls, failures, labeled share, false negatives, confidence buckets, `correct` calls, and p50 and p95 latency for each purpose.

### Settle metrics

Settle stores the task's metrics. Showing an active task refreshes them.

| Field | Record | Source |
| --- | --- | --- |
| `tokens` | subtask | The implementer thread's cumulative tokens. Unknown until reported |
| `lines_added`, `lines_deleted`, `line_diff` | subtask | The implementer thread's line counts, when the driver reports them |
| `duration_ms` | subtask | From `started_at` to `settled_at`, or to now |
| `tokens` | task | The sum of subtask `tokens` and every started reviewer thread |
| `lines_added`, `lines_deleted`, `line_diff` | task | The whole branch against the Project default branch |
| `duration_ms` | task | From `started_at` to now while active, or to settle |
| `questions`, `escalations` | both | The [questions](#questions) asked on that record. `escalations` counts records with `escalated_at` set, including a record whose status is now `answered`. A task's counts are the sums of its subtasks |

A failed read keeps the stored value. While a task is active, a missing value stays unknown, not zero. Settle stores an unknown task value as 0. Settle writes the task row from the table above: its tokens add every started reviewer thread to the subtask tokens, and its line diff is the whole branch against the Project default branch. Showing an active task refreshes those values. The board reads that row.

### Tokens and line diff

The task's line counts come from the Node agent's [task workspace](/reference/node-agent#task-workspaces) state while the Gateway's view of that Node is fresh. Otherwise, and when the agent's diff is truncated, they come from `git diff --shortstat origin/{default branch}...HEAD` over SSH. Both count against the fetched `origin/{default branch}`, so a merge of the default branch into the task branch adds no lines. When the agent reports a new commit or new counts, the Gateway stores the counts and broadcasts `task_group.updated`.

A thread's `tokens` is the Pi session usage `total`. Pi reports no per-thread line counts.

### Thread token metrics

Each agent thread also records five split fields. `tasks:agents` and the agents API show them. They stay on the thread and are not copied to the subtask, the task, the board, or the webhook.

| Field | Meaning |
| --- | --- |
| `input_tokens` | Uncached input summed over the model calls, cache writes included |
| `cached_input_tokens` | Input read from cache, summed over the calls |
| `output_tokens` | Output summed over the calls, reasoning included |
| `model_calls` | The number of model calls |
| `peak_context_tokens` | The largest context of one call: its uncached plus its cached input |

Null means the driver did not report the field, or the split is partial. A reported zero is zero. The average context per call is `(input_tokens + cached_input_tokens) / model_calls`, and the cached share of input is `cached_input_tokens / (input_tokens + cached_input_tokens)`.

The Pi server's `usage` object holds `input`, `output`, `cacheRead`, `cacheWrite`, `total`, `calls`, and `peakContext`. `input_tokens` is `input + cacheWrite`, `cached_input_tokens` is `cacheRead`, `output_tokens` is `output`, `model_calls` is `calls`, and `peak_context_tokens` is `peakContext`. The Gateway does not run `tasks:collect-t3-metrics`.

## Review and merge

A Project can opt in to review and merge. Orbit then holds three rules:

1. Orbit pushes only a commit that a final review of the whole branch approved.
2. Orbit reviews each pull request that a listed author opens, and applies the changes it requests itself.
3. Orbit merges a pull request through the GitHub App. The head must be a commit Orbit fully reviewed, and CI must pass on that head.

No cloud agent reviews or fixes the work. Orbit hands no pull request to Cursor, Codex, or Copilot.

### Turn it on

Set `review_and_merge` and `merge_check` on the Project. [Projects: Review and merge](/reference/projects#review-and-merge) describes the fields.

```bash
orbit project:update 46 --review-and-merge=true --merge-check="Required checks"
```

The flow needs `source_access: github_app` and `task_compute: shared`. It is off by default. Turning it on affects open tasks at the next tick: an approval that is not pushed yet waits for a final review.

### Final review

A final review is a subtask with `type` `final_review` and the title `Final review`. Orbit appends one when a review-and-merge task has no open subtask and its latest approved commit has no final review. It has no implementer and runs no task check. It starts in `reviewing` with a fresh reviewer.

Its start commit is the merge base of the workspace `HEAD` and `origin/{default branch}`. So the [review packet](#review-packet) holds the whole branch diff, the task brief, and the earlier approvals. Its one deliverable is the `review` deliverable `final-review`, which the reviewer confirms. When the task has no pull request yet, the final review opens it: its approval needs `--pr-summary`, `--pr-change`, and `--pr-breaking`, and Jev checks [brief coverage](#pull-request-and-settle-metrics) then. Ordinary subtask approvals in the task need no pull request fields.

| Outcome | Orbit |
| --- | --- |
| `approved` | Records `HEAD` as reviewed, pushes it, opens the pull request when none exists, completes the final review, and settles the task |
| `changes_requested` | Completes the final review and starts the fixup subtask `Address final review`, whose brief holds the findings |
| `blocked` | Asks for direction, as any reviewer does |

A final review commits nothing. Orbit checks that the workspace still holds the reviewed `HEAD` and tree, as for any [reviewer outcome](#reviewer-outcomes). The approval stores that `HEAD` as its `commit_sha`. A failed push or pull request open keeps the final review in `reviewing` and retries on the [publication backoff](#pull-request-and-settle-metrics).

The fixup has the `project-check` command deliverable when the Project has a task check, and the `review` deliverable `final-review-findings`. Its `fixup_problem` is `final-review`. It runs the normal implementer, task check, and fresh subtask reviewer. Its approval is held, and a new final review follows.

At most three final-review fixups run in one window. The window ends at the latest completed operator subtask, as for [settling fixups](#fix-a-settling-pull-request). At the fourth set of findings, Orbit completes the final review, settles the task, and asks for assistance with a reason that starts with `The final review keeps requesting changes: `. Append an operator subtask to continue. It resumes the task and clears that request, and its completion opens a new window. Final reviews and their fixups do not count toward the settling fixup caps, and a final review does not open a new window.

### Every push is reviewed

In a review-and-merge task, an approved subtask is committed and not pushed. Conflict, failed-check, and trusted-feedback fixups are subtasks too, so their approvals wait for a final review. Only a final review's approval pushes.

Orbit never asks GitHub to update the branch of such a task. GitHub's merge commit would land without Orbit's review. A conflict gets the `Merge origin/{base}` fixup at once. A branch that is only behind its base still merges. When GitHub refuses that merge, for example because a branch rule requires an up-to-date branch, Orbit appends a final-review fixup `Merge origin/{base}` instead.

Cancel and the cancelled-task sweep push the latest approved commit only when a final review approved it. Approved work that no final review saw is removed with the workspace.

### Incoming pull requests

`ORBIT_TASKS_PULL_REQUEST_AUTHORS` lists the numeric GitHub account IDs whose pull requests Orbit reviews, for each repository, in the format of [`ORBIT_TASKS_GITHUB_REVIEWERS`](#trusted-github-feedback). For example, `ORBIT_TASKS_PULL_REQUEST_AUTHORS=nckrtl/orbit:1234567`. Unset or empty reviews no incoming pull request.

At most once a minute, each `tasks:tick` lists the open pull requests of every review-and-merge Project, at most three pages of 100. A pull request is eligible when all of these hold:

- Its author's account ID is listed for the repository.
- Its head branch is in the same repository, not a fork.
- It is not a draft.
- Its base is the Project's default branch, and its head branch is not the default branch or a `task-*` branch.
- No task for that pull request exists, except tasks that `failed`.

For each eligible pull request, Orbit creates a task in `todo` titled `Review #{number}: {title}`, with the pull request description in its brief. The task stores the pull request as `pr_url` and its head branch as `pr_branch`. It has one final review, which records the head it was created for. The workspace's local branch stays `task-{id}`. Orbit fetches, watches, and pushes `pr_branch` instead of `task-{id}`. Completing or cancelling the task stops Orbit from reviewing that pull request again. A settling task with a pull request cannot be cancelled, so complete it. The task keeps the review-and-merge rules until it ends, even when the Project turns the flow off.

Before the first final review, Orbit runs the [baseline check](#baseline-check) on the fresh workspace, so setup serves the fixups that follow. Then it fetches `pr_branch` and moves `task-{id}` to the head the pull request has at that moment, which can be newer than the head the task was created for. It moves the workspace only when no approved work is unpushed and the tree has no tracked changes.

The author can push while Orbit's reviewed fixups wait. Orbit's push is then not a fast-forward, and GitHub refuses it. Orbit then completes the final review and starts the final-review fixup `Merge origin/{pr_branch}`, so the author's commits are merged and reviewed before the next push.

| Final review outcome | Orbit |
| --- | --- |
| `approved` on the pull request head | Records the head as reviewed and submits an `APPROVE` review with `commit_id` set to that head. It pushes nothing |
| `approved` after Orbit's own fixups | Pushes the reviewed commit to `pr_branch`, never forced, records it, and submits `APPROVE` for it |
| `changes_requested` on the pull request head | Submits the findings as a `REQUEST_CHANGES` review on that head, then starts the fixup |

The App can review the pull request because the pull request author is a person, not the App. GitHub forbids an account to review its own pull request, so Orbit never submits a review on a pull request it opened.

### Merge on green

Each tick, after the [settling watch](#settling), Orbit evaluates a review-and-merge task that is `settling` with an open pull request, no open subtask, and no assistance. It merges when all of these hold on the current head:

1. The head SHA is one Orbit recorded as fully reviewed for this task, and no newer approved work waits for its final review.
2. The `merge_check` runs on that SHA pass by the [green-commit rules](/reference/github-app#find-the-newest-green-commit).
3. The pull request base is the Project's default branch, which the final review diffed against.
4. GitHub reports the pull request mergeable, with no conflict.
5. A complete review read finds no trusted account in `ORBIT_TASKS_GITHUB_REVIEWERS` whose effective decision is `CHANGES_REQUESTED`, on any head. A repository without trusted reviewers has none.

The green-commit rules need at least one run of that name. Every such run must be for that SHA, completed with `success`, and created by `github-actions`.

The App merges with a merge commit and `sha` set to the head, so GitHub refuses when the head moved. The repository must allow merge commits. The merge is a push by the App, so GitHub runs the `push` workflows on the default branch. The next tick sees the merge and completes the task. Orbit evaluates the checks of one head at most once a minute.

A head that Orbit did not record means someone else pushed. On an incoming pull request, Orbit appends a final review of that head. On an Orbit task branch, the task asks for assistance with a reason that starts with `The pull request head was not reviewed by Orbit: `, and Orbit does not merge. Append an operator subtask to continue: it resumes the task, clears that request, and builds on the fetched branch, so a final review covers the other commits before Orbit pushes. The request also clears when the head is one Orbit reviewed again.

| Merge status | Meaning |
| --- | --- |
| `waiting` | A condition does not hold yet, such as a pending or missing check, an unreported mergeability, or an incomplete review read |
| `refused` | A condition failed: the check failed, a trusted account requests changes, the base is not the default branch, GitHub refused the merge, or someone else pushed |
| `merged` | The App merged the pull request. `merged_sha` is the merge commit |

### Records and status

`task_reviewed_commits` holds each SHA Orbit fully reviewed for a task, with its source, the final review, when Orbit pushed it, and the GitHub review Orbit submitted. The source is `orbit_push` for Orbit's own reviewed commit and `pull_request_review` for an incoming head Orbit approved as it was. Only a recorded SHA can merge.

Activity records `final review appended`, `final review approved`, `final review requested changes`, `reviewed commit pushed`, `pull request approved`, `pull request changes requested`, `incoming pull request task created`, `pull request merged`, and each change of the merge result as `merge waiting` or `merge refused`. The task stores the latest merge result in `merge_status` and `merge_reason`. [`tasks:show`](/cli/tasks#orbit-tasksshow) and [`tasks:status`](/cli/tasks#orbit-tasksstatus) show them.

## Web task board

**Tasks** in the web navigation shows every task on a board with Backlog, Todo, In progress, and Done lanes. In progress holds `reserved`, `running`, `reviewing`, and `settling` tasks. Done holds `completed`, `failed`, and `cancelled` tasks with their outcome visible.

The task board and the subtasks board hide lanes with no cards. The remaining lanes share the width. An empty task board says "No tasks yet." An empty subtasks board says "No subtasks yet."

Each card shows the Project code and the task id, such as `ORB-13`, its line counts, its status, and its duration. A card for a task that asks for direction says `Needs your direction`. A card for a task that asks because of a failure says `Needs attention`. A task page shows the brief, the metrics, a board of its subtasks, and an Agents section. When the task asks for direction, its page shows the question first. A subtask page shows that subtask's implementer and reviewer. The board is read-only. The [web app](/reference/web-app#live-tasks) keeps it current from task events.

The same Tasks page lists task definitions. Opening one draws it, and that drawing does not start a task.

## Agent viewer

The Agents section lists every started thread of the task. `GET /api/v1/task-groups/{group}/agents` returns each thread with its driver, external id, state, observation time, errors, and metrics. `GET /api/v1/task-groups/{group}/agents/{session}/stream` streams the thread's normalized conversation to the browser. Both need Gateway access and an enabled extension. Runtime credentials stay in the Gateway.

Stored T3 task-thread rows stay in that list, with their metrics. The `t3_*` columns on `agent_threads` stay. A transcript request for a `t3` thread returns HTTP 409 `tasks.agent_transcript_unavailable` and does not open a stream.

A snapshot replaces the browser transcript. Entries merge by id and kind, so an updated entry replaces the earlier one. On reconnect, the browser sends its last cursor. The stream resumes after the cursor and sends only what the viewer missed. When one event becomes several entries, only the last carries the cursor. The viewer writes no thread state. When the Pi server deletes a conversation, Orbit cannot restore it.

## Coder settle webhook

The Gateway posts signed events to Coder when `ORBIT_CODER_WEBHOOK_URL` and `ORBIT_CODER_WEBHOOK_SECRET` are both set. A refused or failed post changes nothing in Orbit.

| Event | When | Body adds |
| --- | --- | --- |
| `task_group.settled` | A task with `notify_coder` first reaches `settling` with a pull request | `tokens`, `line_diff`, `duration_ms`, `questions`, `escalations`, `pull_request_url` |
| `task_group.assistance_requested` | A task or subtask starts asking for assistance | `kind`, `question`, `reason` |
| `task_group.escalated` | A thread stays unobservable past the grace period | `reason`, `confidence`, `thread_id`, `observation` |

Every body holds `event`, `task_group_id`, and `title`. The Gateway signs `{unix timestamp}.{raw body}` with HMAC-SHA256 and sends the headers `X-Orbit-Timestamp`, `X-Orbit-Signature: sha256={hex}`, and `Content-Type: application/json`.

## OpsBot direction webhook

The Gateway posts one JSON object to OpsBot when a task or subtask starts asking for assistance of kind `direction` and both `ORBIT_OPSBOT_WEBHOOK_URL` and `ORBIT_OPSBOT_WEBHOOK_SECRET` are set. There is no scheduled poll. A refused or failed post changes nothing in Orbit. If the URL or secret is unset, the Gateway skips that POST and leaves the rest of the task flow unchanged.

The body is `event` `task_group.assistance_requested`, `task_group_id`, `title`, `kind`, `question`, and `reason`. The Gateway sends `Content-Type: application/json`, `Authorization: Bearer {secret}`, and `X-Automation-Key` set to the same secret.

Settle, escalate, and assistance that is not `direction` stay on the [Coder webhook](#coder-settle-webhook) only. They do not go to OpsBot.

Annotations, not task agents, use a Node's T3 connection. A Node whose settings hold a `t3` object uses its own `t3.token`, and its `t3.url` as the base URL when set. Such a Node never falls back to `ORBIT_T3_TOKEN`, and a missing token fails closed. Without that object, the Gateway calls `http://{wireguard_ip}:{ORBIT_T3_PORT}` with the bearer `ORBIT_T3_TOKEN`. The port default is `3773`.

## Cancel a stuck task

`tasks:cancel` ends a task in any status except `completed`, and except `settling` with a `pr_url`. Those return HTTP 409 `tasks.not_cancellable`. Complete a settling task instead. `watched_pr_url` does not make the task published, so a `running` or `reviewing` task stays cancellable.

Cancel removes the task's workspace, then marks the task and its open subtasks `cancelled`. Subtasks, comments, and thread links stay as history. Cancel does not stop the agent conversations. Cancelling again is safe, and it retries a removal that failed. It also finds an unattached leftover by Project, `task-{id}` name, and matching branch; a name alone never permits removal.

Cancel uses forced [Instance removal](/reference/instance-removal), including Project teardown and checkout deletion. A development workspace in `source_resolved` can have no Route or exactly one pending or failed Route targeting only that Instance. The eligible Route and its RouteTarget are removed with the workspace. An active or shared Route still prevents removal, keeps the Instance, and follows the removal-refused rule below.

- **Settling without a pull request.** Cancel first pushes the latest approved commit to `task-{id}`, so you can open a pull request from it. A failed push returns HTTP 502 `tasks.push_failed` and keeps the task.
- **Review and merge.** Cancel pushes only an approved commit that a final review approved. It removes approved work that no final review saw.
- **Node unreachable.** Cancel still ends the task and keeps the Instance attached. The task does not ask for assistance. It keeps the reason `Workspace removal failed: The Node is unreachable.` The sweep removes the workspace later.
- **Removal refused.** Cancel returns the error and keeps the task. A task other than `cancelled` asks for assistance with `Workspace removal failed: `. A `cancelled` task keeps that reason and does not ask for assistance.
- **Claim in flight.** A task `reserved` within `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` becomes `cancelled`, and the claim removes the workspace it provisions.

Uncommitted changes are never pushed. Git refuses the push when `origin` holds an unrelated `task-{id}` branch, for example after a Gateway rebuild reused the id. Rename that branch on `origin`, then cancel again.

When cancel marks the task `cancelled`, it clears the assistance flags on the task and its subtasks and keeps the last reasons.

## Complete and cleanup

A merged pull request completes its `settling` task on the next tick when every subtask has ended. `tasks:complete` completes a `settling` task by hand, without reading the pull request again.

It also completes a `running` or `reviewing` task when a read of `watched_pr_url` reports `merged` or `closed`. Before it changes a subtask, that command stores the state on the task as `watched_pr_completion`. The branch watch does not set this column. The receipt is a durable execution hold, even when the task has no ended-PR assistance reason. Fresh locked transitions and agent or check start/send boundaries honor it. The scheduler tick resumes a `running` or `reviewing` task that already has `watched_pr_completion`, and that resume does not read GitHub.

Resume stops a running implementer or reviewer and any running check before the database transaction, using the same stop as subtask cancel. A failed stop returns HTTP 502 `tasks.subtask_interrupt_failed`, leaves the task and open subtasks in their statuses, and keeps `watched_pr_completion` for retry. Resume marks each `todo`, `running`, and `reviewing` subtask `cancelled` and marks the task `completed` in one database transaction. Subtasks already `completed`, `failed`, or `cancelled` stay as they are. A failed transaction rolls back, so the parent stays `running` or `reviewing` and its open subtasks stay open. The stored `watched_pr_completion` remains.

The Gateway serializes receipt authorization with agent and check start/send operations using a file lock for each task under `$ORBIT_HOME`. It holds no database transaction across those remote calls. Work admitted before authorization finishes recording its thread or check before authorization can commit. Completion rechecks the stopped status, acting thread, and running checks under the cancellation lock; if that snapshot changed, it stops the new work before retrying cancellation. A failed stop keeps the receipt and the workspace for recovery.

A running check without a positive PID and a recorded start time cannot be stopped safely. A crash may have started it remotely before recording that identity. Completion returns `tasks.subtask_interrupt_failed` and keeps the receipt, open statuses, and workspace until the check is reconciled. Once its process identity is recorded, a retry stops it without reading GitHub again.

Workspace removal runs only after that transaction commits. A crash before the commit cannot leave a `running` or `reviewing` task with no open subtasks. A crash after the commit leaves the task `completed`.

A missing `watched_pr_url`, an open watched pull request, or an unreadable watched pull request does not complete a `running` or `reviewing` task when `watched_pr_completion` is null. Any other status returns HTTP 409 `tasks.not_settling`. Completing a `completed` task retries the removal when the workspace is still attached, and does not read GitHub. There is no `tasks:continue` command. Cancel the task and start a new one to continue the work.

The Gateway tests inject these completion failures:

- Commit `watched_pr_completion`, then stop before any subtask is cancelled. The parent stays `running` or `reviewing` with its open subtasks. Resume completes the task and does not call GitHub.
- A baseline start loses its process identity. Completion returns `tasks.subtask_interrupt_failed` and preserves the receipt and workspace. Recording the identity lets a retry stop the check without GitHub.
- The parent update fails inside the completion transaction. The subtask cancellations roll back. Resume uses the receipt and does not call GitHub.
- Stop after the task is `completed` and before workspace removal. The next complete retries removal and does not call GitHub.

Cancel, complete, and the sweep remove a workspace the same way. The forced Instance remover deletes the recorded checkout and the workspace's Routes. It writes a removal record, and it deletes the Instance row only after the checkout is gone.

The Instance remover runs the Project's teardown steps before deleting the checkout. A failed teardown keeps the checkout and Instance for retry. On a completed or cancelled task, that failure does not ask for assistance and keeps the reason. On any other task, it asks for assistance through the normal task cleanup path. The engine has no Orbit bridge cleanup hook. The Orbit Project records its bridge cleanup as a [teardown step](/reference/instance-setup#configure-orbits-task-policy); [Incus topologies](/reference/incus-topologies#task-workspace-clones) defines its ownership checks. Orbit releases any acquired Incus topology before removing the group's workspace. A group without a topology needs no acquisition or release.

`apps/e2e/resources/proofs/task-policy-handoff.sh` runs that install and the teardown create, update, readback, and destroy commands on a disposable Project. `apps/e2e/resources/proofs/project-owned-tasks.sh` proves the task lifecycle on the same topology. Neither proof uses the live Project. The directory also holds proofs that are not part of Tasks. `apps/e2e/resources/proofs/mcp-instance-timeouts.sh` calls `instance-create` and `instance-destroy` through the Gateway MCP endpoint on a disposable topology. It prints how long the first call waits, what an identical call returns while that work is still running, and what it returns after the Gateway has finished.

`apps/e2e/resources/proofs/large-sqlite-transfer.py` proves [Instance transfer](/reference/instance-transfer) on an allocated topology. It checks a checkout larger than 1 GiB with a selected SQLite file inside it, Gateway disk staging, and a different request after a failed pre-cutover transfer. It verifies lease ownership before enlarging the allocated workload Nodes' memory and temporary staging capacity, and records that capacity before transfer. It records each result, removes its disposable fixtures, and audits for leftovers.

When a manual complete cannot remove the workspace, the task is already `completed` and keeps its Instance. Open subtasks cancelled in the completion transaction stay `cancelled`. It does not ask for assistance. It keeps the reason `Workspace removal failed: `. The retry does not read GitHub.

Each tick sweeps workspaces that still exist:

- of a `cancelled` or `completed` task, attached or found by the `task-{id}` name and branch. A workspace that a live claim still owns waits.
- of a `settling` task whose merged pull request cleanup failed.

For a cancelled task, the sweep first pushes the latest approved commit, under the same review-and-merge rule as cancel. A failed push stops that removal. The task does not ask for assistance, and the reason names the push error.

A failed removal waits for that Instance only: 1 minute, then 2, 5, 10, and 30 minutes, and then every 30 minutes. A completed or cancelled task does not ask for assistance and keeps the reason. A settling task asks for assistance. A tick starts no removal after 60 seconds of removals. A success clears only a reason that starts with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. The sweep never removes the workspace of a `reserved`, `running`, or `reviewing` task, nor of a `settling` task that still waits for its merge. When the Gateway cannot read or write a retry delay in its cache, it logs a warning and tries at once.

## Configuration

These Gateway environment keys configure the extension.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_TASKS_WORKER_USER` | The worker account granted checkout ACLs. Unset leaves checkout access unchanged during rollout |
| `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER`, `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` | The drivers of new tasks. Both default to `pi`. Any other value is `tasks.agent_driver_unavailable` |
| `ORBIT_TASKS_IMPLEMENTER_MODEL`, `ORBIT_TASKS_REVIEWER_MODEL` | The models of new tasks. Both default to `gpt-5.6-luna`. A Claude model is refused |
| `ORBIT_TASKS_IMPLEMENTER_EFFORT`, `ORBIT_TASKS_REVIEWER_EFFORT` | The effort of new implementer and reviewer threads. Unset or empty keeps `high`. See [Drivers](#drivers) for when changes apply and runtime validation |
| `ORBIT_TASKS_GITHUB_REVIEWERS` | Trusted reviewer account IDs per repository, `owner/repo:id,id;owner/repo:id`. Unset or empty trusts no one. See [Trusted GitHub feedback](#trusted-github-feedback) |
| `ORBIT_TASKS_PULL_REQUEST_AUTHORS` | Account IDs whose pull requests Orbit reviews and merges, per repository, in the format of `ORBIT_TASKS_GITHUB_REVIEWERS`. Unset or empty reviews no incoming pull request. See [Incoming pull requests](#incoming-pull-requests) |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | Comma-separated GitHub logins requested as reviewers when a task pull request is opened or reused. Unset or empty requests no one. The pull request author is skipped |
| `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` | The wait before one escalation for an observation outage. Default `120` |
| `ORBIT_TASKS_PROVISIONING_FAILURE_THRESHOLD` | Consecutive provisioning failures before failure assistance. Default `3`, at least `1`. A successful start resets the count |
| `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | How long a task may stay `reserved`. Default `3600`, at least `60`. Keep it above the slowest workspace provision |
| `ORBIT_T3_PORT`, `ORBIT_T3_TOKEN` | The T3 port, default `3773`, and bearer token for [annotations](#coder-settle-webhook). Task agents do not use them |
| `ORBIT_PI_PORT`, `ORBIT_PI_TOKEN`, `ORBIT_PI_PROVIDER` | The Pi server port, default `3774`, its bearer token, and the provider for plain model names |
| `ORBIT_CODER_WEBHOOK_URL`, `ORBIT_CODER_WEBHOOK_SECRET` | The Coder webhook endpoint and its HMAC secret. The Gateway never returns the secret |
| `ORBIT_OPSBOT_WEBHOOK_URL`, `ORBIT_OPSBOT_WEBHOOK_SECRET` | The OpsBot webhook endpoint and its bearer secret. The Gateway never returns the secret. Unset skips the direction POST |
| `TYPESAFE_API_KEY` | The key for Jev calls |
| `TYPESAFE_URL`, `TYPESAFE_MODEL` | The TypeSafe endpoint, default `https://api.typesafe.ai/v1`, and the classification model, default `jev-latest` |

Problem suppression is not an environment key. The two lists live in `apps/gateway/config/orbit.php` under `problems`, beside `tasks`. [Suppression](#suppression) defines the match.

## Project-owned task policy

The engine knows the configured check, lifecycle steps, workspace routing, and typed deliverables. It does not select a task check, a fixup, or workspace routing by slug, package manager, manifest, or CI job name. A command may use any toolchain installed on the task Node. The rubric does not require a Composer script or inspect a manifest to judge the Project's check. It does not encode a docs-first workflow, an ADR rule, or a language or package manager, and it does not treat any Project slug as Orbit.

### No planner

Task create accepts no planner. There is no `plan` field, no planner thread, and no stored planner state. An external ADE plans and steers the work. Orbit runs the assigned work.

### Starting from the default release

A new task workspace is a linked worktree of the Project's `default` repository on the selected Node. Its task branch starts at the current successful release's commit, not a newer fetched default branch. Orbit reads the authoritative `current` selection under the Node source lock, records `seed_path` and `seed_commit` on the new Instance before preparing its source, and preserves that selection on retries. An empty selection is recorded too: a later default deployment does not reseed a clone that already started without a release.

The default Instance API also reads that selection, so an interrupted release switch cannot expose stale database fields. Both explicit Instance setup and asynchronous task baseline setup receive the recorded `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`; the Project copies its own dependency and cache folders from that release with reflinks. The workspace never writes back to the seed.

When the Project has no development release on that Node, Orbit creates an independent clone and resolves its branch as before. The seed variables are empty and setup must install dependencies from its lock files. There is no automatic root-only dependency copy. External `instance:register` callers read `seed_path` and `seed_commit` from the `default` Instance API, create a branch and linked worktree at that commit, then register it and run setup.

### Routing and cleanup

[Task workspace routing](/reference/projects#task-workspace-routing) decides whether a new workspace is visitable. It defaults to routed, and a change applies only to a workspace Orbit creates afterward. An unrouted workspace stays healthy in `source_resolved`. Orbit provisions the workspace without a topology. Only the [reviewer's turn request](#request-a-topology) acquires the group's one [Incus topology](/reference/incus-topologies#task-workspace-clones). Orbit releases it before removing the workspace; a failed acquisition or missing topology never prevents approval.

Orbit-specific cleanup, including a task bridge worktree, is a Project teardown step. The engine has no bridge cleanup hook. [Configure Orbit's task policy](/reference/instance-setup#configure-orbits-task-policy) records Orbit's check, setup, and installed helper. [Task workspace clones](/reference/incus-topologies#task-workspace-clones) defines that helper's ownership checks.

### The base-run limit

The base run is the exception that remains. It copies only installed `vendor` and `node_modules` directories into the start-commit archive, as [Prove a command fails on the start commit](#prove-a-command-fails-on-the-start-commit) describes. A Project whose command needs other installed dependencies cannot treat that base failure as a reproduction.

### No implicit task check

New Projects have no task check until one is configured, regardless of type. Existing stored checks remain unchanged. Shared instructions, reminders, the check runner, and pull request descriptions name only an explicit Project check; none supplies a fallback. Without a check, Orbit still verifies the tree and deliverables and requires review.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### An optional, generic extension

Tasks is an extension, so an operator can switch it off without a Gateway downgrade. The engine knows tasks, subtasks, deliverables, one task check, and a lifecycle. Each Project's own policy and task check decide how it plans and verifies work. Inferring policy from a slug, manifest, or CI job name would create a second hidden contract in the Gateway, so those choices belong to the Project. The ADE plans, because planning needs the conversation with you. A web form to create tasks would be a second path beside MCP and the API.

Three alternatives were rejected. Selecting one Project's behavior by its slug would keep a second task policy in the Gateway. Inferring that policy from repository files would hide it in the engine instead of the Project's skill and task check. A compatibility path for a planner thread was rejected, because you plan with an external ADE and the engine keeps no planner state.

Shared prompts stay free of Project policy. They do not name a feature contract or an Orbit lease rule. The repository's instructions and `orbit-tasks` skill carry that policy.

### Agents run as orbit-worker

Task agents and task teardown run as `orbit-worker`, so a program an agent starts cannot read the managed user's home. The baseline and handoff checks are the exception: they run as the managed user, because host-dependent tests need its sudo, ACL, and `caddy` access. [The candidate gate runs as the managed user](/reference/pi-server#the-candidate-gate-runs-as-the-managed-user) records that choice and its cost.

Teardown's command is the root-owned helper `/usr/local/lib/orbit/e2e-task-cleanup`, which `orbit-worker` can execute and cannot write. Privileged removal is separate: the managed user deletes the tree and does not run a checkout program. The Pi server runs as `orbit-worker`, and an agent can read the server token and the provider sign-in. [Pi server limits](/reference/pi-server#limits) records the root-equivalent `incus-admin` access.

A [task VM](#task-vm-workspace) needs no `orbit-worker`. The VM edge is the boundary there, so agents and checks share `orbit` and see the same environment. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records that choice.

### Backlog before Todo

A task needs an id before its branch `task-{id}` can hold the contract, and it must not run while that contract is written. So a task starts in Backlog and runs only when someone moves it to Todo. A draft flag on a Todo task would give one lifecycle fact two fields.

### A person starts a filed problem

Code can see that a failure came back. It cannot write the test that proves the bug. A model does not choose what to file. The thresholds are code. This serves [agents operate, humans steer](/mission#principles) and [deterministic first](/mission#principles).

The [outer loop](#outer-loop) files a Backlog draft. The reproduce subtask carries a review placeholder, and a person replaces it with a scoped `fails_on_base` command before the task can run. The filer cannot know the right test, so it never writes a whole-suite command such as `vendor/bin/pest`. Filing straight to Todo is rejected, because an agent would start before a person names the test. A whole-suite command is rejected, because a green or red result would not prove this failure.

A page on every server-class row is rejected. One command can fail dozens of times in an hour, and a Doctor finding from one run can be gone on the next. A Doctor fingerprint stays unfiled until two occurrences are at least 10 minutes apart.

Ten occurrences of one Activity, log, or assistance key are enough to file. An occurrence is one 5-minute window, so the many lines of one burst count once and are not enough to file. Ten windows means the same failure came back across separate windows. Three occurrences file only when they fall in two UTC quarter hours or on two UTC dates. Three isolated occurrences do not file.

Known noise stays in Gateway config, beside the Tasks settings, not in a hidden code list. The [suppression lists](#suppression) name exact fingerprints and source-path prefixes. A match is neither counted nor filed. The prefix list ships with `app/Infrastructure/Tasks/T3/`, so a log frame under that directory does not become a task. Keeping the list only in code is rejected, because an operator has to see it and extend it. The 14-day mute after a cancel stays. The list drops noise before a task exists. The mute waits after a person cancelled a draft.

A shortened log key does not contain the frame path, so the sample keeps that path as `source_path`. Each filer run checks the current lists against the stored path, including a row counted before the prefix was configured. Filing a shortened row that has no stored path is rejected while a prefix is configured, because the filer cannot recover the path. The row stays in place until an accepted signal records it.

The cap of three tasks a day stops a burst from filling the board. `filed_at` holds that count. Counting the cap from the brief is rejected, because the operator edits that brief on the filing day. Cancel is the person's mute, and it lasts 14 days. A completed or failed task waits 7 days. Refiling as soon as a task reaches `failed` is rejected, because that task never ran and the next hourly pass would file the same key again.

The mute write also clears the hits collected while the task was open. Keeping that episode in the ready test is rejected, because the old counts would file the same key when the wait ends, with no new failure.

Clearing the episode only when the task is filed is also rejected, because hits while it sits in Backlog, Todo, or Settling would pass the ready test when the mute ends. When the wait ends, only a hit after the task ended can make the key ready. A hit after the merge and before the deploy can still file a draft, and the operator cancels it.

### The signals the Gateway already has

The loop reads Doctor, Activity, the Gateway log, and assistance reasons. Waiting for schedule rows or an alert manager is rejected. The `schedules` table is empty, and no alert manager is configured.

### A release alert files on its first occurrence

A failed release is a verdict from deterministic checks, not a noisy signal, so waiting for three windows would only delay it. The release command pushes the alert at that moment, and the next hourly run files it.

Collecting it from the release's Activity row is rejected: the Activity key has no commit, so two different bad commits would share one task, and a single row would never be ready. The key holds the commit, because each failed commit is its own problem and is not released again. Filing release alerts first keeps a burst of recurring noise from taking the daily cap before them. The webhook, not the task, is the prompt signal, so the filer keeps its hourly run.

### One fingerprint per problem

The Activity key is the command and the error code. Putting the resource id in that key is rejected, because the id lives only in `properties.path` and the same failure would split into one task per resource. Merging a log error with its Activity row is also rejected. The keys differ, and a log error with no Activity row would disappear.

### A small sample, and no past rows on the first run

The sample keeps bounded Doctor values and a short redacted log excerpt. Storing raw Doctor reports or full traces is rejected, because that output exposes paths and credentials. [Doctor](/cli/doctor#no-stored-reports) keeps no raw report for the caller. Counting past Activity and log rows on the first run is rejected, because those rows would take the daily cap on the day the loop starts. The first run records its cursors at the end of the current data.

### The Gateway claims, not the Nodes

The Gateway already knows every Instance and Node, so it counts active tasks itself. Node-side polling would add a second loop and a second source of truth. A claim reserves the task first and provisions afterwards, so a slow checkout never holds a lock.

### A committed start resets the provisioning streak

The counter needs a durable record of its reset because changes to a file cache cannot commit with the task row. Deleting before commit loses the streak if the start rolls back. Deleting after commit alone retains a stale streak if the Gateway stops before cleanup.

An activity row records each successful start, commits with the task row, and supplies the counter generation without a new column or table. `started_at` alone is not a generation: it records the first start and does not change on a later successful claim. Old-generation cache cleanup is best effort and cannot erase failures in the new generation.

### One subtask at a time on one branch

Each approved subtask becomes one commit on the shared branch before the next subtask starts. So every subtask builds on reviewed work, and the pull request reads as a sequence of reviewed steps.

### One table for a task and its subtasks

A task and its subtasks share a lifecycle and most of their fields, so both are rows in `tasks`. A second model would give one concept two names. A subtask has no children, because the workspace, the branch, and the pull request belong to the top-level task. [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions#one-task-model) records the alternatives this rejects.

### The turn receipt ends a turn

An agent states its outcome with `"$(git rev-parse --git-path orbit)/turn"`, the same command for every driver. The command writes the turn receipt to `$(git rev-parse --git-path orbit)/receipt.json` and stays in place, so a second call overwrites the receipt and the tick can remove that file without deleting the command. The Gateway does not infer outcomes from transcripts, and agents need no Gateway access or ids of their own. The receipt only marks the end of a turn. Code checks decide whether the work moves on. A hand-written JSON file would need extra turns to fix.

### The Gateway runs the check

One check decides for every driver, because it does not depend on tool output. It runs detached, and the process state shows whether it still runs. A time limit would fail a slow check that is not broken, so an operator cancels a check that hangs. The workspace is not copied, because nobody edits it between handoff and review, and the tree comparison catches an edit.

The check `TMPDIR` is created under `/tmp` with mode `0711`, not under the ACL-shared workspace tree, and the check removes that exact directory when it ends. A private `0700` ancestor in that tree blocked cross-user fixtures even when children granted access.

### Deliverables are checked, not read

Orbit cannot check prose, so a subtask names typed items. The check script runs each command itself. The Gateway verifies against its own run, because the agent controls the workspace and could change a script that verified itself. A `file` path accepts a glob, including `{a,b}` or `{php}` alternation. A command's `paths` list is exact files, because a glob could match a file made to satisfy the base run.

### A command must fail on the start commit

A command that only passes on the fixed code does not prove it covers the bug. So a base run uses the start commit and adds only the files named in `paths`. It also copies installed `vendor` and `node_modules` directories so a Composer or JavaScript command can run, and it copies nothing else. A missing Python or Rust dependency tree is not proof that the bug existed on the start commit.

The base tree is an extracted archive inside `$(git rev-parse --git-path orbit)/bases/`, not a registered worktree, because a killed run would leave a registered worktree that blocks removal of the clone. Exit 126 or 127 is not that proof: the command did not run.

### One reminder, then a person

An agent can repair a named list of failures in one turn, so the first failure gets one reminder that names every failed item. A second failure asks for assistance, because unlimited reminders hide a stuck subtask. A blocked agent must ask one specific question, because a vague block costs the operator a round trip.

### Questions go through the reviewer

[Agents operate, humans steer](/mission#principles): the reviewer resolves what the contract already decides, and the operator gives direction that no agent can give. A blocked implementer asks its reviewer before the operator, because the reviewer reads the same brief, documentation, code, and task history. Asking the operator about every block was rejected because it makes a person repeat answers already in the contract. A consult costs one reviewer turn, but keeps those questions away from the operator.

The two-consult limit stops an implementer and a reviewer from passing one question back and forth without end. An unlimited consult loop would hide a question that needs a person's decision. The third block goes to the operator with both earlier answers, so the operator can see what did not resolve it.

The operator's answer goes through the reviewer, so the reviewer translates it into the contract and later reviews the work under the same direction. Sending that answer straight to the implementer was rejected because the reviewer would judge work done under direction it had not seen. The reviewer can therefore start before the implementer's first review handoff, and the later review keeps the consult in its context.

Assistance has a kind, so the operator finds the questions that need a person among failures that the operator only has to fix. A `blocked` status was rejected: the subtask would have to remember whether to return to `running` or `reviewing`, and every status filter, transition, and board lane would change. A kind marks the request without adding a lifecycle step.

The existing `task_group.assistance_requested` webhook carries the kind and question. A separate `task_group.direction_requested` event was rejected because receivers would need a second subscription for the same assistance flag. Waiting on another task or pull request is not a third assistance kind: a dependency wait that resumes on its own is a separate feature. The operator answers through the CLI, MCP, or API; a web answer box is outside this feature.

### Direction wakes OpsBot immediately

A direction request needs a person now. A scheduled poll would leave the task waiting until the next check. The Gateway already posts assistance once on the Coder path, so it posts that same event to OpsBot at that moment.

OpsBot is not a second Coder. Settle and escalate stay on the HMAC-signed Coder webhook. A failure is something the operator can find on the board; it does not wake OpsBot. Sending every assistance kind would page the operator for disk-full and push failures.

OpsBot's contract is Bearer plus `X-Automation-Key`, not Orbit's HMAC headers. Reusing the Coder signature would fail at OpsBot. A missing URL or secret skips the post so a Gateway without OpsBot still runs tasks.

### Questions are records, not parsed comments

Each consult and direction request has a record with its answer and cause, while comments keep the conversation. Questions kept only in comment bodies would need free-text parsing before the operator could count them, group them by cause, or trace them to a brief. The records and the `questions` and `escalations` counts show where briefs, contracts, and subtask scopes need attention.

The reviewer chooses a cause from a fixed list when it hands off, because it already holds the contract and the answer. Asking a model to infer the cause later was rejected: the handoff has the evidence, and a fixed list avoids another model call and its cost. A cause stays empty until that reviewer receipt, so an open, escalated, or migrated question does not claim a diagnosis nobody has made.

### Only the acting thread pauses a subtask

If any working thread paused a subtask, an operator who talks to the reviewer would stall the implementer's handoff. So only the thread that acts in the subtask's phase defers it. Every send still waits for its target to stop, so no turn lands in the middle of another.

### A fresh reviewer for each subtask

A long-lived reviewer would carry the context of every earlier review into each new one, and that inherited context would be most of its tokens. A fresh thread with a capped packet reviews only this subtask. The retrieval commands print the diff the caps cut, and every cut note names `$(git rev-parse --git-path orbit)/context.md`, which holds the full task brief, subtask brief, deliverables, earlier approval bodies, and held resolution. The file is in the workspace, so it works on every driver. A re-review continues the same thread, so the reviewer keeps its own findings.

### The reviewer does not edit

The approval commit must hold only the work that the implementer handed off. So a reviewer that edits the workspace gets a reminder to revert and to request the change. A silent revert would hide the edit.

### Orbit commits and pushes

Orbit holds the branch, the receipts, and the GitHub App, so it commits after approval and publishes itself. It pushes the stored commit, not `HEAD`, because `HEAD` can move after the approval. It pushes after every approval, so a lost clone loses no approved work. Retries back off, so a failing Node or GitHub is not called every 10 seconds. Publication requests configured reviewers so GitHub emits `review_requested` and the fleet reviewer wakes. That request is optional and soft-fails, because an empty reviewer list must not block settle.

### A watched pull request is not the reviewed pull request

`pr_url` means the reviewed pull request. The last approval sends its description, Jev checks `brief_coverage`, and cancel treats a `settling` task with `pr_url` as published. Reusing it for a pull request found before the last approval would skip the description and coverage checks and change cancel's publication rule. Orbit keeps that branch-watch result in `watched_pr_url` instead.

An early merge or close leaves the remaining work with an ended pull request. Continuing to start subtasks, reviewers, or pushes would spend work against a pull request that has ended. Orbit holds the task and names the pull request, its state, and the open work in its assistance request. It leaves the operator to complete or cancel the task, rather than silently marking unfinished work completed.

Interrupting a running agent would discard a turn that has not handed off its work. Orbit lets that turn finish, then sends one notice to its acting thread. It commits a stable send key before delivery so a crash or lost response can retry without a second notice. A resolution comment cannot lift this hold: answering a question does not reopen the pull request.

Manual completion has a separate durable receipt in `watched_pr_completion`. That receipt holds execution independently of assistance text and preserves the operator's authorization when a stop, transaction, or cleanup fails. Resume does not depend on another GitHub read. Cancelling the open subtasks and completing their parent in one transaction prevents a crash from leaving a running task with no open work. Removing the workspace after that commit lets cleanup retry without undoing completion.

There is no `tasks:continue` command. Cancel and a new task already cover continuing the work; adding a second recovery path would leave two ways to make the same choice. The [branch watch](#watch-the-branch-while-subtasks-are-open) and [manual completion](#complete-and-cleanup) define these rules.

### Fetch before every turn

The default branch and the pull request base move while a task is open. A turn that reads stale remote-tracking refs can miss a conflict or merge the wrong base. The Gateway fetches before every turn, including a reminder and an operator message, so the workspace sees the current refs.

The fetch names only the default branch, `task-{id}`, and the pull request base when that base differs. `--no-tags` keeps the read to those branches. Tags are not part of the review. The read token cannot push, so a command that runs with it cannot publish the branch.

A missing `task-{id}` ref is not a failure of the turn fetch, whether or not a pull request exists. The branch is absent until Orbit publishes it. Resumed preparation still treats a missing task branch as a failure when a pull request exists, because that preparation expects the published branch.

When an ordinary turn's fetch fails, the turn still starts. The message says the fetch failed and warns that `origin/*` may be stale. Holding every ordinary turn for a retry would stall the task on one GitHub error. The agent keeps working with the last fetched refs.

A resumed fixup reuses this fetch instead of a second one. It needs `origin/task-{id}` and the pull request base, and those refs are already in the set. The preparation still fast-forwards a workspace that is strictly behind, and it never forces. A failed preparation keeps the subtask `todo`, retries on the same backoff, and asks for assistance on the fifth failure. The subtask has not started, so a stale base would make the fixup merge the wrong commits. That wait does not apply to an ordinary turn.

An agent holds no GitHub token and never fetches or pushes. A token in the agent environment would land in the transcript or the workspace. The Gateway fetches with the read token, and it pushes an approved commit with the write token.

### Trusted reviews are input, not merge authority

GitHub review prose does not grant authority. The operator lists numeric accounts for each repository, granting only bounded repair work. Logins can change, repository roles are too broad, and a plain comment does not express a requested-change decision. Selecting the latest decisive record across heads prevents dismissal or out-of-order results from reviving obsolete work. Approval observations remain separate from the final reviewer's merge responsibility and the maintainer profile's admin bypass.

The ledger couples one review to one fixup in a local transaction. A cursor misses edits and out-of-order results; a cache loses deduplication on restart. A digest and immutable findings packet keep the scope that the internal reviewer actually checked. Reading complete bounded lists and refusing overflow costs operator intervention on unusually large reviews, but partial findings cannot safely define repair scope. Review IDs identify consumption, while reviewer IDs identify the cap, so repeated submissions do not buy unlimited automatic work.

Trust belongs in Gateway configuration, not a task definition or branch: the work being reviewed must not authorize its own instruction source. Trusting all collaborators, associations, or the App would exceed the operator's consent. Comments remain informational because prose alone cannot distinguish advice from a formal requested-change decision. Filtering by head before selecting the latest decisive review would revive superseded decisions.

Consumption and fixup creation commit together because either order in separate transactions can lose work or duplicate it after a crash. Consumed scope stays immutable even if GitHub edits or dismisses the source; rewriting or cancelling active work would need a separate interruption protocol. The operator can cancel through the existing lifecycle, but cancellation retains consumption and cap charges. The API still permits deletion only in Backlog; a missing link is corruption or unsupported cleanup, not permission to recreate work.

Polling uses bounded read-only GitHub App access, so private Gateways need no webhook ingress or new permission. The final uncached validation reduces stale creation, but GitHub endpoints and the Gateway cannot share an atomic snapshot. A remote edit can still race with creation. Stored source provenance makes that limitation inspectable; it is not a guarantee of live resolution.

Durable approval observations are separate from internal receipts and consumption. Their local report shows stored provenance, latest confirmed status, and freshness, not an aggregate verdict or live merge gate. Failed reads retain evidence without confirming approval. An approval marked `historical` never becomes `current` merely because GitHub dismissed a newer decision.

A fixup uses the ordinary implementer, reviewer, Project check, and publication flow. Existing identity, brief, and deliverables carry the findings without new public trust or merge fields. Internal approval neither posts a GitHub decision nor requests re-review. Automatically requesting review or enforcing or performing merge would add unnecessary write authority. Outside a review-and-merge Project, the external final reviewer must repeat affected verification and formally approve the new exact head; the authorized maintainer retains delegated merge consent and admin bypass, and Orbit observes the merge. [Orbit merges only what it reviewed](#orbit-merges-only-what-it-reviewed) covers a Project that opts in.

### Orbit reviews before it pushes

On 2026-10-08 the maintainer decided that Orbit does not use or rely on cloud agents to review or fix Orbit's pull requests, and that every commit Orbit pushes is already fully reviewed. Subtask reviewers each see one subtask, so nobody saw the whole branch before it reached GitHub. The final review closes that gap, and holding every push until it approves makes the invariant hold for fixups too. This serves [agents operate, humans steer](/mission#principles): the maintainer opts a Project in, and the agents review, repair, and merge.

Pushing each subtask and reviewing only before the merge was rejected. A pushed commit is unreviewed, and a person or a fleet reviewer acts on what GitHub shows. A separate final-review state machine on the task was rejected. It would repeat the reviewer thread, receipt, reminder, restart, topology, and direction handling that a subtask already has. So a final review is a subtask without an implementer.

The cost is that approved work waits in the workspace. A lost workspace loses it, and cancel does not push it. The cap of three final-review fixups in one window stops a reviewer and an implementer from passing findings back and forth without end, as the [fixup caps](#fixups-are-bounded) do for settling.

### Incoming pull requests become tasks

A pull request from the maintainer's account was not reviewed by Orbit, so Orbit reviews it before it can merge. Making it a task reuses the workspace, the reviewer, the fixups, and the push rules. The local branch stays `task-{id}`, and `pr_branch` names only the branch Orbit fetches and pushes. A second workspace branch name was rejected: provisioning, removal, and the rubric all rely on `task-{id}`.

The author list is Gateway configuration, like reviewer trust. A pull request cannot name its own author as trusted. Logins, repository roles, and forks confer nothing, because the account that opened a pull request is the authority Orbit checks. Orbit applies requested changes itself instead of asking the author or a cloud agent, so the head it merges is one it reviewed.

### Orbit merges only what it reviewed

The merge condition is a fact Orbit recorded: this exact SHA passed Orbit's final review. A GitHub approval cannot carry that fact for Orbit's own pull requests, because GitHub forbids the App to approve them. The merge check uses the green-commit rules, so a check run from another App cannot make a head green. The `sha` parameter makes GitHub refuse a merge when the head moved after the gate read it.

Asking GitHub to update a branch was rejected for these tasks, because GitHub's merge commit would land without Orbit's review. A trusted request for changes blocks the merge until that account approves or dismisses it, even after Orbit's fixup. A person still steers through briefs, direction answers, and requested changes. A merge deploys the Gateway, so a wrong final review ships. The merge check and trusted requested changes are the remaining guards.

### Fixups are bounded

The workspace and a reviewer can repair a conflict or a failed check, so the first problem gets a fixup instead of a person. Each fixup is a normal subtask on the same pull request, never a rebase or a force push, because the open pull request is the review.

The caps stop a loop: two per problem, three per task, and a new window only after a person's subtask completes. A problem's identity ignores the check URL and the base commit, so a moving `main` does not look like a new problem. Infrastructure failures get no fixup, because the branch did not cause them.

### Resume a restarted turn

The checkout still holds the work after an agent server restarts, and the same thread can finish it. So the tick resumes the turn instead of asking for assistance. Two resumes cover one upgrade and one retry. The resume is counted before it is sent, because the send can succeed while its response is lost.

### Metrics stay on the thread

The thread spent the tokens, so the split lives there. A total alone does not show whether the prompt grew, the cache missed, or the output grew. Pi reports the split on the session usage object. A missing field stays null, because a partial sum would look complete.

### Task agents run on Pi

T3 task threads run as the operator's Unix user and have that user's full access. Pi task agents run as a dedicated `orbit-agent` account. The operator approved that split on 2026-10-01. T3 Code stays installed as the operator's own tool. Annotations still use the operator's T3 threads.

Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. Task agents therefore cannot use Claude, and they do not keep a second runtime to reach it. One driver, Pi, owns implementers and reviewers. This serves [one way, one name](/mission#principles) and [no exceptions and no legacy](/mission#principles).

Keeping T3 as a selectable driver would keep two restart rules, two metric paths, and two archive paths. Pi owns restart recovery and reports usage on its sessions. The scheduler has no T3 metric collector or thread archive. Annotation delivery is a separate operation on the operator's existing T3 thread, not a task-agent runtime.

Both roles default to `gpt-5.6-luna` at `high` effort. Keeping `claude-opus-5` as the reviewer default would make each new review fail on Pi. Each role keeps its driver setting, with a `pi` default, because deployment selects Pi explicitly. A different configured driver fails before a new task is stored.

Finishing an open T3 task turn would preserve the second runtime, so a managed task that records a driver other than `pi` never starts or resumes an agent turn. The operator cancels or replaces it. Deleting its thread row, metrics, or `t3_*` columns would erase the record of work that already ran, so that history stays. A transcript request returns HTTP 409 `tasks.agent_transcript_unavailable` rather than contacting T3. Pi session files stay on the Node; Orbit keeps their thread rows and metrics too.

### Jev only checks coverage

Code decides every fact that code can check. Jev answers only whether the change list covers each subtask, because the reviewer writes that list and code cannot compare prose. Every call is stored with its input and later labeled by rule from the merged pull request, so the checks can be measured without a second model.

### Definitions stay Project data

The Gateway stays generic by storing each Project's plan as a task definition instead of as code. An operator or an agent can change that plan through the API. Kinds stay in code, because a kind is executable behavior, and definitions stay data. [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions#task-definitions) records the alternatives this rejects.
