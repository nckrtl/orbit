# Architecture decisions

Architecture decision records explain durable choices that are difficult to
reverse or that govern more than one feature.

Create an ADR for:

- a cross-component contract;
- a durable architecture boundary;
- a security or ownership model; or
- a costly-to-reverse operational choice.

Do not put a new ADR in a dependent feature pull request. Review and merge the
ADR to `main` first. Then link its canonical GitHub URL from every dependent
Linear issue. This lets multiple feature branches use one decision in parallel.

Use the next four-digit number and a short kebab-case name:

```text
0001-short-decision-name.md
```

Each ADR must contain `Status`, `Context`, `Decision`, and `Consequences`.
Keep accepted ADRs immutable. Add a new ADR that supersedes an old decision.
