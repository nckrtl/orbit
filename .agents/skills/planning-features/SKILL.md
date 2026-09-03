---
name: planning-features
description: Use when preparing or correcting an Orbit Feature plan.
---

# Planning Features

Turn one Linear issue into `.orbit/plan.md`, the implementation map the plan reviewer checks and the implementer follows, and bring the maintained documentation for the issue up to date before any code exists. Do not change product code, tests, proof files, Git history, Linear, or GitHub. Documentation under `docs/` is the one thing besides the plan this task edits.

This is an independently invokable planning task. It does not assume who implements the plan or what lifecycle surrounds it.

## Inputs

- The issue: outcome paragraph, `Scope` In and Out bullets, `Acceptance` checklist, and its labels, attachments, and relations.
- Every ADR attached to the issue, read for its `Decision` bullets and `Affects` block.
- A checkout or worktree at the intended base, nearby code, tests, and the proof commands the issue's `Proof:` actions name.
- `.orbit/plan.md` as `bin/worktree-create` scaffolds it; create the same headings by hand in another checkout workflow.

Stop when an `Acceptance` item has no proof action the current machinery can run, an `In` bullet needs a component the issue is not labeled with, an attached ADR contradicts the issue, the issue has sub-issues, or a page cannot be written without guessing product behavior. Report the gap; do not plan around it.

## Write the documentation

Before the acceptance map, run `auditing-documentation` in its default issue scope and fix the drift it finds. Then, when the issue carries the `docs` label, write or update the pages that describe the issue's outcome by following `writing-documentation`, stating the behavior the `Acceptance` items deliver in the present tense. Run `composer docs-lint` and `composer docs-build`. The implementer starts from these pages and corrects them only where implementation deviates.

## Write the plan

Fill every section of `.orbit/plan.md` without copying the issue into it:

- **Outcome:** the issue's outcome in one sentence.
- **Code boundaries:** for each `In` bullet, the files or directories that change. For each `Out` bullet, the exclusion that keeps it unchanged.
- **Documentation:** the pages under `docs/` this task changed and what each now states, plus every audit finding it reported instead of fixing and where it went. Without the `docs` label and with no drift found, `none`.
- **Acceptance map:** one row per `Acceptance` item, in the issue's order, mapped to its code boundary and the exact focused proof: a test file, a command, or an Incus proof action.
- **Implementation order:** the smallest coherent ordered changes.
- **Must preserve:** every attached ADR `Decision` bullet the change touches, plus the existing tests and invariants that protect adjacent behavior.
- **Open questions:** facts the implementer cannot verify from the repository. A product decision is not an open question; it is a stop.

Set `Review verdict: PENDING` and clear stale findings. Do not create slice files, mandatory per-increment commits, or an agent-per-increment plan. If the plan would absorb more than one independently shippable design, report that the issue needs splitting.

## Corrections

When given review findings, change only what the findings cite. Do not widen the outcome, weaken a proof, or absorb a new requirement; a new requirement is separate Linear work.

## Verify

The plan is complete when every `Acceptance` item has a row, every row names a boundary and a runnable proof, every `Out` bullet has an exclusion, the Documentation section lists every changed page and every reported finding, `composer docs-lint` passes, `Must preserve` names the touched ADR bullets, and no open question hides a product decision.
