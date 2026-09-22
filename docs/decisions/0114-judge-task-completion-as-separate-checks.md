---
title: "ADR 0114: Judge task completion as separate checks"
sidebarTitle: "0114 Separate completion checks"
description: "Proposed. Judge a stopped task thread with one rubric. Jev answers transcript questions. Git and GitHub answer commit and pull-request checks. One reminder lists every failure, and the next idle pass asks for assistance if any check still fails."
---

# ADR 0114: Judge task completion as separate checks

The Gateway judges a stopped task thread with a rubric. Each item passes or fails on its own. Jev answers questions about the transcript. Git and GitHub answer the commit and pull-request checks. One reminder names every failed item. The next time that thread is idle, any item that still fails asks for assistance.

## Status

Proposed.

This amends the single outcome Choice in [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev) and the composer-check evidence check in [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review). It also removes `ready_for_review` as a required comment. `changes_requested`, `approved`, `assistance_requested`, and `resolution` stay comments. The reviewer's ownership of the commit and pull request, the one-reminder allowance, and assistance history stay.

## Context

[ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) requires a passing `composer check` and a `ready_for_review` comment before review, and a verified commit and pull request before an approval advances. The scheduler matches `composer check` plus a passing phrase in recent tool output, and otherwise asks Jev for one of three outcomes: `completed_successfully`, `changes_requested`, or `assistance_required`. One confidence covers that bundle. A missing comment, a stale check, a dirty tree, and a blocked agent then share one assistance reason.

The observation keeps the last five messages and the tool activity from the oldest of those messages onward. Each entry keeps the last 2000 characters of its text. A `Working` thread suppresses the observation for that tick. `Idle` and `Done` are the states in which the thread can receive a follow-up.

## Decision

Evaluate the implementer rubric when the task is `running` and the implementer thread is `Idle`, `Done`, or `AskingForInput`. Evaluate the reviewer rubric when the task is `reviewing` and the reviewer thread is `Idle`, `Done`, or `AskingForInput`. The observation must be available. If any attached thread cannot be observed, the tick uses the observation grace period before evaluating a rubric or accepting a comment. Cached thread state cannot advance a task. A `Working` thread defers the task. A thread in `Failed` asks for assistance from that runtime state on that tick and does not receive the rubric reminder.

`AskingForInput` fails the code item `waiting_for_input`. That item joins the same reminder as the other failures. The second pass waits until that pending input has cleared or the reminder has started a turn that has since stopped. Ticks that still show the same pending input do not escalate. If the driver refuses the reminder, the tick records the refusal and uses the existing communication-failure path.

A Jev item passes only when Jev selects the passing choice at a confidence greater than or equal to `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` (default `0.75`). The threshold applies to each question. A code item passes or fails on the fact itself. A confident failing choice and a passing choice below the threshold are both failures. The Gateway asks the transcript questions in one Classification call. A missing answer, a missing TypeSafe key, or a classification error follows the existing communication-failure path. That path does not send the rubric reminder and does not treat the missing answer as a pass.

A successful classification does not clear a failed-send counter. When the rubric requires a message, successful delivery clears that counter.

### Implementer

Use this rubric while a `running` task's implementer is `Idle`, `Done`, or `AskingForInput`:

| Item | Instrument | Pass |
| --- | --- | --- |
| `check_invoked` | Code, from a tool activity whose text names `composer check` | The activity is present |
| `check_passed` | Code, from the `exit code` on that activity | The exit code is 0 |
| `check_current` | Code, from tool activity after that run | No edit, write, or patch activity follows it |
| `blocked` | Jev yes/no over the thread | `no` at or above the threshold |
| `waiting_for_input` | Runtime pending-input state | No pending input |

`check_invoked` passes from a tool activity. An assistant message does not pass it. A run with no `exit code` fails `check_passed`. When `check_invoked` fails, `check_passed` and `check_current` fail with it. The activities are the ones already in the observation window. A `composer check` that has scrolled out of that window fails `check_invoked`, and the reminder asks for a new run. Jev is not asked these three questions. When every item passes, the Gateway sets the task status to `reviewing` and sends `please review` to the reviewer thread. If that send fails, the next tick sends it again before the reviewer is asked for an outcome comment. The Gateway observes the shared reviewer before the handoff and records its current turn. While the reviewer thread is still idle, or its snapshot is still the turn recorded at the handoff, the Gateway waits. A missing current turn ID does not prove that a newer turn stopped. It asks for an outcome only after a newer review turn stops. A pending input skips the blocked question. A thread state the rubric does not recognize waits without a model call. The agent does not post a `ready_for_review` comment, and a posted comment of that type is not a gate.

