# ADR 0037: Promote fresh three-node topology snapshots

In the context of replacing the legacy application samples in Orbit's shared development topology, facing a snapshot that carries unsupported state and a cold scenario that deletes its result, we decided for retaining and promoting an explicitly requested fresh three-node topology and against converting the old samples or rebuilding every issue topology from scratch, to give subsequent discovery and proof runs an AppInstance-only starting point, accepting the construction cost and retention of one replacement candidate through review.

## Status

Accepted on 2026-09-06. Extends [ADR 0036](0036-support-only-appinstances.md). Supersedes [ADR 0005](0005-rolling-incus-development-topology.md) for explicitly requested cold snapshot replacement, [ADR 0006](0006-topology-led-feature-development.md) for the starting state of that replacement's proof, and [ADR 0019](0019-run-disposable-incus-scenario-lanes.md) for cold runs declared as snapshot replacements before construction.

## Context

The shared cold constructor already builds an isolated topology from a generic base image and synchronizes an exact repository commit. Its scenario caller constructs four Nodes and unconditionally removes them, while normal issue proof starts from the promoted snapshot. Replacing the legacy samples requires a fresh Gateway, app-dev, and app-prod topology that can become the shared snapshot after verification and review.

## Decision

- The harness may construct a cold snapshot replacement when an issue explicitly declares that purpose before construction.
- The harness must reuse the shared cold constructor with the registered three-node Gateway, app-dev, and app-prod topology.
- The replacement must start from the configured generic base image and the exact candidate commit, without inheriting application records, source directories, or runtime state from the old snapshot.
- The replacement must construct its sample workloads through AppInstance and App-owned Route contracts under ADR 0036.
- The replacement must satisfy ADR 0006's issue-owned proof, declared acceptance, immutability, diagnosis, and exact cleanup requirements; cold construction replaces only the snapshot-cloning starting step.
- The harness may promote the verified replacement after review and merge satisfy the existing source-acceptance rules in [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md).
- The harness must preserve the current promoted generation until the replacement passes its required gates and the promotion transaction can replace it under the existing recovery rules.
- The promoted replacement must become the single shared snapshot for subsequent discovery and proof acquisition; normal issue cycles must continue to start from that snapshot.
- The harness must retire the replaced legacy sample state through exact snapshot-resource replacement, without converting its workloads or supplying fleet migration tooling.
- Ordinary regression scenarios must retain ADR 0019's disposable lifecycle and must not acquire acceptance or promotion authority after they run.

## Rejected alternatives

- Convert the legacy sample workloads: rejected because ADR 0036 requires native AppInstance construction and excludes Orbit-provided migration tooling.
- Run the existing cold scenario and discard its topology: rejected because the verified fresh topology would not become the next shared snapshot.
- Construct every discovery and proof topology from the generic base image: rejected because subsequent issue cycles can reuse the verified replacement snapshot.
- Promote the four-node scenario recipe: rejected because the shared development topology remains Gateway, app-dev, and app-prod.

## Consequences

- A verified fresh topology can replace a snapshot containing legacy samples without carrying their state forward.
- Cold replacement reuses construction code but requires integration with retained proof evidence and snapshot promotion before dependent work can use it.
- The candidate consumes three Nodes through review; failed construction or verification cannot replace the current snapshot.
- ORB-91 can require fresh AppInstance sample construction and promotion, while ORB-88 can remove legacy product surfaces after that snapshot replacement is delivered.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0036](0036-support-only-appinstances.md); supersedes [ADR 0005](0005-rolling-incus-development-topology.md) for cold replacement, [ADR 0006](0006-topology-led-feature-development.md) for replacement proof construction, and [ADR 0019](0019-run-disposable-incus-scenario-lanes.md) for declared replacement runs
- Detail: [Topology snapshot](../reference/topology-snapshot.md)
- Verify: `composer docs-lint`; implementation conformance through the declared cold replacement acceptance and subsequent discovery and proof acquisition
