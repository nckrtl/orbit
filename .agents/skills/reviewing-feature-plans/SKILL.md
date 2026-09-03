---
name: reviewing-feature-plans
description: Use when independently reviewing the plan for one Orbit issue of any type.
---

# Reviewing Feature Plans

Independently review one `.loop/plan.md` and the documentation commits the planner made, before any code exists. This role reports plan quality only. It never edits planning content, documentation, product code, tests, proof, Linear, or GitHub; it may update only `Review verdict` and `## Review findings` in the plan and commit that tracked review record. Never approve a plan you authored, and never decide lifecycle transitions.

The plan starts uncommitted, so this review runs on the planner's worktree. Commit the reviewed `.loop/plan.md` and no other change with a message beginning `plan:` after recording the verdict and findings.

## Inputs

Read the same sources the planner had: the issue with its labels, attachments, and relations; every attached ADR's `Decision` bullets and `Affects` block; the plan; the diff under `docs/` between the branch base and the branch head; the named code boundaries; nearby tests; and the proof commands.

## Check

- **Coverage:** every `Acceptance` item has one row, in order, with a concrete boundary, or the page from the Documentation section when documentation is what the item delivers, and a proof that exists and can run today.
- **Labels:** every boundary is inside a component the issue is labeled with, where a component is one of the five Composer projects, pages under `docs/` and files under `.loop/proof/` are not components, a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`. A boundary in an unlabeled component is a finding, not a silent expansion.
- **Exclusions:** every `Out` bullet has an exclusion, and no boundary or order step crosses one.
- **Documentation:** the changed pages state the behavior the `Acceptance` items deliver, judged against the issue and its ADRs rather than against code that does not exist yet; they follow `writing-documentation`, restate no ADR bullet, and pass `composer docs-lint`; every change under `docs/` is committed; the audit in the issue's scope found no drift that is neither fixed nor reported with an owner; pages written for the outcome exist when the `docs` label is present unless the Documentation section states the pages already describe the outcome, which is accepted as written, and every other changed page is a drift fix the audit found.
- **ADRs:** `Must preserve` names every attached ADR `Decision` bullet the boundaries touch, and no step contradicts one.
- **Order:** the steps are coherent and rediscover no product behavior the issue or an ADR already states.
- **Risk:** every existing behavior the boundaries put at risk has a named test, invariant, or proof.
- **Honesty:** open questions are facts, not product decisions; the plan invents no requirement and drops none.
- **Atomicity:** independently shippable work is not bundled without a stated shared invariant.

Collect every known blocking finding in one pass. Each finding cites an `Acceptance` item, `Scope` bullet, label, ADR bullet, or repository rule. On a second pass, treat findings the planner marked `addressed:` as closed once the cited change is present.

## Verdict

- `PASS`: no blocking findings; leave findings empty and commit the recorded verdict.
- `FIX`: every concrete in-scope plan correction, each with its citation.
- `BLOCK`: the exact incompatibility, missing contract, label or proof conflict, or product decision, with evidence, the smallest safe recommended resolution, and the apparent decision boundary. A blocked issue returns to `Backlog` with a `Readiness` section through `creating-issues`.

Do not expand scope or present a recommendation as authority. A new requirement is separate Linear work.
