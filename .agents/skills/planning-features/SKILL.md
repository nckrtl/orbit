---
name: planning-features
description: Use when preparing or correcting the plan for one Orbit issue of any type.
---

# Planning Features

Turn one Linear issue into `.loop/plan.md`, the separately versioned implementation map the plan reviewer checks and the implementer follows, and bring the maintained documentation for the issue up to date before any code exists. This task is the planner, which the other skills call preflight. It edits only the plan, its generated `.loop/plan-lint.json` receipt, and pages under `docs/`. It does not change product code, tests, proof files, Linear, or GitHub, and it never rewrites Git history.

This is an independently invokable planning task. It does not assume who implements the plan or what lifecycle surrounds it.

You may use read-only helpers for bounded fact-finding within this planning task.
Their findings inform your plan; they are not its independent review. Return the
completed plan, documentation commit, artifact SHA, checks, and any stop to the
caller. When externally orchestrated, stop there: the orchestrator dispatches
the independent plan reviewer and authorizes any later implementation. Do not
start that reviewer or continue into development from a planning assignment.

## Delivery flow

Run `bin/loop-flow status` in the issue worktree and read [Implementation loop](../../../docs/reference/implementation-loop.md). Name the selected `discovery` or `proof` flow in every handoff. The separately published `.loop/flow.json` binds the choice to the candidate; a missing selection defaults to `discovery`. Proof is opt-in: select `proof` explicitly before planning or reviewing. A repository-default change does not change an existing worktree.

Resolve the issue's `incus` requirement separately from its flow, using that page's table. Record both in the plan and handoff. An `incus` issue requires discovery after plan review; explicit `proof` also requires a separate proof topology. Without the label, plan local checks and no required topology. Report a label mismatch if acceptance needs real machines; do not silently replace those checks with mocks. A rename from `proof:incus` alone is not a flow or product-contract change.

## Inputs

- The issue in the `creating-issues` shape: outcome paragraph, `Scope` In and Out bullets, `Acceptance` checklist, and its labels, attachments, and relations.
- Every ADR attached to the issue, read for its `Decision` bullets and `Affects` block.
- A worktree from `bin/worktree-create <ISSUE>` on the branch `<issue-lowercase>`, with `.loop/plan.md` scaffolded and `.loop/proof/` created; create the same workspace by hand in another checkout workflow.
- Nearby code, tests, and the proof commands the issue's `Proof:` actions name.

Start from the assigned issue worktree and read its branch, `HEAD`, and existing
changes locally, as the implementation loop describes. Planning does not require
a caller-supplied candidate SHA. Treat a copied startup SHA as context unless
the task explicitly requests work on that revision; record a discrepancy and
continue in the verified issue worktree. Do not reset, rebase, recreate the
worktree, or require current main to match it. Preserve existing work and report
an actual checkout, merge-conflict, or writer-ownership problem. Return the
observed revision and produced artifact binding with the completed plan.

Stop, and report the gap instead of planning around it, when:

