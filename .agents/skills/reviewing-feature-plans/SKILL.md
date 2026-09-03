---
name: reviewing-feature-plans
description: Use when independently reviewing an Orbit Feature plan.
---

# Reviewing Feature Plans

Independently review one `.orbit/plan.md` and the documentation the planner changed, before any code exists. This role reports plan quality only. It never edits planning content, documentation, product code, tests, proof, Git history, Linear, or GitHub; it may update only `Review verdict` and `## Review findings` in the plan. Never approve a plan you authored, and never decide lifecycle transitions.

## Inputs

Read the same sources the planner had: the issue with its labels, attachments, and relations; every attached ADR's `Decision` bullets and `Affects` block; the plan; the diff under `docs/`; the named code boundaries; nearby tests; and the proof commands.

## Check

- **Coverage:** every `Acceptance` item has one row, in order, with a concrete boundary and a proof that exists and can run today.
- **Labels:** every boundary is inside a component the issue is labeled with. A boundary in an unlabeled component is a finding, not a silent expansion.
- **Exclusions:** every `Out` bullet has an exclusion, and no boundary or order step crosses one.
- **Documentation:** the changed pages state the behavior the `Acceptance` items deliver, follow `writing-documentation`, restate no ADR bullet, and pass `composer docs-lint`; the audit in the issue's scope found no drift that is neither fixed nor listed as an open question; the section matches the `docs` label.
- **ADRs:** `Must preserve` names every attached ADR `Decision` bullet the boundaries touch, and no step contradicts one.
- **Order:** the steps are coherent and rediscover no product behavior the issue or an ADR already states.
- **Risk:** every existing behavior the boundaries put at risk has a named test, invariant, or proof.
- **Honesty:** open questions are facts, not product decisions; the plan invents no requirement and drops none.
- **Atomicity:** independently shippable work is not bundled without a stated shared invariant.

Collect every known blocking finding in one pass. Each finding cites an `Acceptance` item, `Scope` bullet, label, ADR bullet, or repository rule.

## Verdict

- `PASS`: no blocking findings; leave findings empty.
- `FIX`: every concrete in-scope plan correction, each with its citation.
- `BLOCK`: the exact incompatibility, missing contract, label or proof conflict, or product decision, with evidence, the smallest safe recommended resolution, and the apparent decision boundary.

Do not expand scope or present a recommendation as authority. A new requirement is separate Linear work.
