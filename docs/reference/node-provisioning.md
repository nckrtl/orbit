# Node provisioning

This page tells an operator which Linux user the Gateway connects as when `orbit node:provision <name> [host]` bootstraps a Node, and how `orbit node:remove <node>` returns a machine to a state that a later provisioning can reach. It explains how the user choice differs between a new Node and an existing Node, and which identity inputs the command accepts. The same provisioning request serves a first provisioning and a later change to a Node's TLD, roles, or settings.

## Bootstrap identity

The Gateway opens one SSH session as the bootstrap user and runs the base bootstrap. The bootstrap installs the base packages and creates the managed user when it is missing. It installs the Gateway SSH key for that user and grants that user passwordless sudo. The Gateway then verifies SSH access as the managed user, and every later Gateway command on the Node runs as that user.

When the request names no bootstrap user, the Gateway selects it from the Node record.

| Node state | Bootstrap user | Bootstrap command |
| --- | --- | --- |
| New Node | `root` | Runs the base bootstrap directly. |
| Existing Node | The Node's recorded managed user | Runs the same base bootstrap through passwordless sudo. |

An explicit bootstrap user replaces this default for a new Node and for an existing Node. Name one when the host allows no root login. Name one when the recorded managed user cannot log in, for example after a first provisioning failed before the bootstrap created that user.

## Identity inputs

The CLI sends a bootstrap user only when the option is present. The Gateway API reads an omitted `user` field as absence and rejects an empty or null value.

| Input | Meaning |
| --- | --- |
| `--user` | Optional bootstrap SSH user for the CLI. The Gateway API and PHP software development kit (SDK) field is `user`. |
| `--orbit-user` | Optional managed user for the CLI. The Gateway API and SDK field is `orbit_user`. A new Node records `orbit`; an existing Node keeps its recorded managed user. |

The Gateway console command `orbit:node-provision` applies the same defaults for the first Node.

## Failure codes

Each identity failure names the boundary that stopped the request.

| Code | Meaning |
| --- | --- |
| `node.invalid_linux_user` | The bootstrap user, the managed user, or the recorded managed user is not a valid Linux user name. The Gateway changes no Node. |
| `node.user_change_unsupported` | The request names another managed user for a Node that owns roles or instances. The Gateway changes no Node. |
| `node.bootstrap_failed` | The bootstrap session or the base bootstrap failed as the bootstrap user. |
| `node.orbit_ssh_failed` | The Gateway could not connect as the managed user after the bootstrap. |

## Remove a Node

`orbit node:remove <node> [--offline] [--force]` deletes a Node record and its Gateway-side projections, and leaves the machine reachable over its recorded public SSH target so an operator can provision it again or reach it for recovery. The Gateway refuses the request while the Node still owns AppInstances, instances, Orbit firewall rules, or roles, and it never removes a Node with the Gateway or VPN role or the Node that sends the request.

The online removal runs these steps in order and reports success only after the last step completes.

| Step | Observable result |
| --- | --- |
| Grafana access | The Gateway revokes the Node's Grafana access. |
| Metrics exporter | The Gateway retires the Node's Metrics exporter state and converges the remaining fleet. |
| Public SSH recovery | The Gateway restores the exact `orbit:public-ssh-recovery` UFW rule on the machine over WireGuard without enabling UFW. |
| WireGuard peer | The Gateway removes the Node's WireGuard peer. |
| DNS | The Gateway converges its private DNS records. |
| Record | The Gateway deletes the Node record. |

The Gateway skips the public SSH step for a Node without a WireGuard peer, because provisioning closes public SSH only after the peer exists.

`--offline` is for a Node the Gateway cannot reach. The Gateway probes the Node first, and a Node that answers keeps the ordinary guards, so the flag never bypasses a guard on a reachable machine. The flag also skips the public SSH recovery step when the Node answers, so omit it for a reachable Node.

For an unreachable Node, `--offline --force` sheds every remaining role on the Gateway side, removes the WireGuard peer, and deletes the record. It changes nothing on the machine: the roles' Caddy sites, checkouts, containers, and Orbit UFW rules and the Metrics exporter stay in place, public SSH stays closed, and the response lists what remains under `retained_on_node`.

A failed step rolls the Gateway back and keeps the Node record active. Each failure names the step that stopped and the state the Gateway leaves behind.

| Code | Step | Result |
| --- | --- | --- |
| `node.has_app_instances`, `node.has_instances`, `node.has_firewall_rules`, `node.has_roles` | guard | The Gateway changes nothing. |
| `node.self_removal_forbidden`, `node.gateway_removal_forbidden`, `node.vpn_removal_forbidden` | guard | The Gateway changes nothing. |
| `node.confirmation_required` | guard | The Gateway changes nothing; `--offline` needs `--force`. |
| `node.provisioning_busy` | lifecycle owner | The Gateway changes nothing; another lifecycle operation holds the Node name. |
| `node.grafana_access_revocation_failed` | `grafana-access-revocation` | The Node record is active again. |
| `node.metrics_reconcile_failed` | `metrics-exporters` | The Node record is active again and the Metrics selection is restored. |
| `node.firewall_recovery_failed` | `firewall-recovery` | The Node record is active again, the WireGuard peer is kept, and the Metrics selection is restored. Use `--offline --force` when the machine is unreachable. |
| `node.wireguard_projection_failed` | `wireguard-projection` | The Node record is active again and the Metrics selection is restored. |
| `node.dns_projection_failed` | `dns-projection` | The Node record is active again, and the WireGuard peer and Metrics selection are restored. |
| `node.persistence_failed` | `persistence` | The Node record is active again, and the WireGuard peer, DNS records, and Metrics selection are restored. |
| `node.removal_rollback_failed` | `wireguard-rollback`, `persistence-rollback`, or `metrics-exporters-rollback` | The Node record is active again, but the named rollback did not complete. |

Removing a Node's last role also restores the public SSH recovery rule; [Node retarget](node-retarget.md#two-boundaries) describes that boundary.

The owning implementation and tests live in `apps/gateway`.
