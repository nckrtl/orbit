# ADR 0040: Extend issue proof with one app-prod Node

In the context of proving routing across two production workload Nodes, facing a shared topology with only one app-prod Node, we decided for one declared temporary app-prod Node in the issue's proof and against enlarging the shared snapshot or substituting disposable scenario results, to obtain retained multi-node acceptance evidence, accepting an additional Node per extended attempt and snapshot refresh at closeout.

## Status

Accepted on 2026-09-06. Extends [ADR 0006](0006-topology-led-feature-development.md), [ADR 0035](0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot.md), and [ADR 0037](0037-promote-fresh-three-node-topology-snapshots.md) for issue-owned topology extension while preserving the three-node shared snapshot.

## Context

The shared topology has Gateway, app-dev, and app-prod Nodes. Gateway and app-dev cannot also host app-prod, so a production pool spanning two Nodes requires an additional Node. Disposable scenario results cannot establish issue acceptance, and adding a workload Node to the shared snapshot would change every issue's starting topology.

## Decision

- An issue may extend its discovery and proof topologies with exactly one additional app-prod Node when its acceptance requires two production workload Nodes and declares that requirement before construction.
- The harness must start the standard three Nodes from the shared snapshot and construct the additional Node within the same isolated attempt.
- The harness must bind the extension declaration, construction inputs, and complete resource inventory to the attempt and its proof evidence.
- The harness must apply ADR 0006's candidate identity, convergence, acceptance, diagnosis, immutability, ownership, and exact-cleanup rules to the additional Node.
- The harness must account for the additional Node when reserving capacity and recovering or releasing an incomplete attempt.
- The harness must treat an extended proof as non-promotable and use ADR 0035's mutating-proof closeout path after its accepted candidate merges.
- Closeout must preserve the Gateway, app-dev, and app-prod shared snapshot and release every resource of the extended proof and discovery attempts after the snapshot refresh succeeds.
- The harness must not remove the extra Node from a proved attempt to make that evidence promotable.
- The harness must not substitute disposable scenario results for the issue's retained proof.

## Rejected alternatives

- Add a second app-prod Node to the shared snapshot: rejected because every issue would inherit a fourth Node even when its acceptance does not need one.
- Assign app-prod to Gateway or app-dev: rejected because these product roles are incompatible.
- Use the cold scenario as acceptance evidence: rejected because its unconditional disposal does not retain immutable issue proof.
- Delete the additional Node and promote the remaining proved topology: rejected because deleting part of the attempt changes the evidence that review accepted.

## Consequences

- Multi-target routing can be proved on two app-prod Nodes without changing the default topology for subsequent issues.
- Extended discovery and proof each consume one additional Node, and successful proof remains retained through review.
- Closeout refreshes the standard snapshot from merged main; the extended proof remains the routing acceptance evidence until authorized cleanup.
- A dedicated harness issue must deliver extension construction, evidence, capacity, recovery, and cleanup before a product issue can use this proof venue.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0006](0006-topology-led-feature-development.md), [ADR 0035](0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot.md), and [ADR 0037](0037-promote-fresh-three-node-topology-snapshots.md)
- Detail: [Incus topologies](../reference/incus-topologies.md); [Topology snapshot](../reference/topology-snapshot.md)
- Verify: `composer docs-lint`; implementation conformance through declared extended-proof construction, retention, failure recovery, closeout, and exact-cleanup acceptance
