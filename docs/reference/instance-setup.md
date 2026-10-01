---
title: "Instance setup and teardown"
description: "How a Project stores named setup and teardown commands, and when Orbit runs them for a development Instance."
covers:
  - apps/gateway/app/Domain/Projects/{LifecyclePhase,LifecycleStep,ProjectLifecycleRunner,ProjectLifecycleStepStore}.php
  - apps/gateway/app/Actions/*/{Create*InstanceAction,RegisterInstanceAction,RunInstanceSetupAction}.php
  - apps/gateway/app/Infrastructure/{*/NativeDevelopment*Provisioner,Instances/{RemoteDevelopmentInstanceConfigurator,RemoteDevelopmentInstanceSourceLifecycle,RemoteRegistrationSourceManager,RemoteInstanceDestinationGuard},AppDev/DevelopmentSshExecutor,AppProd/ProductionSshExecutor}.php
  - apps/gateway/app/Domain/Instances/{DevelopmentInstanceProvisioner,InstanceSourceProfileGuard}.php
  - apps/gateway/app/Http/Controllers/Api/ProjectLifecycleStepsController.php
  - apps/gateway/app/Models/ProjectLifecycleStep.php
  - apps/gateway/resources/instances/lifecycle.py
  - apps/cli/app/Commands/Instances/{SetupInstanceCommand,*SetupStepCommand,*TeardownStepCommand}.php
---

# Instance setup and teardown

