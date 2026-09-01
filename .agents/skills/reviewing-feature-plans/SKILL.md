---
name: reviewing-feature-plans
description: Use when independently reviewing an Orbit Feature plan.
---

# Reviewing Feature Plans

Independently review one `.orbit/plan.md`. This role reports plan quality only;
it never edits the plan, code, tests, proof, Git history, Linear, or GitHub.

A reviewer may remain alive to verify one correction it requested. It never
shares the Builder session. Never approve a plan you authored.

## Review

Read the current issue or written contract, governing ADRs, plan, named code
boundaries, nearby tests, and available proof commands. Check that:

- every acceptance criterion maps to a concrete boundary and focused proof;
- issue components permit every planned change;
- exclusions prevent unrelated cleanup or product/harness mixing;
- implementation order is coherent and does not rediscover product behavior;
- existing behavior at risk has a named test, invariant, or proof;
- open questions identify facts rather than hide product decisions;
- independently shippable work is not bundled without an atomicity reason; and
- the plan does not invent requirements or contradict the issue or ADRs.

Collect every known blocking finding before returning a verdict.

## Verdict

Update only `Review verdict` and `## Review findings`:

- `PASS`: no blocking findings; leave findings empty.
- `FIX`: list every concrete in-scope plan correction.
- `BLOCK`: state the exact incompatibility, missing contract, component/proof
  conflict, or product decision. Include the smallest safe recommended
  resolution, evidence, and apparent decision boundary.

Do not silently expand scope or present a recommendation as approved authority.
A genuinely new requirement belongs in separate Linear work.

## Bounded recheck

After an initial `FIX`, the same reviewer may re-read current sources and review
one corrected plan. On that second review:

- return `PASS` when every finding is resolved and no new blocker exists;
- otherwise return `FIX` or `BLOCK` with the complete current evidence and stop
  automatic review cycling.

A second non-`PASS` is explicit non-convergence. The reviewer must stop automatic review cycling.
Do not request a third ordinary correction and do not negotiate with the Builder.

## Verification

The verdict is complete when all findings are included, each cites a contract or
repository boundary, the recommendation is no broader than necessary, and the
review round is clear enough for an external authority to diagnose if needed.
