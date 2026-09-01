---
name: reviewing-feature-plans
description: Use when independently reviewing an Orbit plan.
---

# Reviewing Feature Plans

Review one prepared `.orbit/plan.md`. Do not change product code, tests, proof
files, Git history, Linear, or GitHub.

This role reports the quality of the plan only. It does not start other roles,
change issue state, or decide what happens after the verdict.

## Review

Read the issue or equivalent contract, governing ADRs, the plan, named code
boundaries, nearby tests, and available proof commands. Check that:

- every acceptance criterion maps to a concrete code boundary and focused proof;
- exclusions prevent unrelated cleanup or harness changes;
- implementation order is coherent and does not require rediscovering product
  behavior;
- existing behavior that may regress has a named test, invariant, or proof;
- open questions identify facts rather than hide product decisions; and
- the plan does not invent requirements or contradict the issue or ADRs.

Collect every known blocking finding before returning a verdict.

## Verdict

Update only `Review verdict` and `## Review findings` in `.orbit/plan.md`:

- `PASS`: no blocking findings; leave the findings section empty.
- `FIX`: list concrete, in-scope plan corrections.
- `BLOCK`: state the exact technical incompatibility, missing requirement,
  conflict, or product decision. Include a **Recommended resolution** naming the
  smallest safe contract, scope, dependency, or harness change, the evidence
  supporting it, and the apparent decision boundary.

Do not silently expand scope or present a recommendation as approved authority.
Never approve a plan you authored. A genuinely new requirement belongs in a
separate Linear issue.

## Verification

The verdict is complete when all known findings are included, every finding
cites a contract or repository boundary, and the recommendation is no broader
than necessary.
