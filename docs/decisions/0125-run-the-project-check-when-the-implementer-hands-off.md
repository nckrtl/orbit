---
title: "ADR 0125: Run the Project check when the implementer hands off"
sidebarTitle: "0125 Run the Project check at handoff"
description: "Proposed. When an implementer ends its turn with ready_for_review, Orbit runs the Project's task check in the workspace itself. The check runs detached on the Node, and the scheduler reads its state from the process, not from a timer. The reviewer starts only after the check passes."
---

# ADR 0125: Run the Project check when the implementer hands off

When an implementer ends its turn with `ready_for_review`, Orbit runs the Project's task check in the task workspace itself. The task check is a Project setting, `task_check`, with a default by Project type. The check runs as a detached process on the Node. Each scheduler tick reads the process state and, when the process ends, its result. The reviewer starts only after the check passes. Orbit no longer reads check results from the agent's transcript.

## Status

Proposed.

This amends [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) and [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt). The `check_script` item stays. The transcript items `check_invoked`, `check_passed`, and `check_current` go away.

## Context

[ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) lets mechanical checks decide when an implementer's work moves to review. Today that check is the transcript: the scheduler looks for a `composer check` command in the agent's tool output, with exit code 0 and no edit after it. That evidence depends on how each driver reports tool output. An agent can also run the command in another directory or with other arguments.

A pilot in #591 ran the checks itself, but the implementer called a Gateway action to start it, which ADR 0121 rules out. It also ran a fixed profile of Orbit projects only, on a copy of the workspace, in one blocking SSH call.

In Orbit, root `composer check` runs `bin/review-check`, which refuses a tree with uncommitted changes. Under ADR 0121, work stays uncommitted until the reviewer approves.

## Decision

The scheduler starts the Project's task check when the implementer hands off. The check runs detached, and the process state tells the scheduler whether it still runs. No timer ends a check.

### When the check runs

After a stored `ready_for_review` receipt, the scheduler first applies the items that need no run: `check_script`, `waiting_for_input`, and the receipt itself. When they pass, it starts the check. The task stays `running` while the check runs, and the implementer is idle. The reviewer starts only after the check passes, so a reviewer never sees failing work.

### How the check runs

The Gateway installs `.git/orbit/check` next to `.git/orbit/run`. Over SSH it starts the script as a detached process group and records the process ID, the HEAD, and the tree before the run. The tree is the hash of the whole working tree, including uncommitted and untracked files, taken without touching the Git index.

The script runs the task check in a login shell in the workspace root. It writes the output to `.git/orbit/check.log`. When the command ends, it writes `.git/orbit/check.json` with the exit code, the HEAD and tree before and after, and the start and end times.

Each Project names its own command, so the check works for every Project type. Nobody edits the tree during the check, because the implementer has handed off and the reviewer has not started. The check does not copy the workspace.

### What the scheduler reads

On each tick, for a task with a running check, the scheduler reads the check over SSH:

| State | What the scheduler does |
| --- | --- |
| The process runs | Nothing. The task shows the check and how long it has run. |
| `check.json` exists, exit code 0, tree unchanged | The check passes, and the reviewer starts. |
| `check.json` exists, exit code not 0 | The check fails. The implementer gets its one reminder with the end of `check.log`. |
| `check.json` exists, the tree changed during the run | The result does not count, and the scheduler starts the check again once. When the tree changes again, the check fails, and the reminder names the changed paths. |
| The process is gone without `check.json` | The check was lost, for example by a reboot. The scheduler starts it again once, then asks for assistance. |

The scheduler identifies the process by its ID and start time, so a reused process ID does not count as the check.

### The task check setting

Each Project stores the command in `task_check`. It is an ordinary Project setting, like setup steps: the Project API, `project:show`, and activity show it as stored. A new Project that sends no value gets the default of its type:

| Project type | Default task check |
| --- | --- |
| `laravel-app` | `composer check` |
| `laravel-package` | `composer check` |
| `monorepo` | none |
| `node-package` | none |

The type defaults apply to new Projects only. The upgrade sets `composer check` on every existing Project, whatever its type, because every Project runs that check today. An operator sets another command with `project:update --task-check=COMMAND`, or removes it with `project:update --clear-task-check`, for example on an existing `node-package` Project without a Composer `check` script. A type change keeps the stored value.

