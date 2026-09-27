# ADR 0178: Run project-agnostic Tasks without a planner

The Gateway Tasks engine owns generic task coordination and validation; each Project owns its task policy, and an external ADE plans and steers work.

## Status

Proposed.

## Context

Tasks began as an Orbit feature workflow. The Gateway now contains policy for one Project's docs-first process, Composer and Pest checks, ADR conventions, repository slug, and workspace cleanup. That coupling prevents another Project from using Tasks without inheriting Orbit's assumptions. The Gateway also creates a planner thread, even though an external ADE is responsible for planning and steering.

Tasks is an optional extension. Its durable role is to coordinate task groups and ordered subtasks, validate typed deliverables, run a Project-provided task check, and enforce a lifecycle. Project-specific workflow belongs with the Project, where its own task check can enforce it deterministically.

## Decision

The Gateway Tasks engine is project-agnostic. It provides groups, subtasks, typed deliverables, and a lifecycle. Each Project configures one command for the Gateway to run as its task check. It does not know about docs-first workflows, ADRs, Composer, Pest, PHP, or which Project is Orbit. The Tasks extension remains optional.

Each Project keeps its task policy in its own repository as an `orbit-tasks` skill under `.agents/skills/`. That Project's own task-check command enforces the policy deterministically. The Orbit repository's policy is documented in [`.agents/skills/orbit-tasks/SKILL.md`](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md).

An external ADE plans and steers work. Orbit runs the assigned work. Remove the planner path, including `plan: true`, the T3 planner thread, `StartTaskPlannerAction`, `TaskPlannerSpawner`, and `TaskPlannerObserver`. There is no compatibility path for the planner or its stored state. This supersedes ADR 0124.

This decision also amends ADR 0122 so Backlog groups have no Instance until claimed; amends ADR 0125 so the Project supplies one command for its check; amends ADRs 0133 and 0163 so a subsequent implementation group replaces test deliverables with commands that support `fails_on_base` and `paths`; moves ADR 0135 bridge-worktree removal into the Orbit Project's teardown steps; and amends ADR 0164 so fixups use the Project task check instead of a slug-keyed CI table.

Backlog groups have no Instance. The Gateway creates an Instance only after a group is claimed for execution.

Typed deliverables remain generic. A subsequent implementation group replaces test-specific deliverables with command deliverables, including `fails_on_base` and `paths`. Each Project supplies one command for its task check. Fixup tasks use that command, never a CI table keyed by Project slug.

Bridge-worktree removal is owned by the Orbit Project's teardown steps, not the Gateway's generic task cleanup.

### Policy leaked into the Gateway

The following examples identify Project-specific behavior that the planned implementation groups remove. Locations refer to `origin/main` at the time this ADR was drafted:

- `apps/gateway/app/Domain/Tasks/TaskSettlingFixup.php:24-95` uses the `orbit` slug to select CI reproduction commands in `reproduces()`.
- `apps/gateway/app/Domain/Tasks/InstanceProvisionIntent.php:15-36` makes instance visitability depend on the `orbit` slug in `visitableFor()`.
- `apps/gateway/app/Infrastructure/Tasks/RemoteTaskBridgeWorktreeRemover.php:22` makes the Gateway remove Orbit's Incus bridge worktree.
- `apps/gateway/app/Domain/Tasks/TaskScheduler.php:838` adds a `check_script` rubric item; `TaskScheduler.php:2926-2930` defines `runsComposerCheck`; `apps/gateway/app/Infrastructure/Tasks/RemoteTaskWorkspaceStateReader.php:29-48` defines `definesComposerCheckScript`.
- `apps/gateway/app/Domain/Tasks/TaskScheduler.php:2941-2970` performs implicit Composer and JavaScript dependency setup in `startBaseline`.
- `apps/gateway/app/Domain/Projects/ProjectType.php:30` gives Composer-specific task-check defaults, while `apps/gateway/app/Domain/Tasks/TaskRunInstructions.php:16` and `TaskRubricReminder.php:21` assume `composer check` by default.
- `apps/gateway/resources/tasks/check:8-9,12,150-` binds test deliverables to Pest/JUnit behavior.
- `apps/gateway/resources/tasks/run:1` requires PHP to execute the task receipt command.
- Task prompts and rubrics contain Composer, Pest, ADR and Orbit-repository workflow assumptions, including `apps/gateway/app/Domain/Tasks/TaskPromptRenderer.php:15-22` and `apps/gateway/app/Domain/Tasks/TaskJevOutcome.php:17`.

## Rejected alternatives

- Keep behavior for Orbit selected by the Project slug: that preserves the coupling and creates a second implicit task policy in the Gateway.
- Retain planner compatibility for existing groups: the operator requires removal, not a legacy path, and external ADEs own planning.
- Make the Gateway infer Project policy from repository files: policy belongs to the Project's own skill and deterministic task check, not hidden Gateway interpretation.

## Consequences

- Any Project can opt into generic Tasks without adopting Orbit's language, toolchain, or workflow.
- Each Project must provide and maintain its own `orbit-tasks` skill and task check.
- Removing planner state and behavior is a breaking change; stored planner fields and groups have no compatibility behavior.
- A subsequent implementation group must replace the current test-specific and Orbit-specific checks with command deliverables and Project-provided task checks.
- Orbit-specific cleanup, including bridge worktrees, must happen in Orbit's own teardown steps.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: [0122](/decisions/0122-hold-task-groups-in-backlog-until-ready), [0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner), [0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off), [0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff), [0135](/reference/incus-topologies#task-workspace-clones), [0163](/decisions/0163-prove-a-failing-test-on-the-start-commit), [0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask)
- Detail: [Tasks reference](/reference/tasks), [Tasks CLI](/cli/tasks), [Orbit task policy](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md)
- Verify: `composer docs-lint`; implementation groups verify each Project's task check and generic deliverables
