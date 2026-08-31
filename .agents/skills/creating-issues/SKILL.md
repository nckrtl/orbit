---
name: creating-issues
description: Use when turning an Orbit request or production GitHub report into a Linear issue before implementation.
---

# Creating Issues

Refine an approved request into one or more Linear implementation contracts.
Accepted repository ADRs own architectural decisions. Linear owns outcomes,
scope, acceptance criteria, relationships, affected components, and proof
requirements.

Issue refinement researches current `main` and shapes Linear work. It does not create `.orbit/plan.md` or another implementation plan. That temporary map is
created only after a `Todo` issue is claimed for implementation preflight.

## Issue contract

```text
Status: Todo           # use Backlog when the Readiness condition is unmet

Outcome:

Readiness:             # required when Status is Backlog
- ...

In scope:
- ...

Out of scope:
- ...

Acceptance criteria:
- ...

Components:
- ...

ADR: none

Proof: incus        # only when a real machine is needed; omit otherwise

Source: none
```

- Use verifiable behavior. One acceptance criterion becomes one proof action.
- Name the smallest affected components (`apps/cli`, `apps/gateway`,
  `packages/php-sdk`). Name `apps/e2e` only for a harness issue; a feature
  issue never lists it.
- Add `Proof: incus` when acceptance depends on a real OS, service manager,
  privilege boundary, network, certificate, filesystem ownership, or
  multi-node behavior. Omit it for automated-only work. The topology is
  always `gateway + app-dev + app-prod` on Ubuntu 26.04. Do not describe it.

## Linear states

`Backlog` means the issue is rough or incomplete and still needs refinement.
Its contract, decisions, dependency graph, or proof path may be missing. State
the refinement or readiness condition in the description. Tom never claims it.

`Todo` means the implementation contract is complete, every governing ADR is
on `origin/main`, and every acceptance criterion is verifiable. Todo is the
ordered execution queue. A declared prerequisite may keep a refined Todo issue
temporarily ineligible; Tom skips it until every prerequisite is `Done` and
present on current `origin/main`.

`In Progress` begins when Tom selects an eligible Todo issue, before any
worktree, Herdr worker, or preflight turn starts or resumes. `Blocked` is
reserved for started work that cannot continue; its worktree and Herdr assets
remain parked. `In Review` begins before independent PR review. Issue creation
always sets a new or reshaped issue explicitly to `Backlog` or `Todo`; never
rely on the team's configurable default.

## ADR

Use `ADR: none` only when no ADR applies. List every governing decision as a
canonical `docs/decisions/` URL on `origin/main`.

The ADR threshold is architectural significance, not mere durability. Stop
issue creation when discussion changes architecture, ownership, security, a
cross-feature boundary, or another choice that materially constrains future
work. Tactical implementation choices remain in code, tests, and temporary
implementation planning.

Draft the ADR as `Proposed`. The user reviews its actual text; revise it until
the user explicitly approves the exact final text, then mark it `Accepted`.
Accepted ADRs remain immutable. A later direction becomes a new ADR that names
the decision it extends, amends, or supersedes.

An approved ADR needs neither a Linear issue nor an intrinsic pull request.
It may be committed directly when:

- the user approved the exact final text;
- the commit contains only the approved ADR;
- local `main` matches the current remote base; and
- no unrelated work is modified, included, stashed, reset, or discarded.

If the remote base moves after approval, recheck the ADR against it. A pull request remains optional when the user wants independent review, decision authority is shared, or branch protection requires it.

Push the accepted ADR to `origin/main` before implementation issues are derived. Then reconcile every open issue whose outcome or scope intersects the
decision: surface conflicts, move incomplete work to `Backlog`, block claimed
work that cannot continue, and remove or cancel obsolete work only with
explicit authority.

## Complete-set feasibility

Before any issue in a derived set enters `Todo`, refine the complete set
against current `main`. Inspect the relevant existing product, migration, proof, and harness implementation rather than inferring feasibility from the ADR alone.

Verify all of the following:

- product behavior is decided, including lifecycle, ownership, migration, compatibility, failure, rollback, and removal boundaries that affect the requested outcome;
- each acceptance criterion states observable behavior and has one available proof action through its declared automated or Incus venue;
- the current proof machinery can express that action without a forbidden
  feature-branch harness change;
- the issue dependency graph is explicit and acyclic;
- relationships encode only real prerequisites, not preference, staffing,
  shared files, or possible merge conflicts;
- every prerequisite is explicit, and its status determines whether the
  dependent Todo issue is currently eligible for selection; and
- each issue can be implemented and proved at its graph position without
  redesigning the feature.

When a product contract and its verifier would otherwise block each other,
start with a compatibility bridge that lands and passes against current
`main`. Follow with the product change and remove the fallback only after the
migration. Never create mutually blocking hard cutovers.

Put every fully refined issue into `Todo` and encode real prerequisites as
Linear relationships. Independent roots can execute in parallel. Refined
dependents remain in `Todo` but are skipped until every prerequisite is `Done`
and present on current `origin/main`.

## Preflight accountability

Preflight remains independent and substantive, but correct issue refinement
should normally pass on its first review.

- `PASS` is the normal result. The issue is already `In Progress`; continue to
  implementation.
- `FIX` means the issue remains implementable but the temporary plan missed or
  misstated a code boundary, invariant, order, or acceptance-to-proof mapping.
  Keep the issue `In Progress` while fresh planner and reviewer rounds correct
  it.
- `BLOCK` means the issue was not ready: a product decision, prerequisite,
  ordering boundary, proof path, harness capability, or governing contract is
  missing or contradictory. The reviewer must recommend the smallest safe
  resolution and identify the evidence and decision owner. Tom is routing only:
  he moves the issue to `Blocked`, parks its assets, and records the reviewer
  findings, required owner/action, retained worktree and Herdr assets, and the
  exact restart condition in a Linear comment. It remains parked without consuming execution
  concurrency. Tom must not choose or approve a recommendation,
  refine or edit the issue contract or relations, or move `Blocked` back to
  `Todo`. Nick and Anna own blocker resolution; Anna applies
  approved issue, ADR, or dependency changes and moves the resolved issue to
  `Todo`. On re-selection Tom synchronizes current `origin/main`, preserves the
  issue branch state, and runs a wholly fresh preflight before implementation
  resumes.

Unless `main` materially changed after refinement, a `BLOCK` is an
issue-creation failure. Classify it as product refinement, dependency
ordering, proof or harness feasibility, or repository drift and feed the cause
back into this skill.

## Production reports

Create a Linear bug for a post-deploy defect. Keep the feature closed. Set
`Source` to the GitHub issue and include the deployed commit, environment,
expected and observed behavior, and evidence.

## Implementation readiness

A Linear issue with status `Todo` is refined and ready to enter the execution
queue. Tom may select it only when its declared prerequisites are `Done` and
present on current `origin/main`. No issue enters `Todo` merely because its text
looks complete; the complete-set feasibility checks above must also pass.
