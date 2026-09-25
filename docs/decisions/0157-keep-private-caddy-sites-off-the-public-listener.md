---
title: "ADR 0157: Keep private Caddy sites off the public listener"
sidebarTitle: "0157 Keep private Caddy sites off the public listener"
description: "Proposed. Only public Ingress sites bind every address. Private sites bind the WireGuard and LAN addresses on every Node, and a site that is not public aborts clients outside the ranges it admits. An Ingress can then share a Node with the Gateway web site, for example on a Gateway that is also the Router. Amends ADR 0141."
---

# ADR 0157: Keep private Caddy sites off the public listener

Only public Ingress sites bind `0.0.0.0`. Router, workload, and other private sites bind the Node's WireGuard and LAN addresses on every Node, including an Ingress Node. Every site that is not public aborts a client outside the address ranges it serves. A private site then never answers a public client, and an Ingress can share the Gateway Node.

## Status

Proposed. Amends [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway).

## Context

[ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) fixes one listener rule per site source. On a Node with `ingress`, it binds Router and workload sites to `0.0.0.0`, as it does public Ingress sites. `gateway.orbit`, `metrics.orbit`, and the service metrics scrape site bind only the WireGuard address.

That rule has two defects:

- The Ingress firewall admits public HTTP and HTTPS. A private Router or workload site on `0.0.0.0` then answers a request for its hostname that arrives on the Node's public address. On a Gateway that is also the Router and the Ingress, every private Route of the Cluster answered from the internet.
- Caddy sends a connection for a specific address only to the sites bound to that address. A site on `0.0.0.0` is unreachable over WireGuard once a WireGuard-only site listens on the WireGuard address and the same port. ADR 0141 therefore fails the build on such a Node, so `node:role:add gateway ingress` failed at `converge:caddy-config`. A failed convergence still serves, so each build that follows on the Gateway Node failed the same way.

A third gap is on every Node whose firewall admits HTTP or HTTPS to any destination, such as an Ingress Node. Linux accepts a packet for the WireGuard address on any interface. A LAN neighbour that routes the WireGuard address through the Node's LAN address reached `gateway.orbit` on the Gateway.

## Decision

- Public Ingress sites bind `0.0.0.0` and the Node's WireGuard and LAN addresses. The specific addresses matter because a connection to them reaches only the sites bound to them: a Router forwards to them, and public traffic can arrive on the LAN address behind NAT.
- Router, workload, custom proxy, tracking host, Agentation, and Vite sites bind the WireGuard and LAN addresses on every Node, with or without `ingress`. No private site binds `0.0.0.0`.
- `gateway.orbit`, `metrics.orbit`, the service metrics scrape site, `websocket`, `analytics`, and ProxyCli sites bind the WireGuard address only.
- Every site that is not public aborts a client outside the ranges it admits, right after its `bind` line:
  - A WireGuard-only site admits the VPN subnet.
  - A private site on an Ingress Node admits private and shared address space (`private_ranges` and `100.64.0.0/10`) and the VPN subnet. It covers public traffic that a port forward sends to the LAN address.
  - A private site on any other Node admits every client, because its firewall admits only WireGuard members and Router LAN sources.
- Orbit's global options order `abort` before every other handler, so the guard runs before any `handle` block.
- Doctor compares a public Ingress site with the block that the Node Caddy build renders for it, so it expects the same listeners.

## Rejected alternatives

- Refuse `ingress` on a Node that holds `gateway` before any convergence: rejected because the Routes reference allows Ingress beside the Router, and a small fleet makes the Gateway its Router.
- Bind every site that binds `0.0.0.0` to the WireGuard address too: rejected because it keeps private sites on the public listener.
- Keep private sites on `0.0.0.0` behind a client address matcher only: rejected because the listener rule already separates them, and a matcher alone depends on each site keeping its guard.
- Limit the Ingress firewall rules to the Node's public addresses: rejected because Orbit does not know those addresses. A Node can have a public IPv4 address, IPv6 addresses, or only a LAN address behind NAT.
- Deny the WireGuard address on every interface except `orbit` in the firewall: rejected because the Orbit firewall adds rules without an order, and that deny must follow the WireGuard member rule. A reordered rule would cut WireGuard clients off.

## Consequences

- An Ingress on the Gateway Node converges and serves public Routes. `gateway.orbit` and `metrics.orbit` stay on the WireGuard address.
- A private Route on an Ingress Node answers only WireGuard and LAN clients. A request for it on the public address reaches no site.
- A LAN neighbour that routes to the WireGuard address completes the TCP and TLS handshake with a WireGuard-only site and then gets no HTTP answer. The handshake shows the site's certificate, which names the private hostname.
- Caddy may present a private site's certificate on the public listener to a client that asks for that hostname in SNI, because Caddy's certificate cache is shared across listeners. The public listener serves no private site, so the client gets no answer. The certificate names only the private hostname.
- A private site on an Ingress Node refuses a LAN client whose address is outside private and shared address space.
- A container on the Gateway Node can no longer reach `gateway.orbit`, because its Docker bridge source address is outside the VPN subnet. Containers on other Nodes still reach it, because their traffic leaves with the Node's WireGuard address. No Orbit flow depends on the same-Node case.
- The guard trusts the connection's source address. A load balancer or port forward that rewrites a public client's source to a private or shared address makes private sites on the Ingress Node reachable through it.
- The global options change, so every Node's Caddyfile changes once. Doctor reports `role.caddy_build_drift` on a Node until its next build. After deploying this change, run `php artisan orbit:caddy-build NODE` for every Caddy Node.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway)
- Detail: [Caddy configuration](/reference/caddy-configuration#listener-addresses), [Routes](/reference/routes#publish-a-public-route)
- Verify: `apps/gateway` Pest tests `NodeCaddyfileRendererTest` (listener selection, client guards, a Gateway that is also the Router and the Ingress, `caddy adapt`) and `NativePublicRouteEdgeInspectorTest` (an Ingress on the Gateway Node); an Incus proof with Ingress on the Gateway Router Node: public-address probes for every private and WireGuard-only host return nothing, LAN probes of the WireGuard address return nothing, WireGuard and LAN clients reach their sites, a live public Route, and a healthy Doctor
