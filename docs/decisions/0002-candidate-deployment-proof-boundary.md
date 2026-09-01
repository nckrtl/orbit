# ADR 0002: Keep proof resources task-owned and production separate

## Status

Accepted on 2026-08-26. The former shared-live development-proof venue is
superseded by [ADR 0006](0006-topology-led-feature-development.md). The resource
ownership and production-separation boundaries in this record remain accepted.

## Context

Infrastructure proof can create nodes, routes, certificates, files, services,
and other state that resembles pre-existing or production resources. Without a
strict ownership boundary, a development task can mutate or delete state it did
not create, and proof activity can become an undeclared production release.

## Decision

### Record exact task ownership

Every disposable proof resource records its Orbit owner, issue, attempt, and
operation when it is created. The proof inventory stores exact immutable
identifiers and the expected ownership metadata.

Shared and pre-existing resources are never adopted as task-owned state. A
resource lacking the expected metadata is outside the task boundary even when
its name resembles a generated resource.

### Mutate only recorded resources

Proof setup and cleanup may mutate only resources listed in the task's exact
inventory after live ownership is revalidated. Operations never select by
prefix, glob, age, broad query, or unresolved variable.

A mismatch stops the operation without changing the resource. Cleanup verifies
exact absence after deleting task-owned state and reports any remaining
ownership drift.

### Keep proof evidence bounded

Proof records bind one candidate commit, one attempt, declared actions, and
observed results. Diagnostic state is not successful proof. A changed candidate
requires new proof rather than reusing old evidence.

### Keep production release separate

Development proof never targets or reuses production resources. Production
release, production credentials, and post-release verification are separate
operations with separate authorization and evidence.

A development merge does not imply production deployment. Disposable proof
resources must be absent before any later environment refresh or production
operation can begin.

## Consequences

- Exact metadata and inventory prevent accidental adoption or deletion of
  unrelated resources.
- Cleanup failure is visible and blocks reuse of the affected proof boundary.
- Proof evidence remains tied to one exact candidate.
- Production changes cannot hide inside development verification.
