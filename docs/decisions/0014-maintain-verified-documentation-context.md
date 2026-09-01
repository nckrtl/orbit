# ADR 0014: Maintain verified documentation as implementation context

## Status

Accepted on 2026-09-01.

This ADR extends
[ADR 0010](0010-record-decisions-before-implementation-issues.md) by adding
documentation reconciliation to issue shaping, implementation preflight,
development, and review.

## Context

Orbit uses accepted ADRs as durable architecture history and Linear issues as
bounded implementation contracts. The repository also contains operational
reference, reusable solutions, and other documentation that helps contributors
understand the current system.

That documentation becomes more valuable as it accumulates. Agents shaping new
issues or preparing an implementation can use prior behavior contracts,
terminology, operational knowledge, and implementation lessons instead of
rediscovering them from code. This benefit compounds only when the documentation
remains current, internally consistent, and easy to route into an agent's
context.

The current repository keeps a small canonical corpus under `docs/`, divided
into architecture decisions, reference material, and reusable solutions. It
does not yet have a documentation-specific quality gate, a current-state
product spine, domain-oriented routing, or a deterministic way for preflight to
select relevant documents. Feature implementation also does not explicitly
require documentation reconciliation.

The previous Orbit repository contained a Laravel documentation application
using Librarian. Its useful capabilities included structured documentation,
semantic linting, generated machine-readable indexes, and checks comparing
documentation with code surfaces. Its content and many of its rules describe
the previous Orbit architecture and command surface, however. Restoring that
application and corpus wholesale would reintroduce stale product contracts and
a much larger maintenance burden.

Orbit needs the useful tooling from that system without replacing the current
documentation authority, reviving obsolete product behavior, or creating a
second documentation source.

## Decision

### Keep one canonical documentation corpus

All maintained Orbit documentation remains under the repository-root `docs/`
directory. `apps/docs` does not own a separate content tree.

The documentation corpus has these responsibilities:

- `docs/README.md` explains documentation authority and routes readers through
  the corpus.
- `docs/mission.md` records stable product purpose and boundaries.
- `docs/architecture.md` synthesizes the currently accepted architecture and
  links its governing ADRs.
- `docs/tech-stack.md` describes current implementation technologies and their
  ownership boundaries.
- `docs/concepts.md` defines canonical product terminology and links each
  concept to its owning documentation.
- `docs/decisions/` contains the immutable accepted architecture history.
- `docs/domains/` describes current behavior by product domain.
- `docs/reference/` contains stable operational, runtime, and API reference.
- `docs/solutions/` contains reusable implementation and diagnostic lessons.
- `docs/generated/` contains committed machine-readable routing artifacts
  derived from the maintained corpus and repository surfaces.

Existing documentation is preserved and migrated in place. Introducing the
product spine or domain documentation does not relocate or duplicate accepted
ADRs, reference material, or solutions.

### Preserve authority boundaries

Accepted ADRs own product architecture and durable technical boundaries. Linear
issues own requested outcomes, scope, acceptance criteria, relationships,
affected components, and proof requirements.

Current-state documentation explains the system produced by those authorities.
It may synthesize several accepted ADRs, describe implemented behavior, and
provide operational guidance, but it cannot introduce product architecture
that has not been accepted through an ADR.

Code, tests, and proof provide evidence of implemented behavior. Generated
documentation and indexes provide navigation and drift detection. Neither
generated artifacts nor current implementation silently supersede an accepted
ADR or change a Linear contract.

When an ADR, issue, maintained document, code surface, or test contradicts
another authority, the contributor stops and surfaces the conflict. It does not
resolve the conflict by silently changing an accepted ADR, weakening an issue,
or treating generated output as authority.

### Reintroduce a console-first documentation application

Orbit adds `apps/docs` as an independent Composer project. It owns
documentation tooling, semantic rules, generators, and their tests. Its
Librarian configuration points to the root `docs/` corpus.

The application may use Laravel's container, console commands, and
repository-aware PHP inspection where those capabilities make semantic
verification clearer. It does not initially provide a public documentation
website or require a production runtime, database, queue, frontend build, or
deployed service.

