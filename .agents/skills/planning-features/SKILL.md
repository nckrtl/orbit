---
name: planning-features
description: Use when preparing or correcting an Orbit Feature plan.
---

# Planning Features

Prepare a concise implementation plan for one Orbit issue. Do not change product
code, tests, proof files, Git history, Linear, or GitHub.

This is an independently invokable planning task. It does not assume who will
implement the plan or what orchestration lifecycle surrounds it.

## Inputs

- The current Linear issue or equivalent written contract.
- A checkout or worktree at the intended base.
- Applicable ADRs, nearby code, tests, and available proof commands.
- `.orbit/plan.md`. `bin/worktree-create` initializes it; create the same
  structure manually when using another checkout workflow.

Stop when the outcome, component boundary, acceptance criteria, dependency, or
proof feasibility is incomplete enough to require guessing. Do not turn the
Feature plan into a second issue contract.

## Write the plan

Complete `.orbit/plan.md` without copying the issue into it:

- **Outcome:** observable result in one sentence.
- **Code boundaries:** likely files/components and explicit exclusions.
- **Documentation boundaries:** relevant context selected from the issue and
  expected code boundaries; name the required documentation changes when the
  issue carries the `docs` label, otherwise state that none are planned.
- **Acceptance map:** one row per issue criterion, mapped to the relevant code
  boundary and focused proof.
- **Implementation order:** smallest coherent ordered changes.
- **Must preserve:** existing contracts, tests, and invariants protecting
  adjacent behavior.
- **Open questions:** unresolved facts that prevent safe implementation.

Set `Review verdict: PENDING` and clear stale review findings.
Do not create slice files, mandatory per-increment commits, or an
agent-per-increment plan.

The plan is an implementation map, not an exhaustive migration specification,
command transcript, tracker history, or orchestration ledger. If it must absorb
multiple independently shippable designs, report that the issue needs splitting
or contract repair.

## Corrections

When supplied independent review findings, change only plan content required by
that evidence. Do not widen product intent, weaken proof, or absorb a genuinely
new requirement.

## Verification

The plan is complete when every criterion maps to a boundary and proof action,
documentation impact is mapped, order is explicit, exclusions prevent unrelated
cleanup, and no open question requires guessing product behavior.
