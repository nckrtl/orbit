---
title: "ADR 0125: Run the Project check when the implementer hands off"
sidebarTitle: "0125 Run the Project check at handoff"
description: "Proposed. When an implementer ends its turn with ready_for_review, Orbit runs the Project's composer check in the workspace itself. The check runs detached on the Node, and the scheduler reads its state from the process, not from a timer. The reviewer starts only after the check passes."
---

# ADR 0125: Run the Project check when the implementer hands off

When an implementer ends its turn with `ready_for_review`, Orbit runs the Project's `composer check` in the task workspace itself. The check runs as a detached process on the Node. Each scheduler tick reads the process state and, when the process ends, its result. The reviewer starts only after the check passes. Orbit no longer reads check results from the agent's transcript.

## Status

Proposed.

This amends [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) and [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt). The `check_script` item stays. The transcript items `check_invoked`, `check_passed`, and `check_current` go away.

## Context

[ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) lets mechanical checks decide when an implementer's work moves to review. Today that check is the transcript: the scheduler looks for a `composer check` command in the agent's tool output, with exit code 0 and no edit after it. That evidence depends on how each driver reports tool output. An agent can also run the command in another directory or with other arguments.

A pilot in #591 ran the checks itself, but the implementer called a Gateway action to start it, which ADR 0121 rules out. It also ran a fixed profile of Orbit projects only, on a copy of the workspace, in one blocking SSH call.

In Orbit, root `composer check` runs `bin/review-check`, which refuses a tree with uncommitted changes. Under ADR 0121, work stays uncommitted until the reviewer approves.

## Decision

The scheduler starts the Project's own check when the implementer hands off. The check runs detached, and the process state tells the scheduler whether it still runs. No timer ends a check.

### When the check runs

After a stored `ready_for_review` receipt, the scheduler first applies the items that need no run: `check_script`, `waiting_for_input`, and the receipt itself. When they pass, it starts the check. The task stays `running` while the check runs, and the implementer is idle. The reviewer starts only after the check passes, so a reviewer never sees failing work.

### How the check runs

The Gateway installs `.git/orbit/check` next to `.git/orbit/run`. Over SSH it starts the script as a detached process group and records the process ID, the HEAD, and the tree before the run. The tree is the hash of the whole working tree, including uncommitted and untracked files, taken without touching the Git index.

The script runs `composer check` in the workspace root. It writes the output to `.git/orbit/check.log`. When the command ends, it writes `.git/orbit/check.json` with the exit code, the HEAD and tree before and after, and the start and end times.

The check uses the Project's own `check` script, so it works for every Project. Nobody edits the tree during the check, because the implementer has handed off and the reviewer has not started. The check does not copy the workspace.

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
- A fixed profile of Orbit projects, as in #591: it works for Orbit only. Root `composer check` already covers every Orbit project.
- A copy of the workspace for the check: nobody edits during the check. Comparing the tree before and after catches an edit.
- Starting the check again after every tree change: a check that writes a file Git does not ignore would run forever.
- A systemd unit per check: a unit on a Node needs a new sudo rule. A detached process group with its ID and start time gives the same state.
- A temporary commit before the check: work is committed only after approval.

## Consequences

- One check decides for every driver, because it does not depend on tool output.
- A handoff takes as long as the Project's check. The implementer is idle during that time.
- A Project without a `check` script cannot pass, as today.
- Orbit's `composer check` runs on uncommitted work, and its report says so.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt)
- Detail: [Tasks](/reference/tasks), and `bin/review-check` for uncommitted work
- Verify: scheduler tests for each check state, the lost-check restart and assistance, cancellation, and the changed-tree rerun; a test that runs the real check script against a local Git checkout; a `bin/review-check` test on an uncommitted tree; and an Incus run where Orbit's check gates the handoff
