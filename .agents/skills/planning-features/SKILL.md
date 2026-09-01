---
name: planning-features
description: Use when a Builder prepares or corrects an Orbit Feature plan.
---

# Planning Features

As the retained Builder, prepare a concise implementation map for one Orbit
issue. Do not change product code, tests, proof files, Git history, Linear, or
GitHub during planning.

This task may be invoked independently. In an orchestrated lifecycle the same
retained Builder may continue implementation after an independent plan `PASS`.

## Inputs

- The current Linear issue or equivalent written contract.
- A checkout or worktree at the intended base.
- Applicable ADRs, nearby code, tests, and available proof commands.
- `.orbit/plan.md`; `bin/worktree-create` initializes it.

Stop when the outcome, component boundary, acceptance criteria, dependency, or
proof feasibility is incomplete enough to require guessing. Do not turn the
Feature plan into a second issue contract.

## Write the plan

Complete `.orbit/plan.md` without copying the issue into it:

- **Outcome:** observable result in one sentence.
- **Code boundaries:** likely files/components and explicit exclusions.
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
multiple independently shippable designs, stop and report that the issue needs
splitting or contract repair.

## One correction

When the independent reviewer returns the first `FIX`, change only the plan
content required by the complete findings. Do not widen product intent, weaken
proof, or absorb a new requirement. Return the corrected plan to the same
reviewer for the single allowed recheck.

A second non-`PASS` is non-convergence; stop rather than expanding the plan
again.

## Verification

The plan is complete when every criterion maps to a boundary and proof action,
order is explicit, exclusions prevent unrelated cleanup, and no open question
requires guessing product behavior.
