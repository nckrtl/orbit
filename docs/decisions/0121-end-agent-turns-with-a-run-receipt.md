---
title: "ADR 0121: End agent turns with a run receipt"
sidebarTitle: "0121 Run receipts"
description: "Proposed. An agent ends its turn by running a Gateway-shipped script that writes a run receipt in the workspace. The scheduler reads the receipt, applies mechanical checks, records the result, and starts the next agent. Agents no longer post comments. Orbit commits after approval and opens the final pull request."
---

# ADR 0121: End agent turns with a run receipt

An agent ends its turn by running `.git/orbit/run`, a script the Gateway places in the workspace. The script writes a run receipt, `.git/orbit/run.json`. The scheduler reads the receipt, applies the mechanical checks, records the result, removes the receipt, and starts the next agent. Agents never call the Gateway. Orbit commits the work after the reviewer approves and opens the final pull request.

## Status

Proposed.

This amends [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review), [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), [ADR 0117](/decisions/0117-judge-the-blocked-question-on-role-evidence), and [ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app). The mechanical checks in ADR 0114 and the `check_script` item stay. Task verification (ADR 0120, proposed in #591) supplies stronger evidence when it lands.

## Context

The intended loop has seven steps:

1. A task group is claimed.
2. Orbit creates its workspace.
3. Orbit starts the implementer for the current subtask.
4. When the implementer stops, Orbit runs the mechanical checks.
5. When they pass, Orbit starts the reviewer.
6. When the reviewer stops, Orbit reads its outcome.
7. Orbit repeats for the next subtask.

The current loop needs more than that. Agents must post typed comments to the Gateway, with IDs and fields they cannot know. Agents on task Nodes have no working way to post them. Jev must infer from transcripts whether a stopped agent is blocked. The reviewer starts at the beginning of the group and waits, and its waiting messages confused that judgment. The reviewer must also make the sign-off commit, which the Gateway then verifies.

An end-to-end run of a Pi implementer and a T3 reviewer showed these problems. The reviewer had no way to post its verdict, so every task stopped at review.

## Decision

The scheduler moves every task from turn to turn. Agents report the end of a turn with a run receipt through one Gateway-shipped script. Mechanical checks decide whether work moves on. Orbit commits after approval and opens the final pull request.

### The loop

The scheduler owns every transition. It starts the implementer for the current subtask. When the implementer is idle, it reads the receipt and runs the mechanical checks. When they pass, it starts the reviewer, or sends the existing reviewer a review request. When the reviewer is idle, it reads the receipt and acts on the outcome.

The reviewer thread starts at the first handoff, not when the group starts. One reviewer thread still serves every subtask in the group.

### The run receipt

The Gateway places the script `.git/orbit/run` in the workspace when it prepares the workspace. `.git/orbit/` is inside the Git directory, so Git never tracks it and no Project needs an ignore rule. The Gateway owns the script and its version.

An agent ends its turn with one command:

```bash
.git/orbit/run --outcome=OUTCOME --summary="What was done or what blocks the work"
```

| Turn | Allowed outcomes |
| --- | --- |
| Implementer | `ready_for_review`, `blocked` |
| Reviewer | `approved`, `changes_requested`, `blocked` |

The script refuses unknown outcomes and an empty summary. It writes `.git/orbit/run.json` atomically. The agent never writes JSON itself.

The scheduler knows whose turn it is from the task status, so the receipt carries no role, task, or thread ID. An outcome that does not fit the turn fails the check.

### What the scheduler does with a receipt

When the current agent is idle, the scheduler reads the receipt over SSH, as it reads `composer.json` for `check_script`.

- **Missing receipt:** one reminder asks the agent to run `.git/orbit/run`. If it is still missing on the next idle pass, the task asks for assistance.
- **Receipt present:** the scheduler stores it as the task's comment for this attempt, then removes it. The stored receipts are the history of the group.
- **`blocked`:** the task asks for assistance with the agent's summary.
- **`ready_for_review`:** the mechanical checks decide. If they pass, the reviewer starts. If they fail, the implementer gets one reminder that names the failed checks.
- **`changes_requested`:** the summary is relayed to the implementer, as findings are today.
- **`approved`:** Orbit commits the workspace changes on the task branch, then starts the next subtask. On the last subtask, it opens the pull request and settles the group.

The receipt only marks the end of a turn and states the agent's outcome. It is not evidence. Mechanical checks always win over the receipt and over Jev.

### Jev

Jev no longer asks whether an agent is blocked. An agent that is blocked says so with `blocked`. Jev answers only whether evidence matches the assignment, as task verification proposes.

On the last approval, Jev also checks that every deliverable in the group brief appears in the pull request's change list. Jev cannot read code, so this checks coverage, not correctness; the reviewer keeps that judgment. A missing deliverable fails the check, and the reviewer gets one reminder that names it.

### The commit

The reviewer no longer commits. After an `approved` receipt, Orbit commits the workspace changes on `task-{group id}` with a message built from the subtask title and the reviewer's summary. No commit exists before approval.

### The pull request

Orbit opens the final pull request itself. The Gateway GitHub App gains write permission for repository contents and pull requests, which amends [ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app). Orbit pushes the task branch and opens the pull request against the Project's default branch.

On the last subtask, the reviewer's approval also describes the pull request:

```bash
.git/orbit/run --outcome=approved --summary="…" \
  --pr-summary="One or two sentences" \
  --pr-change="A new feature or behavior change" \
  --pr-breaking="A breaking change, or none"
```

`--pr-summary` is required once. `--pr-change` is required at least once and can repeat. `--pr-breaking` is required at least once; `none` states that nothing breaks, so the question is never skipped. The script accepts these flags only on the last subtask and refuses the approval without them. There is no limit on the number of changes. A long list suggests the group was too large, which is a separate concern.

Orbit renders the description from these fields in that order, adds one line with the check results, and uses the group title as the pull request title.

### What this removes

- Agent-posted `ready_for_review`, `approved`, and `changes_requested` comments, and the fields they need: `review_attempt`, `reviewer_thread_id`, and `driver_turn`.
- Agent access to the Gateway for task comments.
- The Jev `blocked` question and its transcript evidence ([ADR 0117](/decisions/0117-judge-the-blocked-question-on-role-evidence)).
- The reviewer's opening turn at group start.
- The reviewer's sign-off commit and the checks that the approved commit equals the workspace HEAD and is new for the subtask.
- The reviewer pushing the branch and opening the pull request, and the check of the pull request URL it reported.

Operator comments stay: `assistance_requested` from Orbit and `resolution` from an operator.

## Rejected alternatives

- Agents post comments through a `task_comment` command: the task machine needs Gateway access and the agent needs IDs, which it cannot know reliably.
- Agents write `run.json` by hand: malformed JSON needs extra turns to fix.
- A dynamic field request written by the Gateway before each turn: the fields are few and stable. The Gateway already controls them through the script it ships.
- An installed `orbit` command: task Nodes do not have the Orbit CLI installed.
- Accepting a turn without a receipt: the scheduler would again infer the outcome from transcripts.
- The reviewer keeps opening the pull request: it needs GitHub access and its reported URL needs verification. Orbit already holds the branch and the receipts.
- The reviewer writes the whole description: each approval covers one subtask, and free text grows long. Structured fields give a short, consistent description.
- A limit on the number of changes: a long list signals a group that is too large. A limit would hide that signal.

## Consequences

- The loop has one source of truth for the end of a turn, and every transition happens in the scheduler.
- The implementer and reviewer need only one command, the same for every driver.
- A crash between storing and removing a receipt must not record it twice. The scheduler stores the receipt with its content hash and ignores a repeat.
- Existing groups keep the current comment flow until they settle.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app), [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review), [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), [ADR 0117](/decisions/0117-judge-the-blocked-question-on-role-evidence)
- Detail: [Tasks](/reference/tasks)
- Verify: scheduler tests for each receipt outcome, the missing-receipt reminder and assistance, duplicate-receipt handling, the approval commit, and opening the pull request; the brief-coverage check; a script test for accepted and refused input, including the pull request fields; and an Incus run of one group with an implementer and a reviewer that completes review without agent-posted comments
