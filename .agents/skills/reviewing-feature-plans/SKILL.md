---
name: reviewing-feature-plans
description: Use when independently reviewing an Orbit Feature plan.
---

# Reviewing Feature Plans

Independently review one `.orbit/plan.md`. This role reports plan quality only.
It never edits planning content, product code, tests, proof, Git history, Linear,
or GitHub; it may update only `Review verdict` and `## Review findings` in the
plan as its structured review output.

This task is independently invokable and does not decide lifecycle transitions
or start other roles.

## Review

Read the current issue or written contract, governing ADRs, plan, named code
boundaries, nearby tests, and available proof commands. Check that:

- every acceptance criterion maps to a concrete boundary and focused proof;
- the issue's component labels permit every planned change;
- documentation boundaries match the issue's `docs` label and the relevant
  repository context;
- exclusions prevent unrelated cleanup or product/harness mixing;
- implementation order is coherent and does not rediscover product behavior;
- existing behavior at risk has a named test, invariant, or proof;
- open questions identify facts rather than hide product decisions;
- independently shippable work is not bundled without an atomicity reason; and
- the plan does not invent requirements or contradict the issue or ADRs.

Collect every known blocking finding before returning a verdict.

## Verdict

- `PASS`: no blocking findings; leave findings empty.
- `FIX`: list every concrete in-scope plan correction.
- `BLOCK`: state the exact incompatibility, missing contract, component/proof
  conflict, or product decision. Include the smallest safe recommended
  resolution, evidence, and apparent decision boundary.

Do not silently expand scope or present a recommendation as approved authority.
A genuinely new requirement belongs in separate Linear work. Never approve a
plan you authored.

## Verification

The verdict is complete when all findings are included, each cites a contract or
repository boundary, and the recommendation is no broader than necessary.