Task status stays the current phase: `running`, `reviewing`, or `completed`. These comments stay events, and each accepted event updates status or the assistance flag:

| Comment | When it is accepted | What the Gateway updates |
| --- | --- | --- |
| `changes_requested` | The reviewer is idle, done, or asking for input, and the comment belongs to the current review attempt | The comment body is relayed to the implementer. Status returns to `running` only after that send succeeds. A failed send stays in `reviewing` and is retried |
| `approved` | The reviewer code checks pass | The subtask advances |
| `assistance_requested` | Immediately on post | The assistance flag and reason, while status stays `running` or `reviewing` |
| `resolution` | Immediately on post, with a non-empty body | Clears the assistance flag, starts a new attempt, and continues the thread. Status stays in progress |

After findings are delivered, the Gateway records the implementer turn and the latest check activity. A newer implementer turn must stop and a new check must pass before another review. Resolving assistance keeps that evidence requirement. Earlier comments remain stored. The next assistance or review cycle keeps those comments.

### Reviewer

A `changes_requested` comment for the current review attempt relays the full comment body and starts the next implementation attempt. The rubric is not applied.

An `approved` comment uses these code checks:

| Item | Pass |
| --- | --- |
| `commit_sha` | Equals the workspace HEAD |
| Branch | The workspace branch is `task-{group id}` |
| Working tree | Clean |
| New commit | The commit is after the subtask start commit |
| Pull request | On the final subtask, the pull request verifies for that commit |

When every code item passes, the Gateway accepts the review. Jev is not asked on that path. When the outcome comment is missing, that item fails, and Jev answers only whether the reviewer is blocked. `waiting_for_input` applies to the reviewer thread the same way it applies to the implementer. The composer-check questions belong to the implementer.

### Reminder and the second pass

The Gateway writes one reminder that names every failing item in words the agent can act on. Jev does not write that reminder. The implementer receives one such reminder per completion attempt. The reviewer receives one per review attempt. The existing attempt counters own that allowance. A new attempt resets it.

The second pass is the next rubric evaluation after that reminder, once the same thread is `Idle` or `Done` again, or once `AskingForInput` has cleared. Ticks while the thread is `Working`, and ticks that still show the same pending input, do not count as the second pass and do not ask for assistance. If any item fails on the second pass, the task asks for assistance. The reason names each remaining item. A Jev item includes its choice and confidence. The remaining set may differ from the set in the reminder.

## Rejected alternatives

- Keep one bundled Jev outcome: one confidence cannot name which fact failed, so the reminder and the assistance reason stay vague.
- Ask Jev whether the commit or pull request is valid: git and GitHub already determine those facts. A probability cannot override them.
- Escalate on the first failure: the agent can repair a named list of failures in one turn.
- Send a separate reminder for each failed item: one stop would start several turns.
- Skip the reminder while the thread is `AskingForInput`: a pending question is one of the failures the reminder lists. A `Working` or `Failed` thread still does not receive that reminder.
- Widen the transcript until an older `composer check` stays visible: a check outside the current window fails closed, and the reminder asks for a new run.
- Require a `ready_for_review` comment before `reviewing`: the transcript checks are the implementer's claim, and the status change is the Gateway's acceptance of that claim.
- Replace `changes_requested`, `approved`, `assistance_requested`, and `resolution` with a status write: the findings, commit SHA, pull request URL, and earlier assistance cycles need a stored event. Status holds one current phase.
- Relay reviewer findings inferred from prose: [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) requires the `changes_requested` comment before findings are delivered.

## Consequences

- An eligible stopped implementer causes one Jev call with the blocked question until the task leaves implementation.
- A reviewer causes a Jev call only when its outcome comment for the current attempt is missing.
- Assistance reasons name the failed checks, which makes repeated blockers comparable.
- A long thread can fail `check_invoked` after a real run has left the observation window. The repair is a new run.
- Scheduler tests that fake the single outcome Choice need to fake these questions instead. The text match on `composer check` and a passing phrase stops being the gate.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev), [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review)
- Detail: [Tasks](/reference/tasks)
- Verify: `TaskSchedulerTickTest`, `LaravelAiTaskSessionClassifierTest`, and `composer check` in `apps/gateway`
