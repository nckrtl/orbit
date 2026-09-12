# ADR 0062: Select Cluster Router DNS addresses from LAN intent

In the context of Cluster members reaching private Routes, facing a VPN detour between Nodes with configured LAN connectivity, we decided for central DNS address selection from registered Node and Cluster configuration and against automatic reachability detection, to use explicitly configured LAN paths, accepting that an unreachable configured LAN path does not fall back to WireGuard.

## Status

Accepted on 2026-09-12. Supersedes [ADR 0009](0009-clustered-app-instance-routing.md) only where Cluster Router DNS publication always selects the Router's WireGuard address. Extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) and [ADR 0033](0033-trust-wireguard-members-for-private-node-traffic.md).

## Context

The Router already forwards requests to workloads through their configured LAN addresses, but private DNS sends the requesting Cluster member to the Router's WireGuard address. Registered LAN addresses express operator intent, not proof that a roaming client can reach a LAN. The explicit macOS resolver override already provides an operator-controlled local path without automatic roaming.

## Decision

- The Gateway owns central selection of the address returned for a Cluster Router.
- Gateway DNS must return the Router's configured LAN address when it identifies the requester as an active WireGuard Node in the same active Cluster and both Nodes have configured LAN addresses.
- Gateway DNS must return the Router's WireGuard address when any condition for a LAN answer is absent.
- Gateway DNS must derive requesting Node identity from its registered WireGuard source address rather than a client-supplied identity or a shared LAN subnet.
- Gateway DNS must apply the same address-selection rule to the Cluster TLD projection and exact Cluster-scoped Route records.
- Gateway DNS must keep answers for different requesting Nodes separate when their address-selection results differ.
- Orbit must reconcile affected DNS address selection before a Node, Cluster, or Router configuration change becomes authoritative.
- Orbit must treat configured LAN addresses as operator intent for connectivity between those Cluster members.
- Orbit must not probe LAN reachability, switch paths automatically, or substitute a WireGuard answer because a configured LAN path is unreachable.
- Orbit must preserve the registered-Node trust boundary when it exposes Router ingress over the configured LAN path.
- Orbit must preserve Node-scoped Route DNS, control-plane DNS, and the separate Metrics access boundary.

## Rejected alternatives

- Return a LAN address to every client: rejected because remote clients and Nodes in other Clusters cannot rely on that LAN being reachable.
- Infer LAN reachability from Cluster membership alone: rejected because a Cluster can include Nodes without configured LAN connectivity.
- Detect roaming and select a path on each client: rejected because it requires client monitoring and automatic path transitions.
- Change WireGuard peer topology for local traffic: rejected because this choice concerns explicit application address selection, not VPN transport management.

## Consequences

- LAN-configured Cluster members can reach their Router locally while using central VPN DNS.
- Nodes without configured LAN connectivity continue to receive WireGuard answers.
- A configured but unreachable LAN address produces a visible connection failure that the operator must correct.
- Central DNS must support requester-specific answers without sharing a LAN answer with an ineligible requester.
- A shared forwarding resolver cannot supply an original requesting Node identity; its queries use the identity visible to Orbit DNS.
- Existing macOS local resolver overrides remain explicit and require operator reset when their configured target is no longer reachable.

## Affects

- Components: apps/gateway
- ADRs: supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for unconditional Router WireGuard DNS answers; extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) and [ADR 0033](0033-trust-wireguard-members-for-private-node-traffic.md)
- Detail: [Routes](../reference/routes.md) and docs/reference/private-dns.md
- Verify: `composer docs-lint`, requester-specific DNS tests, and DNS and HTTPS observations from LAN-configured and VPN-only Nodes in a disposable topology
