---
title: "Node provisioning"
description: "Which Linux user the Gateway connects as when node:add bootstraps a Node, how it records the machine architecture, and how node:remove hands the machine back."
---

# Node provisioning

Use `orbit node:add <name> [host]` to set up a Node or change its top-level domain (TLD), roles, or settings. The Gateway sets up SSH access and records the machine architecture. Use `orbit node:remove <node>` to remove the Node from Orbit and restore public SSH access.

Human CLI output shows one indeterminate progress operation during each Gateway request, including provisioning and removal. Its indicator continues while the request blocks; the CLI does not receive or infer completion of the internal steps below. The result and request ID follow the settled progress display. JSON remains a single response without progress frames.

Removal first resolves the explicit Node ID and asks a default-No confirmation naming the Node. `--force` supplies consent for automation and JSON. Decline, cancellation, and end of input exit 1 before removal; `--offline` remains a separate reachability override.

## Bootstrap identity

The Gateway connects over SSH as the bootstrap user to install base packages and create the managed user if needed. It adds its SSH key and grants passwordless sudo. After verifying access as the managed user, the Gateway uses that account for all later commands.

When the request names no bootstrap user, the Gateway selects it from the Node record.

| Node state | Bootstrap user | Bootstrap command |
| --- | --- | --- |
| New Node | `root` | Runs the base bootstrap directly. |
| Existing Node | The Node's recorded managed user | Runs the same base bootstrap through passwordless sudo. |

Set an explicit bootstrap user if root login is disabled or the recorded managed user cannot log in. This overrides the default for both new and existing Nodes.

## Identity inputs

The CLI sends a bootstrap user only when the option is present. The Gateway API reads an omitted `user` field as absence and rejects an empty or null value.

| Input | Meaning |
| --- | --- |
| `--user` | Optional bootstrap SSH user for the CLI. The Gateway API and PHP software development kit (SDK) field is `user`. |
| `--orbit-user` | Optional managed user for the CLI. The Gateway API and SDK field is `orbit_user`. A new Node records `orbit`; an existing Node keeps its recorded managed user. |

The Gateway console command `orbit:node-provision` applies the same defaults for the first Node.

## Machine architecture

After verifying managed SSH access, the Gateway runs `uname -m` to read the architecture, such as `x86_64` or `aarch64`. A Node without a recorded architecture stores that value, and keeps it even when a later provisioning step fails and the Gateway restores the Node's other state. An existing Node keeps its recorded architecture regardless of request input.

An explicit architecture for a new Node must match the observed value. A mismatch returns HTTP 409 before package-manager or role setup. Architecture failures mark the Node as failed at `machine-architecture`.

| Input | Meaning |
| --- | --- |
| `--architecture` | Optional machine architecture; the API and SDK field is `architecture`. A new Node records the observed value, which an explicit value must equal. An existing Node keeps its record. |

The Gateway console command `orbit:node-provision` accepts the same option with the same default.

## Failure codes

Each identity or architecture failure names the boundary that stopped the request.

| Code | Meaning |
| --- | --- |
| `node.invalid_linux_user` | The bootstrap user, the managed user, or the recorded managed user is not a valid Linux user name. The Gateway changes no Node. |
| `node.user_change_unsupported` | The request names another managed user for a Node that owns roles or instances. The Gateway changes no Node. |
| `node.bootstrap_failed` | The bootstrap session or the base bootstrap failed as the bootstrap user. |
| `node.orbit_ssh_failed` | The Gateway could not connect as the managed user after the bootstrap. |
| `node.architecture_unavailable` | The Gateway could not read a machine architecture from the Node as the managed user. |
| `node.architecture_mismatch` | The request names an architecture that differs from the observed one for a Node without a record. The Gateway records no architecture and converges no role. |

## Role compatibility

