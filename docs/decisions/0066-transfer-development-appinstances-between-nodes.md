# ADR 0066: Transfer development AppInstances between Nodes

In the context of development AppInstances that need another Node placement, facing remove-and-register workflows that lose managed identity and state, we decided for identity-preserving staged transfer between active Clusters and against source-layout preservation or live migration, to make placement moves recoverable, accepting explicit downtime and destination checkout conversion.

## Status

Accepted on 2026-09-13. Extends [ADR 0027](0027-adopt-local-git-sources-into-appinstance-ownership.md), [ADR 0038](0038-cascade-appinstance-removal-through-processes-and-schedules.md), [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md), [ADR 0063](0063-prefer-active-cluster-tlds-for-generated-routes.md), and [ADR 0065](0065-replace-routes-when-domains-change.md).

## Context

Orbit can create, register, and remove an AppInstance but cannot move its managed placement between Nodes. Removing and registering a replacement loses AppInstance identity and independently rebuilds its Route, configuration, processes, and schedules. A linked worktree cannot move to another Node with its common repository relationship intact, and a cross-Cluster move may also change the generated application domain and routing path.

## Decision

- The Gateway owns transfer of one active development AppInstance between distinct active app-dev Nodes in active Clusters.
- The Gateway must permit a transfer within one Cluster or between two Clusters.
- The Gateway must preserve the AppInstance ID and App ownership through transfer.
- The Gateway must create an independent checkout at the destination for every transferred source layout.
- The Gateway must preserve the source Git state, tracked and untracked content, and unpublished commits in the destination checkout.
- The Gateway must not mutate a source worktree's common repository or sibling worktrees.
- The Gateway must validate the destination placement, capacity, runtime requirements, and requested identity before it mutates the source.
- The Gateway must permit an explicit AppInstance rename when the original destination identity is unavailable.
- The Gateway must transfer only an explicitly selected consistent SQLite snapshot as application database state.
- The Gateway must not transfer an external database or infer another persistent-data source.
- The Gateway must import the source environment into its encrypted configuration before it rebuilds the destination environment.
- The Gateway must preserve AppInstance Process and Schedule records and desired states while it recreates their runtime artifacts on the destination Node.
- The Gateway must use an explicit downtime window to stop source execution and establish consistent transferable state.
- The Gateway must preserve an explicit Route domain during transfer.
- The Gateway must apply the destination Cluster naming authority and replacement Route lifecycle when a generated domain changes.
- The Gateway must restore the source as authoritative when a failure occurs before cutover.
- The Gateway must recover only forward when a failure occurs after cutover.
- The Gateway must delete the old managed placement and runtime artifacts after successful destination cutover.
- The Gateway must not delete a transferred worktree's common repository, sibling worktrees, or local branches during source cleanup.
- The Gateway must not transfer production AppInstances or AppInstances on standalone Nodes.

## Rejected alternatives

- Remove and register a replacement AppInstance: rejected because it loses AppInstance identity and independently destroys or recreates owned state.
- Preserve a linked worktree at the destination: rejected because another Node cannot retain its source Node's common repository relationship.
- Limit transfers to one Cluster: rejected because placement ownership and Route reconciliation already distinguish Cluster scope from AppInstance identity.
- Require zero downtime: rejected because source execution and SQLite writes would prevent one consistent transferable snapshot.
- Discover and copy external databases: rejected because Orbit cannot infer their ownership, credentials, consistency boundary, or destination service.

## Consequences

- Development placement can move between Clusters without replacing the AppInstance record.
- Every destination uses an independent checkout even when the source was a worktree.
- Transfer interrupts application processes and schedules during the cutover window.
- A generated domain can change to the destination Cluster namespace, while an explicit domain remains stable.
- Dirty source content and unpublished commits increase transfer size and make destination verification more expensive.
- A failure after cutover can leave old placement cleanup pending while the destination remains authoritative.
- The Gateway owns transfer and exposes no HTTP route, CLI command, or PHP SDK method for it.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0027](0027-adopt-local-git-sources-into-appinstance-ownership.md), [ADR 0038](0038-cascade-appinstance-removal-through-processes-and-schedules.md), [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md), [ADR 0063](0063-prefer-active-cluster-tlds-for-generated-routes.md), and [ADR 0065](0065-replace-routes-when-domains-change.md)
- Detail: [Applications](../domains/applications.md)
- Verify: `composer docs-lint`; CLI command-surface tests omit instance:transfer; Gateway API routes omit an AppInstance transfer endpoint
