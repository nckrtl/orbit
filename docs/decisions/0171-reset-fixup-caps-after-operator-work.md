---
title: "ADR 0171: Reset fixup caps after operator work"
sidebarTitle: "0171 Reset fixup caps after operator work"
description: "Proposed. ADR 0164 limits fixups by problem and group. Both limits count only fixups since the most recent completed non-fixup subtask, which the operator or planner appends."
---

# ADR 0171: Reset fixup caps after operator work

ADR 0164's limits of two fixups per problem identity and three fixups per group count fixups only since the most recent completed non-fixup subtask. This gives a person a chance to move the work forward before the Gateway starts a fresh bounded fixup cycle.

## Status

Proposed.

This amends [ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask). It changes only the counting window for its two fixup caps. Its detection, fixup, and assistance behavior otherwise remains in force.

## Context

ADR 0164 limits repeated attempts because repeated fixups may not solve the same problem. Its original counts lasted for the group's entire lifetime. That can block a legitimate repair after an operator has intervened and changed the task branch.

In group 136, two `conflict:main` fixups happened early. The operator then appended four review-fix subtasks. Main moved during that work, so the next conflict was a new problem. The lifetime cap treated it as a third attempt at the original conflict and requested assistance rather than healing it.

A reset must not turn a fixup loop into an unbounded sequence. Requiring a completed non-fixup subtask creates a human checkpoint: only the operator or planner appends that subtask. The fixup caps cannot reset repeatedly without a human step between the loops.

## Decision

- Count both ADR 0164 caps—the maximum of two fixups for one `fixup_problem` identity and three Gateway fixups for a group—using only fixups created after the most recent completed subtask whose `fixup_problem` is null.
- A non-fixup subtask resets the window only when it is completed. A pending, running, cancelled, or failed non-fixup subtask does not reset the caps. If the group has no completed non-fixup subtask, count all of its fixups, as before.
- Count every fixup status within the active window, including `cancelled` and `failed`.
- A non-fixup subtask is appended by a person: the operator or planner. Each reset therefore requires a human step between fixup loops.
- Show and the assistance reason continue to identify the counts that applied. The reason names the reached cap for the identity or group; it does not report the lifetime total.

## Rejected alternatives

- Keep lifetime counts: rejected because operator work can change the branch and make the next conflict a new problem, while the old fixups still block it.
- Reset on any appended non-fixup subtask: rejected because unfinished work has not yet supplied a completed human intervention.
- Reset on every completed subtask, including fixups: rejected because resetting after a fixup would permit an unbounded loop without a human checkpoint.

## Consequences

- Completed operator or planner work lets the Gateway make up to two new attempts for an identity and up to three fixups in the new window.
- The limits still bound fixups between human interventions. A person starts another bounded cycle by appending and completing non-fixup work.
- Show and assistance remain interpretable because they identify the cap counts used for the current window.

## Affects

- Components: apps/gateway
- ADRs: amends [ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask)
- Detail: [Tasks](/reference/tasks)
- Verify: `composer docs-lint`; Gateway behavior tests for the fixup counting window
