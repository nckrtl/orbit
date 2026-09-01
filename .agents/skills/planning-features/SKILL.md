---
name: planning-features
description: Use when preparing an Orbit implementation plan.
---

# Planning Features

Prepare a concise implementation plan for one Orbit issue. Do not change product
code, tests, proof files, Git history, Linear, or GitHub.

This is an independently invokable planning role.

## Inputs

- The Linear issue or equivalent written contract.
- A repository checkout or worktree at the intended base.
- Applicable ADRs, nearby code, tests, and available proof commands.
- `.orbit/plan.md`. `bin/worktree-create` initializes it; create the same
  structure manually when using another checkout workflow.

Stop if the requested outcome or acceptance criteria are too incomplete to map
safely.

## Write the plan

Complete `.orbit/plan.md` without copying the issue into it:

- **Outcome:** the observable result in one sentence.
- **Code boundaries:** likely files/components and explicit exclusions.
- **Documentation boundaries:** relevant context selected from the issue and
  expected code boundaries; name required documentation changes or preserve the
  issue's `none` rationale.
- **Acceptance map:** one row per issue criterion, mapped to the relevant code
  boundary and focused proof.
- **Implementation order:** the smallest coherent ordered changes.
- **Must preserve:** existing contracts, tests, and invariants protecting
  adjacent behavior.
- **Open questions:** only unresolved facts that prevent a safe implementation.

Set `Review verdict: PENDING`, clear stale review findings, and leave
`Reconciliation verdict: PENDING`. Do not create slice files, mandatory
per-increment commits, or an agent-per-increment plan.

## Corrections

When given review findings or a reconciliation recommendation, change only the
plan content required by that evidence. Do not widen product intent, weaken
proof, or absorb genuinely new requirements into the current issue.

## Verification

The plan is complete when every criterion maps to a boundary and proof action,
documentation impact is mapped, implementation order is explicit, exclusions
prevent unrelated cleanup, and no open question requires guessing product
behavior.