A Project stores two ordered lists of named commands: setup steps and teardown steps. Orbit runs the setup list when it creates a development Instance, and the teardown list before it removes one. Production Instances run neither list. They use [deploy steps](/reference/deployments#deploy-steps).

Each step is one database row with a name, a command string, a timeout, and a position. Orbit writes no script into the checkout. The lists belong to the Project, and no Instance keeps a copy. The next run uses the lists as they are at that moment.

## Record a step

Record the steps on the Project before you create an Instance. `--project` selects the Project by its numeric ID.

```bash
orbit instance:setup-step:create install-php --project=4 --command='composer install --no-interaction'
orbit instance:setup-step:create install-js --project=4 --command='vp install' --after=install-php
orbit instance:teardown-step:create drop-sqlite --project=4 --command='rm -f database/database.sqlite'
```

| Command | Result |
| --- | --- |
| `instance:setup-step:create NAME --project=ID --command=COMMAND` | Add a setup step at the end, or at `--before=NAME` or `--after=NAME`. |
| `instance:setup-step:list --project=ID` | List the setup steps in order. |
| `instance:setup-step:update NAME --project=ID` | Change `--command`, `--timeout`, or the position. |
| `instance:setup-step:destroy NAME --project=ID` | Remove a setup step. The others keep their order. |
| `instance:teardown-step:*` | The same four commands for the teardown list. |

`destroy` asks for confirmation with No selected. `--yes` confirms without a prompt and is required for JSON and noninteractive calls.

The Gateway checks each change against these limits and stores nothing when one fails.

| Field | Rule |
| --- | --- |
| `name` | 1 to 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. Unique within the list. |
| `command` | Nonempty UTF-8, at most 16 KiB, no NUL byte. |
| `timeout_seconds` | 1 to 540. The default is 240. |
| `before`, `after` | The name of a step in the same list. Use at most one. |

A list holds at most 32 steps. The timeouts of one list add up to at most 540 seconds, so a whole list fits in one API request. The Gateway stores a step only when its timeout is inside that limit, and a later read returns the stored timeout.

Authorized reads return the commands. [Activity](/cli/activity) records no input for the step commands and `instance:setup`, so it never holds command text or command output.

## Run setup

`instance:create` runs the setup list after the Instance and its Route are active. Activation records `failed_step: setup` in the same transaction, so a Gateway interruption before or during setup cannot make an identical create retry report success without setup. Orbit clears the marker only after setup completes.

Each command runs with `bash -eu` in the checkout, on the Instance's Node, as the Node's managed user. Commands read no input, and Orbit discards their output. When a step ends, for any reason, Orbit kills its process group, so background processes do not survive the step. Each run holds a lifecycle lock on the Instance. If another operation holds that lock during `instance:create`, Orbit keeps the active Instance and records `error_code: instance.lifecycle_busy`.

An identical create retry then reports that setup must run; use `instance:setup` to retry the list. A busy lock never removes the Instance.

The first command that exits non-zero or times out stops the list. Then Orbit rolls back the new Instance:

1. It runs every teardown step.
2. It removes the Instance with forced removal, which also deletes a dirty checkout.
3. It returns `instance.setup_step_failed` with the failed setup step. A failed teardown step is named too.

A teardown failure during this rollback does not keep the Instance. Rollback never removes another Instance.

Orbit keeps the Instance, with its setup marked failed, in three cases:

| Case | Result |
| --- | --- |
| Orbit cannot confirm the setup step's outcome, for example after a lost SSH connection. | No rollback runs. The error is the step's own `instance.setup_step_failed` with `outcome: unconfirmed`. |
| Orbit cannot confirm a teardown step's outcome. | The error adds `cleanup: unconfirmed`. |
| The removal starts but does not finish. | The error adds `cleanup: incomplete` and names `orbit instance:destroy <id> --force`. |

Inspect the Instance before you retry.

`instance:register` runs no setup. `instance:register --setup` runs the setup list after adoption. `instance:setup` runs the list again on an active development Instance. Both keep the Instance when a command fails and return `instance.setup_step_failed`. Every run starts at the first step.

```bash
orbit instance:setup <instance>
```

`instance:clone` runs neither list. `instance:create --from` runs the setup list after the [copy](/reference/instance-copies) is in place, on the same path as a repository create. Installed dependencies make the matching commands cheap. A failed step still rolls the new Instance back.

## Deadlines

One API request runs a whole list. The request's remote work ends after 570 seconds, and its forward work stops 20 seconds earlier to leave time for cleanup. Each step's timeout is cut to the time that remains.

`instance:create` keeps 150 seconds back from its setup list for rollback: up to 60 seconds for the teardown list and 90 seconds for the removal. `instance:setup` and `instance:register --setup` roll nothing back, so their setup list can use the whole request.

A step that the request deadline stops, or that has no time left to start, is not a failed command. The request returns `command.deadline_exceeded` (HTTP 504) with `outcome: deadline` and the step name. `instance:create` still rolls back and keeps that code. Lower the step timeouts until the list fits.

## Run teardown

`instance:destroy` of a development Instance runs the teardown list after the [removal checks](/reference/instance-removal) accept the source. Then Orbit checks the source again and deletes the Route, the source, and the record. In a forced removal of a checkout with worktrees, each member runs its own teardown list.

Teardown may delete ignored files. It must keep the checkout, its Git identity, and its worktrees. When teardown changes tracked files, normal removal refuses. Retry with `--force` to discard them.

The first teardown command that exits non-zero or times out stops the removal. The Route, source, and record stay, and the command returns `instance.teardown_step_failed` with the step name. Fix or destroy the step, then run `instance:destroy` again.

## Bootstrap the Orbit repository

The Orbit Project can record `bin/bootstrap` as a setup step, or record its locked dependency installs as separate steps. It installs the locked dependencies, seeds caches, and runs the checks in all five Composer projects. A cold bootstrap can exceed the request deadline.

```bash
orbit instance:setup-step:create bootstrap --project=PROJECT_ID --command='bin/bootstrap' --timeout=540
```

Task workspaces are not created with `instance:create`, so this create-time run does not happen for them. The task baseline check runs the Project setup steps before the task check instead. See [Project check](/reference/tasks#project-check) and [Implementation loop](/reference/implementation-loop).

## Configure Orbit's task policy

Orbit's task check is explicitly `composer check`; new Project defaults do not supply it. Record setup steps for each dependency tree that this repository check needs. The baseline runs only those steps. The live Orbit Project is id 46. Re-read it before every deployment, and stop if the id, check, or lists differ from the handoff.

### Preflight

These reads change nothing. Run them against the Gateway that will receive the deployment.

```bash
orbit project:show 46 --json
orbit instance:setup-step:list --project=46 --json
orbit instance:teardown-step:list --project=46 --json
orbit node:list --json
orbit project:excluded-node:list --project=46 --json
orbit node:excluded-project:list --node=NODE --json
```

Replace `NODE` with each candidate. An eligible Node is active, has the `app-dev` role, is not in the Project exclusion list, and does not exclude Project 46. Install the helper on every eligible Node, including one that has no task checkout yet. A later workspace can land there.

Confirm `task_check` is `composer check`. Confirm each stored `timeout_seconds` is an integer from 1 to 540. A new list totals at most 540 seconds. A list stored before that cap may total more, and a later change may not raise its total.

The task baseline runs each setup step with that step's own timeout, outside the API request deadline. `instance:create`, `instance:setup`, and `instance:destroy` run one whole list inside one request. Remote work ends at 570 seconds, and forward work stops 20 seconds earlier. `instance:create` holds 150 seconds back: 60 for teardown and 90 for removal.

A setup list whose timeouts cannot fit is cut with `command.deadline_exceeded`. Lower those timeouts before relying on `instance:create` or `instance:setup` for this Project. Do not raise the total.

### Install the helper

Install the reviewed `bin/e2e-task-cleanup` as `$HOME/.local/lib/orbit/e2e-task-cleanup` for the managed user on every eligible Node. Copy that blob from the reviewed commit. Do not copy it from an old checkout: clones created before this change do not contain the file. The command runs with `bash -eu` in the checkout, as the Node's managed user, so `$HOME` is that user's home.

```bash
rev=REVIEWED_SHA
git show "$rev:bin/e2e-task-cleanup" | ssh MANAGED_USER@NODE 'install -d -m 0755 -- "$HOME/.local/lib/orbit" && cat > "$HOME/.local/lib/orbit/e2e-task-cleanup.new" && install -m 0755 "$HOME/.local/lib/orbit/e2e-task-cleanup.new" "$HOME/.local/lib/orbit/e2e-task-cleanup" && rm -f "$HOME/.local/lib/orbit/e2e-task-cleanup.new"'
ssh MANAGED_USER@NODE 'sha256sum "$HOME/.local/lib/orbit/e2e-task-cleanup"'
git show "$rev:bin/e2e-task-cleanup" | sha256sum
```

The two digests must match. A missing file, a different digest, or a command that names a missing file is not a completed handoff. Keep the old Gateway until they match on every eligible Node.

The helper returns success without changing anything for an ordinary checkout. For a task checkout it removes only that task's matching bridge, unused bridge branch, and staging ref. It preserves the checkout and its own Git identity. See [Task workspace clones](/reference/incus-topologies#task-workspace-clones) for ownership and retry rules. Clones created before deployment use this installed copy too. The helper and the old Gateway hook may coexist during the handoff because both are idempotent.

### Record teardown

Record the step only after the helper digest matches. The Incus proof ran the create form. It stores the default timeout of 240 seconds. That is inside the 1 to 540 limit, and it is the whole teardown list, so the list total fits. `instance:create` rollback still has only 60 seconds for teardown; the runner cuts the step to the time that remains. The helper is a short Git operation. This command was not run against the live Project.

```bash
orbit instance:teardown-step:create task-e2e-bridge --project=46 --command='"$HOME/.local/lib/orbit/e2e-task-cleanup"' --json
```

When that name already exists, update it instead. The update form stores the same command and an explicit 240 second timeout. It was not part of the Incus proof.

```bash
orbit instance:teardown-step:update task-e2e-bridge --project=46 --command='"$HOME/.local/lib/orbit/e2e-task-cleanup"' --timeout=240 --json
```

Read the list again. The stored name is `task-e2e-bridge`, the command is `"$HOME/.local/lib/orbit/e2e-task-cleanup"`, and `timeout_seconds` is 240. Do not deploy the Gateway release that deletes this hook until that read matches. Do not claim the live step is ready from an unread or untested command.

### After deployment

Verify the Orbit Project's explicit check and install steps on a cold fixture, and verify teardown on a task fixture, including a checkout that has no `bin/e2e-task-cleanup` of its own. Retry a retained failed-removal fixture and confirm its bridge and checkout are removed in order. Release its topology before removal. Keep CI, merge approval, and this deployment handoff as separate gates. Do not change live Project configuration as part of task planning.

### Rollback

If the new Gateway is not deployed yet, remove the step and then the installed file. The old hook remains.

```bash
orbit instance:teardown-step:destroy task-e2e-bridge --project=46 --yes --json
ssh MANAGED_USER@NODE 'rm -f -- "$HOME/.local/lib/orbit/e2e-task-cleanup"'
```

If the new Gateway is already deployed, keep the helper and the step until the previous Gateway is restored. The new Gateway has no built-in bridge hook, so removing them leaves task bridges behind. Restore the previous Gateway first, re-read the teardown list, and only then destroy the step and delete the file.

## Failure codes

These codes name the step that failed. The sections above say whether the Instance stays.

| Code | Cause |
| --- | --- |
| `instance.setup_step_failed` | A setup command failed or timed out. |
| `instance.teardown_step_failed` | A teardown command failed or timed out during `instance:destroy`. |
| `instance.setup_unavailable` | `instance:setup` targets an Instance that is not an active development Instance. |
| `command.deadline_exceeded` | The request deadline stopped a step. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Named rows, not a script

Each command is its own row, so a failure names one step and you can reorder steps without rewriting a script. One script body and a path in the repository were rejected: the failure report could name no step, and the operator records the command on the Project.

### Lists on the Project

The commands run for every Instance of a repository, so they live on the Project. Per-Instance copies were rejected because they would drift from what the operator recorded. The command family is `instance:` because the commands run for an Instance.

### A failed setup removes the new Instance

A half-set-up Instance is not a useful result of `instance:create`. So a confirmed failure tears it down and removes it, and the next create starts clean. Resuming a create after a failed setup was rejected.

### Registration skips setup by default

Registration adopts a checkout that is usually set up already. Running setup on every registration was rejected. `--setup` runs it on request.

### Teardown failure stops removal

Teardown is the operator's cleanup. Removal continues only after that cleanup succeeds.

### The Project removes its own bridge

Orbit's task bridge is this repository's cleanup, so the Orbit Project runs `bin/e2e-task-cleanup` as a teardown step. A Gateway hook for that bridge was rejected, because another Project would inherit Orbit's worktree layout. The installed helper, not the Gateway, performs the ownership checks in [Task workspace clones](/reference/incus-topologies#task-workspace-clones).
