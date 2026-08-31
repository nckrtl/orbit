# ADR 0010: Record decisions before implementation issues

## Status

Accepted on 2026-08-31. This ADR supersedes ADR 0007 only where ADR 0007
requires an architecture decision itself to begin as a Linear issue and pass
through the feature pull-request flow. ADR 0007 continues to govern
implementation issues after their governing decisions are on `main`.

## Context

Orbit uses Linear issues as implementation contracts and repository ADRs as
durable architecture history. The existing workflow nevertheless creates a
Linear issue for an ADR, creates dependent implementation issues before the
ADR exists on `main`, and then sends the ADR through the same pull-request
flow as product code.

That sequence puts the implementation queue ahead of its authority. A later
ADR can conflict with already-created issues, while agents do not have the
canonical decision available when those issues are shaped. It also asks the
same person who reached the decision with an agent to review it again through
a pull request without adding an independent decision-maker.

Recent preflight blocks also showed that complete architecture text is not
enough to make an issue ready. Issue creation had left lifecycle behavior and
migration compatibility unresolved, had not inspected a stale proof-harness
contract, and had produced a circular dependency in which neither the product
rename nor its verifier change could land safely first. Preflight correctly
prevented implementation, but it discovered issue-refinement failures after
the issues had already entered `Todo`.

Linear and the repository have different jobs. Linear should describe work
that can be implemented. An ADR should record why Orbit chose an architectural
direction, how that direction relates to earlier decisions, and what future
work it governs. The repository's accepted ADRs collectively form an
append-only decision history; they are not implementation tasks.

The Orbit Linear team has explicit `Backlog` and `Todo` states. It has no
`Preparation` state. An omitted Linear state resolves through configurable
team defaults and therefore cannot express readiness reliably.

## Decision

### Record a decision before deriving work

Feature discussion can start without a Linear issue. When the discussion
introduces or changes a significant architectural decision, issue creation
stops. The agent writes an ADR before it creates or reshapes implementation
issues.

An ADR records `Status`, `Context`, `Decision`, and `Consequences`. It names
the accepted decisions it extends, amends, or supersedes. Accepted ADRs remain
immutable. A later change is a new ADR that preserves the history and names
the relationship explicitly.

The ADR threshold is architectural significance, not mere durability. Use an
ADR for a direction that changes architecture, ownership, security, a
cross-feature boundary, or another choice that materially constrains future
work. Tactical implementation choices remain in code, tests, and temporary
implementation planning.

### Let the decision authority approve the exact record

The agent drafts the ADR as `Proposed` during discussion. The user reviews the
actual text and the agent revises it until the user explicitly approves that
exact content. Approval changes the ADR to `Accepted`.

An approved ADR does not require a Linear issue or pull request. The agent may
commit the ADR directly to a clean, up-to-date `main` and push it to
`origin/main` when all of these conditions hold:

- the user approved the exact final text;
- the commit contains only the approved ADR;
- local `main` matches the current remote base before the commit; and
- no unrelated work is included, modified, stashed, reset, or discarded.

If `main` moves after approval, the agent rechecks the ADR against the new
state before committing. A pull request remains optional when the user asks
for independent review, multiple people share decision authority, or branch
protection requires it. The pull request is a transport or additional-review
choice, not an intrinsic ADR requirement.

### Reconcile existing work immediately

After the accepted ADR is on `origin/main`, the agent inspects every open
Linear issue whose outcome or scope intersects the decision. It surfaces
conflicts before creating new issues.

- An incomplete or dependent issue stays in `Backlog`.
- A `Todo` issue that no longer conforms to the ADR returns to `Backlog`.
- An `In Progress` or `In Review` issue that cannot continue safely moves to
  `Blocked` and names the conflict.
- Obsolete issues are canceled or removed only with explicit authority.

The agent then derives implementation issues from the accepted decision. Each
issue links every governing ADR using its canonical `origin/main` URL.

### Give Linear states exact admission meaning

`Backlog` means the issue is recorded but is not ready to implement. It can be
incomplete, await a dependency, or still require a product decision.

`Todo` means the implementation contract is complete, its acceptance criteria
are verifiable, every governing ADR is on `origin/main`, and no unresolved
dependency prevents work from starting. A `Todo` issue can be claimed without
another product decision.