The baseline and the handoff use the same setting. When it is null, the baseline runs only the setup steps, and the handoff runs no command. The handoff still compares the trees and verifies the deliverables. The `check_script` item applies only when the task check runs `composer check`.

### Baseline check

Before the first implementer of a group starts, Orbit runs the task check on the fresh workspace. The detached check runs Project setup steps first. Orbit installs Composer dependencies when the command runs `composer` or references `vendor/`. It walks tracked `composer.json` files and runs `composer install --no-interaction --prefer-dist` where `vendor/autoload.php` is missing and either the file is at the repository root or a sibling lockfile exists. A root package without a lockfile is installed too, and Orbit then removes the `composer.lock` that the install wrote, so it cannot reach the task commit. Orbit skips nested manifests without a lockfile, because test fixtures use them. Orbit installs JavaScript dependencies when the command references a JavaScript runtime, package manager, Vite+, or `node_modules`. It walks tracked `package.json` files and runs `vp install --frozen-lockfile` where a supported lockfile exists and `node_modules` is missing. These guards skip projects whose dependencies are already installed. Project setup runs first so it can prepare credentials or configuration, and it may install dependencies itself.

An agent never starts until the baseline passes. A failed setup or dependency install asks for assistance and names the failed step. A check failure includes its exit code and real output. When the output identifies missing Composer or JavaScript dependencies, assistance says that Project dependencies appear to be missing instead of calling the branch broken. The operator fixes the setup, dependencies, command, or branch, then cancels and creates the group again. A group whose implementer already started skips the baseline.

### Cancel a check

An operator can cancel a running check through the Gateway API. The Gateway stops the process group and records the check as cancelled. The implementer then gets its reminder, which says that Orbit cancelled the check. A check has no time limit. A check that hangs stays visible with its run time until an operator cancels it.

### Records

The Gateway keeps one record per check run, linked to the `ready_for_review` receipt it checks. The record holds the state, the process ID, the HEAD and trees, the times, the exit code, and the end of the output. The task shows its latest check.

### Orbit's own check

`bin/review-check` checks a tree with uncommitted changes as it is. Its report records the working tree hash in place of the commit tree, and it marks the candidate as uncommitted. A clean candidate keeps today's behavior.

## Rejected alternatives

- A time limit on the check: a slow check is not a failed check. The process state shows exactly whether the check runs. A hung check stays visible, and an operator cancels it.
- Jev judges whether a check is stalled: the process state is exact, and mechanical state wins over a model's judgment.
- The implementer starts the check through a Gateway action: agents do not call the Gateway ([ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt)).
- A fixed profile of Orbit projects, as in #591: it works for Orbit only. A per-Project command covers every Project type.
- Always running `composer check`: a Node package or a monorepo without a Composer `check` script never hands off.
- Hiding the task check as a secret: it is a command, like a setup step. Secrets belong in the environment, not in the command.
- A copy of the workspace for the check: nobody edits during the check. Comparing the tree before and after catches an edit.
- Starting the check again after every tree change: a check that writes a file Git does not ignore would run forever.
- A systemd unit per check: a unit on a Node needs a new sudo rule. A detached process group with its ID and start time gives the same state.
- A temporary commit before the check: work is committed only after approval.

## Consequences

- One check decides for every driver, because it does not depend on tool output.
- A handoff takes as long as the Project's check. The implementer is idle during that time.
- A Project whose task check is `composer check` cannot pass without a Composer `check` script. A Project without a task check hands off after its deliverables pass.
- Existing Projects, Orbit itself included, keep `composer check` after the upgrade. An existing Project without a Composer `check` script does not hand off until an operator changes or clears its task check.
- A new `monorepo` or `node-package` Project has no task check until an operator sets one.
- Orbit's own `composer check` runs on uncommitted work, and its report says so.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type), [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt)
- Detail: [Tasks](/reference/tasks), [Projects](/reference/apps), and `bin/review-check` for uncommitted work
- Verify: tests for the task check defaults by type, the upgrade that sets `composer check` on every existing Project, and a null task check at baseline and handoff; scheduler tests for each check state, the lost-check restart and assistance, cancellation, and the changed-tree rerun; a test that runs the real check script against a local Git checkout; a `bin/review-check` test on an uncommitted tree; and an Incus run where Orbit's check gates the handoff
