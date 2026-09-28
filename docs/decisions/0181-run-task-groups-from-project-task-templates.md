---
title: "ADR 0181: Run task groups from Project task templates"
sidebarTitle: "0181 Run task groups from task templates"
description: "In progress. Each Project stores task templates in the Gateway. A template creates a task group with ordered tasks of four generic kinds: agent, check, merge, and action. A template can run on a cron schedule, and a task can run one Gateway action when it fails."
---

# ADR 0181: Run task groups from Project task templates

Each Project stores its task flows as task templates in the Gateway database. A template creates a task group with ordered tasks, and each task has one of four generic kinds: `agent`, `check`, `merge`, or `action`. The Gateway code knows these kinds but no Project's flow, so any Orbit user can define a flow without changing code.

## Status

In progress.

Principle: this decision serves [deterministic first](/mission#principles) and [one way, one name](/mission#principles). A template decides the structure of a group, the `check` and `merge` kinds decide outcomes without a model, and every flow runs through one task group lifecycle.

## Context

[ADR 0178](/decisions/0178-run-project-agnostic-tasks-without-a-planner) makes the Tasks engine project-agnostic. It removes Orbit's own policy from the Gateway and puts each Project's task policy in an `orbit-tasks` skill in the Project's repository. Every task is still one coding flow: an implementer and a reviewer work in a task workspace, and the group ends with a pull request that an operator completes by hand.

Other flows do not fit that shape. A maintenance run for an App needs these steps in a fixed order:

1. Provision a task workspace.
2. Update the dependencies, and fix what the update breaks.
3. Run the tests and verify the App in a browser.
4. Merge the pull request.
5. Deploy the production Instance.
6. Verify production, and roll back when verification fails.

The run must repeat on a timer without an operator. Other Projects need other flows, such as content that is drafted, checked, and published. The Orbit Project's own feature flow, with ADRs and documentation before implementation, is also a flow that each group repeats by hand today.

All custom configuration must live in the database, so that the Gateway code stays generic and other people can run Orbit for their own Projects. A skill in a repository is guidance for an agent, not configuration the Gateway can enforce, and a Project without a repository has no place for it.

## Decision

The Gateway owns templates, task kinds, and scheduled runs. Each Project owns its templates as data. The Gateway code owns no Project's flow.

### Task templates

A **task template** belongs to one Project and is a Gateway record. It has a `name` that is unique within the Project, a group `title` and `brief`, the `status` that a new group starts in (`backlog` or `todo`), an optional `cron` expression with the `app_id` it runs for, and an ordered list of task definitions. A task definition has the fields of a task: `title`, `brief`, `kind`, `deliverables`, and the kind's own fields below.

`tasks:template:create`, `tasks:template:update`, `tasks:template:list`, `tasks:template:show`, and `tasks:template:destroy` manage templates and require Gateway access. `tasks:create` accepts `template` with an `app_id` of the template's Project. Orbit copies the template's title, brief, status, and tasks into the new group, and a `title` or `brief` in the request replaces the copied one. The group records its template. Changing the template does not change groups that it already created. A group without a template stays valid: the external ADE composes its tasks directly, as today.

A template is a starting structure, not a lock. In `backlog`, the ADE edits the copied tasks as it edits any task today. The Project's task check enforces what must always hold.

### Task kinds

Every task has a `kind`. The kind decides who works on the task and what completes it. The group lifecycle and the task statuses stay the same for every kind.

| Kind | Work | Completes when |
| --- | --- | --- |
| `agent` | An implementer and a reviewer in the task workspace, as today. | The reviewer approves, and Orbit has verified the deliverables. |
| `check` | Orbit runs the task's `command` deliverables in the task workspace. No agent runs. | Every command deliverable passes. Any failure fails the task. |
| `merge` | Orbit publishes the group's approved commits as the group's pull request, when none is open, waits for the pull request's required checks, and merges it with the Gateway GitHub App. | GitHub reports the pull request merged. A failed check or a refused merge fails the task. |
| `action` | Orbit calls one Gateway API `operation` with the stored `arguments`, as the Gateway. | The operation succeeds. An error fails the task. |

An `action` task can call only an operation that the OpenAPI document marks as a task action. This decision marks `instance:deploy` and `instance:rollback`. Marking another operation needs its own decision. The action runs with the Gateway's authority. Only a Gateway-access caller can write a template, so a template cannot grant a caller more authority than it already has.

An `agent` task can set `stop_when_unchanged`. When the reviewer approves it with an empty diff, Orbit cancels the remaining `todo` tasks and completes the group without a pull request. A maintenance run with no dependency updates ends this way.

Any task can set `on_failure` to one `action`: an `operation` and its `arguments`. When the task fails, Orbit runs that action once, then fails the group and asks for assistance. The assistance reason names the failed task and the result of the action.

A group whose `merge` task has merged its pull request completes when its last task completes. Orbit then removes the task workspace, as `tasks:complete` does. A group without a `merge` task still settles and waits for `tasks:complete`.

### Scheduled templates

A template with a `cron` expression creates a group for its `app_id` on each match. The Gateway's Tasks scheduler tick evaluates the expression in UTC. Orbit skips a match while the last group from the same template and App is still open, and records the skipped run in Activity. The extension must be on. A group created by the schedule starts in the template's `status`.

### Example: App maintenance

| Position | Kind | Task |
| --- | --- | --- |
| 1 | `agent` | Update the dependencies and fix what breaks. `stop_when_unchanged: true`. |
| 2 | `check` | Run the Project's end-to-end browser tests against the task workspace URL. |
| 3 | `merge` | Merge the pull request after its required checks pass. |
| 4 | `action` | `instance:deploy` for the production Instance. |
| 5 | `check` | Run the smoke tests against the production URL. `on_failure`: `instance:rollback` for the production Instance. |

The template stores `cron: "0 3 * * 1"` and starts groups in `todo`. Every Monday at 03:00 UTC, Orbit updates the App, merges, deploys, and verifies it without an operator. A failed production check rolls back and asks for assistance.

### Changes to ADR 0178

A Project's task flows live in its templates in the Gateway, not in a repository skill. The `orbit-tasks` skill keeps only guidance for an agent that writes briefs and deliverables. The Orbit Project's feature flow becomes a `feature` template: an `agent` task for ADRs and documentation, then `agent` tasks for the implementation. The template starts groups in `backlog`, where the ADE fills in each brief.

## Rejected alternatives

- Extract Tasks into a separate package or project: the engine depends on Instances, agent threads, realtime, and the GitHub App, so it still runs inside the Gateway, and a package adds versioning across repositories without making flows configurable.
- Keep templates as files in each Project's repository: a Project without a repository cannot have one, the Gateway cannot validate or schedule a file it does not hold, and the web app cannot list or edit it.
- A general workflow engine with branches, conditions, and parallel steps: none of the known flows needs it. An ordered list, `stop_when_unchanged`, and `on_failure` cover them.
- Let an `action` task call any operation: a template would then carry destructive operations with no review. Marking each task action keeps the list explicit.
- Schedule groups with a Node Schedule that runs `orbit tasks:create`: it needs the CLI and Gateway credentials on a host Node, and Tasks cannot see a timer that failed on that Node.
- Verify production with an `agent` task: a model would judge a pass or fail that a smoke test decides deterministically.

## Consequences

- Any Orbit user can define and schedule a flow for a Project through the API, CLI, and MCP, with no code change.
- The Gateway needs a `task_templates` table, a `template_id` and a `kind` on tasks, and the kind-specific fields.
- `TaskScheduler` must dispatch on `kind`. Today it assumes that every task has an implementer, a reviewer, and a diff. Separating that assumption is the main cost of this decision.
- The Gateway GitHub App needs permission to merge pull requests in the Project repositories that use a `merge` task.
- A `merge` task merges without a human review. A Project uses one only when its required checks and `check` tasks are enough to trust the result.
- The web task board filters by template. Each task card shows its kind. A `check` or `action` card shows its command or operation result instead of agent tokens.
- Project templates and scheduled runs are part of the Project's configuration, so exporting a template from one Gateway and importing it on another needs its own decision.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: amends [0178](/decisions/0178-run-project-agnostic-tasks-without-a-planner) and [0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)
- Detail: [Tasks reference](/reference/tasks), [Tasks CLI](/cli/tasks), [Concepts](/concepts#tasks)
- Verify: Gateway feature tests for each kind, `stop_when_unchanged`, `on_failure`, and the cron tick; an Incus proof that runs the maintenance example against a test App, including a forced rollback