Some roles never share a Node. The Gateway refuses a conflicting role before it claims or converges anything. `node:role:add` and `node:role:relocate` answer `validation.failed` with `Role [ROLE] conflicts with assigned role [OTHER].`. `node:add` answers `node.role_conflict`, with `Role [ROLE] conflicts with requested role [OTHER].` when one request names both roles of a conflicting pair. For `gateway` and `ingress` it fails earlier: `ingress` cannot be assigned during provisioning (`Role [ingress] cannot be assigned during provisioning.`), and `gateway` is already assigned to the Gateway Node (`Role [gateway] is already assigned to node [NODE].`). Doctor reports each assignment of an existing conflicting pair as `role.assignment_conflict`.

| Role | Never shares a Node with |
| --- | --- |
| `gateway` | `ingress`, `app-dev`, `app-prod`, `database`, `analytics` |
| `ingress` | `gateway`, `app-dev`, `database` |
| `vpn` | `database` |
| `app-dev` | `gateway`, `ingress`, `app-prod` |
| `app-prod` | `gateway`, `app-dev`, `database` |
| `database` | `gateway`, `vpn`, `ingress`, `app-prod` |
| `analytics` | `gateway` |

`router`, `metrics`, and `websocket` share a Node with any role. Ingress is public and the Gateway is private, so a Gateway that is the Router places Ingress on another Node of the Cluster. [ADR 0157](/decisions/0157-keep-private-caddy-sites-off-the-public-listener) records that rule.

## Package sources

A role installs its packages from the Ubuntu archive, except for the two Orbit pins.

| Package | Source | Owned file |
| --- | --- | --- |
| PHP | Sury, `https://packages.sury.org/php/` | `/etc/apt/sources.list.d/orbit-php.sources`, `/usr/share/keyrings/orbit-sury-php.gpg` |
| Caddy | The Caddy project, `https://dl.cloudsmith.io/public/caddy/stable/deb/debian` | `/etc/apt/sources.list.d/orbit-caddy.sources`, `/usr/share/keyrings/orbit-caddy.gpg` |

Both sources work the same way. The Gateway downloads the publisher's signing key and refuses it unless it matches a pinned SHA-256 digest and a pinned primary fingerprint. It then publishes the keyring and a deb822 source file as `root:root` mode `0644`, and restores the previous pair when a step after that fails. It refuses to continue unless the package candidate comes from that exact origin. Orbit never uses `apt-key` or `add-apt-repository`, and never accepts a caller-supplied source.

Orbit installs Caddy this way because the Ubuntu archive ships Caddy 2.6.2, which does not know `log_skip` — a directive an `app-dev` site renders for every hibernating Instance — or `tls force_automate`, which every public Ingress site renders. A Node below **Caddy 2.9.0** fails the `caddy-package-source` step of role convergence with the installed and required release named. [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source) records the decision and [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) raises the floor to 2.9.0.

