# Node retarget

`orbit:node-retarget NAME HOST [--ssh-port=PORT]` moves an active node's
recorded public SSH target. The Gateway keeps the node's pinned host key,
WireGuard address, and every other identity field.

## Shared lifecycle owner

The Gateway gives each Node name one lifecycle owner. Provision, retarget, and removal hold that owner from identity lookup through remote effects and compensation.

A contender for the same name receives `node.provisioning_busy` with HTTP 409 before any remote effect or state write. A retry reads current identity and eligibility after it acquires the owner. A deleted Node fails with the ordinary lookup or `node.not_found` refusal. An ineligible Node fails with its ordinary guard. The owner releases after success or failure, including verification and rollback failures. Independent Node names proceed together.

The owner does not serialize role or settings writers. A held owner expires after 3600 seconds.

## Two boundaries

Role convergence removes the `orbit:public-ssh-recovery` UFW rule once the `orbit:wireguard-members` rule exists. After that, the Gateway can reach the node only over WireGuard. Removing the last role row restores the `orbit:public-ssh-recovery` rule over WireGuard before the Gateway deletes that row, and [Node removal](node-provisioning.md#remove-a-node) restores it before the Gateway removes the WireGuard peer. Removing a role while another role row remains keeps public SSH closed. When that restore fails, the Gateway leaves the role assignment failed at step `remove:firewall-recovery` with `node.firewall_recovery_failed`, and a retry repeats the removal. The retarget selects its path from stored state:

| Node state | Path | Steps |
| --- | --- | --- |
| No role rows | Public SSH | Scan the host key on the new public address, require the pinned fingerprint, rewrite the node's WireGuard peer over public SSH, probe SSH over WireGuard, pin known hosts. |
| Any role row | WireGuard | Scan the host key over the node's WireGuard address, require the pinned fingerprint, update the public record, probe SSH over WireGuard, pin known hosts. |

Any role row counts, including a `provisioning` or `failed` role. That keeps
the retarget fail closed when a role convergence stopped after it closed
public SSH.

The WireGuard path never opens a public SSH connection and never rewrites
the node's WireGuard configuration. A restart of `wg-quick@orbit` over the
tunnel would cut the session that drives it.

## Recover a converged node whose tunnel is down

The node starts the WireGuard tunnel toward the `Endpoint` in
`/etc/wireguard/orbit.conf`. When the Gateway address changes (for example a
cloned topology on a new subnet), that endpoint is stale, the tunnel stays
down, and `orbit:node-retarget` fails with
`node.retarget_requires_vpn`. The node record stays active and unchanged.

Repair the endpoint on the node as root, then retry the retarget:

```bash
conf=/etc/wireguard/orbit.conf
gateway=198.51.100.1            # the Gateway's current public address
port=$(sed -n 's/^Endpoint *= *.*://p' "$conf" | head -n 1)
sed -i "s|^Endpoint *=.*|Endpoint = $gateway:$port|" "$conf"
systemctl restart wg-quick@orbit
ping -c 1 10.44.0.1              # the Gateway's WireGuard address
```

The Incus harness runs the same repair through root `incus exec`
(`apps/e2e` `retarget-vpn.sh`).

## Failure codes

Each failure names the step that stopped and what the node record looks like afterwards.

| Code | Step | Node record |
| --- | --- | --- |
| `node.public_ssh_host_invalid`, `node.public_ssh_port_invalid` | `validation` | unchanged |
| `node.provisioning_busy` | lifecycle owner | unchanged |
| `node.not_active` | `lookup` | unchanged |
| `node.retarget_requires_vpn` | `wireguard-ssh` | unchanged, still active |
| `node.ssh_host_key_scan_failed` | `ssh-host-key` | failed |
| `node.ssh_host_key_mismatch` | `ssh-host-key` | failed |
| `vpn.peer_address_missing` | `wireguard-address` | failed |
| `vpn.peer_ssh_failed` | `wireguard-ssh` | failed, public target restored |
| any WireGuard peer error | `wireguard-*` | failed, public target restored |
