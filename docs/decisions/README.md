# Architecture decisions

Architecture decision records explain why Orbit chose a significant product or
technical direction. Accepted ADRs form an append-only decision history; they
are not implementation tasks, issue lifecycle rules, or agent instructions.

The threshold is architectural significance, not mere durability. Create an
ADR for:

- a cross-component contract;
- a durable architecture boundary;
- a security or ownership model; or
- a costly-to-reverse operational choice in the product or its infrastructure.

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

If the remote base moves, recheck the ADR before committing. A pull request
remains optional when the user requests independent review, multiple people
share decision authority, or branch protection requires it.

Put an accepted ADR on `origin/main` before deriving implementation issues that
depend on it. Link the canonical GitHub URL from each governed issue.

Use the next four-digit number and a short kebab-case name:

```text
0001-short-decision-name.md
```

Each ADR must contain `Status`, `Context`, `Decision`, and `Consequences`. ADRs from 0020 onward follow the template beside the `recording-decisions` skill under `.agents/skills`, add `Rejected alternatives` and `Affects`, and pass the `orbit.adr_structure` and `orbit.adr_language` lint rules in `apps/docs`.

An ADR records one decision, why it won, and what it binds. It does not carry mechanism: command syntax, verification checklists, error text, field lists, and Doctor behavior belong in the implementing issue's acceptance criteria and, once shipped, in the `docs/reference` page named by the ADR's `Affects` section.

Current contributor-governance decision:

- [ADR 0010: Record decisions before implementation issues](0010-record-decisions-before-implementation-issues.md)
