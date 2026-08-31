# Architecture decisions

Architecture decision records explain why Orbit chose a significant direction
and how that choice relates to earlier decisions. Accepted ADRs form an
append-only decision history as the project progresses; they are not
implementation tasks or substitutes for Linear contracts.

The threshold is architectural significance, not mere durability. Create an
ADR for:

- a cross-component contract;
- a durable architecture boundary;
- a security or ownership model; or
- a costly-to-reverse operational choice.

Draft a new ADR as `Proposed`. Revise the actual record with the user until the
user explicitly approves the exact final text, then mark it `Accepted`.
Accepted ADRs remain immutable. A later direction becomes a new ADR that names
the decision it extends, amends, or supersedes.

An approved ADR does not intrinsically need a Linear issue or pull request. It
may be committed directly to `main` only when:

- the user approved the exact final text;
- the commit contains only the approved ADR;
- local `main` matches the current remote base; and
- no unrelated work is included, modified, stashed, reset, or discarded.

If the remote base moves, recheck the ADR before committing. A pull request remains optional when the user requests independent review, multiple people share decision authority, or branch protection requires it.

Put the accepted ADR on `origin/main` before implementation issues are derived. Then reconcile affected open work and link the canonical GitHub URL
from every governed Linear issue. This lets independent issue roots proceed in
parallel from the same decision authority.

Use the next four-digit number and a short kebab-case name:

```text
0001-short-decision-name.md
```

Each ADR must contain `Status`, `Context`, `Decision`, and `Consequences`.

Current delivery-workflow decisions:

- [ADR 0007: Use the nine-step feature flow](0007-nine-step-feature-flow.md)
- [ADR 0010: Record decisions before implementation issues](0010-record-decisions-before-implementation-issues.md)
- [ADR 0011: Separate issue refinement from active delivery](0011-linear-lifecycle-states.md)