Agents always set `Backlog` or `Todo` explicitly during issue creation. They do
not rely on the team's configurable default. `Blocked` is reserved for work
that had started and can no longer proceed.

### Shape implementation work for safe parallel delivery

One approved direction can produce one or more Linear issues. The issue set
optimizes the shortest safe delivery path rather than maximizing issue count.
Separate issues own independently verifiable outcomes that can be implemented,
proved, reviewed, and merged in parallel. Work stays together when partial
delivery would violate an invariant, create an incompatible intermediate
state, or require a coordinated merge.

Issue dependencies represent only real prerequisites. Preferred order,
staffing, shared files, or possible merge conflicts do not create product
dependencies. Every complete and unblocked issue can enter `Todo` together.

### Refine the complete issue set against current main

Before any issue in a newly derived set enters `Todo`, its creator performs a
lightweight feasibility pass over the complete set and the current repository.
This is issue refinement, not implementation planning, and it creates no
repository plan.

The creator verifies all of the following:

- product behavior is decided, including lifecycle, ownership, migration,
  compatibility, failure, rollback, and removal boundaries that affect the
  requested outcome;
- relevant existing product, migration, proof, and harness implementations on
  current `main` have been inspected rather than inferred from the ADR alone;
- every acceptance criterion names verifiable behavior and has an available
  proof action through the declared automated or Incus venue;
- the current proof harness can express and verify the proposed contract
  without a forbidden feature-branch harness change;
- the issue dependency graph is explicit and acyclic;
- each prerequisite is already on `main` before its dependent issue enters
  `Todo`; and
- each issue can be implemented and proved within its declared scope at its
  position in the graph without redesigning the feature.

When a product contract and its harness verifier would otherwise block each
other, the issue set starts with a compatibility bridge that can land and pass
against current `main`. The product change follows, and a later cleanup can
remove the legacy fallback after migration. The creator does not admit two
mutually blocking hard cutovers.

Only root issues whose prerequisites are already on `main` enter `Todo`.
Dependent issues remain in `Backlog`, even when their contracts are otherwise
complete. After a prerequisite merges, the creator rechecks the dependent
issue against the new `main` before moving it to `Todo`. Independent roots can
enter `Todo` together and execute in parallel.

### Make preflight an independent verification of readiness

Preflight remains substantive and independent. It is not a formality, but a
correctly refined issue is expected to receive `PASS` on its first review.

- `PASS` is the normal result. The issue moves to `In Progress` and
  implementation begins.
- `FIX` means the issue can still be implemented, but the temporary
  implementation plan missed or misstated a code boundary, invariant, order,
  or acceptance-to-proof mapping. The issue remains `Todo` while a fresh
  planner and reviewer correct the plan.
- `BLOCK` means the issue was not ready: a product decision is unresolved, a
  prerequisite or ordering boundary is missing, the harness cannot prove the
  contract, the scope conflicts with a governing ADR, or implementation is
  impossible inside the issue. The claimed issue moves to `Blocked` until its
  contract or dependency graph is repaired, then returns to `Todo` for a fresh
  preflight.

Unless `main` materially changed after issue refinement, a `BLOCK` is an
issue-creation failure. Its cause is classified as product refinement,
dependency ordering, proof or harness feasibility, or repository drift and is
fed back into the creating-issues guidance. The intended trend is that
first-review `PASS` is normal, `FIX` is occasional, and `BLOCK` is exceptional.

### Keep implementation planning after admission

Linear owns implementation outcomes, scope, acceptance criteria,
relationships, affected components, and proof requirements. Repository ADRs
own architectural decisions.

Issue creation does not create `.orbit/plan.md` or plan implementation order.
The worktree-local `.orbit/plan.md` remains a temporary implementation
preflight created after a `Todo` issue is claimed. It never becomes a second
product or architecture authority.

## Consequences

- Agents see accepted architecture on `main` before they shape or claim work.
- New decisions trigger immediate reconciliation instead of waiting for a
  future implementer to discover conflicts.
- ADR history records architectural progression without parallel Linear
  records that exist only to create the ADR.
- Direct commits remove a redundant pull-request ceremony but require exact
  user approval and strict ADR-only commit hygiene.
- Linear status communicates real readiness instead of an undocumented
  `Preparation` convention.
- Independent implementation outcomes can proceed in parallel once their
  governing decision is accepted.
