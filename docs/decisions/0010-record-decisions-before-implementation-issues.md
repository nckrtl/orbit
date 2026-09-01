# ADR 0010: Record decisions before implementation issues

## Status

Accepted on 2026-08-31.

## Context

Orbit uses Linear issues as implementation contracts and repository ADRs as
durable architecture history. Creating implementation issues before their
governing decision exists on `main` can put work ahead of its authority, produce
conflicting contracts, and force later issue rewrites.

Architecture text alone is not enough to shape safe work. Issue creation must
also inspect current product, migration, proof, and harness behavior; close
compatibility and rollback boundaries; and avoid circular dependencies or
mutually blocking hard cutovers.

Linear and ADRs have different jobs. Linear describes work with observable
outcomes. An ADR records why Orbit chose a significant direction and how that
direction relates to earlier decisions.

## Decision

### Record significant decisions first

Discussion may begin without a Linear issue. When it introduces or changes
architecture, ownership, security, a cross-component contract, or another
choice that materially constrains future work, stop issue creation and write an
ADR.

An ADR contains `Status`, `Context`, `Decision`, and `Consequences`. It names the
accepted decisions it extends, amends, or supersedes. Tactical implementation
choices remain in code, tests, and temporary planning.

### Require exact-text approval

Draft the ADR as `Proposed`. The user reviews the actual text and revisions
continue until the user explicitly approves that exact content. Approval changes
the ADR to `Accepted`.

Accepted ADRs remain immutable. A later change becomes a new ADR preserving the
history and naming the relationship.

An approved ADR may be committed directly to clean, current `main` when:

- the user approved the exact final text;
- the commit contains only the approved ADR;
- local `main` matches the current remote base; and
- no unrelated work is modified, included, stashed, reset, or discarded.

A pull request remains optional when the user requests independent review,
decision authority is shared, or branch protection requires it.

### Reconcile affected work

After an accepted ADR reaches `origin/main`, inspect every open issue whose
outcome or scope intersects the decision. Surface conflicts, return incomplete
contracts to `Backlog`, and cancel obsolete work only with explicit authority.

Each implementation issue links every governing ADR through its canonical
`origin/main` URL.

### Use issue maturity explicitly

`Backlog` records a request whose contract is incomplete or whose readiness
condition is unmet.

`Todo` records a complete implementation contract with accepted governing ADRs,
observable acceptance criteria, an available proof venue, and explicit real
prerequisites. Issue creators always set `Backlog` or `Todo` instead of relying
on a configurable team default.

### Shape a feasible issue set

One accepted direction may produce one or more issues. Separate issues own
independently verifiable outcomes. Keep work together when partial delivery
would violate an invariant, create an incompatible intermediate state, or
require a coordinated cutover.

Dependencies represent only real prerequisites. Preferred order, staffing,
shared files, or possible merge conflicts are not product dependencies.

Before finalizing a derived issue set, inspect current `main` and verify:

- product behavior is decided, including ownership, migration, compatibility,
  failure, rollback, and removal boundaries affecting the outcome;
- relevant existing product, migration, proof, and harness code was inspected;
- every criterion names observable behavior and has an available automated or
  Incus proof action;
- current proof machinery can express the contract without mixing product and
  harness changes;
- the dependency graph is explicit and acyclic; and
- each issue can be implemented and proved at its graph position without
  redesigning the request.

When product and verifier changes would otherwise block each other, begin with
a compatibility bridge that passes against current `main`. Apply the product
change afterward and remove the fallback only after migration.

### Keep issue contracts and plans separate

Linear owns outcomes, scope, acceptance criteria, relationships, affected
components, and proof requirements. ADRs own architecture. `.orbit/plan.md` is
optional implementation context and never becomes a second product contract or
architecture authority.

## Consequences

- Contributors see accepted architecture before shaping implementation work.
- Decisions trigger issue reconciliation instead of leaving conflicts for later
  discovery.
- ADR history records architectural progression without duplicate issues that
  exist only to create the ADR.
- Linear communicates issue maturity explicitly.
- Complete issue sets are checked for proof feasibility and compatible delivery
  boundaries before implementation begins.
