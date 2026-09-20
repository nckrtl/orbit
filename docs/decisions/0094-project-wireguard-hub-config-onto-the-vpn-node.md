---
title: "ADR 0094: Project WireGuard hub config onto the VPN node"
sidebarTitle: "0094 Project WireGuard hub config onto the VPN node"
description: "Proposed. After gateway and vpn split, peer converge installs hub PrivateKey and Address on the vpn node, not on the Gateway PHP host."
---

# ADR 0094: Project WireGuard hub config onto the VPN node

After the `gateway` role leaves the `vpn` node, WireGuard hub `PrivateKey` and `Address` install on the node that owns `vpn`. Peer converge and `node:add` must not write those hub credentials onto the Gateway PHP host.

## Status

Proposed.

This amends [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn). It does not change [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers), the VPN baseline, or [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate).

## Context

[ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn) moves the `gateway` role without moving `vpn`. The WireGuard hub, its listen port, and the fleet peer list stay on the `vpn` node. `NativeGatewayPeerProjectionManager` still installed the rendered hub configuration with local `sudo` on the process that runs Gateway PHP.

After a live split, that process runs on the Gateway app host. That host is already a WireGuard peer of the hub. Installing the hub `PrivateKey` and `Address` there overwrites the peer configuration, takes the hub address, and locks the Gateway out of the mesh. The 2026-09-20 incident hit during a services `node:add` after the role split.

[ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate) already publishes private DNS on the listener owner. Hub peer projection needs the same target rule. First-class spoke topology for the Gateway app host after the split remains a follow-up.

## Decision

- Hub `PrivateKey`, `Address`, listen port, and the serialized peer list install on the node that holds the active `vpn` role.
- When that node is the same machine as the active `gateway` role holder, or no active `gateway` assignment exists, projection stays a local process on the Gateway PHP host.
- When `gateway` and `vpn` are different nodes, the Gateway SSHes the generated hub configuration to the `vpn` node's WireGuard address and activates `wg-quick@orbit` there.
- A split without SSH wiring fails without writing `/etc/wireguard` on the Gateway PHP host. Local install is not a fallback.
- Hub private-key bytes travel as protected stdin, never as argv.
- The Gateway app host keeps the peer configuration that already joined the mesh. This decision does not invent a new spoke topology for that host.

## Rejected alternatives

- Keep local `sudo` install after the split: rejected because each peer converge after the split overwrites the Gateway host's WireGuard identity with the hub key.
- Always SSH, even when `gateway` and `vpn` share a node: rejected because bootstrap and the colocated default would add a self-SSH path they do not need.
- Fall back to local install when remote SSH is missing: rejected because that is the lockout.
- Make the Gateway app host a first-class spoke during this fix: rejected as a separate topology change. This record only stops hub-key install on the wrong machine.

## Consequences

- `orbit node:add` after a split updates hub peers on the `vpn` node and leaves the Gateway PHP host's peer configuration in place.
- Operators inspect `/etc/wireguard/orbit.conf` on the `vpn` node for hub `PrivateKey` and `Address`, not on the serving Gateway.
- A failed remote hub projection does not take down the Gateway's own tunnel.
- First-class spoke topology for the Gateway app host after the split remains a follow-up.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn); leaves [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) and [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate) unchanged
- Detail: [Relocate the gateway role](/solutions/relocate-gateway-role), [Node provisioning](/reference/node-provisioning), [`node`](/cli/node)
- Verify: Gateway NativeGatewayPeerProjectionManager targeting tests and NativeWireGuardPeerConverger split-role projection tests
