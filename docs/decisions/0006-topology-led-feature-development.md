# ADR 0006: Separate disposable discovery from immutable proof

## Status

Accepted on 2026-08-29. This decision builds on
[ADR 0005](0005-rolling-incus-development-topology.md) and supersedes the
shared-live development-proof venue from
[ADR 0002](0002-candidate-deployment-proof-boundary.md). ADR 0002's ownership
and production-separation principles remain in force.

## Context

ADR 0002 proved candidate code on registered shared live nodes. That venue
required serial rollout, recorded pre-state, careful restoration, and broad
coordination because proof state could outlive one change.

ADR 0005 introduced cheap issue-specific Incus topologies created from a
prepared topology snapshot generation. Disposable resources remove the
shared-state constraints, but exploratory changes must not contaminate evidence
for the exact candidate commit.

## Decision

### Separate discovery and proof attempts

Work requiring real-machine proof uses separate disposable attempts:

- `discovery` may mount the worktree and may be changed while diagnosing and
  developing the requested behavior;
- `proof` never mounts host state and synchronizes one exact candidate commit
  from Git.

Each attempt has its own ID, network, instances, devices, inventory, and state.
An issue may have one discovery attempt and one proof attempt at the same time.
Discovery remains the default target for development commands.

Discovery output is diagnostic context, not proof evidence. Keep discovery
available while a fresh proof topology is created from the promoted topology
snapshot. This lets development continue on discovery when proof fails.

### Prove one exact commit

A proof attempt:

1. creates a fresh topology from the prepared topology snapshot generation;
2. synchronizes the exact candidate commit from Git;
3. verifies clean guest checkout identity at that commit;
4. runs repository convergence;
5. runs declared proof setup;
6. runs one acceptance action per acceptance criterion; and
7. records the complete result.

The proof record binds the attempt ID, candidate commit, topology recipe,
declared actions, observed result of each action, and a status of `proved` or
`diagnosis`.

A transport failure may be retried once before clean guest checkout identity is
verified. Any later failure, or a second transport failure, changes the attempt
to `diagnosis`. Diagnosis resources may be inspected but can never become
proved. `shell --proof` and `exec --proof` provide explicit unprivileged access
to the retained failed proof while normal commands continue to target
discovery. Release only the failed proof before another proof attempt.

Every declared setup and acceptance action must exit `0`. A timeout or any
other nonzero exit makes the result a diagnosis. Review starts only after the
complete declared action sequence exits `0`.

### Keep successful proof immutable

A proved topology is immutable. The harness rejects synchronization, command
execution, and state changes against it. Changing it to diagnosis is one-way.
Releasing it invalidates the proof.

A new candidate commit makes prior proof stale automatically. Proof consumers
must require an active proved topology whose recorded candidate equals the
commit being evaluated, whose recorded plan fingerprint matches the current
plan, and whose complete action sequence contains only zero exits.

### Clean up by exact inventory

Cleanup revalidates ownership and removes only the exact resources recorded for
the attempt. It never deletes by prefix, glob, age, broad query, or unresolved
variable. Every resource records its Orbit owner, issue, attempt, and operation.

After promotion, the harness releases the successful proof and the retained
discovery topology. Neither disposable topology may linger after it becomes
the new topology snapshot generation.

### Preserve production separation

Development proof never reuses production resources. Production release and
post-release verification remain separate operations. Shared or pre-existing
resources are never adopted as task-owned state.

## Consequences

- Exploration remains fast and flexible without weakening proof.
- Discovery remains available when proof fails, while the failed proof remains
  separately available for direct comparison and diagnosis.
- Proof demonstrates one exact commit on a physically fresh topology.
- Stale proof is detected from candidate identity rather than a separate
  invalidation ledger.
- Exact ownership and cleanup protect unrelated Incus resources.
- The repository must maintain attempt-scoped inventories, proof records,
  immutable proved-state checks, and exact cleanup in `apps/e2e`.