A future published documentation site must consume the same root corpus.
Introducing a separate publication runtime or content source requires a later
decision.

Root Composer commands coordinate documentation checks in the same way they
coordinate the existing independent projects. GitHub Actions runs the
documentation project as its own matrix job.

### Generate deterministic agent context

The documentation application produces a committed machine-readable context
index from the maintained corpus. The index relates documentation to applicable
concepts, domains, repository components, and governing ADRs.

A read-only context command accepts known concepts and likely code boundaries
and returns an ordered reading set:

1. governing accepted ADRs;
2. relevant current-state architecture and domain documentation;
3. applicable operational or API reference; and
4. reusable solutions related to the affected boundary.

The context command is a routing aid. It does not invent requirements, decide
product authority, summarize an unknown Linear issue as fact, or replace
repository inspection.

Documentation linting verifies that the committed context index is current.
Generation is an explicit write operation; linting and CI remain read-only.

### Reconcile documentation for every feature

Every implementation issue or equivalent written contract receives a
documentation-impact classification during issue shaping or preflight:

- `required` when the outcome changes durable behavior, product terminology,
  architecture synthesis, a public or operational contract, an agent-relevant
  boundary, or reusable project knowledge; or
- `none` when the implementation leaves those surfaces unchanged, accompanied
  by a concise rationale.

Planning loads the relevant documentation selected from the issue, expected
code boundaries, and context index. When documentation changes are required,
the plan names the affected documentation boundaries alongside code, tests,
and proof.

Implementation updates required documentation in the same pull request as the
behavior it describes. Review verifies that the resulting documentation
matches the exact implementation and governing authorities.

A defect fix that restores already documented behavior, a mechanical refactor,
or another change without durable documentation impact may use `none`. Orbit
does not require contributors to create or modify prose merely to produce a
documentation diff.

The durable requirement is reconciliation, not unconditional document
creation.

### Apply layered documentation verification

The documentation application provides four kinds of checks:

- **Structure checks** verify the required documentation spine, placement,
  headings, and local links.
- **Consistency checks** verify canonical terminology, document relationships,
  and authority boundaries.
- **Semantic checks** compare selected documented contracts with deterministic
  repository surfaces such as CLI commands, API routes, roles, runtime
  defaults, or supported component versions.
- **Generation checks** verify that committed indexes and catalogs match their
  source material.

`composer docs-lint` is the root read-only quality gate. Documentation tooling
also has focused automated tests for custom rules and generators.

A semantic rule is introduced only when it protects a current Orbit invariant
and has focused tests demonstrating both valid and invalid cases. The previous
repository's rules are candidates for reuse, not an obligatory migration set.

Documentation checks do not require a production deployment, external service,
Incus topology, or network access. Live product proof remains separate from
deterministic documentation verification.

### Migrate from the previous repository incrementally

Orbit does not copy the previous documentation corpus into the current
repository wholesale.

Previous documents and rules may be used as historical evidence. Content is
imported only after it has been reconciled against current accepted ADRs,
current code, current tests, and current terminology.

Migration begins with the minimal documentation application, root-corpus
integration, the product spine, basic linting, and agent-context generation.
Domain documentation and semantic rules then return incrementally as current
behavior requires them.

The old numbered command-page hierarchy, generated catalogs, and
command-specific rule set are not presumed to be part of the new documentation
model. Each is retained only when it provides current, verifiable value.

## Consequences

- Orbit gains one canonical documentation corpus for humans and agents.
- Feature work must explicitly reconcile documentation without generating
  meaningless prose changes.
- Preflight can load focused, cumulative project context instead of
  rediscovering every boundary from code.
- Current-state documentation can evolve as features land while accepted ADRs
  remain immutable.
- Documentation drift becomes a deterministic CI failure where it can be
  checked from repository state.
- Semantic linting introduces code and test maintenance, so rules must remain
  proportional to current invariants.
- The previous repository remains a source of reusable ideas and evidence, not
  current product authority.
- `apps/docs` adds another independently verified Composer project but no new
  production service.
- Publishing a documentation website remains optional and separate from
  documentation ownership and verification.
