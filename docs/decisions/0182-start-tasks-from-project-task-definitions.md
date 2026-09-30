---
title: "ADR 0182: Start tasks from Project task definitions"
sidebarTitle: "0182 Start tasks from task definitions"
description: "In progress. A Task is one row in one tasks table, and its subtasks are child tasks. Each Project stores task definitions in the Gateway: ordered subtasks of five generic kinds, with forward routes on outcomes and declared parameters. Every task starts from a definition, now or at a scheduled time. Six slices build it."
---

# ADR 0182: Start tasks from Project task definitions

Orbit has one task model. A **task** is one row in the `tasks` table, and the subtasks of a task are its child tasks. Each Project stores **task definitions** in the Gateway database: predefined tasks with ordered subtasks, conditions between the subtasks, and the parameters they accept. Every task starts from a definition, either now or at a scheduled time. The Gateway code knows the task kinds but no Project's definitions, so any Orbit user can define work for a Project without changing code, and an agent can change a definition through the API.

## Status

In progress.

Principle: this decision serves [one way, one name](/mission#principles) and [deterministic first](/mission#principles). One model and one table hold every task and subtask, every task starts from a definition, and code decides every subtask outcome it can.

## Context

The [Tasks engine](/reference/tasks) stores two models: a `TaskGroup`, which the Tasks board shows, and its ordered `Task` rows, which the CLI and web app call subtasks. Both have a title, a brief, a status, assistance, comments, and metrics, and the scheduler advances both on the same tick. The engine runs one fixed flow for every group, and a Project's policy lives in a repository skill such as `orbit-tasks`.

Other work does not fit that fixed flow. A maintenance run for an App needs these subtasks in order:

1. Update the dependencies, and fix what the update breaks.
2. Run the tests and verify the App in a browser.
3. Merge the pull request.
4. Deploy the production Instance.
5. Verify production, and roll back when verification fails.

The run must repeat every week without an operator, stop early when there is nothing to update, and take the rollback path only when production verification fails. Other Projects need other work, such as content that is drafted, checked, and published, or a weekly report.

All custom configuration must live in the database, so that the Gateway code stays generic and other people can run Orbit for their own Projects. A skill in a repository is guidance for an agent, not configuration the Gateway can enforce. Keeping definitions in the Gateway also lets the web app draw them, lets an agent change one definition through the API, and keeps the work next to the machines it runs on.

Most subtasks can be decided by a script. An agent session is the most expensive kind of work, so a definition uses one only where a subtask needs judgment. [Jev](/reference/tasks#jev-decision-records) already answers typed questions with probabilities for routing choices that need judgment.

## Decision

The Gateway owns the task model, the task kinds, routing, and schedules. Each Project owns its task definitions as data.

### One task model

A `Task` is one row in the `tasks` table. A task without `parent_id` is a top-level task: it is what the Tasks board shows, and it holds the parameters of its start. A task with `parent_id` is a subtask of that parent. A subtask has no subtasks of its own.

| Only on a top-level task | Only on a subtask |
| --- | --- |
| The task workspace, the branch, the pull request, `scheduled_at`, and the parameters | The position, the task kind and key, the deliverables, the implementer thread, the task checks, and the turn receipts |

Both levels share the title, brief, status, outcome, assistance, comments, and metrics. When a top-level task ends, Orbit writes the totals of its subtasks (tokens, line diff, and duration) to its own row, so the board reads one row per task. The Gateway enforces which columns each level uses.

The run receipt becomes the **turn receipt**: `.git/orbit/turn` ends an agent's turn, so "run" names nothing else in Tasks.

The `TaskGroup` model, its table, and the separate subtask table are removed. Top-level tasks keep the ids of their groups, so task identities such as `ORB-177`, the `task-{id}` branches, and pull request titles stay valid. Subtasks get new ids, and every reference to a subtask moves with it. There is no compatibility path for the old names.

### Task definitions

A **task definition** belongs to one Project and is a Gateway record. It has these fields:

| Field | Contract |
| --- | --- |
| `name` | Unique within the Project. 1 to 63 lowercase ASCII letters or digits, with hyphens only between them |
| `title`, `brief` | The title and brief of the tasks it starts. Either can name a parameter as `{parameter}` |
| `parameters` | Ordered list of `{name, type, required, default}`. `type` is `text`, `app`, or `subtasks` |
| `status` | `backlog` or `todo`: where a task started now begins |
| `schedule` | Optional: a five-field cron expression in UTC, and the parameter values each scheduled task uses |
| `phases` | Optional ordered list of `{key, title, brief, repeat}`. A phase groups subtasks for the drawing only |
| `subtasks` | Ordered list of subtask definitions, at least one |

A subtask definition has `key`, `title`, `kind`, and optional `brief`, `phase`, `deliverables`, and `routes`, plus the fields of its kind:

| Kind | Fields |
| --- | --- |
| `agent` | Optional `implementer_model` and `reviewer_model` |
| `check` | At least one `command` deliverable |
| `merge` | None |
| `action` | `operation` and `arguments` |
| `decide` | `question`, `options`, `evidence`, and optional `min_probability` |

`key` is unique within the definition. `deliverables` follow the [Tasks deliverables](/reference/tasks#deliverables) contract.

A `subtasks` parameter inserts the subtasks that the starter passes at that point in the list. Every inserted subtask is an `agent` subtask. Orbit's own feature work uses it: the "Build a feature" definition has a fixed documentation subtask and a `subtasks` parameter for the implementation subtasks that the ADE plans.

### Start a task

Every task starts from a definition. There are two ways:

- **Now.** A caller starts a definition with its parameter values. Orbit creates the top-level task in the definition's `status` and creates its subtasks.
- **At a time.** A definition with a `schedule` always has exactly one upcoming task. The Tasks scheduler tick keeps it in `backlog`, with `scheduled_at` set to the next time the schedule matches, and the board shows it as scheduled. A caller can also start a definition once at a given `scheduled_at`.

When `scheduled_at` has passed, the tick creates the subtasks from the definition as it is at that moment, moves the task to `todo`, and creates the next upcoming task. A definition edited between the two therefore takes effect on the next run. Three rules keep a schedule predictable:

- A due task waits in `backlog` while an earlier task from the same definition is still active, and moves to `todo` when that task ends.
- After downtime, a definition starts one task, not one per missed time.
- Removing or changing a schedule deletes the upcoming task or replaces it with one at the new time.

A task records its definition and its parameters. Changing a definition does not change tasks that have already moved to `todo`.

### Task kinds

The kind decides who works on a subtask and which outcome it ends with. The task and subtask statuses stay the same for every kind.

| Kind | Work | Outcomes |
| --- | --- | --- |
| `agent` | An implementer and a reviewer in the task workspace, as the engine runs every subtask today. | `passed` when the reviewer approves and Orbit has verified the deliverables. `skipped` when that approval has an empty diff. `failed` when the subtask fails. |
| `check` | Orbit runs the subtask's `command` deliverables in the task workspace. No agent runs. | `passed` when every command exits 0. `skipped` when a command exits 125 and none fails. `failed` on any other exit code. |
| `merge` | Orbit publishes the task's approved commits as its pull request, when none is open, waits for the required checks, and merges it with the Gateway GitHub App. | `passed` when GitHub reports the pull request merged. `skipped` when the task has no approved commit. `failed` on a failed check or a refused merge. |
| `action` | Orbit calls one Gateway API `operation` with the stored `arguments`, as the Gateway. | `passed` when the operation succeeds. `failed` on an error. |
| `decide` | Orbit asks Jev one `question` with named `options`, over the `evidence` the subtask names. No agent runs. | The chosen option, when its probability reaches `min_probability`. Below it, the subtask asks for assistance and waits for an operator's choice. |

Exit code 125 means skipped, as it does for `git bisect run`. A `check` or `action` subtask spends no model tokens, and a `decide` subtask makes one classification call.

An `action` subtask can call only an operation that the OpenAPI document marks with `x-orbit-task-action: true`. This decision marks `instance:deploy` and `instance:rollback`. Marking another operation needs its own decision. Only a Gateway-access caller can write a definition, so a definition cannot grant a caller more authority than it already has.

A `decide` subtask's `evidence` names subtasks before it by key. For each one, Orbit sends its outcome, its deliverable results, the last 200 lines of each command's output, and the diff summary of an `agent` subtask. `min_probability` is between 0 and 1 and defaults to 0.8. Every call is stored as a Jev decision record with the purpose `task_route`. A failed Jev call asks for assistance, as a low probability does.

Each kind is one Gateway class that implements one contract, registered as agent drivers are: **start** begins the work, **observe** reads its progress on a tick, **cancel** stops it, and **outcome** returns the outcome and its evidence. The scheduler knows only this contract. A kind declares its fields and outcomes, and definition validation uses that declaration. A new kind needs a new lifecycle, such as waiting for a person or an outside event, and its own decision. Kinds stay in code; definitions stay in data.

### Routes

A subtask's `routes` map each of its outcomes to a target: the `key` of a subtask after it, `complete`, or `fail`. A missing route uses the default:

| Outcome | Default target |
| --- | --- |
| `passed` | The next subtask, or `complete` after the last subtask |
| `skipped` | `complete` |
| `failed` | `fail` |

A `decide` subtask has no defaults. Its routes must name a target for every option.

A route points only forward, so a task cannot loop and always ends. The fix loop inside an `agent` subtask, where the reviewer requests changes, stays inside that kind. Orbit cancels each subtask that a route passes over. `complete` ends the task as it ends today: it settles while its pull request is open and completes when the pull request merges. `fail` fails the task and asks for assistance, with a reason that names the subtask and its outcome.

### Validation

The Gateway validates a definition on every write and refuses an invalid one with HTTP 422 `tasks.definition_invalid`. `details` names each failing rule with the subtask key it concerns. It refuses:

- a duplicate subtask key, an unknown kind, a field that the kind does not declare, or a missing field that the kind requires;
- a route from an outcome that the kind does not declare, or to an unknown key, to the subtask itself, or to a subtask before it;
- a `decide` subtask without a route for each option;
- a subtask that no path from the first subtask reaches;
- a subtask whose `phase` is not in `phases`, or a phase whose subtasks are not next to each other;
- a duplicate phase key;
- an `action` operation that is not marked as a task action;
- more than 100 subtasks, 50 parameters, 50 phases, 50 arguments on one subtask, or 100 schedule values;
- a `{parameter}` that `parameters` does not declare, more than one `subtasks` parameter, or a schedule value for a parameter that the definition does not declare;
- a cron expression that is not five valid fields, or a schedule without a value for each required parameter.

A name that the Project already uses returns HTTP 409 `tasks.definition_exists`. Starting a definition with a missing or wrongly typed parameter returns HTTP 422 `tasks.parameters_invalid`.

Models are not refused, because ProxyCli's model list changes over time. When that list is available, the web app reports a model that no driver can run as a finding: a model is known when ProxyCli offers it, or when it is a Claude model, which T3 runs on its own Claude subscription. When the list is missing, empty, or refused, the view says that the model list is unavailable and reports no driver findings.

### API

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:definition:list` | `GET /api/v1/task-definitions`, optional `project_id` filter | Any authorized peer |
| `tasks:definition:show` | `GET /api/v1/projects/{project}/task-definitions/{name}` | Any authorized peer |
| `tasks:definition:create` | `POST /api/v1/projects/{project}/task-definitions` | Gateway |
| `tasks:definition:update` | `PUT /api/v1/projects/{project}/task-definitions/{name}` | Gateway |
| `tasks:definition:destroy` | `DELETE /api/v1/projects/{project}/task-definitions/{name}` | Gateway |
| `tasks:create` | `POST /api/v1/projects/{project}/task-definitions/{name}/tasks`, with parameter values and an optional `scheduled_at` | Gateway |

Update replaces the whole definition, so an agent edits one by reading it, changing it, and writing it back. The update body may omit `name`; the path supplies it. A body `name` that differs from the path is refused. Every operation refuses with HTTP 409 `extension.disabled` while the extension is off, the same code as the other task operations. The CLI commands take a definition as a JSON file, and the MCP tools are generated from these operations. The existing task operations keep working on top-level tasks, and the subtask operations work on subtasks.

`GET /api/v1/proxycli/models` (`proxycli:models`) lists the models that CLIProxyAPI offers, as `{id, provider}`. On each poll the collector reads `GET /v0/management/auth-files/models` for each auth file, with the management key, and stores the union in its snapshot. When one auth file's request fails, that file keeps the models from the previous poll. It does not call `GET /v1/models`: that route checks a client API key, and the management key is not one. `provider` is the ProxyCli provider that serves the model: `owned_by` `openai` is `codex`, `anthropic` is `claude`, `xai` is `grok`, `moonshot` is `kimi`, and any other value keeps its name. `google` and `meta` keep their names, and no ProxyCli provider serves them. When `owned_by` is absent, the collector maps the auth file's `provider` with that same table. The management list sets `provider` and `type` to one value. A model with neither `owned_by` nor an auth-file provider is omitted. The route refuses with `proxycli.disabled` while the fleet feature is off.

### Definition view

The web app lists definitions on the Tasks page and on each Project page, and draws a definition on a canvas from the live API. The drawing shows:

- the main path down the middle, and detours and failure paths in side columns, each ending in its own `complete` or `fail` node;
- each subtask's kind, models, and routes with their outcome labels; default routes to an end stay hidden;
- each phase as one card that opens into a frame around its subtasks;
- the engine's fixed stages around the definition: starting the workspace before the first subtask, the implementer, handoff check, and reviewer inside each `agent` subtask, and the pull request, the merge by a person unless the definition has a `merge` subtask, and the cleanup after the last subtask;
- the schedule in words, such as "Weekly on Monday at 03:00 UTC";
- findings: a subtask no path reaches, a `decide` subtask whose options all lead to one subtask, and, when the ProxyCli model list is available, a model that no driver can run. A missing, empty, or refused list is one notice and no driver findings. The drawing opens at full size, and a side path is reached by panning.

The Tasks board shows scheduled tasks in Backlog with their `scheduled_at`.

### Changes to ADR 0178

A Project's task policy lives in its task definitions in the Gateway, not in a repository skill. The `orbit-tasks` skill keeps only guidance for an agent that writes briefs and deliverables. Orbit's feature work becomes the "Build a feature" definition described above, starting in `backlog`, where the ADE fills in the parameters.

### Slices

Each slice is one task and one pull request. This record stays until the last slice absorbs it into the [Tasks reference](/reference/tasks).

| Slice | Delivers |
| --- | --- |
| 1. One task model | `tasks` with `parent_id` replaces task groups and their tasks; the turn receipt; totals on the top-level row. No behavior changes |
| 2. Store and show definitions | Definition records, validation, API, CLI, SDK, and MCP; the ProxyCli model list; the definition view on live data |
| 3. Start tasks from definitions | Starting now and at a time, parameters, schedules, and scheduled tasks on the board, for definitions made only of `agent` subtasks; every task starts from a definition |
| 4. Kind contract and routes | The scheduler runs subtasks through the kind contract, adds `check`, and follows routes and outcomes |
| 5. `merge` and `action` | The two kinds and the task-action marks |
| 6. `decide` | Jev routing with the `task_route` purpose |

### Example: App maintenance

| Key | Kind | Subtask | Routes |
| --- | --- | --- | --- |
| `update` | `agent` | Update the dependencies and fix what breaks. | defaults: `skipped` completes the task |
| `size` | `decide` | Is this a major upgrade? Options `major` and `minor`, over the evidence of `update`. | `major` → `review`, `minor` → `browser` |
| `review` | `agent` | Read the changelogs of the major upgrades and adapt the App. | defaults |
| `browser` | `check` | Run the Project's end-to-end browser tests against the task workspace URL. | defaults |
| `merge` | `merge` | Merge the pull request after its required checks pass. | defaults |
| `deploy` | `action` | `instance:deploy` for the production Instance. | defaults |
| `verify` | `check` | Run the smoke tests against the production URL. | `passed` → `complete`, `failed` → `rollback` |
| `rollback` | `action` | `instance:rollback` for the production Instance. | `passed` → `fail` |

The definition has an `app` parameter and the schedule `0 3 * * 1` with that App. Every Monday at 03:00 UTC, its upcoming task leaves Backlog, and Orbit updates the App, merges, deploys, and verifies it without an operator. A week without updates ends after `update`. A minor upgrade skips `review`. A failed production check rolls back, fails the task, and asks for assistance.

## Rejected alternatives

- Separate models for a run and its subtasks, such as `TaskRun` and `StepRun`, or keep `TaskGroup` beside `Task`: a task and its subtasks share their lifecycle and most fields, and two models give one concept two names. The columns that only one level uses are few, and most per-subtask data already lives in its own tables.
- Let subtasks have their own child tasks: no known definition needs it, and it raises open questions about workspaces and pull requests per level. A separate decision can add a task kind that starts another definition.
- Tasks without a definition: that keeps a second way to create work. Ad hoc work uses a definition with a `subtasks` parameter.
- A separate schedule record, or Orbit's host Schedules: a host Schedule runs a command on a Node, which would need the CLI and Gateway credentials there, and Tasks cannot see a timer that failed on that Node. The upcoming task in Backlog makes the next run visible on the board.
- Copy the subtasks when the upcoming task is created: an edit to the definition during the week would then miss the next run.
- Extract Tasks into a separate package, or use n8n as the engine: the engine depends on Instances, agent threads, realtime, and the GitHub App, and flows would live in two systems. Orbit builds no connectors for other services; a separate decision can add a generic webhook kind.
- Keep definitions as files in each Project's repository: a Project without a repository cannot have one, and the Gateway cannot validate or schedule a file it does not hold.
- A general workflow engine with backward jumps, conditions in expressions, and parallel branches: no known definition needs them, and forward routes always end.
- Store the engine's fixed stages, such as the workspace start and the pull request, in each definition: every definition would repeat them, and a definition would then decide what the engine guarantees.
- Special fields such as `stop_when_unchanged` and `on_failure`: the `skipped` outcome and a `failed` route cover both.
- Route with an `agent` subtask: an agent session costs far more than one classification and returns prose that code must parse.
- Let an `action` subtask call any operation: a definition would then carry destructive operations with no review.
- Load task kinds from the database: a kind is executable behavior, and storing code as configuration crosses the Gateway's trust boundary.

## Consequences

- Any Orbit user can define, start, schedule, and see a Project's work through the API, CLI, MCP, and web app, with no code change.
- Merging the two task tables is one migration without a compatibility layer. Every part of the Gateway, CLI, SDK, and web app that reads groups or subtasks moves to the one model.
- The scheduler must run subtasks through the kind contract and follow routes. Today it assumes that every subtask has an implementer, a reviewer, and a diff, and that subtasks run in position order. Separating those assumptions is the main cost of this decision.
- The Gateway GitHub App needs permission to merge pull requests in the Project repositories that use a `merge` subtask, and a `merge` subtask merges without a human review.
- Exporting a definition from one Gateway and importing it on another needs its own decision.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: amends [0178](/decisions/0178-run-project-agnostic-tasks-without-a-planner)
- Detail: [Tasks](/reference/tasks), [proxycli](/reference/proxycli), [Web app](/reference/web-app), [Tasks CLI](/cli/tasks), [Concepts](/concepts)
- Verify: slice 1: the migration test on a copy of the task tables and the existing task suite on the one model; slice 2: tests for each validation rule and definition operation, the proxycli models route, CLI and SDK fixtures, and web screenshots; slice 3: tests for starting now, `scheduled_at`, the upcoming task, the three schedule rules, and parameters; slices 4 to 6: tests for each kind and its outcomes and the `decide` threshold, and an Incus proof of the maintenance example, including the `skipped` path and a forced rollback
