---
title: "ADR 0132: Pause a task only for its acting thread or a real question"
sidebarTitle: "0132 Acting thread and blocked question"
description: "Proposed. Only the thread that acts in a task's phase defers the task while it works. The Gateway sends no turn to a working thread and sends it once the thread stops. A blocked run receipt must ask the operator one specific question."
---

# ADR 0132: Pause a task only for its acting thread or a real question

Only the thread that acts in a task's phase defers that task while it works. The other thread's work does not hide a finished agent. The Gateway never sends a turn to a working thread; it sends the turn on the first tick after the thread stops. A `blocked` run receipt must ask the operator one specific question.

## Status

Proposed.

This amends the rule in [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev) and [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) that any working thread defers a task. It also amends the `blocked` outcome of the run receipt in [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt). The rest of those records stays.

## Context

Task group 58 stalled with a finished implementer. The operator was talking to the group's shared reviewer, which was the planner thread under [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner). That thread was `working`. Meanwhile, the Pi implementer of subtask 1 ended its first turn after 34 seconds with a `blocked` receipt.

The observer dropped every thread of a task when any of them was working. The shared reviewer belongs to every task, so each 10-second tick skipped subtask 1. The receipt stayed unread. No comment was stored, and no assistance was requested, for as long as the reviewer was busy.

The receipt itself was not useful either. Its summary was "Gateway implementation not completed; required project guidance/bootstrap review and implementation remain." It asked the operator nothing. A `blocked` receipt pauses the whole group, so a vague one costs the operator a round trip to find out what the agent needs.

The old rule did protect two things. No turn went to a thread that was busy with another turn. Orbit never committed the workspace while an agent was changing it.

## Decision

The Gateway owns all three rules. The observer decides which thread's work defers a task. Each scheduler send checks its target's state. The run script and the receipt parser enforce the question.

### Only the acting thread defers a task

The acting thread is the task's implementer while the task is `running`, and the group's reviewer while the task is `reviewing`. While that thread is `working`, the observation of the task is empty and the tick skips the task, as before.

The other thread is observed, but its work does not defer the task. While the reviewer works, the tick still reads the implementer's receipt, stores it, asks for assistance on `blocked`, runs the handoff check, and reminds the implementer. While the implementer works, the tick still reads the reviewer's receipt and reminds the reviewer.

### No turn for a working thread

Every send that the scheduler makes checks the state of its target in the same tick's observation.

- **Review request:** when the handoff check passes, the task moves to `reviewing` as before. If the reviewer is working, the Gateway does not send the request and does not install the reviewer's run script. The review attempt stays unnotified. The task in review now waits for the reviewer, because the reviewer is its acting thread. Once the reviewer stops, the tick sends the request through the same retry path that already retries a failed send. The request is sent once per review attempt.
- **Review findings:** while the implementer works, the `changes_requested` comment stays unhandled, and the task stays in `reviewing`. The first tick after the implementer stops relays the findings.
- **Approval commit:** Orbit commits the whole workspace. While the implementer works, the approval waits, so the commit never contains half-made changes.
- **Reminders:** a reminder goes only to the acting thread, after it has stopped.

### A blocked receipt asks a question

The run script requires `--question` with `--outcome=blocked`:

```bash
.git/orbit/run --outcome=blocked --summary="What stops you" --question="One specific question the operator can answer"
```

Without a question, the script refuses the receipt. Its error tells the agent to ask one specific question, or to keep working when it can decide or find the answer itself. The script refuses `--question` with any other outcome. The receipt stores the question. The Gateway stores the summary and the question in the comment body. The assistance reason quotes that body, so the operator sees the question.

The Gateway treats a `blocked` receipt without a question as invalid. It fails the `run_receipt` item, which gets one reminder and then asks for assistance. The implementer and reviewer instructions say that `blocked` pauses the group and needs a question.

## Rejected alternatives

- Keep the task in `running` until the reviewer is idle: the task would stay in the implementer's phase after its handoff passed. The Gateway would have to repeat or hold the check result. Moving the task and deferring only the send reuses the existing retry path.
- Send the request to a working reviewer and let the runtime queue it: drivers differ in how they handle a turn during a turn. The request would also land in the middle of the operator's conversation.
- Exclude only the shared reviewer from the old rule while a task runs: this fixes group 58 but not the reverse case, where a working implementer hides a finished reviewer. The send guard is still needed.
- Ask Jev whether a `blocked` summary is specific enough: this adds a model call. [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) removed Jev's `blocked` question on purpose.
- Store the question in its own comment field: every API, SDK, and CLI surface would need a new field for one line of text. The comment body already reaches all of them.

## Consequences

- The operator can talk to the reviewer or the planner without stalling the running subtask.
- A review request can wait as long as the operator talks to the reviewer. During that time the task shows `reviewing`, but no review has started.
- A thread can start a turn between the observation and the send in the same tick. The Gateway cannot prevent that race; the driver then handles a turn sent during a turn.
- Operator resolution comments are not scheduler sends. They still go to the thread at once.
- A `blocked` receipt without a question does not pause the group at once. The agent first gets one reminder. Orbit keeps no support for the old form.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev), [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt)
- Detail: [Tasks](/reference/tasks)
- Verify: observer tests for the acting thread in each phase; scheduler tests for a `blocked` receipt while the reviewer works, a working implementer, a deferred review request, a deferred relay, and a deferred approval commit; run script and receipt tests for the question
