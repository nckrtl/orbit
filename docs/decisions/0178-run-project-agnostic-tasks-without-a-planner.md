# ADR 0178: Run project-agnostic Tasks without a planner

The Gateway Tasks engine owns generic task coordination and validation; each Project owns its task policy, and an external ADE plans and steers work.

## Status

In progress.

Principle: this decision serves [deterministic first](/mission#principles). The Gateway enforces generic task mechanics; each Project supplies explicit policy, checks, setup, and cleanup.

## Context

Tasks began as an Orbit feature workflow. The Gateway now contains policy for one Project's docs-first process, Composer and Pest checks, ADR conventions, repository slug, and workspace cleanup. That coupling prevents another Project from using Tasks without inheriting Orbit's assumptions. The Gateway also creates a planner thread, even though an external ADE is responsible for planning and steering.

Tasks is an optional extension. Its durable role is to coordinate tasks and ordered subtasks, validate typed deliverables, run a Project-provided task check, and enforce a lifecycle. Project-specific workflow belongs with the Project, where its own task check can enforce it deterministically.

## Decision

The Gateway Tasks engine is project-agnostic. It provides tasks, subtasks, typed deliverables, and a lifecycle. Each Project configures one command for the Gateway to run as its task check. It does not know about docs-first workflows, ADRs, Composer, Pest, PHP, or which Project is Orbit. The Tasks extension remains optional.

Each Project keeps its task policy in its own repository as an `orbit-tasks` skill under `.agents/skills/`. That Project's own task-check command enforces the policy deterministically. The Orbit repository's policy is documented in [`.agents/skills/orbit-tasks/SKILL.md`](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md).

An external ADE plans and steers work. Orbit runs the assigned work. Remove the planner path, including `plan: true`, the T3 planner thread, `StartTaskPlannerAction`, `TaskPlannerSpawner`, and `TaskPlannerObserver`. There is no compatibility path for the planner or its stored state. This supersedes ADR 0124.

This decision also amends ADR 0122 so Backlog tasks have no Instance until claimed; amends ADR 0125 so the Project supplies one command for its check; amends ADRs 0133 and 0163 so a subsequent task replaces test deliverables with commands that support `fails_on_base` and `paths`; moves ADR 0135 bridge-worktree removal into the Orbit Project's teardown steps; and amends ADR 0164 so fixups use the Project task check instead of a slug-keyed CI table.

Backlog tasks have no Instance. The Gateway creates an Instance only after a task is claimed for execution.

Typed deliverables are `file`, `command`, and `review`; `test` is removed with no compatibility path. A `command` deliverable has a command and directory, plus optional `fails_on_base: bool` and `paths: list<string>`. With `fails_on_base: true`, the engine runs the command against the start commit and then the working tree; it must exit nonzero on the base and zero on the working tree. For the base run, the engine overlays the listed working-tree files on its archive of the base commit and copies installed `vendor` and `node_modules` directories from the workspace when present. It copies no other dependency directory. ORB-155 leaves that limit in place. The follow-up is [Dependency directories on a base run](#dependency-directories-on-a-base-run). Exit 126 or 127 means Orbit did not run the command on the start commit; these codes are not reproduction evidence. The engine owns that archive, the timeout, and the evidence. It does not interpret runner-specific results: matching Pest test names, parsing JUnit, and similar policy belong to the Project's command.

### Follow-up: generic command deliverables

Main has no `test` deliverable type. The migrations `2026_09_29_130000_convert_test_deliverables_to_commands` and `2026_09_29_125000_add_continuation_source_to_tasks` convert stored `test` deliverables that were still open. The Project's `task_check` remains the workspace-root quality check; it is not a test-file runner and the migration never changes or appends arguments to it. Because the removed `test` type explicitly represented named Pest tests, conversion uses an explicit legacy mapping that changes to the former Project directory before running its local `vendor/bin/pest`, file, and filter. This preserves that Project's PHPUnit configuration and bootstrap. The legacy name is matched as a case-sensitive, regex-escaped substring. The migration normalizes former project/file paths, carries over `fails_on_base`, overlays the resulting workspace-relative file for a base run, and adds a `file` deliverable with `change: any` for each converted test file so the diff is checked. It converts each source task and all its continuation rows in one database transaction. It keeps each converted command/file pair together, allocates distinct IDs of at most 64 characters, and moves overflow deliverables into continuation subtasks immediately after their source task so each stored list stays within the five-deliverable limit. Continuations retain a reference to the source and use its start commit for diff and base-run verification, even when the source has already committed its fixes. Custom test-name matching remains in the Project's command, not the generic engine.

### ORB-155: complete the engine boundary

Bridge-worktree removal is owned by the Orbit Project's teardown steps, not the Gateway's generic task cleanup. The maintained [Tasks reference](/reference/tasks#project-owned-task-policy), [Project settings](/reference/projects#task-workspace-routing), and [lifecycle handoff](/reference/instance-setup#configure-orbits-task-policy) define this slice. The baseline runs only configured setup and check commands, fixups use the configured check or review, and no new Project gets an implicit check. Existing checks remain unchanged.

`task_workspace_routed` defaults to true. Its one-time migration preserves the old creation policy, while each existing workspace retains its provisioned mode. Updates apply only when Orbit creates a workspace and do not reroute an existing Instance. A workspace without a Route stays healthy in `source_resolved`.

Orbit's repository owns `bin/e2e-task-cleanup`, installed outside task checkouts and configured as teardown before the Gateway drops its old hook. It preserves the existing ownership checks and retry safeguards. The installed helper also serves task clones created before deployment.

ORB-155 completes the slug and toolchain couplings in [Policy leaked into the Gateway](#policy-leaked-into-the-gateway), then absorbs this decision into the Tasks reference and the owning Project and lifecycle pages. It does not change which dependency directories the base run copies. ADR 0182 remains in progress: its task-definition policy is a separate decision and its inbound link moves to the absorbed Tasks section.

### Policy leaked into the Gateway

These locations are Project-specific behavior still on main at `0f493f3f505815da3b1d800ab2afeb26d4f5e32f`. ORB-155 removes them. The planner, the `test` deliverable, `TaskRunInstructions`, `TaskJevOutcome`, and `resources/tasks/run` are already gone. `resources/tasks/turn` is Python and does not require PHP. The base-run dependency copy is also still on main, and ORB-155 keeps it; see [Dependency directories on a base run](#dependency-directories-on-a-base-run).

- `apps/gateway/app/Domain/Tasks/TaskSettlingFixup.php:23-33` holds the Orbit CI table. `reproduces()` at lines 95-97 is true only for slug `orbit`. `deliverables()` at lines 103-120 always adds a `composer-check` command that runs `composer check`, and adds `reproduce-check` for a matching Orbit job.
- `apps/gateway/app/Domain/Tasks/InstanceProvisionIntent.php:31-33` makes `visitableFor()` false only when the slug is `orbit`. `apps/gateway/app/Domain/Tasks/TaskWorkspaceLifecycle.php:23` uses that result.
- `apps/gateway/app/Infrastructure/Tasks/RemoteTaskBridgeWorktreeRemover.php:26` `remove()` deletes the task bridge from the primary checkout. The script at line 76 cites ADR 0135.
- `apps/gateway/app/Domain/Tasks/TaskScheduler.php:856` adds the `check_script` rubric item. `runsComposerCheck()` at lines 2944-2946 and `definesComposerCheckScript()` in `RemoteTaskWorkspaceStateReader.php:32`, `TaskWorkspaceStateReader.php:15`, and `NullTaskWorkspaceStateReader.php:21` inspect `composer.json`. `startBaseline()` at lines 2975-2987 appends Composer and JavaScript install steps inferred from the check text. Lines 2922-2923 and `checkOutputShowsMissingDependencies()` at line 2949 classify those installs and missing dependency output.
- `apps/gateway/app/Domain/Projects/ProjectType.php:29-34` `defaultTaskCheck()` returns `composer check` for `laravel-app` and `laravel-package`. `apps/gateway/app/Data/Projects/CreateProjectData.php:28` uses that default when the caller omits `task_check`.
- `apps/gateway/app/Domain/Tasks/TaskPullRequestDescription.php:17` defaults the named check to `composer check`. `TaskTurnInstructions` and `TaskRubricReminder` do not. They name only the command they are given.
- `apps/gateway/resources/tasks/check` lines 12, 85, and `run()` at line 407 fall back to `composer check` when no command is supplied. An empty command file runs no command, as line 11 says. The script no longer parses Pest or JUnit.

### Dependency directories on a base run

ORB-155 does not change `copy_dependencies()` in `apps/gateway/resources/tasks/check` at lines 251-264. The base archive receives installed `vendor` and `node_modules` directories only. Other installed trees, such as a Python virtualenv or a Rust `target` directory, stay out.

A `fails_on_base` command that needs one of those trees can exit nonzero because the dependency is missing, and that exit falsely satisfies the deliverable. A follow-up must copy the dependency directories the Project uses, or must refuse to treat a missing-dependency failure as reproduction evidence. Until that follow-up, a Project author cannot rely on `fails_on_base` for a command whose dependencies live outside `vendor` and `node_modules`.

## Rejected alternatives

- Keep behavior for Orbit selected by the Project slug: that preserves the coupling and creates a second implicit task policy in the Gateway.
- Retain planner compatibility for existing tasks: the operator requires removal, not a legacy path, and external ADEs own planning.
- Make the Gateway infer Project policy from repository files: policy belongs to the Project's own skill and deterministic task check, not hidden Gateway interpretation.

## Consequences

- Any Project can opt into generic Tasks without adopting Orbit's language, toolchain, or workflow.
- Each Project keeps its policy in its own repository. It may configure one task check; without one, Orbit still verifies the tree and deliverables.
- Removing planner state and behavior is a breaking change; stored planner fields and tasks have no compatibility behavior.
- The test-deliverable replacement is on main. ORB-155 replaces the remaining slug and toolchain checks in the leak list with the configured Project check, explicit workspace routing, and Project teardown. The base run still copies only `vendor` and `node_modules`; a follow-up has to widen that copy or stop counting a missing dependency as reproduction evidence.
- Orbit-specific cleanup, including bridge worktrees, must happen in Orbit's own teardown steps.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: [0122](/reference/tasks#prepare-a-task-in-backlog), [0124](/reference/tasks#prepare-a-task-in-backlog), [0125](/reference/tasks#project-check), [0133](/reference/tasks#deliverables), [0135](/reference/incus-topologies#task-workspace-clones), [0163](/reference/tasks#prove-a-command-fails-on-the-start-commit), [0164](/reference/tasks#fix-a-settling-pull-request)
- Detail: [Tasks reference](/reference/tasks), [Tasks CLI](/cli/tasks), [Orbit task policy](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md)
- Verify: `composer docs-lint`; the implementing tasks verify each Project's task check and generic deliverables
