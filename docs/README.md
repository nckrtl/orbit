# Orbit documentation

This directory is Orbit's single maintained documentation corpus. It gives
humans and agents current product context without replacing the authority of
accepted architecture decisions or Linear implementation contracts.

## Authority

- Accepted records under [decisions](decisions/README.md) own product
  architecture and durable technical boundaries.
- Linear issues own requested outcomes, scope, acceptance criteria, affected
  components, relationships, and proof requirements.
- [Architecture](architecture.md), [concepts](concepts.md), domain documents,
  references, and solutions explain the current system within those
  authorities.
- Files under `generated/` route readers to maintained sources. They are never
  product authority.

When these sources conflict, stop and reconcile the conflict. Do not silently
change an accepted ADR, weaken an issue, or trust generated output over its
sources.

## Start here

- [Mission](mission.md) describes Orbit's purpose and boundaries.
- [Architecture](architecture.md) summarizes components, relationships, state,
  and ownership.
- [Tech stack](tech-stack.md) records current implementation technologies.
- [Concepts](concepts.md) routes canonical product terminology.
- [Product domains](domains/README.md) holds current behavior by domain as it is
  introduced.
- [Reference](reference/) records stable operational and API contracts.
- [Solutions](solutions/README.md) records reusable implementation lessons.

## Verified context

The console-only `apps/docs` project owns documentation linting and context
generation. Its Librarian configuration reads this root corpus directly.

Run the read-only quality gate from the repository root:

```bash
composer docs-lint
```

Rebuild the committed routing index explicitly after changing its sources:

```bash
composer docs-build
```

Ask for an ordered reading set by component, concept, or both:

```bash
composer docs-context -- --component=apps/gateway --concept=Cluster
```