Roles that serve through Caddy are `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, and `analytics`. Each one installs Caddy when it converges, so an Ingress-only Node can serve its public sites. An Ingress keeps serving its public sites while its role converges, so a converge keeps the public sites the Node already serves. Removing the `ingress` role rebuilds the Node Caddyfile without it, so the Node serves no public site and nothing listens on every address. The removal then closes the Orbit public HTTP and HTTPS rules and reconciles service metrics. The Caddy package stays installed. [Routes](/reference/routes#ingress-removal) describes the removal guard and retry.

Converging a role on a Node that still carries the archive package upgrades it in place. Orbit owns `/etc/caddy/Caddyfile` as a symlink into its own versions directory, and the install keeps the existing file, so the live configuration survives the upgrade.

The same step sets one kernel setting for Caddy reloads. It writes `/etc/sysctl.d/60-orbit-caddy.conf`, owned by `root:root` with mode `0644`:

```text
net.ipv4.tcp_migrate_req = 1
```

On each reload, Caddy opens a new listening socket and closes the old one. With this setting, the kernel moves the connections that wait on the old socket to the new one. Without it, the kernel resets them, and a client can see `curl: (35) Recv failure: Connection reset by peer`. In a reproduction, resets fell from 28 in 268 reloads to 2 in 228. [ADR 0144](/decisions/0144-migrate-waiting-connections-when-caddy-reloads) records the measurements.

The step applies a candidate file with `sysctl --load` before it installs the file, so a kernel that refuses the setting fails the `caddy-package-source` step and leaves no file behind. When the file already matches, the step leaves it untouched but applies it again, so a changed live value returns to `1`. The step refuses a live file that is a symlink, not a regular file, or not `root:root` mode `0644`. Doctor does not check the setting; converge the role to repair it.

The setting does not help HTTP/1.1 clients. When the old Caddy configuration has already accepted a new HTTP/1.1 connection, a request it reads after the reload starts gets an empty reply. Orbit's own CLI and PHP SDK use HTTP/1.1, so a command can fail this way while the Gateway's Caddy reloads; run it again. HTTP/2 clients, such as browsers and curl, do not see this.

[`orbit doctor`](/cli/doctor) reports a Node whose Caddy is below the floor as `role.caddy_version_unsupported`, with the constraint as the expected value and the installed release as the observed one. Doctor never repairs; `orbit node:role:add <node> <role> --converge` does.

The Gateway machine installs Caddy the same way. The `gateway` role lists the `caddy` package. Gateway bootstrap and Gateway web convergence (`php artisan orbit:gateway-web`) run the Caddy source step on the Gateway machine through local `sudo`, and then order the Caddy service after `wg-quick@orbit`. Both steps run after a read-only check of the checkout path and before any step that changes the checkout, the certificates, or Caddy's configuration. A failure stops at the `gateway-caddy-install` step with `gateway.caddy_install_failed` and leaves the live Caddy configuration unchanged.

Converging the `gateway` role runs the same two steps over SSH before its firewall step. When the source step fails, the API returns `node_role.convergence_failed` for step `caddy-package-source`, and the role becomes `failed` with error code `gateway.caddy_install_failed`. So `orbit node:role:add <node> gateway --converge` repairs a Gateway that Doctor reports. After it publishes private DNS, the convergence routes the private domain on the Gateway machine to Orbit VPN DNS, as [Private DNS](/reference/private-dns#the-gateway-machine) describes. A Gateway that still runs the archive package upgrades on its next bootstrap, web convergence, or `gateway` role convergence.

Each `gateway` role convergence that installs a newer Caddy restarts it, and Caddy also serves the Gateway API. The CLI can then report `Could not reach the gateway.` while the Gateway finishes the operation. `orbit activity:list` shows the real result. Wait a minute and run the command again: the second run finds Caddy current and reports the result. On the Gateway machine, `php artisan orbit:gateway-web` makes the same repair without going through Caddy, while the `gateway` role is `active`.

A failed `gateway` role convergence leaves the role `failed`. `php artisan orbit:gateway-web` then refuses with `gateway.web_node_missing`, because it needs an active `gateway` role. Fix the cause, then run `orbit node:role:add <node> gateway --converge` again. A successful run returns the role to `active`.

Every Node with Caddy sites installs Caddy through this step before its first [Node Caddy build](/reference/caddy-configuration#node-caddy-build). Role convergence runs it for each role that lists the `caddy` package, `analytics` and ProxyCli publication run it on their Node, and Gateway bootstrap and web convergence run it on the Gateway machine. The build uses only the packaged `/usr/bin/caddy` and checks the floor again before it writes a version. A build on a Node without that Caddy fails at stage `release` and changes nothing.

## Converge an existing Node

`node:add` for a recorded Node converges that machine again. The Gateway may record the Node as `provisioning` while that work runs. When the Node was already `active` and a later step fails, the Gateway restores `active`, including when private DNS or Router LAN cleanup also fails. It does not leave a serving Gateway in `provisioning`. A non-active Gateway hides the peer from fleet authority, so clients then receive `node_access.required` until an operator repairs the status by hand.

A Node that was never `active` still becomes `failed` at the step that stopped. A private DNS failure uses step `private-dns`.

## Role operations on one Node

The Gateway runs one role convergence or removal per Node at a time, so two operations never run package, firewall, or service steps on the same machine together. A `node:role:add`, `node:role:remove`, `node:role:relocate`, Cluster Router change, or `node:add` that reaches a role step while another one runs on that Node waits up to 2 minutes.

| Result | Meaning |
| --- | --- |
| The other operation finishes within 2 minutes | This operation continues. |
| It is still running after 2 minutes | Convergence fails at step `node-lock` with error code `node_role.node_busy`, and the role becomes `failed`. Run `orbit node:role:add <node> <role> --converge` again. A removal fails with `node_role.remove_failed` and the same code. |

The lock lives in a file cache store under `ORBIT_HOME`, whatever `CACHE_STORE` says. It expires after 10 minutes, the Gateway's PHP-FPM request limit, so an operation whose worker dies without releasing it blocks that Node's role operations for at most 10 minutes. Operations on different Nodes run in parallel.

## Node agent

After the Metrics exporters, provisioning installs or upgrades the [Node agent](/reference/node-agent) on a managed Node, at step `agent`. A failure returns `node.agent_install_failed` and follows the converge rules above: a new Node becomes `failed`, and an existing active Node stays `active`.

## Public SSH after provisioning

Bootstrap adds the `orbit:public-ssh-recovery` UFW rule and enables UFW over the public address. Once SSH answers over the WireGuard tunnel, the Gateway adds the `orbit:wireguard-members` rule over that tunnel and keeps public SSH open. The first role convergence removes the public SSH rule, so a Node provisioned with roles ends with public SSH closed, and a Node provisioned without roles stays reachable over its public SSH target until a role converges. [Node retarget](/reference/node-retarget#two-boundaries) describes the same two boundaries.

A later `node:add` of a roleless Node therefore connects over public SSH again and republishes the WireGuard peer. The Gateway finalizes that publication over the verified tunnel, because role convergence closes the public path during the same request.

After [relocate](/solutions/relocate-gateway-role) splits `gateway` from `vpn`, republishing a peer writes the hub WireGuard configuration to the `vpn` node. It does not install the hub `PrivateKey` or `Address` on the Gateway PHP host. [ADR 0094](/decisions/0094-project-wireguard-hub-config-onto-the-vpn-node) owns that target.

## Remove a Node

`orbit node:remove <node> [--offline] [--force]` removes the Node record and Gateway configuration. Online removal restores public SSH so you can recover or provision the machine again. First remove its Instances, Orbit firewall rules, roles, and processes. Orbit refuses to remove the caller's Node or one with the Gateway or VPN role. Other units, containers, and checkouts stay on the machine. See [ADR 0072](/decisions/0072-add-and-remove-nodes-without-changing-the-machine).

The online removal runs these steps in order and reports success only after the last step completes.

| Step | Observable result |
| --- | --- |
| Grafana access | The Gateway revokes the Node's Grafana access. |
| Metrics exporter | The Gateway retires the Node's Metrics exporter state and converges the remaining fleet. |
| Node agent | The Gateway stops and disables `orbit-agent.service`, then deletes the unit, `/usr/local/bin/orbit-agent`, and `/etc/orbit/agent` on the machine. A failure does not stop the removal; the Gateway logs a warning. |
| Public SSH recovery | The Gateway restores the exact `orbit:public-ssh-recovery` UFW rule on the machine over WireGuard without enabling UFW. |
| WireGuard peer | The Gateway removes the Node's WireGuard peer. |
| DNS | The Gateway converges its private DNS records. |
| Record | The Gateway deletes the Node record. |

The Gateway skips the public SSH step for a Node without a WireGuard peer, because public SSH closes only after the peer exists.

Use `--offline` only for an unreachable Node. The Gateway probes it first and keeps all normal guards if it answers. The flag skips public SSH recovery even for a reachable Node, so omit it for online removal.

For an unreachable Node, `--offline --force` sheds every remaining role on the Gateway side, deletes Node-owned Process records without remote runtime cleanup, removes the WireGuard peer, and deletes the record. It changes nothing on the machine: the roles' Caddy sites, checkouts, containers, Process units or containers, Orbit UFW rules, the Metrics exporter, and the Node agent stay in place, public SSH stays closed, and the response lists what remains under `retained_on_node`.

A failed step rolls the Gateway back and returns the Node record to the status it had before removal, so a `failed` Node stays `failed`. Each failure names the step that stopped and the state the Gateway leaves behind.

| Code | Step | Result |
| --- | --- | --- |
| `node.has_app_instances`, `node.has_instances`, `node.has_firewall_rules`, `node.has_roles`, `node.has_processes` | guard | The Gateway changes nothing. |
| `node.self_removal_forbidden`, `node.gateway_removal_forbidden`, `node.vpn_removal_forbidden` | guard | The Gateway changes nothing. |
| `node.confirmation_required` | guard | The Gateway changes nothing; `--offline` needs `--force`. |
| `node.provisioning_busy` | lifecycle owner | The Gateway changes nothing; another lifecycle operation holds the Node name. |
| `node.grafana_access_revocation_failed` | `grafana-access-revocation` | The Node record has its previous status again. |
| `node.metrics_reconcile_failed` | `metrics-exporters` | The Node record has its previous status again and the Metrics selection is restored. |
| `node.firewall_recovery_failed` | `firewall-recovery` | The Node record has its previous status again, the WireGuard peer is kept, and the Metrics selection is restored. Use `--offline --force` when the machine is unreachable. |
| `node.wireguard_projection_failed` | `wireguard-projection` | The Node record has its previous status again and the Metrics selection is restored. |
| `node.dns_projection_failed` | `dns-projection` | The Node record has its previous status again, and the WireGuard peer and Metrics selection are restored. |
| `node.persistence_failed` | `persistence` | The Node record has its previous status again, and the WireGuard peer, DNS records, and Metrics selection are restored. |
| `node.removal_rollback_failed` | `wireguard-rollback`, `persistence-rollback`, or `metrics-exporters-rollback` | The Node record has its previous status again, but the named rollback did not complete. |

Removing a Node's last role also restores the public SSH recovery rule; [Node retarget](/reference/node-retarget#two-boundaries) describes that boundary.

The retained tunnel can still select Orbit DNS even though its Gateway peer is gone. When provisioning the machine again, bootstrap first tries the configured package sources and proxy. If that attempt fails because the source hostnames cannot resolve, bootstrap temporarily clears only the DNS server and route-all domain that match Orbit's retained ownership record on the `orbit` link.

Bootstrap uses the machine's existing network DNS during base package installation, then restores the exact previous link values on success or failure. It holds the same lock as other Orbit DNS updates until restoration finishes. It preserves operator overrides and refuses to reset missing, malformed, or changed ownership state. It changes no global resolver files or IP routes. If the existing network DNS cannot resolve the package sources, bootstrap fails before managed-user setup.

After base bootstrap, provisioning writes a new tunnel configuration and restarts `wg-quick@orbit` while the earlier tunnel is still up. The configuration carries a `PostUp` hook that points the link at Orbit DNS and no `PreDown` hook: the AppArmor profile that Ubuntu 26.04 ships for wg-quick denies the resolver revert call, and systemd-resolved drops the link configuration when wg-quick deletes the interface. Private DNS becomes reachable after the Gateway peer and tunnel are ready.

The owning implementation and tests live in `apps/gateway`.
