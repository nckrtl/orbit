---
title: "ADR 0063: Prefer active Cluster TLDs for generated Routes"
sidebarTitle: "0063 Prefer active Cluster TLDs for generated Routes"
description: "Accepted on 2026-09-13. Supersedes ADR 0023 only where generated development Route hostnames prefer the Node TLD over the active Cluster TLD."
---

# ADR 0063: Prefer active Cluster TLDs for generated Routes

In the context of generated development Route hostnames, facing Nodes whose own TLD keeps a Cluster member outside the Cluster namespace, we decided for explicit hostname, active Cluster TLD, then Node TLD precedence and against Node-first generation, to make active Cluster membership own the shared application namespace, accepting hostname reconciliation when Cluster authority changes.

## Status

Accepted on 2026-09-13. Supersedes [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing) only where generated development Route hostnames prefer the Node TLD over the active Cluster TLD.

## Context

[ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing) gives a Node TLD precedence over its active Cluster TLD. A generated Route can therefore have Cluster routing scope while retaining a Node-specific namespace. Development AppInstances that move between Clusters need their generated hostname to identify the destination Cluster, while an operator-supplied hostname must remain independent from generated naming.

## Decision

- Orbit must use an explicitly supplied Route hostname instead of generating one.
- The Gateway must use an active Cluster TLD before a Node TLD when it generates a development Route hostname for a Cluster member.
- The Gateway must use the Node TLD when no active Cluster with a TLD owns the Node.
- The Gateway must refuse generated hostname selection when neither an active Cluster TLD nor a Node TLD is available.
- Orbit must recompute a generated Route hostname when its effective Cluster or Node TLD authority changes.
- Orbit must preserve an explicit Route hostname when Cluster membership, Cluster state, Cluster TLD, or Node TLD changes.

## Rejected alternatives

- Prefer the Node TLD for an active Cluster member: rejected because the generated hostname would not identify the Cluster that owns its routing scope.
- Always preserve a generated hostname when an AppInstance changes Cluster: rejected because the old Cluster namespace would remain authoritative after placement moves.
- Recompute explicit hostnames from Node or Cluster TLDs: rejected because it would discard operator-selected Route identity.

## Consequences

- Generated development Routes in an active TLD-bearing Cluster share that Cluster namespace even when their Nodes also have TLDs.
- Moving a development AppInstance between Clusters can change its generated hostname to the destination Cluster namespace.
- Activating, deactivating, joining, leaving, or renaming a TLD-bearing Cluster can rename generated Routes that previously retained their Node namespace.
- A Node TLD remains the generated-name fallback outside an active TLD-bearing Cluster.
- Existing Route reconciliation and open issue contracts must adopt the new precedence before they can claim conformance.

## Affects

- Components: apps/gateway
- ADRs: supersedes [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing) for generated development Route TLD precedence
- Detail: [Routes](/reference/routes)
- Verify: `composer docs-lint`, Gateway Route generation and reconciliation tests, and generated Route resolution across Node and Cluster TLD changes on disposable Nodes
