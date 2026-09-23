---
title: "ADR 0117: Judge the blocked question on the role's own evidence"
sidebarTitle: "0117 Blocked question evidence"
description: "Proposed. Jev answers each blocked question from the asking role's thread and the task and group briefs. Rubric reminders do not claim a blocker, are left out of Jev's evidence, and are calibrated against real Jev on demand."
---

# ADR 0117: Judge the blocked question on the role's own evidence

Jev answers the implementer's blocked question from the implementer thread and the task and group briefs. It answers the reviewer's blocked question from the reviewer thread and the same briefs. Rubric reminders do not say that the thread is blocked and do not ask for an action the agent cannot take. Jev does not read reminder turns. A real-Jev calibration suite runs on demand and measures the threshold.

## Status

Proposed.

This amends the `blocked` rubric item and the reminder in [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks). The rubric items, the code checks, the one-reminder allowance, the second pass, and the `0.75` default threshold in that record stay.

## Context

A controlled run on an Incus topology used a task group with a Pi implementer and a T3 reviewer. Finished implementers with a passing `composer check` failed the `blocked` item. Three causes do not depend on the agent driver:

- The implementer observation always includes the shared reviewer thread. Jev received the whole observation and only a role name. The reviewer's text, such as a note that it waits for the subtask handoff, read as task state. The implementer scored `no` at 0.50 to 0.57 with the reviewer thread and at 0.75 to 0.83 without it.
- The reminder began with "The thread is blocked." and asked for an `assistance_requested` comment. The Gateway sent it as a user turn, so the next observation contained it and Jev read it as evidence. Agents have no tool to post that comment, and their honest reply said so, which also read as a blocker. Removing only the reminder turned `yes` into `no`.
- TypeSafe reports confidence as the margin between the chosen and the other probability. A `0.75` threshold therefore needs `P(no)` of at least `0.875`. Every test fakes Jev at 0.95 to 1.0, and no fixture measures real answers.

On coherent input the question discriminates well. Clean successes score `no` at 0.98 to 0.99. Genuine blockers, such as a missing tool or a denied `sudo`, score `yes` at 0.83 to 0.86.

## Decision

The Gateway owns the evidence Jev reads, the reminder text, and the calibration fixtures. The implementer and reviewer rubrics keep their items.

### Evidence

The Gateway sends Jev one evidence document for each blocked question. It contains the classification role, the group title and brief, the task title and brief, and the asking role's thread state and recent entries. The other role's thread is not sent. Pull request, commit, and CI fields are not sent, because code checks own those facts.

The observation window stays as [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) describes it. The code checks still read the full window.

### Reminder

Each rubric reminder starts with a fixed lead sentence and ends with a fixed closing. The failed code items sit between them. The [Tasks reference](/reference/tasks) quotes the exact text.

| Role | The lead states | The closing asks for |
| --- | --- | --- |
| Implementer | Orbit has not confirmed that the brief is complete | A short summary of the changes and the composer check result, or what outside the brief stops the work |
| Reviewer | Orbit has not confirmed that the review is complete | What outside the review stops the work |

A failed `blocked` item adds no sentence of its own. A pending input adds "The thread is waiting for input." and asks the agent to continue the current brief or review without it. A reminder never states that the thread is blocked. It does not ask the agent to post an `assistance_requested` comment. The reviewer's `outcome_comment` item still asks for the reviewer's outcome comment, because the review workflow requires it.

### Excluding reminders from evidence

Before Jev reads a thread, the Gateway removes user messages that start with a reminder lead sentence. The agent's reply to a reminder stays. Other Gateway messages stay, including the brief, the review handoff, and relayed findings, because they carry task content.

### Threshold and calibration

The default threshold stays `0.75`. It is a margin, so a passing `no` needs `P(no)` of at least `0.875`. Clean evidence scores `no` at 0.98 to 0.99, and genuine blockers choose `yes`, which fails at any threshold. The measured failures came from mixed evidence, not from a threshold set too high.

A calibration run on 2026-09-23 confirmed this with the new evidence. Both successes scored `no` at 1.00. The denied `sudo` scored `yes` at 0.96, and the missing tool scored `yes` at 0.59. The returned probabilities confirm the margin: the missing tool had `yes` at 0.79 and `no` at 0.21. The same success after the old reminder and the agent's reply about the missing comment tool scored `no` at 0.18 with the old input.

A calibration suite sends observations to real Jev through the production classifier: a clean success, a success after a reminder, a denied `sudo`, and a missing tool. Each observation includes the waiting reviewer thread. The suite asserts each expected choice and that each passing fixture clears the configured threshold. It prints the probabilities and the confidence for each fixture. It runs only on demand with `TYPESAFE_API_KEY` and is outside the default test suite and CI. Revisit the threshold when a calibration run places a passing fixture below it or a blocker above it.

A Jev answer without a confidence counts as a missing answer and uses the communication-failure path.

## Rejected alternatives

- Keep the reviewer thread and tell Jev which role asks: that is the current input, and it produced the measured mixed judgments.
- Reduce the reviewer thread to state fields for the implementer question: the reviewer's state does not bear on whether the implementer is blocked, and any field is another source of mixing.
- Mark reminder turns and tell Jev to ignore them: removal is exact, and a classifier instruction is not.
- Lower the threshold: coherent evidence already clears it by a wide margin, and a lower threshold would pass a weak `no` from mixed evidence.
- Add a comment tool to agents in this change: the reminder then depends on an agent capability that drivers do not provide today. A blocker stated in the reply reaches assistance through the second pass.
- Run calibration in CI: it needs a live key, costs a model call per fixture, and model changes would make unrelated PRs fail.

## Consequences

- Finished implementers and reviewers pass the blocked item on their own evidence.
- A blocker the agent states in its reply to a reminder still fails the second pass and asks for assistance.
- Jev sees fewer entries when a reminder is in the window, because the window size does not change.
- Calibration data depends on a person running the suite with a key. The default suite still fakes Jev.
- Changing a reminder lead sentence also changes which turns Jev reads. The lead sentences live in one class.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks)
- Detail: [Tasks](/reference/tasks)
- Verify: `TaskSchedulerTickTest`, `LaravelAiTaskSessionClassifierTest`, `TaskRubricReminderTest`, `composer test:calibration` with `TYPESAFE_API_KEY`, and `composer check` in `apps/gateway`