- the issue is in a lifecycle state other than exactly `Todo` or `In Progress`, still has a `Readiness` section, has an unfinished `blocked by` relation, or has sub-issues;
- the issue does not follow the `creating-issues` template; it is refined through `creating-issues` first;
- an attached ADR's Status is not `Accepted on`, or an attached ADR contradicts the issue;
- an `Acceptance` item has no test, command, or development observation the selected flow can run;
- an `In` bullet needs a component the issue is not labeled with, where a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`;
- the outcome changes documented behavior and the issue has no `docs` label; report it for relabeling; or
- a page cannot be written without guessing product behavior.

## Classify a stop

When an issue that is or was claimable reaches preflight and stops, report one classification with evidence and the smallest next action. An issue that has only ever been intentionally incomplete in `Backlog` is not an issue-creation defect; it is not eligible for preflight.

- `ISSUE_CREATION_DEFECT` — the issue was incomplete, contradictory, or unsupported when it was published directly into a claimable state or at its most recent transition to a claimable state.
- `POST_CREATION_DRIFT` — available history shows that the issue was complete at that claimable-state baseline, then a later change to `main`, an ADR, a dependency, or proof capability made it stale.
- `PREFLIGHT_BLOCKER` — the issue contract remains valid, but current lifecycle or infrastructure state prevents planning. Never use this for an unresolved product or architecture decision.
- `UNKNOWN` — current Linear and Git history cannot establish whether the defect existed at creation or appeared later.

Use existing Linear activity and Git history; do not require a separate creation receipt. A missing or conflicting product decision is an issue defect or later drift when history proves which, and otherwise `UNKNOWN`. Do not use the classification to weaken the stop or repair Linear from the planner role.

## Write the documentation

Before the acceptance map, run `auditing-documentation` in its default issue scope and fix the drift it finds. Then, when the issue carries the `docs` label, write or update the pages that describe the issue's outcome by following `writing-documentation`, stating the behavior the `Acceptance` items deliver in the present tense. For these pages the reference is the issue and its ADRs, not the code; the code follows. Run `composer docs-build` and then `composer docs-lint` from the repository root, then commit every change under `docs/`, including `docs/generated/context.json`, as one commit on the feature branch whose message starts with `docs:`. Save the completed plan and lint receipt together at handoff, as described below. The reviewer saves its verdict on that separate draft ref. A blocked issue keeps its `docs:` commits on the branch, and the next planning pass starts from them. The implementer starts from these pages and corrects them only where implementation deviates.

## Write the plan

In `discovery`, map existing issue `Proof:` venues to focused tests, the local review gate, and reproducible discovery observations without changing acceptance outcomes. Apply the implementation loop's current local check policy to stale generic full no-TIA CI wording; record that mapping and return the issue text correction to the orchestrator. This policy alignment alone is not a product-contract stop. Do not require a proof plan, fixtures, observed inputs, exact-commit proof, or main-freshness checks. For an `incus` issue, name the planned development observations and state that proof instrumentation is not required. Otherwise record `Incus: not required` with the local acceptance checks. Preflight and its independent review precede discovery acquisition. Optional topology extension declarations reuse the existing format; they do not require running proof actions.

Use [the current template](template.md) for every new or revised plan. Keep its `Plan format`, assigned `Issue`, and selected `Flow` headers. Fill every section of `.loop/plan.md` without copying the issue into it:

- **Outcome:** the issue's outcome in one sentence.
- **Code boundaries:** for each `In` bullet, the files or directories that change. For each `Out` bullet, the exclusion that keeps it unchanged. Pages under `docs/` belong to the Documentation section and `.loop/proof/<ISSUE>.json` to the acceptance map; neither is a code boundary or a component.
- **Documentation:** the pages under `docs/` this task changed and what each now states, plus every audit finding it reported instead of fixing, each with its owner. When the label is absent and no drift was found, `none: <why the outcome changes no documented behavior>`. When the label is present and the pages already state the outcome, say so; that is not a stop, and the label is corrected through `creating-issues` afterwards.
- **Acceptance map:** one row per `Acceptance` item, in the issue's order, mapped to its code boundary, or to the page from the Documentation section when documentation is what the item delivers, and the exact focused proof: a test file, a command, or an Incus proof action.
- **Incus observations:** in the `proof` flow with `incus`, plan `observed_inputs: true` when the actions support complete PHP observations on the required surfaces. Otherwise record why instrumentation is unsuitable or incomplete. Keep this decision in the plan; the implementer creates the proof file. See [proof plans](../../../docs/reference/proof-plans.md) for collection and cleanup requirements.
- **Implementation order:** the smallest coherent ordered changes.
- **Must preserve:** every attached ADR `Decision` bullet the change touches, plus the existing tests and invariants that protect adjacent behavior.
- **Open questions:** facts the implementer cannot verify from the repository. A product decision is not an open question; it is a stop.

Set `Review verdict: PENDING` and clear stale findings. Do not create slice files, mandatory per-increment commits, or an agent-per-increment plan. If the plan would absorb more than one independently shippable design, report that the issue needs splitting.

## Corrections

When given review findings, change only the plan content and the pages the findings cite. Do not widen the outcome, weaken a proof, or absorb a new requirement; a new requirement is separate Linear work. Commit changed pages as a further `docs:` commit, mark each addressed finding `addressed:` under `## Review findings`, and set `Review verdict: PENDING` again.

## Verify and hand off

Run `bin/plan-lint record <ISSUE>` after the final plan edit. Fix each reported
structural error and rerun until it exits zero. This writes
`.loop/plan-lint.json`; it does not approve the plan. Then run
`bin/loop-artifacts save <ISSUE>` and
`bin/plan-lint verify <ISSUE> --artifact=<artifact-sha>` with its exact output.
Return that artifact binding and the verification output with the handoff.
A completed planning response requires this receipt. If planning cannot finish,
return the classified stop and evidence instead of claiming completion.

Trust the successful structural check. Do not repeat section, format, or table
checks with extra tool calls. Check the meaning: every `Acceptance` item has a matching row and a suitable runnable proof, every `Out` bullet has an exclusion, the Documentation section lists every changed page and every reported finding with its owner, every change under `docs/` is committed, `composer docs-lint` passes, `Must preserve` names the touched ADR bullets, and no open question hides a product decision.
