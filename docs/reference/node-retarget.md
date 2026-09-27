---
title: "Node retarget"
description: "How the Gateway moves a Node to a new public SSH target over public SSH or WireGuard without changing its identity, and how one lifecycle owner serializes Node changes."
covers:
  - apps/gateway/app/Actions/Nodes/RetargetNodeAction.php
  - apps/gateway/app/Console/Commands/RetargetNodeCommand.php
  - apps/gateway/app/Infrastructure/Nodes/NativeNodeProvisioningLock.php
---

# Node retarget

`php artisan orbit:node-retarget NAME HOST [--ssh-port=PORT]` changes the public SSH target of an active Node. It runs on the Gateway machine. The Node keeps its pinned host key, its WireGuard address, and every other identity field.

## One lifecycle owner per Node name

`node:add`, `node:remove`, `node:rename`, and retarget each take one lock for the Node name. They hold it from the lookup to the last remote step and rollback. Another operation on the same name fails with `node.provisioning_busy` (HTTP 409) before it changes anything. A retry reads the Node again after it gets the lock. Operations on different names run at the same time. The lock does not block role or settings changes. A lock that nobody releases expires after one hour.

## Two paths

Retarget picks its path from the Node's role assignments. Any assignment counts, also a `provisioning` or `failed` one, because a failed convergence can already have closed public SSH. [Public SSH](/reference/node-provisioning#public-ssh) describes that boundary.

| Node | Path |
| --- | --- |
| No role | Public SSH |
| Any role | WireGuard |

On the public SSH path, the Gateway scans the host key at the new address and requires the pinned fingerprint. It publishes the WireGuard peer over public SSH, probes SSH over WireGuard, and pins the key for both addresses.

On the WireGuard path, the Gateway scans the host key over the WireGuard address and requires the pinned fingerprint. It stores the new target, probes SSH over WireGuard, and pins the key for the new address.

The WireGuard path never opens a public SSH connection and never rewrites the Node's WireGuard configuration, because a restart of `wg-quick@orbit` would cut the session that drives it.

## Repair a tunnel that is down

The Node starts its tunnel toward the `Endpoint` in `/etc/wireguard/orbit.conf`. When the Gateway's public address changes, for example in a cloned topology on a new subnet, that endpoint is stale and the tunnel stays down. Retarget then fails with `node.retarget_requires_vpn`, and the Node record stays active and unchanged.

Repair the endpoint on the Node as root, then retry:

```bash
conf=/etc/wireguard/orbit.conf
gateway=198.51.100.1            # the Gateway's current public address
port=$(sed -n 's/^Endpoint *= *.*://p' "$conf" | head -n 1)
sed -i "s|^Endpoint *=.*|Endpoint = $gateway:$port|" "$conf"
systemctl restart wg-quick@orbit
ping -c 1 10.44.0.1              # the Gateway's WireGuard address
```

The Incus harness runs the same repair in `apps/e2e/resources/guest/retarget-vpn.sh`.

## Failure codes

Each failure names its step and the Node record it leaves.

| Code | Step | Node record |
| --- | --- | --- |
| `node.public_ssh_host_invalid`, `node.public_ssh_port_invalid` | `validation` | Unchanged |
| `node.provisioning_busy` | lifecycle owner | Unchanged |
| `node.not_active` | `lookup` | Unchanged |
| `node.retarget_requires_vpn` | `wireguard-ssh` | Unchanged and active |
| `node.ssh_host_key_scan_failed`, `node.ssh_host_key_mismatch` | `ssh-host-key` | `failed` |
| `vpn.peer_address_missing` | `wireguard-address` | `failed` |
| `vpn.peer_ssh_failed`, any other WireGuard peer error | `wireguard-*` | `failed`, with the old public target restored |
| `node.retarget_failed` | `retarget` | `failed`, with the old public target restored |
