---
name: planning-features
description: Use when preparing an Orbit feature worktree before implementation.
---

# Planning Features

Prepare one implementation preflight. Do not change product code, tests, proof
files, Git history, Linear, or GitHub.

## Inputs

- The Linear issue has status `In Progress`; the delivery coordinator verified that transition
  before starting or resuming preflight.
- Its bootstrapped worktree.
- The prepared `<worktree>/.orbit/plan.md` with `Verdict: PENDING`.

Stop if any input is missing. Read the issue, governing ADRs, the nearest
`AGENTS.md`, only the relevant code, and the available proof commands.

## Write the plan

Complete `.orbit/plan.md` without copying the issue into it. Keep it short and
implementation-facing:

- **Outcome:** the observable result in one sentence.
- **Code boundaries:** likely files/components and explicit exclusions.
- **Acceptance map:** one row per issue criterion, mapping it to the relevant
  boundary and focused proof.
- **Implementation order:** the smallest ordered increments needed.
- Do not create separate slice files or require one agent or commit per increment.
- **Must preserve:** existing tests, contracts, and invariants that protect
  adjacent behavior.
- **Open questions:** only unresolved facts that prevent safe implementation.

Leave `Verdict: PENDING`. The independent reviewer owns the verdict.

## Corrections

When correcting a `FIX` or an approved `TECHNICAL_RESOLUTION`, read the review
findings and reconciler recommendation already in the plan, plus any verified
durable issue or ADR update. Change only the implementation content required by
that authority, clear resolved findings, and leave `Verdict: PENDING`. Do not
widen product intent or weaken proof.
A new requirement belongs in a separate Linear issue.
