---
title: "ADR 0092: Publish private DNS on the VPN node after gateway relocate"
sidebarTitle: "0092 Publish private DNS on the VPN node after gateway relocate"
description: "Proposed. Private DNS publication runs on the node that owns the listener. Gateway role add and relocate grant access to the vpn and metrics nodes. A failed DNS publication does not destroy a healthy Metrics runtime."
---

# ADR 0092: Publish private DNS on the VPN node after gateway relocate

After the `gateway` role leaves the `vpn` node, private DNS publication must run on the listener owner, not through a local process on the serving Gateway. Adding or relocating `gateway` grants directed access from that Gateway to the `vpn` and `metrics` nodes. A Metrics publication failure after a successful runtime leaves Grafana, Prometheus, and `/etc/orbit/metrics` in place.

## Status

Proposed.

This amends [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn). It does not change [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) or the VPN baseline.

## Context

[ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn) moves the `gateway` role without moving `vpn`. The WireGuard server, dnsmasq backend, and `orbit-private-dns` listener stay on the `vpn` node. `DnsmasqPrivateDnsManager` still ran `NativeProcessRunner` on the serving Gateway process. After a live split (`vpn` at `10.44.0.1`, `gateway` at `10.44.0.2`), `metrics --converge` and other publication paths failed at `converge:private-dns` / `app-dev.dns_config_failed` because the serving host has no listener.

`MetricsRoleBaseline` treated that publication failure as a full convergence failure. It removed a runtime that had already started Grafana and Prometheus and deleted `/etc/orbit/metrics`. Operators then had to restore Metrics by hand before the next converge succeeded.

The new Gateway node also had an empty `can_access` list. Operators needed directed edges to the `vpn` node and the Metrics node before SSH publication and Metrics work ran from that Gateway.

## Decision

- Private DNS publication targets the node that owns the listener: the active `vpn` role holder. When that node is the same machine as the serving Gateway, publication stays a local process. When `gateway` and `vpn` are different nodes, the Gateway SSHes the same publication script to the `vpn` node.
- The listen address stays the configured VPN DNS address, then the `vpn` node's WireGuard address. It does not fall back to the `gateway` node's address after the roles split.
- Adding or relocating the `gateway` role grants directed access from the Gateway role holder to the active `vpn` node and the active `metrics` node, when those nodes exist and are not the Gateway itself. The grant is idempotent. The granted set is `vpn` and `metrics`.
- When Metrics exporters, cAdvisor, and runtime have already converged, a publication failure after that point (including private DNS) must not remove the runtime, cAdvisor, or exporters. Publication still rolls back its own Gateway-side Caddy and certificate changes.

## Rejected alternatives

- Keep local `NativeProcessRunner` and tell operators to publish DNS by hand on the `vpn` node: rejected because every role converge that republishes private DNS would stay broken after a split.
- Always SSH, even when `gateway` and `vpn` share a node: rejected because bootstrap and the colocated default would add a self-SSH path they do not need.
- Roll back a successful Metrics runtime when publication fails: rejected because a DNS or Caddy fault then destroys Grafana, Prometheus, and `/etc/orbit/metrics`.
- Grant access to every fleet node: rejected. The required edges after relocate are the listener owner and the Metrics node.

## Consequences

- `orbit node:role:relocate <node> gateway --force` and `orbit node:role:add <node> gateway` create the documented access edges before the next role converge needs them.
- Bare `orbit node:role:add <metrics-node> metrics --converge` is safe again after a split: private DNS runs on the `vpn` node, and a DNS fault does not wipe a healthy Metrics runtime.
- Operators can inspect the published catalog and listener on the `vpn` node, not on the serving Gateway, after the roles split.
- The leftover serving-host setting still names the original node until an operator updates it. Access grants follow the Gateway role holder, not that leftover setting.

## Affects

- Components: apps/gateway, apps/docs, apps/e2e
- ADRs: amends [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn); leaves [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) unchanged
- Detail: [Relocate the gateway role](/solutions/relocate-gateway-role), [Private DNS](/reference/private-dns), [Metrics](/reference/metrics), [`node`](/cli/node)
- Verify: Gateway DnsmasqPrivateDnsManager targeting tests, MetricsRoleBaseline publication-failure tests, RelocateNodeRoleAction and GatewayRoleBaseline access-grant tests, MCP `node-role-add` boolean schema
