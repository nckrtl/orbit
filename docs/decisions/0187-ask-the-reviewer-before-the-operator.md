# ADR 0187: Ask the reviewer before the operator

A blocked implementer asks its subtask's reviewer first. Only a question that the reviewer cannot answer becomes a direction request for the operator. A direction request is a distinct kind of assistance, separate from system failures.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). The reviewer resolves what the contract already decides. The operator is asked only for direction that no agent can give.

## Context

[Turn receipt](/reference/tasks#turn-receipt) lets an implementer hand off `blocked` with one question. Today that receipt [asks for assistance at once](/reference/tasks#rubric-and-reminders). The reviewer never sees the question. Yet the reviewer reads the same brief, ADRs, documentation, and code, so it can answer every question that the contract already decides.

Assistance also has one meaning for every cause. A question that needs the operator's decision has the same flag and the same free-text reason as a failed push, a dead agent thread, or a failed check. On 2026-10-01 the assistance list held two real questions (ORB-152 and ORB-694) among push failures and a failed thread. The operator had to read every reason to find the questions.

## Decision

The Tasks engine routes an implementer's question to the reviewer, and marks each assistance request with its kind.

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
- Waiting on another task or pull request as a third kind: this decision covers only questions. A dependency wait that resumes on its own is a separate feature.

## Consequences

- The operator sees only questions that need a person, and sees them first.
- A consult costs one reviewer turn before an operator sees a block.
- The reviewer of a subtask can start before the first handoff. The review that follows keeps the consult in its context.
- Existing open requests are migrated: a reason that starts with `The implementer is blocked: ` or `The reviewer is blocked: ` becomes `direction`, with the stored question. Every other open request becomes `failure`.
- A web answer box for direction requests is not part of this decision. The operator answers through the CLI, MCP, or the API.

## Affects

- Components: apps/gateway, apps/cli, apps/web, packages/php-sdk, apps/docs
- ADRs: [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions) keeps its own rule for an unsure `decide` subtask. That request stays `failure` until ADR 0182 says otherwise.
- Detail: [Tasks reference](/reference/tasks#assistance-and-resolution), [Turn receipt](/reference/tasks#turn-receipt), [`tasks` CLI](/cli/tasks), and [Coder settle webhook](/reference/tasks#coder-settle-webhook)
- Verify: Gateway feature tests for the consult, the limit, the relay, the kinds, and the migration; CLI and SDK tests for the new fields; web screenshots of a task that needs direction
