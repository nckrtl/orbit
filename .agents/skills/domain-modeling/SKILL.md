---
name: domain-modeling
description: Use when shaping Orbit terminology, entity relationships, ownership, lifecycle, or durable product boundaries.
---

# Domain Modeling

Sharpen Orbit's model while a request is being shaped. This is a read-only design discipline. It proposes canonical language and decision records; it does not publish future behavior in documentation, create an ADR, create an issue, or change code.

## Authority and evidence

Read `docs/concepts.md`, relevant accepted ADRs, maintained documentation, current code, and nearby tests. Accepted ADRs govern durable choices. Code and tests establish current behavior. Documentation establishes the current public explanation. Report contradictions instead of choosing one source silently.

## Model the change

- Challenge a term when Orbit already gives it another meaning, or when one word is being used for distinct concepts.
- Propose one precise canonical term and use it consistently after the user accepts it.
- Identify who owns each record, identifier, state transition, route, or side effect.
- Test relationships with concrete scenarios, especially creation, mutation, movement, failure, retry, rollback, and deletion.
- Separate current behavior from proposed behavior. A proposed glossary change belongs in the shaping handoff until planning or implementation publishes the delivered behavior.
- Identify the smallest decision boundary. A tactical mechanism local to one implementation belongs in `.loop/plan.md`, code, and tests. When it has an observable consequence, shaping and the later issue record that consequence, not the mechanism. A significant choice about architecture, ownership, security, a cross-component contract, or another durable constraint requires an ADR accepted on `origin/main` before dependent issues are created.

When an ADR is required, state the decision that needs authority, the affected concepts and independently developed work, and the real alternatives. Leave acceptance and publication to `recording-decisions`.
