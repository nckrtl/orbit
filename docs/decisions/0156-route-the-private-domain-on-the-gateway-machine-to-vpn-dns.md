---
title: "ADR 0156: Route the private domain on the Gateway machine to VPN DNS"
sidebarTitle: "0156 Route the private domain on the Gateway machine to VPN DNS"
description: "Proposed. Gateway bootstrap and gateway role convergence send queries for the private domain on the Gateway machine to Orbit VPN DNS, so clients there resolve reverb.orbit and other private names. Ordinary names stay on the uplink resolvers."
---

# ADR 0156: Route the private domain on the Gateway machine to VPN DNS

The Gateway machine sends queries for the private domain, such as `*.orbit`, to Orbit VPN DNS and keeps every other query on its uplink resolvers. The Orbit CLI and other clients on that machine then resolve `reverb.orbit`, `gateway.orbit`, and the other private names, so live features stay live there.

## Status

Proposed.

This extends [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) to the machine that holds the `gateway` role. It fills the resolver part of the Gateway host follow-up that [ADR 0094](/decisions/0094-project-wireguard-hub-config-onto-the-vpn-node) leaves open.

## Context

[ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) makes Orbit VPN DNS the resolver on managed peers. Peer convergence writes that selection into the peer's WireGuard hooks. The Gateway machine never runs peer convergence. When it also holds `vpn`, its WireGuard configuration is the hub. When `gateway` moved to another machine, that machine kept the tunnel it joined with. In both cases its `orbit` link has no DNS server, so private names do not resolve there.

The Gateway application works around this. It connects to Reverb by WireGuard address and presents `reverb.orbit` only for TLS. Other clients on the machine cannot. The Orbit CLI on the Gateway receives `wss://reverb.orbit` from `GET /api/v1/realtime`, fails to resolve it, and falls back to polling for every live feature. Independent reviews on Incus and the production Gateway both show this.

The Gateway is the control plane. It must still resolve ordinary names, such as package mirrors, GitHub, and ACME endpoints, while Orbit VPN DNS is down, because it repairs that service.

## Decision

- Gateway bootstrap and every `gateway` role convergence route the private VPN domain on the Gateway machine to Orbit VPN DNS. The Gateway owns the step.
- The route is suffix-only. systemd-resolved sends `~<domain>` queries over the `orbit` link to the VPN DNS address. Other queries keep their current resolvers.
- The VPN DNS address is the configured VPN DNS server, or the WireGuard address of the `vpn` Node when none is configured.
- A `wg-quick@orbit` drop-in reapplies the route whenever the tunnel starts. A failure there never fails the tunnel.
- A machine whose tunnel is a managed peer keeps the resolver policy that peer convergence owns. The step changes nothing on its `orbit` link.
- The step sets the routing domain and turns off the link's default DNS route before it sets the server, so the link never becomes a route for every name.
- A failure in this step never fails bootstrap or the `gateway` role. A failed role would drop implicit Gateway authority, which realtime and metrics authorization rely on. The role convergence response reports the failure in `follow_up` instead, the Gateway logs a warning with the underlying error code, and Doctor reports the missing route.
- Removing the `gateway` role removes the drop-in and reverts the `orbit` link it configured. Relocating it adds the route on the target and removes it from the source in the same way. A failure there fails the removal, as other removal steps do. The role stays `failed` until the removal runs again and succeeds.

## Rejected alternatives

- Use VPN DNS as the default resolver (`~.`) on the Gateway machine, as on managed peers: rejected because a VPN DNS outage would then stop the control plane from resolving the names it needs to repair that outage.
- Resolve private names inside the CLI, for example with a serving address in the realtime response: rejected because it fixes one client, and every other tool on the Gateway machine would still fail to resolve private names.
- Add `/etc/hosts` entries for the reserved names: rejected because the records move with their roles, and static entries would go stale.
- Change the harness only: rejected because the production Gateway has the same gap.

## Consequences

- The Orbit CLI on the Gateway machine stays live, and other clients there resolve every private name that Orbit VPN DNS answers.
- Names under Node or Cluster top-level domains still use the uplink resolvers on the Gateway machine.
- An existing Gateway gets the route at its next `gateway` role convergence. `node:role:relocate` moves the route with the role.
- A Gateway without systemd-resolved or without a `wg-quick@orbit` tunnel cannot use this route. Doctor reports it as `role.private_dns_route_mismatch`.
- When Orbit VPN DNS is unreachable, a private-name lookup on the Gateway machine waits for systemd-resolved to give up, which takes about 40 seconds. Before this route it failed within about 100 ms. systemd-resolved has no per-link timeout to shorten this. Ordinary names are not affected.
- A Gateway that runs on a machine that is already a managed peer keeps that peer's route-everything policy (`~.`). It does not get the suffix-only protection, so a VPN DNS outage there also affects ordinary names, as on every managed peer.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers)
- Detail: docs/reference/private-dns.md
- Verify: `GatewayPrivateDnsResolverTest`, `NodeRoleBaselinesTest`, `NativeGatewayVpnConvergerTest`, and `getent hosts reverb.orbit` on an Incus Gateway after `orbit node:role:add gateway gateway --converge`
