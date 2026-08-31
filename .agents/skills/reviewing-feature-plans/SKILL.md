---
name: reviewing-feature-plans
description: Use when independently reviewing an Orbit implementation preflight.
---

# Reviewing Feature Plans

Review one prepared `.orbit/plan.md` before implementation. Do not change
product code, tests, proof files, Git history, Linear, or GitHub.

## Review

Read the unchanged Todo Linear issue that satisfies Orbit's admission gate, governing ADRs, the plan, named code
boundaries, nearby tests, and available proof commands. Check that:

- every acceptance criterion maps to a concrete code boundary and focused proof;
- exclusions prevent unrelated cleanup or harness changes;
- implementation order can proceed without rediscovering feature design;
- existing behavior that may regress has a named test, invariant, or proof;
- the plan does not invent requirements or contradict the issue or ADR.

Collect every blocking finding before returning a verdict. Do not spread known
findings across multiple rounds.

## Verdict

Update only the verdict metadata and reviewer-findings section in
`.orbit/plan.md`:

- `PASS`: implementation may start; findings must be empty.
- `FIX`: list concrete, in-scope corrections. Tom starts a fresh correction
  planner and then a fresh independent reviewer. Repeat with fresh agents until
  `PASS` or `BLOCK`.
- `BLOCK`: state the exact missing requirement, conflict, or product decision;
  implementation must not start.

Increase `Review round` by one. Never approve a plan you authored. A genuinely
new requirement is separate Linear work, not a finding against this plan.
