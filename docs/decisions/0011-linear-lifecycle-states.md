# ADR 0011: Separate issue refinement from active delivery

## Status

Accepted on 2026-08-31. This ADR extends ADR 0007 and ADR 0010 and supersedes
their Linear transition and concurrency rules where they conflict.

## Context

Orbit needs to accept rough issues without allowing Tom to start them before
they are refined. At the same time, planning and preflight are real active
work: an issue already has a worktree, a Herdr workspace, and role agents, so
leaving it in `Todo` hides active delivery.

A blocked issue may need Nick's input while he is unavailable. Stopping all
other delivery until that issue is resolved wastes independent capacity, but
deleting its worktree and Herdr state would discard useful recovery context.
Dependent issues must wait without preventing unrelated issues from starting.

## Decision

Linear states have these exact meanings:

- `Backlog`: a rough or incomplete issue that still requires refinement. It is
  never claimable.
- `Todo`: a refined, proof-feasible issue in the ordered execution queue. A
  declared dependency may still make it temporarily ineligible.
- `In Progress`: Tom has selected the issue and started or resumed its Herdr
  lifecycle. Tom moves `Todo` to `In Progress` and reads it back before creating
  or resuming a worker, worktree, or preflight turn.
- `Blocked`: a started issue cannot continue. Its worktree, Herdr workspace,
  branch, plan, and other issue assets remain parked and idle.
- `In Review`: implementation and required proof are complete and independent
  PR review, correction, approval, merge, or closeout remains.
- `Done`: the PR is merged to `main` and required cleanup is verified.
- `Canceled`: the issue is canceled or superseded.

Execution concurrency is the number of Linear issues in `In Progress` plus
`In Review`, with a maximum of three. `Blocked` does not consume execution capacity.
Merge remains singleton.

When capacity is below three, Tom scans `Todo` in the established queue order
and selects the first issue whose declared prerequisites are all `Done` and
present on current `origin/main`. A Todo issue depending on work in `Backlog`,
`Todo`, `In Progress`, `In Review`, or `Blocked` is skipped and remains `Todo`;
Tom continues to the next eligible issue.

Preflight runs while the issue is `In Progress`:

- `PASS` continues into implementation without a status transition.
- `FIX` starts fresh correction-planner and reviewer rounds while the issue
  remains `In Progress`.
- `BLOCK` moves the issue to `Blocked`. Tom adds a Linear comment naming the
  exact blocker and evidence, why routine recovery cannot resolve it, the
  required decision/dependency/action and owner, the retained artifact state,
  and the condition for returning to `Todo`.

When a blocker is resolved, the issue moves `Blocked` to `Todo` and waits in
the execution queue without deleting its retained assets. On re-selection Tom:

1. moves it to `In Progress` and verifies the transition;
2. finds and reuses the existing worktree and Herdr workspace instead of
   creating duplicates;
3. synchronizes the worktree with current `origin/main` while preserving the
   issue branch state; and
4. invalidates the prior preflight approval and runs a fresh planner and fresh preflight review before implementation resumes.

Tom moves an issue to `In Review` before dispatching independent PR review.
Actionable review findings move it back to `In Progress` before the retained
implementer resumes. An approved issue waiting for the singleton merger stays
`In Review` and continues to consume one execution slot.

## Consequences

- Raw issue capture is safe because Tom claims only `Todo` issues.
- Linear shows planning and preflight as active work.
- Independent delivery continues while blocked issues preserve recovery state.
- Dependency-blocked Todo issues wait without consuming capacity or being
  mislabeled as started work.
- Resumed work is revalidated against repository drift before coding continues.
- Linear comments make blocked work directly actionable for Nick.
