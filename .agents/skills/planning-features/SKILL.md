---
name: planning-features
description: Use when preparing or correcting the plan for one Orbit issue of any type.
---

# Planning Features

Turn one Linear issue into `.orbit/plan.md`, the implementation map the plan reviewer checks and the implementer follows, and bring the maintained documentation for the issue up to date before any code exists. This task is the planner, which the other skills call preflight. It edits only the plan and pages under `docs/`. It does not change product code, tests, proof files, Linear, or GitHub, and it never rewrites Git history.

This is an independently invokable planning task. It does not assume who implements the plan or what lifecycle surrounds it.

## Inputs

- The issue in the `creating-issues` shape: outcome paragraph, `Scope` In and Out bullets, `Acceptance` checklist, and its labels, attachments, and relations.
- Every ADR attached to the issue, read for its `Decision` bullets and `Affects` block.
- A worktree from `bin/worktree-create <ISSUE> <slug>` on the branch `<issue-lowercase>-<slug>`, with `.orbit/plan.md` scaffolded; create the same headings by hand in another checkout workflow.
- Nearby code, tests, and the proof commands the issue's `Proof:` actions name.

Stop, and report the gap instead of planning around it, when:

- the issue is not `Todo`, still has a `Readiness` section, has an unfinished `blocked by` relation, or has sub-issues;
- the issue does not follow the `creating-issues` template; it is refined through `creating-issues` first;
- an attached ADR's Status is not `Accepted on`, or an attached ADR contradicts the issue;
- an `Acceptance` item has no proof action the current machinery can run;
- an `In` bullet needs a component the issue is not labeled with, where a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`;
- the outcome changes documented behavior and the issue has no `docs` label; report it for relabeling; or
- a page cannot be written without guessing product behavior.

## Write the documentation

Before the acceptance map, run `auditing-documentation` in its default issue scope and fix the drift it finds. Then, when the issue carries the `docs` label, write or update the pages that describe the issue's outcome by following `writing-documentation`, stating the behavior the `Acceptance` items deliver in the present tense. For these pages the reference is the issue and its ADRs, not the code; the code follows. Run `composer docs-lint` and `composer docs-build` from the repository root, then commit every change under `docs/`, including `docs/generated/context.json`, as one commit on the feature branch whose message starts with `docs:`. The plan itself stays uncommitted; `.orbit/` is ignored. A blocked issue keeps its `docs:` commits on the branch, and the next planning pass starts from them. The implementer starts from these pages and corrects them only where implementation deviates.

## Write the plan

Fill every section of `.orbit/plan.md` without copying the issue into it:

- **Outcome:** the issue's outcome in one sentence.
- **Code boundaries:** for each `In` bullet, the files or directories that change. For each `Out` bullet, the exclusion that keeps it unchanged. Pages under `docs/` belong to the Documentation section and `proofs/<ISSUE>.json` to the acceptance map; neither is a code boundary or a component.
- **Documentation:** the pages under `docs/` this task changed and what each now states, plus every audit finding it reported instead of fixing, each with its owner. When the label is absent and no drift was found, `none: <why the outcome changes no documented behavior>`. When the label is present and the pages already state the outcome, say so; that is not a stop, and the label is corrected through `creating-issues` afterwards.
- **Acceptance map:** one row per `Acceptance` item, in the issue's order, mapped to its code boundary, or to the page from the Documentation section when documentation is what the item delivers, and the exact focused proof: a test file, a command, or an Incus proof action.
- **Implementation order:** the smallest coherent ordered changes.
- **Must preserve:** every attached ADR `Decision` bullet the change touches, plus the existing tests and invariants that protect adjacent behavior.
- **Open questions:** facts the implementer cannot verify from the repository. A product decision is not an open question; it is a stop.

Set `Review verdict: PENDING` and clear stale findings. Do not create slice files, mandatory per-increment commits, or an agent-per-increment plan. If the plan would absorb more than one independently shippable design, report that the issue needs splitting.

## Corrections

When given review findings, change only the plan content and the pages the findings cite. Do not widen the outcome, weaken a proof, or absorb a new requirement; a new requirement is separate Linear work. Commit changed pages as a further `docs:` commit, mark each addressed finding `addressed:` under `## Review findings`, and set `Review verdict: PENDING` again.

## Verify

The plan is complete when every `Acceptance` item has a row, every row names a boundary or a page and a runnable proof, every `Out` bullet has an exclusion, the Documentation section lists every changed page and every reported finding with its owner, every change under `docs/` is committed, `composer docs-lint` passes, `Must preserve` names the touched ADR bullets, and no open question hides a product decision.
