---
title: "ADR 0181: Run task groups from Project task templates"
sidebarTitle: "0181 Run task groups from task templates"
description: "In progress. Each Project stores task templates in the Gateway. A template creates a task group with keyed tasks of five generic kinds: agent, check, merge, action, and decide. Each task ends with an outcome, and forward routes on outcomes choose the next task. A template can run on a cron schedule."
---

# ADR 0181: Run task groups from Project task templates

Each Project stores its task flows as task templates in the Gateway database. A template creates a task group with keyed tasks, and each task has one of five generic kinds: `agent`, `check`, `merge`, `action`, or `decide`. Each task ends with an outcome, and the task's routes choose the next task from that outcome. The Gateway code knows the kinds but no Project's flow, so any Orbit user can define a flow without changing code.

## Status

In progress.

Principle: this decision serves [deterministic first](/mission#principles) and [one way, one name](/mission#principles). Code decides every outcome it can, `decide` asks a model only where code cannot, and every flow runs through one task group lifecycle and one routing rule.

## Context

[ADR 0178](/decisions/0178-run-project-agnostic-tasks-without-a-planner) makes the Tasks engine project-agnostic. It removes Orbit's own policy from the Gateway and puts each Project's task policy in an `orbit-tasks` skill in the Project's repository. Every task is still one coding flow: an implementer and a reviewer work in a task workspace, and the group ends with a pull request that an operator completes by hand.

Other flows do not fit that shape. A maintenance run for an App needs these steps in a fixed order:

1. Provision a task workspace.
2. Update the dependencies, and fix what the update breaks.
3. Run the tests and verify the App in a browser.
4. Merge the pull request.
5. Deploy the production Instance.
6. Verify production, and roll back when verification fails.

The run must repeat on a timer without an operator. It must stop early when there is nothing to update, and it must take the rollback path only when production verification fails. Other Projects need other flows, such as content that is drafted, checked, and published. The Orbit Project's own feature flow, with ADRs and documentation before implementation, is also a flow that each group repeats by hand today.

All custom configuration must live in the database, so that the Gateway code stays generic and other people can run Orbit for their own Projects. A skill in a repository is guidance for an agent, not configuration the Gateway can enforce, and a Project without a repository has no place for it.

Most tasks in these flows can be decided by a script. An agent session is the most expensive kind of work, so a flow uses one only where a task needs judgment. A routing choice can need judgment too, such as whether a dependency update is a major upgrade that needs an agent's review. [Jev](/reference/tasks#jev-decision-records-and-report) already answers typed questions with probabilities for session routing, and the Gateway records every call.

## Decision

The Gateway owns templates, task kinds, routing, and scheduled runs. Each Project owns its templates as data. The Gateway code owns no Project's flow.

### Task templates

A **task template** belongs to one Project and is a Gateway record. It has a `name` that is unique within the Project, a group `title` and `brief`, the `status` that a new group starts in (`backlog` or `todo`), an optional `cron` expression with the `app_id` it runs for, and a list of task definitions. A task definition has the fields of a task: `key`, `title`, `brief`, `kind`, `deliverables`, `routes`, and the kind's own fields below. `key` is unique within the template.

`tasks:template:create`, `tasks:template:update`, `tasks:template:list`, `tasks:template:show`, and `tasks:template:destroy` manage templates and require Gateway access. `tasks:create` accepts `template` with an `app_id` of the template's Project. Orbit copies the template's title, brief, status, and tasks into the new group, and a `title` or `brief` in the request replaces the copied one. The group records its template. Changing the template does not change groups that it already created. A group without a template stays valid: the external ADE composes its tasks directly, as today.

A template is a starting structure, not a lock. In `backlog`, the ADE edits the copied tasks as it edits any task today. The Project's task check enforces what must always hold.

### Task kinds

Every task has a `kind`. The kind decides who works on the task and which outcome it ends with. The group lifecycle and the task statuses stay the same for every kind.

| Kind | Work | Outcomes |
| --- | --- | --- |
| `agent` | An implementer and a reviewer in the task workspace, as today. | `passed` when the reviewer approves and Orbit has verified the deliverables. `skipped` when that approval has an empty diff. `failed` when the task fails. |
| `check` | Orbit runs the task's `command` deliverables in the task workspace. No agent runs. | `passed` when every command exits 0. `skipped` when a command exits 125 and none fails. `failed` on any other exit code. |
| `merge` | Orbit publishes the group's approved commits as the group's pull request, when none is open, waits for the pull request's required checks, and merges it with the Gateway GitHub App. | `passed` when GitHub reports the pull request merged. `skipped` when the group has no approved commit. `failed` on a failed check or a refused merge. |
| `action` | Orbit calls one Gateway API `operation` with the stored `arguments`, as the Gateway. | `passed` when the operation succeeds. `failed` on an error. |
| `decide` | Orbit asks Jev one `question` with named `options`, over the `evidence` the task names. No agent runs. | The chosen option, when its probability reaches `min_probability`. Below it, the task asks for assistance and waits for an operator's choice. |

`passed`, `failed`, and `skipped` are the outcomes of every kind except `decide`. Exit code 125 means skipped, as it does for `git bisect run`. A `check` or `action` task burns no model tokens. A `decide` task makes one classification call. Only an `agent` task starts an agent session.

An `action` task can call only an operation that the OpenAPI document marks as a task action. This decision marks `instance:deploy` and `instance:rollback`. Marking another operation needs its own decision. The action runs with the Gateway's authority. Only a Gateway-access caller can write a template, so a template cannot grant a caller more authority than it already has.

A `decide` task's `evidence` names tasks before it in the list by key. For each one, Orbit sends its outcome, its deliverable results, the last 200 lines of each command's output, and the diff summary of an `agent` task. `min_probability` is between 0 and 1 and defaults to 0.8. Every call is stored as a Jev decision record with the purpose `task_route`, so the report and the labels in [ADR 0173](/decisions/0173-record-and-label-jev-decisions) measure its accuracy. A failed Jev call asks for assistance, as a low probability does.

### The task kind contract

Each kind is one Gateway class that implements the same contract, registered in a task kind registry, as agent drivers are in `AgentDriverRegistry`:

- **start** begins the work for a task.
- **observe** reads its progress on a scheduler tick.
- **cancel** stops it.
- **outcome** returns the task's outcome and its evidence when the work has ended.

The scheduler knows only this contract. A kind declares its fields and its outcomes, and template validation uses that declaration. A new kind needs a new lifecycle, such as waiting for a person or for an outside event, and its own decision. Work that a script can do is a `check` task, and a change in Orbit is an `action` task, so neither needs a new kind.

### Routes

A task's `routes` map each of its outcomes to a target: the `key` of a task after it in the list, `complete`, or `fail`. A missing route uses the default:

| Outcome | Default target |
| --- | --- |
| `passed` | The next task in the list, or `complete` after the last task |
| `skipped` | `complete` |
| `failed` | `fail` |

A `decide` task has no defaults. Its routes must name a target for every option.

A route points only forward, to a task after it in the list. A flow therefore cannot loop, and every group ends. The fix loop inside an `agent` task, where the reviewer requests changes, stays inside that task. Orbit cancels each `todo` task that the route passes over.

`complete` completes the group. When the group's pull request is merged, or it has none, Orbit removes the task workspace, as `tasks:complete` does. When an unmerged pull request is open, the group settles and waits for `tasks:complete`, as today. `fail` fails the group and asks for assistance, and the reason names the task and its outcome.

Orbit validates routes when a template or a group's tasks are written. It refuses a route to an unknown or earlier key, a route for an outcome that the kind does not declare, and a `decide` task without a route for each option.

### Scheduled templates

A template with a `cron` expression creates a group for its `app_id` on each match. The Gateway's Tasks scheduler tick evaluates the expression in UTC. Orbit skips a match while the last group from the same template and App is still open, and records the skipped run in Activity. The extension must be on. A group created by the schedule starts in the template's `status`.

### Example: App maintenance

| Key | Kind | Task | Routes |
| --- | --- | --- | --- |
| `update` | `agent` | Update the dependencies and fix what breaks. | defaults: `skipped` completes the group |
| `size` | `decide` | Is this a major upgrade? Options `major` and `minor`, over the evidence of `update`. | `major` → `review`, `minor` → `browser` |
| `review` | `agent` | Read the changelogs of the major upgrades and adapt the App. | defaults |
| `browser` | `check` | Run the Project's end-to-end browser tests against the task workspace URL. | defaults |
| `merge` | `merge` | Merge the pull request after its required checks pass. | defaults |
| `deploy` | `action` | `instance:deploy` for the production Instance. | defaults |
| `verify` | `check` | Run the smoke tests against the production URL. | `passed` → `complete`, `failed` → `rollback` |
| `rollback` | `action` | `instance:rollback` for the production Instance. | `passed` → `fail` |

The template stores `cron: "0 3 * * 1"` and starts groups in `todo`. Every Monday at 03:00 UTC, Orbit updates the App, merges, deploys, and verifies it without an operator. A week without updates ends after `update`. A minor upgrade skips `review`. A failed production check rolls back, fails the group, and asks for assistance.

### Changes to ADR 0178

A Project's task flows live in its templates in the Gateway, not in a repository skill. The `orbit-tasks` skill keeps only guidance for an agent that writes briefs and deliverables. The Orbit Project's feature flow becomes a `feature` template: an `agent` task for ADRs and documentation, then `agent` tasks for the implementation. The template starts groups in `backlog`, where the ADE fills in each brief.

## Rejected alternatives

- Extract Tasks into a separate package or project: the engine depends on Instances, agent threads, realtime, and the GitHub App, so it still runs inside the Gateway, and a package adds versioning across repositories without making flows configurable.
- Keep templates as files in each Project's repository: a Project without a repository cannot have one, the Gateway cannot validate or schedule a file it does not hold, and the web app cannot list or edit it.
- A general workflow engine with backward jumps, conditions in expressions, and parallel branches: none of the known flows needs them. Forward routes on outcomes cover every known flow, and a flow that cannot loop always ends.
- Special fields such as `stop_when_unchanged` and `on_failure`: each covers one case and adds a second way to route. The `skipped` outcome and a `failed` route cover both.
- Route with an `agent` task: an agent session costs far more than one classification, and it returns prose that code must parse. `decide` returns a typed choice with a probability that Orbit can threshold and measure.
- Let an `action` task call any operation: a template would then carry destructive operations with no review. Marking each task action keeps the list explicit.
- Load task kinds from the database: a kind is executable behavior, and storing code as configuration crosses the Gateway's trust boundary. Kinds stay in code, and flows stay in data.
- Schedule groups with a Node Schedule that runs `orbit tasks:create`: it needs the CLI and Gateway credentials on a host Node, and Tasks cannot see a timer that failed on that Node.
- Verify production with an `agent` task: a model would judge a pass or fail that a smoke test decides deterministically.

## Consequences

- Any Orbit user can define, route, and schedule a flow for a Project through the API, CLI, and MCP, with no code change.
- Scripts, Gateway operations, and typed decisions do most of the work, so a flow spends agent tokens only on tasks that need judgment.
- The Gateway needs a `task_templates` table, and tasks need `template_id`, `key`, `kind`, `routes`, `outcome`, and the kind-specific fields.
- `TaskScheduler` must run tasks through the kind contract and follow routes. Today it assumes that every task has an implementer, a reviewer, and a diff, and that tasks run in position order. Separating those assumptions is the main cost of this decision.
- The Gateway GitHub App needs permission to merge pull requests in the Project repositories that use a `merge` task.
- A `merge` task merges without a human review. A Project uses one only when its required checks and `check` tasks are enough to trust the result.
- A `decide` task depends on the TypeSafe key that session routing already uses. Its accuracy is measured from its decision records before a flow relies on a low `min_probability`.
- The web task board filters by template. Each task card shows its kind and outcome, and a task that a route passed over shows as cancelled. A `check`, `action`, or `decide` card shows its command output, operation result, or choice and probabilities instead of agent tokens.
- Project templates and scheduled runs are part of the Project's configuration, so exporting a template from one Gateway and importing it on another needs its own decision.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: amends [0178](/decisions/0178-run-project-agnostic-tasks-without-a-planner), [0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff), and [0173](/decisions/0173-record-and-label-jev-decisions)
- Detail: [Tasks reference](/reference/tasks), [Tasks CLI](/cli/tasks), [Concepts](/concepts#tasks)
- Verify: Gateway feature tests for each kind and its outcomes, route validation, skipped tasks, the `decide` threshold, and the cron tick; an Incus proof that runs the maintenance example against a test App, including the `skipped` path and a forced rollback
