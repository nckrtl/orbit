# ADR 0033: Trust WireGuard members for private Node traffic

In the context of Orbit Nodes that use WireGuard for private reachability, facing a choice between per-port Node grants and membership-based network trust, we decided for trusting every active WireGuard member for private Node-to-Node traffic and against applying Node grants to ordinary traffic, to keep network reachability separate from Orbit command authorization, accepting that WireGuard membership grants broad private access.

## Status

Accepted on 2026-09-05. Extends [ADR 0009](0009-clustered-app-instance-routing.md), [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md), and [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md).

## Context

Orbit already distinguishes its WireGuard control-plane identity from application traffic and keeps public traffic behind Ingress. A Node grant authorizes an Orbit command or Gateway API action, but it does not describe general service-to-service reachability. Applying Node grants to every private connection would couple those independent boundaries and require Orbit to maintain a service-port authorization model.

## Decision

- Orbit must treat active WireGuard membership as trust to communicate privately with every other active WireGuard member over all protocols and ports.
- Orbit must not use Node grants to authorize or deny ordinary private Node-to-Node traffic.
- Orbit must use Node grants to authorize Orbit commands and Gateway API actions.
- Ingress must limit public traffic to HTTP and HTTPS; Router and workload Nodes must not become direct public endpoints.
- Orbit may prefer a configured LAN path for private application traffic when that path preserves the same registered-Node trust boundary.

## Rejected alternatives

- Apply Node grants to private traffic: rejected because command authorization and network reachability have different owners and would create a per-service authorization model.
- Limit private traffic to Route HTTPS: rejected because trusted Nodes need general private connectivity beyond one application protocol.

## Consequences

- A registered WireGuard Node can reach every registered WireGuard Node over the private network.
- Removing a Node from WireGuard membership revokes its private network trust.
- WireGuard membership is a stronger security grant and requires careful admission and removal controls.
- Route firewall and transport detail must describe this trust boundary before private Route provisioning ships.

## Affects

- Components: apps/docs, apps/e2e, apps/gateway
- ADRs: extends [ADR 0009](0009-clustered-app-instance-routing.md), [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md), and [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md)
- Detail: docs/reference/routes.md
- Verify: `composer docs-lint` and the private Route Incus proof actions
