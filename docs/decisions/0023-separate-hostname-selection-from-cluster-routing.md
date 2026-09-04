# ADR 0023: Separate hostname selection from Cluster routing

In the context of standalone and clustered AppInstances, facing generated names that change merely because a Node joins a Cluster and Cluster Routes that still require workload Caddy, we decided for Route-owned hostnames with Node-first generation and routing scope derived independently from active Cluster membership, and against Cluster-first naming, AppInstance-owned hostnames, and public workload exposure, to preserve one hostname across direct private and routed paths, accepting coordinated Route reconciliation and duplicate derived Caddy projections.

## Status

Accepted on 2026-09-04. Extends [ADR 0009](0009-clustered-app-instance-routing.md), [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md), and [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md). Supersedes [ADR 0017](0017-optional-cluster-placement-and-tld-precedence.md) where it gives the active Cluster TLD precedence over the Node TLD, uses TLD presence to select routing scope, permits direct public workload publication, and makes a Router optional for every TLD-less Cluster.

## Context

ADR 0017 couples generated hostname selection to the traffic path, so joining an active TLD-bearing Cluster can replace a useful Node hostname even though the workload remains on the same Node. A Router must recognize every Cluster Route while workload Caddy must recognize the same hostname to serve the selected AppInstance, including an explicit production hostname that uses no Node or Cluster TLD. Orbit needs hostname ownership, generated-name selection, routing scope, and public exposure to remain distinct while every affected Route changes consistently with its Node or Cluster.

## Decision

- A Route owns its hostname; an AppInstance must not store a second authoritative hostname.
- Orbit must use an explicitly supplied Route hostname instead of generating one.
- Orbit must generate a development Route hostname from the Node TLD when that TLD exists.
- Orbit may generate a development Route hostname from the active Cluster TLD only when the Node has no TLD.
- An app-dev Node must have a Node TLD or belong to an active Cluster that has a TLD.
- An app-prod Node may have no TLD whether it is standalone or belongs to a Cluster.
- Active Cluster membership determines Cluster routing scope independently from the hostname and the presence of a Cluster TLD.
- A Cluster that owns a Route must have exactly one active Router, including when the Cluster has no TLD.
- A Cluster-scoped Route must project its one hostname to the Cluster Router and to every workload Node that hosts one of its targets.
- A private client may reach an allowed workload Node projection directly without changing Route ownership or Cluster scope.
- Public traffic must enter through the Route's active Ingress; Orbit must not publish a workload Node as a direct public endpoint.
- Orbit must preserve the logical Ingress, Router, and workload path when those roles share one Node.
- A Node or Cluster mutation that changes a Route hostname, scope, or projection must reconcile every affected Route before the mutation becomes authoritative.
- Route reconciliation must preserve explicit hostnames and must recompute only generated hostnames whose effective TLD changes.
- Orbit must refuse the complete Node or Cluster mutation when any affected Route has no valid resulting hostname, scope, target, or required routing role.

## Rejected alternatives

- Give the active Cluster TLD precedence over the Node TLD: rejected because Cluster membership would rename a working Node hostname even when the Node keeps its own namespace.
- Store the hostname on AppInstance: rejected because one Route must retain hostname identity with zero targets and may select AppInstances on more than one Node.
- Treat Router and workload Caddy configuration as separate hostname records: rejected because one Route change would not keep two authoritative records equal.
- Allow public traffic to reach a workload Node directly: rejected because it exposes placement and moves public TLS and firewall ownership away from Ingress.

## Consequences

- Joining a Cluster does not rename a generated Route while its Node TLD remains available.
- A TLD-less Cluster can route explicit production hostnames through its Router.
- Router and workload Nodes carry duplicate derived Caddy projections for one Route hostname.
- Active Cluster membership can change routing scope even when the generated hostname does not change.
- Node TLD, Cluster TLD, Cluster state, and membership changes can require coordinated reconciliation across many Routes.
- An app-dev workload cannot run on a Node after that Node loses its last effective TLD.
- An app-prod workload can run on a Node without a TLD.
- Public traffic keeps one Ingress-owned boundary even when Ingress, Router, and app-prod roles are co-located.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0009](0009-clustered-app-instance-routing.md), [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md), and [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md); supersedes [ADR 0017](0017-optional-cluster-placement-and-tld-precedence.md) for generated-hostname precedence, routing-scope selection, Router requirements, and direct public workload publication
- Detail: docs/reference/routes.md
- Verify: `bin/test` verifies the typed Route, placement, scope, and reconciliation contracts
