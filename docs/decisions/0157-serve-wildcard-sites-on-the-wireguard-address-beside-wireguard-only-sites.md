---
title: "ADR 0157: Serve wildcard sites on the WireGuard address beside WireGuard-only sites"
sidebarTitle: "0157 Serve wildcard sites on the WireGuard address beside WireGuard-only sites"
description: "Proposed. On a port that carries a WireGuard-only site, every site that binds 0.0.0.0 also binds the WireGuard address. An Ingress can then share a Node with the Gateway web site, for example on a Gateway that is also the Router. Amends ADR 0141."
---

# ADR 0157: Serve wildcard sites on the WireGuard address beside WireGuard-only sites

On a port that carries a WireGuard-only site, such as `gateway.orbit` or `metrics.orbit`, every site that binds `0.0.0.0` also binds the Node's WireGuard address. WireGuard clients then reach every site on that port. A site that binds only the WireGuard address still never joins the public listener. The Gateway Node can hold the `ingress` role, including when it is also the Router.

## Status

Proposed. Amends [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway).

## Context

[ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) fixes one listener rule per site source. On a Node with `ingress`, Router and workload sites bind `0.0.0.0`, and so do public Ingress sites. `gateway.orbit`, `metrics.orbit`, and the service metrics scrape site bind only the WireGuard address.

Caddy sends a connection for a specific address only to the sites bound to that address. A site that binds only `0.0.0.0` is then unreachable over WireGuard once a WireGuard-only site opens a listener on the WireGuard address and the same port. ADR 0141 therefore fails the build on such a Node and lists "`gateway` with `ingress` and `router`" as a Node that cannot build.

That rule surfaces late. `node:role:add gateway ingress` converges and fails at `converge:caddy-config`. A failed convergence still serves, so each build that follows on the Gateway Node fails the same way. Doctor reports `role.caddy_build_drift`, and Route changes that build the Gateway fail too. The [Routes reference](/reference/routes#publish-a-public-route) says that Ingress may share a Node with the Router, and a small fleet makes the Gateway its Router.

The conflict is only about which sites share the WireGuard listener. Nothing on the Node needs a site to be off the WireGuard address, except that WireGuard-only sites must stay off the public one.

## Decision

- A site whose rule binds `0.0.0.0` also binds the Node's WireGuard address when a WireGuard-only site uses the same port on that Node. That covers Router and workload sites on an Ingress Node, public Ingress sites, and `websocket`, `analytics`, and ProxyCli sites that join the wildcard listener.
- WireGuard-only sites keep their rule. They never bind `0.0.0.0`, so `gateway.orbit` and `metrics.orbit` stay off the public listener.
- The wildcard listener still takes every other address, so public clients and LAN clients reach the same sites as before.
- The build no longer fails because a WireGuard-only site shares a port with a first-row site. A WireGuard-only site and a public or first-row site for the same host and port now share the WireGuard address, so the build fails on that pair as a duplicate address and names both sites.
- Doctor compares a public Ingress site with the block that the Node Caddy build renders for it, so it expects the same listeners.

## Rejected alternatives

- Refuse `ingress` on a Node that holds `gateway` before any convergence: rejected because the Routes reference allows Ingress beside the Router, and a small fleet makes the Gateway its Router. The conflict has a listener fix that keeps every site private or public as before.
- Bind the WireGuard-only sites to `0.0.0.0` with a client address matcher: rejected because ADR 0141 keeps `gateway.orbit` and `metrics.orbit` off the public listener, and a matcher would put them on it.
- Bind first-row sites only to the WireGuard and LAN addresses on an Ingress Node: rejected because public traffic can arrive on the LAN address behind NAT, and a composed public site must stay on every address.

## Consequences

- An Ingress on the Gateway Node converges and serves public Routes. The Gateway web site and Metrics site stay on the WireGuard address.
- A Router or workload site on a Gateway that is also the Ingress keeps answering over WireGuard, which private DNS names for it.
- The Gateway Node's firewall opens public HTTP and HTTPS while its Cluster has a live public Route, as on any Ingress Node. `gateway.orbit` still answers only on the WireGuard address.
- Caddy runs two servers on such a port: one for the WireGuard address and one for every other address. A site on both appears in both.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway)
- Detail: [Caddy configuration](/reference/caddy-configuration#listener-addresses), [Routes](/reference/routes#publish-a-public-route)
- Verify: `apps/gateway` Pest tests `NodeCaddyfileRendererTest` (listener selection, a Gateway that is also the Router and the Ingress, `caddy adapt`) and `NativePublicRouteEdgeInspectorTest` (an Ingress on the Gateway Node); an Incus proof with Ingress on the Gateway Router Node, a live public Route, and a healthy Doctor
