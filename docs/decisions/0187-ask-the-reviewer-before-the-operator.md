# ADR 0187: Ask the reviewer before the operator

A blocked implementer asks its subtask's reviewer first. Only a question that the reviewer cannot answer becomes a direction request for the operator. A direction request is a distinct kind of assistance, separate from system failures. Orbit records every question with its answer and its cause, so the operator can find the subtasks that were scoped badly.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). The reviewer resolves what the contract already decides. The operator is asked only for direction that no agent can give.

## Context

[Turn receipt](/reference/tasks#turn-receipt) lets an implementer hand off `blocked` with one question. Today that receipt [asks for assistance at once](/reference/tasks#rubric-and-reminders). The reviewer never sees the question. Yet the reviewer reads the same brief, ADRs, documentation, and code, so it can answer every question that the contract already decides.

Assistance also has one meaning for every cause. A question that needs the operator's decision has the same flag and the same free-text reason as a failed push, a dead agent thread, or a failed check. On 2026-10-01 the assistance list held two real questions (ORB-152 and ORB-694) among push failures and a failed thread. The operator had to read every reason to find the questions.

Orbit aims for subtasks specific enough that an implementer builds each one in one go. Every question that an implementer asks shows that the brief, the contract, or the scope left something open. Today those questions live only in comment bodies, so nobody can count them, group them by cause, or trace them back to the brief that caused them.

## Decision

The Tasks engine routes an implementer's question to the reviewer, records every question, and marks each assistance request with its kind.

### Consult the reviewer

When an implementer hands off `blocked`, Orbit sends the summary and the question to the subtask's reviewer. Orbit starts that reviewer when the subtask has none yet. The subtask stays `running`, and the reviewer is the acting thread until it hands off. This turn is a **consult**.

A consult turn has two outcomes:

| Outcome | Effect |
| --- | --- |
| `answered` | The summary is the answer. Orbit sends it to the implementer, which continues the same attempt. |
| `blocked` | The reviewer cannot answer from the contract. Its `--question` becomes a direction request. |

The reviewer answers only from the brief, the ADRs, the documentation, the code, and the task history. A question about scope, priorities, access, money, or a resource that only the operator controls is the reviewer's `blocked`.

Orbit consults the reviewer at most twice in one implementer attempt. A third `blocked` in that attempt becomes a direction request at once. Its question is the implementer's question, and its reason includes both earlier answers.

A reviewer's own `blocked` during a review is a direction request, as before.

### Record every question

A **question** is one record for each consult and each direction request. The `task_questions` table stores it, and the comments keep the conversation.

| Field | Meaning |
| --- | --- |
| `task_id`, `subtask_id`, `attempt` | Where the question was asked |
| `asked_by` | `implementer`, `reviewer`, or `operator` |
| `question` | The one question, from `--question` or the comment body |
| `status` | `open`, `answered`, or `escalated` while the operator answers |
| `answered_by`, `answer` | `reviewer` or `operator`, and the answer |
| `cause` | Why the question arose, set by the reviewer |
| `asked_at`, `escalated_at`, `answered_at` | When each step happened |

The reviewer gives the cause with `--cause` on every `answered` turn and every `blocked` turn. The cause is one of:

| Cause | Meaning |
| --- | --- |
| `brief_unclear` | The brief or its deliverables allow more than one reading |
| `contract_gap` | The ADRs and the documentation do not decide it |
| `scope` | The work needs something outside the subtask, or the subtask is too large |
| `environment` | A resource, an access grant, or infrastructure that the implementer cannot control |
| `missed_contract` | The brief or the contract already answers it |

The relay of an operator's answer sets the final cause, because only then does the reviewer know the answer. A question that the reviewer escalates keeps the reviewer's cause until the relay replaces it.

Each subtask and task stores `questions` and `escalations` in its [settle metrics](/reference/tasks#settle-metrics). `escalations` counts the questions that reached the operator. `tasks:question:list` lists questions across tasks, filtered by Project, cause, status, and time, so the operator can analyze which briefs caused them. The Coder `task_group.settled` webhook adds both counts.

### Direction requests

Assistance has a kind: `direction` or `failure`. Both levels store `assistance_kind` and `assistance_question` beside `assistance_requested` and `assistance_reason`.

| Kind | Cause | `assistance_question` |
| --- | --- | --- |
| `direction` | A reviewer's `blocked` in a consult or a review, a third implementer block in one attempt, or an operator's `assistance_requested` comment | The one question for the operator |
| `failure` | Every other cause, such as a failed push, check, thread, or webhook | Null |

The task takes the kind and question of the subtask that asks. While a task asks for direction, a failure that follows does not replace that request.

### Answer a direction request

An operator answers with a `resolution` comment, as today. On a direction request, Orbit sends the resolution to the subtask's reviewer. The reviewer turns it into instructions for the implementer with an `answered` turn. That relay does not count toward the consult limit. When the reviewer asked during a review, it continues that review instead.

### Show and notify

- `tasks:status`, `tasks:list`, and `tasks:show` include `assistance_kind` and `assistance_question`. The human `tasks:status` table lists direction requests first, under "Needs your direction", with the question.
- The web task board marks a task that needs direction apart from a failure, and the task page shows the question first.
- The Coder `task_group.assistance_requested` webhook adds `kind` and `question` to its body.

## Rejected alternatives

- A `blocked` status: the subtask would have to remember whether to return to `running` or `reviewing`. Every status filter, transition, and board lane would change. The kind field marks the same state without a new lifecycle step.
- The operator answers every block: the reviewer already holds the contract, and most blocks are questions the contract answers.
- No consult limit: an implementer and a reviewer pass the same question back and forth without end.
- The operator's answer goes straight to the implementer: the reviewer would review work done under a direction it did not see or translate into the contract.
- A separate `task_group.direction_requested` webhook: receivers would subscribe to a second event for the same assistance flag.
- Questions only in comment bodies: they cannot be counted or grouped by cause without parsing free text.
- A cause chosen by a model after the fact: the reviewer already holds the contract and the answer when it hands off, so a fixed list at that moment is deterministic and cheaper.
- Waiting on another task or pull request as a third kind: this decision covers only questions. A dependency wait that resumes on its own is a separate feature.

## Consequences

- The operator sees only questions that need a person, and sees them first.
- Every question has a record, an answer, and a cause. The operator can count questions per task, Project, and cause, and read the brief that caused each one.
- A consult costs one reviewer turn before an operator sees a block.
- The reviewer of a subtask can start before the first handoff. The review that follows keeps the consult in its context.
- Existing open requests are migrated: a reason that starts with `The implementer is blocked: ` or `The reviewer is blocked: ` becomes `direction`, with the stored question, and gets an `escalated` question record without a cause. Every other open request becomes `failure`. Closed requests get no records, so the counts start with this change.
- A web answer box for direction requests is not part of this decision. The operator answers through the CLI, MCP, or the API.

## Affects

- Components: apps/gateway, apps/cli, apps/web, packages/php-sdk, apps/docs
- ADRs: [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions) keeps its own rule for an unsure `decide` subtask. That request stays `failure` until ADR 0182 says otherwise.
- Detail: [Tasks reference](/reference/tasks#assistance-and-resolution), [Turn receipt](/reference/tasks#turn-receipt), [`tasks` CLI](/cli/tasks), and [Coder settle webhook](/reference/tasks#coder-settle-webhook)
- Verify: Gateway feature tests for the consult, the limit, the relay, the kinds, the question records, the causes, and the migration; CLI and SDK tests for the new fields and `tasks:question:list`; web screenshots of a task that needs direction
