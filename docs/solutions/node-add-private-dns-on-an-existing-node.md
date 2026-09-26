---
title: "node:add private DNS on an existing Node"
description: "Why node:add of a split gateway missed the vpn Node, and how private DNS publication reaches that Node."
---

# node:add private DNS on an existing Node

## Problem

`orbit node:add` on an active gateway Node stopped at private DNS when `vpn` was a different Node. The Gateway reported `Could not reconcile Cluster Router DNS selection for node [gateway].` at step `private-dns`. The previous error was `Could not converge Orbit private DNS records.` from `DnsmasqPrivateDnsManager::publish()`. That message names no Node. dnsmasq runs on the `vpn` Node and is not installed on the Gateway machine. The Node stayed `active`, which is the status it had before the command.

## Cause

`node:add` saves the Node as `provisioning` before it reconciles Cluster Router DNS. `ProvisionNodeAction` resolves `ClusterRouterDnsSelectionReconciler`, and that reconciler uses the container `DnsmasqPrivateDnsManager`. The singleton is constructed with an SSH executor, a key provider, and known hosts. Role convergence uses that same manager.

`publish()` sends the script to the `vpn` Node only when `remoteListenerOwner()` returns that Node. The method returns null in any of these cases:

- The manager has no SSH executor, keys, or known hosts.
- `vpn` and `gateway` share a Node.
- `roleHolder()` finds no Node for `vpn` or for `gateway`.

The container manager has the SSH dependencies, and the roles are on different Nodes. The third case is the one `node:add` hit.

`roleHolder()` loaded only a Node whose status was `active`. It preferred an active role, then a provisioning role. When `node:add` had stored the gateway Node as `provisioning`, the lookup missed it. `remoteListenerOwner()` returned null, and `publish()` ran the script with the local process runner on the Gateway machine. The failure message named no Node.

When role convergence leaves the Node `active` and marks only the role `provisioning`, the lookup finds that Node. The same manager publishes on the `vpn` Node over SSH.

`expandDnsSelection()` passes `status: active` for the catalog records of the Node being added. `roleHolder()` does not read that override. It reads the stored Node status. The same miss happened when `node:add` marked the `vpn` Node `provisioning`, and publication ran on the Gateway machine for that command as well.

## Solution

`roleHolder()` counts a `provisioning` Node when no `active` Node holds the role. An active Node still wins. An active role and a provisioning role both count. `remoteListenerOwner()` returns the separate `vpn` Node, and `node:add` publishes there over SSH.

Converging a mutable role also publishes on the `vpn` Node, because that command leaves the Node `active`:

```bash
orbit node:role:add <node> gateway --converge
```

`vpn` is not one of those roles. `RoleRegistry` marks `vpn` immutable, and adding the role rejects the assignment before convergence. Reprovision that Node with `node:add`. The same lookup publishes on it over SSH. [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate) owns the publication target.

## Limits

A Node that holds both `gateway` and `vpn` still publishes on the local machine. A `failed` or `removing` Node is not a holder. This note does not install dnsmasq on the Gateway machine.

## Verification

`apps/gateway/tests/Feature/Actions/Nodes/ProvisionNodePrivateDnsTest.php` reprovisions a split gateway with `node:add`. Publication runs over SSH to the `vpn` Node, the local process runner is not used, and the Node stays `active`.
