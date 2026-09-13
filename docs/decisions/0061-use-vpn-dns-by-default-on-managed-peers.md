# ADR 0061: Use VPN DNS by default on managed peers

In the context of managed Nodes resolving private Route hostnames, facing missing DNS suffix configuration when Cluster naming changes, we decided for Orbit VPN DNS as the default resolver on managed peers and against distributing private suffix lists, to centralize name resolution, accepting that ordinary DNS resolution also depends on VPN DNS availability.

## Status

Accepted on 2026-09-12. Extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md).

## Context

Managed peer DNS configuration selects private suffixes independently from Route hostname generation. A Node can therefore have a working Route and no resolver selection that reaches its DNS record. Orbit VPN DNS already answers private names and forwards ordinary queries to upstream resolvers.

## Decision

- The Gateway must configure managed Linux peer Nodes to use Orbit VPN DNS as their default DNS resolver unless an explicit operator DNS override applies.
- Managed peers must resolve private Route hostnames without a local list of Node or Cluster TLDs.
- The Gateway must not require a local DNS server or local hostname records on managed peers for this behavior.
- Orbit VPN DNS must forward ordinary queries through upstream resolvers that do not depend on its own private resolver path.
- DNS convergence must not change the Node's general IP routing policy.
- DNS convergence must preserve explicit operator DNS overrides and client-owned resolver configuration.
- Orbit must preserve the last working resolver configuration when DNS convergence fails.

## Rejected alternatives

- Distribute every private suffix to every peer: rejected because a naming change would require client configuration changes beyond the authoritative DNS publication.
- Run a private DNS server on every peer: rejected because each peer would acquire a service and record publication responsibility.
- Send all application traffic through the VPN: rejected because DNS server selection does not require changing application transport.

## Consequences

- Private naming changes do not require suffix updates on managed peers that use the default resolver.
- A VPN DNS outage affects ordinary DNS resolution as well as private names on those peers.
- Existing managed peers need a repeatable resolver migration with recovery before they satisfy this contract.
- The VPN DNS host must retain independent upstream resolution to avoid forwarding loops.
- Operator-owned clients and the explicit macOS local resolver override remain outside managed peer convergence.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md)
- Detail: docs/reference/private-dns.md
- Verify: `composer docs-lint`, managed peer DNS convergence tests, and ordinary resolver queries on disposable managed Nodes
