# ADR 0055: Restrict Grafana access to authorized Gateway peers

In the context of private Metrics access, facing a conflict between unrestricted WireGuard connectivity and Gateway-owned Grafana publication, we decided for one authorized Gateway entry point and against direct access by every WireGuard peer, to make Gateway access govern the dashboard, accepting a Grafana exception to general private network trust.

## Status

Accepted on 2026-09-10. Extends [ADR 0003](0003-singleton-metrics-role.md) from Metrics command authorization to Grafana access. Supersedes [ADR 0033](0033-trust-wireguard-members-for-private-node-traffic.md) only for Grafana network access and authorization. Retains ADR 0003's Grafana login, credential controls, and Gateway-owned publication.

## Context

The Gateway proxy publishes Grafana without checking a browser caller's Gateway grant. ADR 0033 permits every active WireGuard member to reach every private port, while ADR 0003 restricts Grafana's upstream to the Gateway. The repository owner requires `metrics.orbit` to be the sole user-facing entry point and limits access to WireGuard members with Gateway authority.

## Decision

- Orbit must expose Grafana to users only through `metrics.orbit` on the Gateway's private WireGuard endpoint.
- The Gateway must resolve each Grafana caller to an active WireGuard Node before forwarding its traffic.
- The Gateway must admit Grafana traffic only when the caller has implicit access as the active Gateway Node or an explicit directed access grant to that Gateway.
- The Gateway must deny Grafana access when it cannot establish the caller's identity or Gateway authority.
- The Gateway must apply this authorization to all Grafana traffic, including requests to Grafana APIs and streaming connections.
- The Gateway must revoke Grafana access when the caller loses active WireGuard membership or the required Gateway authority.
- Orbit must restrict Grafana's upstream to traffic from the Gateway proxy, including when Gateway and Metrics roles share a Node.
- Orbit must prevent direct Grafana access by other WireGuard peers and public clients.
- Orbit must retain Grafana's own login as an additional requirement after Gateway authorization.

## Rejected alternatives

- Let every WireGuard peer connect directly to Grafana: rejected because membership alone does not establish Gateway authority.
- Require a Grafana login without a Gateway access check: rejected because Grafana credentials do not establish the caller's Orbit authorization.
- Grant dashboard access through permission on the Metrics Node: rejected because the Gateway owns the Metrics access boundary.

## Consequences

- Gateway access controls both the Metrics commands and the dashboard entry point.
- Grafana availability depends on the Gateway proxy and its ability to establish current caller authority.
- General WireGuard trust has an explicit Grafana exception; other private services retain their existing access contracts.
- Gateway publication, upstream isolation, and access revocation need coordinated implementation before this boundary is enforced.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0003](0003-singleton-metrics-role.md) for dashboard authorization; supersedes [ADR 0033](0033-trust-wireguard-members-for-private-node-traffic.md) for Grafana access
- Detail: [Metrics role](../reference/metrics.md)
- Verify: Gateway authorization and Metrics publication tests; private access, bypass refusal, and revocation checks on an isolated topology; `composer docs-lint`
