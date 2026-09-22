---
title: "ADR 0114: Expand the three-node Incus Cluster"
sidebarTitle: "0114 Expand the three-node Incus Cluster"
description: "Proposed. Exercise shared roles and Cluster routing without another VM."
---

# ADR 0114: Expand the three-node Incus Cluster

## Status

Proposed.

## Context

The registered disposable topology has three VMs, but the sample Cluster contains only app-dev and routes locally. Agents need database, WebSocket, Ingress, and routing between Nodes without paying for another VM in every session.

## Decision

Extend [ADR 0005](/decisions/0005-rolling-incus-development-topology) while retaining its three physical Nodes and snapshot identities. Gateway carries gateway, vpn, websocket, and router. App-dev carries app-dev, metrics, and database. App-prod carries app-prod and ingress. All three belong to the existing active, TLD-less `e2e-development` Cluster. Gateway owns its Router assignment.

Convergence uses Orbit commands to expand the existing sample Cluster and replace its app-dev Router. It preserves the explicit private sample Route. It rejects conflicting membership and role ownership. Readiness verifies the shared Cluster and required assignments. The database role supplies Docker; database containers and richer workloads remain separate additions.

Keep the cold acceptance scenario's declared recipe independent. The optional fourth production Node receives only app-prod and does not join the default Cluster automatically. Snapshot promotion remains an explicit operation after resource preparation and verification.

## Rejected alternatives

- Add a fourth VM to every topology: this raises the cost of every agent session for tests that only need one development host.
- Put Router on app-prod: supported, but production routing stays on one VM and exercises fewer network hops.
- Add app-dev to Gateway or app-prod: both combinations conflict with Orbit's current role rules.

## Consequences

The default exercises more roles and routing between Nodes with the same VM count. It shares development and production test workloads in one routing boundary. Tests that need two development hosts still require a separate recipe. The old saved generation does not satisfy the expanded profile until explicitly updated; ordinary acquisition does not silently provision it.

## Affects

- Components: apps/e2e
- ADRs: Extends [ADR 0005](/decisions/0005-rolling-incus-development-topology).
- Detail: [Incus topology registry](/reference/incus-topologies), [Topology snapshot](/reference/topology-snapshot).
- Verify: E2E affected tests, shared Cluster convergence and readiness on a disposable Incus topology.
