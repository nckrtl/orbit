---
title: "Node provisioning"
description: "How node:add bootstraps or converges a Node, how roles share and lock a Node, how the Orbit CLI and footprint reach it, and how node:remove hands it back."
covers:
  - apps/gateway/app/Actions/Nodes/{ProvisionNodeAction,EnrollMacOsNodeAction,RemoveNodeAction,AddNodeRoleAction,RemoveNodeRoleAction,AssignRoleAction}.php
  - apps/gateway/app/Domain/Nodes/RoleRegistry.php
  - apps/gateway/app/Infrastructure/Nodes/{NativeNodeConverger,MacOsNodeConverger,NodeBootstrapCommandFactory,NodeBootstrapDnsProgram,NodeBootstrapPackageCatalog,CaddyPackageSourceProgram,RemotePhpPackageManager,NodeLocks,NodeLock}.php
  - apps/gateway/app/Infrastructure/Firewall/NodeFirewallRuleCatalog.php
  - apps/gateway/app/Infrastructure/WireGuard/NativeGatewayPeerProjectionManager.php
  - apps/gateway/app/Console/Commands/ProvisionNodeCommand.php
  - apps/gateway/app/Infrastructure/Ssh/{NativeSshExecutor,SshConnection}.php
  - apps/gateway/app/{Infrastructure/Nodes/*Cli*.php,Domain/Nodes/NodeCli*.php,Domain/Fleet/NodeFootprint*.php,Domain/Fleet/NodeCliConvergence.php,Infrastructure/Fleet/Footprint/**,Actions/Fleet/ConvergeNodeFootprintAction.php,Http/Controllers/Api/NodeFootprintsController.php}
---

# Node provisioning

`orbit node:add <name> [host]` adds a machine to the fleet. For a Node that already exists, the same command converges it again after you change its TLD, roles, or settings. `orbit node:remove <node>` removes the Node from Orbit and leaves the machine reachable over public SSH. [`node`](/cli/node) lists the options.

## Add a Node

A new Node needs a public SSH host and an approved SSH host key fingerprint, `--host-key-fingerprint`. Without the fingerprint, the Gateway refuses with `node.ssh_host_fingerprint_required`. A Node with `app-dev` needs a TLD, unless it joins an active Cluster that has one. Otherwise the Gateway refuses with `node.tld_required`. Platforms are `linux` for Ubuntu 26.04 service Nodes and `macos` for selected tools. [macOS Nodes](#macos-nodes) use a separate enrollment path.

For Ubuntu, the Gateway records the Node as `provisioning` and runs these steps in order:

| Step | Work |
| --- | --- |
| 1 | Check the host key against the fingerprint. |
| 2 | Connect as the bootstrap user, install the base packages, create the managed user, add its SSH key, and grant passwordless sudo. |
| 3 | Connect as the managed user and read the machine architecture. |
| 4 | Publish the Node's WireGuard peer and pin SSH over the tunnel. |
| 5 | Record the architecture, install the `apt` [Tool Manager](/reference/tools#tool-managers), and converge each requested role. |
| 6 | Update Router LAN access and private DNS for the Node's Cluster. |
| 7 | Mark the Node `active`. |
| 8 | Store and prepare the [storage settings](/reference/node-settings). |
| 9 | Reconcile the [Metrics exporters](/reference/metrics#exporter-selection). |
| 10 | Install or upgrade the [Node agent](/reference/node-agent), at step `agent`. |

After the agent, the Gateway installs the [Orbit CLI](#orbit-cli) on a Node of the [fleet rollout set](/reference/gateway-recovery#rollout-set-and-order). A role converge does the same after its agent converge. Both are best effort: an unpublished CLI release or a failed install only logs a warning, and the fleet catch-up installs the CLI later.

The Gateway console command `orbit:node-provision` runs the same steps for the first Node. Role firewall state comes from the shared Node firewall rule catalog, keeping initial provisioning and later role reconciliation consistent.

### Bootstrap identity

The Gateway picks the bootstrap user from the Node record, unless the request names one.

| Node | Bootstrap user | How the bootstrap runs |
| --- | --- | --- |
| New | `root` | Directly |
| Existing | The recorded managed user | Through passwordless sudo |

Name a bootstrap user with `--user` when root cannot log in, or when the managed user cannot log in. `--orbit-user` names the managed user. A new Node uses `orbit`, and an existing Node keeps its recorded user. The API fields are `user` and `orbit_user`. The API refuses an empty or null `user`.

### Machine architecture

After it connects as the managed user, the Gateway reads the architecture with `uname -m`, such as `x86_64` or `aarch64`. A Node without a recorded architecture stores it. The value stays even when a later step fails. An existing Node keeps its recorded value. An explicit `--architecture` for a new Node must equal the observed value. A mismatch fails with `node.architecture_mismatch` (HTTP 409) at step `machine-architecture`, before any role converges.

### Failure codes

Each failure names the check or step that stopped the request.

| Code | Meaning |
| --- | --- |
| `node.invalid_linux_user` | The bootstrap user or the managed user is not a valid Linux user name. Nothing changes. |
| `node.user_change_unsupported` | Another managed user for a Node with roles, or another account for an already managed Mac. Nothing changes. |
| `node.platform_unsupported` | The platform or operation is unsupported. A macOS role request returns it before SSH. HTTP 422. |
| `node.platform_mismatch` | The observed platform is not the requested one. HTTP 409 at `machine-architecture`. |
| `node.macos_account_required` | macOS enrollment omitted the existing account. HTTP 422. Nothing changes. |
| `node.macos_account_mismatch` | `user` and `orbit_user` name different accounts. HTTP 422. Nothing changes. |
| `node.account_unavailable` | SSH as the existing macOS account failed. HTTP 502 at `account`. |
| `node.wireguard_required` | macOS enrollment has no WireGuard address to verify, or the requested address differs from the Node row. HTTP 409 at `identity`. Nothing changes. |
| `node.agent_unsupported` | A Node agent install or repair targeted macOS. HTTP 422. |
| `node.ssh_host_fingerprint_required` | A new Node has no approved host key fingerprint. |
| `node.ssh_host_key_scan_failed` | The Gateway could not read the host key. |
| `node.ssh_host_key_mismatch` | The host key differs from `--host-key-fingerprint`. |
| `node.ssh_host_key_changed` | The host key differs from the fingerprint stored for the Node. |
| `node.bootstrap_failed` | The bootstrap failed as the bootstrap user. |
| `node.orbit_ssh_failed` | The Gateway could not connect as the managed user after the bootstrap. |
| `node.architecture_unavailable` | The Gateway could not read the architecture. |
| `node.architecture_mismatch` | The requested architecture differs from the observed one. HTTP 409 at `machine-architecture`. |
| `node.role_convergence_failed` | A role failed to converge. The step is `role:<step>`. |
| `node.agent_install_failed` | The Node agent failed to install. |

## macOS Nodes

A macOS Node supports selected Homebrew formulae, casks, and Vite+ global packages. It needs no role. It uses an existing account, a working WireGuard connection, and SSH access authorized for the Gateway. Enrollment pins the approved SSH host identity and verifies the actual platform, architecture, account, and tunnel address before it succeeds. The Gateway resolves the host name. An SSH alias on the caller's machine is not a fleet address.

Use `node:add` with `--platform=macos`, the approved host fingerprint, and `--user` and `--orbit-user` set to the same existing account. The Gateway does not default those fields to `root` or `orbit`. A missing field returns `node.macos_account_required`. Different names return `node.macos_account_mismatch`. A name that fails the existing portable user check returns `node.invalid_linux_user`. All three are HTTP 422 and change nothing.

The request must name a WireGuard address that is already on the machine, or the Node row must already have one. The Gateway does not allocate a new address, install a tunnel, or rewrite the host tunnel, DNS, or firewall. A missing address returns `node.wireguard_required`. A requested address that differs from the address stored on the Node returns `node.wireguard_required` and changes nothing. A role, DNS server override, WireGuard endpoint override, storage settings change, Cluster change, TLD change, or LAN change returns `node.platform_unsupported` and changes nothing.

An eligible existing peer can be enrolled in place. It already has a row, no roles, a WireGuard address, and no pinned SSH fingerprint. After the platform, architecture, account, and tunnel checks pass, the Gateway stores the observed platform and `uname -m` architecture and the named account. It keeps the Node ID, name, access grants, WireGuard keys, address, and endpoint. Apple silicon stays `arm64`. The Gateway does not rewrite `arm64` to `aarch64`.

A requested platform or architecture that disagrees with the machine fails. `node.platform_mismatch` and `node.architecture_mismatch` are HTTP 409 at `machine-architecture`. An already managed Node has a pinned SSH fingerprint. Enrollment does not change its platform, architecture, or user. A disagreement fails and leaves the record as it was. A different account on that Node returns `node.user_change_unsupported` (HTTP 409).

The macOS path creates no account and applies no Ubuntu bootstrap, apt packages, UFW rules, systemd units, or managed DNS. It keeps the existing account, tunnel, Homebrew prefix, and Vite+ global scope. Discovery and adoption report a missing or conflicting manager without installing, replacing, or repinning it. Tool mutations require an active Node with verified WireGuard and pinned SSH identity.

### Enrollment steps

The Gateway does not record a new Node until enrollment succeeds or a remote check fails. A refusal before SSH creates no row. It runs these steps and does not run Ubuntu steps 2 to 10.

| Step | Name | Work |
| --- | --- | --- |
| 1 | `ssh-host-key` | Resolve the host and compare its key with the approved fingerprint. Do not pin the key yet. |
| 2 | `account` | SSH as the existing account. Create no user and change no sudoers file. |
| 3 | `machine-architecture` | Read `uname -s` and `uname -m`. Refuse a platform or architecture disagreement. |
| 4 | `identity` | Verify the WireGuard address on the machine. Do not write the registry yet. |
| 5 | `ssh-pin` | Write the approved host key to the Gateway known_hosts file for the public host and the WireGuard address. |
| 6 | `active` | Store the host key, fingerprint, observed identity, and `active` status. Skip apt, roles, firewall, DNS, exporters, and the Node agent. |

`uname -s` must be Darwin. A Linux host requested as macOS fails with `node.platform_mismatch` before any account, package, DNS, or firewall change.

### Enrollment recovery

A failed check changes nothing on the Mac. Retry with the same `node:add`. The retry does not run the Ubuntu bootstrap. The Gateway writes known_hosts before the registry pin. A known_hosts entry with no registry pin is harmless, and the retry completes it. The registry never stores an active pin without those entries.

| Failure | New Node | Existing peer |
| --- | --- | --- |
| Before SSH | No row. | Unchanged. |
| Remote check before `ssh-pin` | `failed` at that step. No host key is stored. | Unchanged. |
| `ssh-pin` | `failed` at `ssh-pin`. No host key is stored in the registry. | Unchanged. |

An already managed Node that fails stays as it was. Storage settings, Metrics reconcile, and the Node agent do not run, so a macOS failure never stops on steps 8 to 10.

### Unsupported operations

These operations refuse macOS before remote mutation and before they write a role or other new assignment.

| Operation | Code | HTTP |
| --- | --- | --- |
| Any role on `node:add` or `node:role:add` | `node.platform_unsupported` | 422 |
| Process mutation | `process.platform_unsupported` | 422 |
| Schedule mutation | `schedule.platform_unsupported` | 422 |
| Metrics exporter or Metrics role | `metrics.platform_unsupported` | 422 |
| Node agent install or repair | `node.agent_unsupported` | 422 |
| Firewall mutation | `firewall.platform_unsupported` | 422 |
| DNS repair | `node.dns_repair_platform_unsupported` | 422 |

Doctor checks lifecycle, SSH reachability, platform, architecture, tunnel identity, tools, and free space on the enrolled account's home volume. It does not expect systemd, a Node agent, an exporter, or a Linux disk path. A missing agent is not `node.agent_missing`. Realtime and the web page treat the Mac as a Node with no agent, not as a failed Linux service.

### Removal

Removing a macOS Node deletes the registry row and the hub peer. It does not delete the user, uninstall Homebrew or Vite+, or edit the host tunnel. It skips the Node agent step and `firewall-recovery`. `retained_on_node` lists `user`, `package-managers`, and `host-wireguard`. A failed hub or registry step uses the normal removal rollback: the Node returns to its previous status, and a removed peer is restored when that rollback succeeds. macOS OS updates, firewall management, application hosting, and a macOS agent are separate features.

JSON and the human `node:remove` output both report that list, so the operator can see the account, package managers, and host tunnel that remain.

## Nodes without roles

`node:add` without `--role` adds a Node that hosts no Orbit service. Use it for an operator machine that runs the Orbit CLI over WireGuard.

On Ubuntu, the Gateway uses this setup when the request names no role and the Node has no role assignment. macOS uses the [macOS enrollment](#macos-nodes) path instead of the steps below. Ubuntu runs the same steps as for any Linux Node, with these differences.

| Area | Node without roles |
| --- | --- |
| Bootstrap | Base packages, managed user, and passwordless sudo, as on any Node. The bootstrap keeps the machine's DNS as it is. |
| WireGuard DNS | A `DNS =` line with the WireGuard address of the `vpn` Node. No `PostUp` hook and no `orbit.dns-link`. |
| `--dns-server` | The Gateway stores the override, but the `DNS =` line does not use it. |
| Firewall | UFW is on, with `orbit:public-ssh-recovery` and `orbit:wireguard-members`. Public SSH stays open. See [Public SSH](#public-ssh). |
| Node agent | The Gateway installs the [Node agent](/reference/node-agent), because the Node has a pinned SSH host key. |
| Metrics | The Node runs no exporter until you run `metrics:exporter:enable`. See [Exporter selection](/reference/metrics#exporter-selection). |

When the package sources do not resolve, the bootstrap fails. Unlike a Node with roles, it does not clear Orbit DNS on the `orbit` link first. See [Add the machine again](#add-the-machine-again).

The `DNS =` line is in `/etc/wireguard/orbit.conf`, and `wg-quick` applies it when the tunnel starts. The Gateway deletes an existing `/etc/wireguard/orbit.dns-link`.

A later `node:add` without `--role` keeps this setup. To call the Gateway API from the Node, grant it access with [`node:access:add`](/cli/node#orbit-nodeaccessadd). The Gateway identifies the caller by its WireGuard address.

On Ubuntu, `node:role:add` accepts the same roles as on any other Linux Node. Adding the first role closes public SSH and moves the Node to managed DNS in the same operation. macOS refuses every role with `node.platform_unsupported` before SSH.

[`orbit:node-dns-repair`](/reference/private-dns#repair-one-peer) still refuses a Node without roles with `node.dns_repair_operator_owned`.

[Node retarget](/reference/node-retarget) keeps a Node without roles in operator DNS mode. The `DNS =` line stays, and the Node does not get an `orbit.dns-link`.

Doctor checks a Node without roles like any other Node when the Gateway has a pinned SSH host key for it. The `role` family reports nothing. The `schedule` family skips its orphan scan unless the Node hosts a Schedule. A Node without roles and without a pinned SSH host key gets only the lifecycle check.

## Converge an existing Node

`node:add` for a recorded Node converges the machine again. It refuses a Node that owns Instances with `node.has_instances`. One exception: it changes only the TLD of a Node with an active `app-dev` role.

What a failure leaves depends on the step. A macOS Node uses [enrollment recovery](#enrollment-recovery) instead of this table.

| Failed step | New Node | Node that was `active` |
| --- | --- | --- |
| Steps 1 to 6 | `failed` at that step | `active` again, with its earlier record and network state restored |
| 8, storage settings | `active`, with no settings stored | `active`, with the earlier settings kept |
| 9, Node exporter or cAdvisor reconcile failure | `active`, with Metrics degraded | `active`, with Metrics degraded |
| 9, Metrics runtime on the Metrics Node fails | `active`, with Metrics degraded | `active`, with Metrics degraded |
| 10, Node agent | `failed` at `agent` | `active` again |

A storage-settings failure returns its `node.settings_*` code, and the request skips steps 9 and 10. Only an unexpected error in step 8 marks the Node `failed` at `node-storage-root`.

Step 9 runs whenever a Metrics role exists. A Metrics reconcile failure does not fail provisioning or demote an active Node, including the `gateway` Node. The Node stays `active`; Metrics reports the failure as degraded. This applies to exporter, cAdvisor, runtime, and unexpected failures, both when `node:add` provisions a Node and when it converges an existing Node. See [Metrics role](/reference/metrics#exporter-selection) for how exporter state and degradation are reported.

## Orbit CLI

Every Node of the [fleet rollout set](/reference/gateway-recovery#rollout-set-and-order) runs the Orbit CLI, so the fleet rollout can run [`orbit self-update`](/reference/self-update) there. Provisioning, role converge, and the fleet rollout install it with the same step.

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-<version>`, `root:root` mode `0755` |
| Link | `/usr/local/bin/orbit`, a link to the binary. `orbit self-update` switches it with one rename and keeps the previous release |
| Profile | `/root/.orbit/config.json`, `root:root` mode `0600`: the active profile `gateway` with the URL `https://<Gateway WireGuard address>` and the root certificate `/etc/orbit/agent/ca.pem`, which the agent converge writes |
| Download | The desired CLI release's binary for `linux-<arch>`, from `https://github.com/nckrtl/orbit/releases/download/cli-v<version>/orbit-<version>-linux-<arch>` |

This is the layout `orbit self-update` keeps, so either one can update what the other installed. The profile holds no secret. The Gateway identifies the Node by its WireGuard address, as for every CLI call, and its certificate covers that address. `sudo orbit self-update` reads root's profile.

The step reads the link path first, as the managed user, never as root. A link may name only an `orbit-0.N.0` file beside it, without `/` or `..`. The file must be a `root`-owned ELF executable. Only then does the step run it with `--version`, which must print `Orbit 0.N.0` or, for a pre-release build, `Orbit <40-character commit>`.

| Found | Result |
| --- | --- |
| Nothing | Download the binary to `/usr/local/bin/orbit-<version>.orbit-candidate`, check its SHA-256 from the desired state and that it reports the release version, move it to `orbit-<version>`, and switch the link: `cli_installed` |
| An Orbit CLI that has `self-update` | Leave it: `cli_present`. `orbit self-update` replaces it |
| An Orbit CLI without `self-update`, such as a pre-release build | An older CLI. Install the release as for a missing one, and keep the old file as `orbit.orbit-previous` |
| Another link, a script, another program, or a file another user owns | Refuse with `cli.foreign_binary` without running a script. The fleet rollout leaves the Node out as `foreign_cli` until an operator moves the file aside |

The step reads the path again under the Node's update lock before it installs, because a self-update may have changed it meanwhile. The download, the move, and the link switch run under that lock, `/run/lock/orbit-self-update.lock`, which `orbit self-update` holds too ([Node agent](/reference/node-agent#install-and-upgrade)). A checksum mismatch deletes the candidate and fails with `cli.checksum_mismatch`; the link stays as it was. Then the step writes root's profile when it differs.

| Code | Meaning |
| --- | --- |
| `cli.release_unavailable` | The CLI release is not published yet, so a missing CLI cannot be installed |
| `cli.architecture_unsupported` | The release has no binary for the Node's architecture |
| `cli.download_failed` | The download failed |
| `cli.checksum_mismatch` | The download has another SHA-256. Nothing was installed |
| `cli.candidate_invalid` | The download does not run, or reports another version. Nothing was installed |
| `cli.foreign_binary` | `/usr/local/bin/orbit` is a link, a script, or a file Orbit did not install, such as a wrapper around a source checkout |
| `cli.configuration_failed` | The profile could not be written, or the Gateway has no WireGuard address |

## Converge the Orbit footprint

`orbit node:converge <node> [--force]` re-applies what the Gateway renders for a Node, under the Node's role lock. The fleet rollout runs the same step on each Node. It never changes an Instance, a Process unit, or a role, and it never runs `node:add`, so it works on a Node that owns Instances.

| Artifact | On which Node | Re-apply |
| --- | --- | --- |
| `agent` | Every managed Linux Node | The [agent converge](/reference/node-agent#install-and-upgrade). It restarts the agent only when a file changed |
| `caddy` | A Node with a Caddy role or Caddy sites | The [Caddy build](/reference/caddy-configuration). It reloads Caddy gracefully, only when the Caddyfile changed |
| `private-dns` | The `vpn` Node, when it is not the Gateway's | The [private-DNS](/reference/private-dns) listener release, units, records, and catalog. It restarts the listener or dnsmasq only for a change |
| `proxycli` | The [ProxyCli](/reference/proxycli) collector's Node | The collector script. A changed script restarts the collector, a Node-owned Process |
| `annotator` | A Node with an annotator Process | The server files in `/opt/orbit/annotator`. Running annotators keep their code until their Process restarts |
| `route-residue` | A Node that an [offline Route removal](/reference/routes#remove-a-route-from-an-unreachable-node) skipped | Caddy and PHP-FPM without the removed Route, then its certificates and firewall rules. A failure is `skipped` and retried later |

Each artifact has a digest that the Gateway computes from its own code and pins, without SSH. The Caddy digest covers every Gateway source file the Caddy build renders from: the build, its site sources, and the classes they use, such as `DevelopmentSite` and the `CaddyRelease` pin. The other digests cover the private-DNS listener release and publication code, the agent pin and the inputs its unit renders from, the collector script, and the annotator files.

A user's sites, Routes, and DNS records never change a digest. Their own operations publish them, and Doctor reports their drift. The `route-residue` digest is the exception: it covers the residues of offline Route removals, which are Orbit's own unfinished work, so the Node drifts until a converge removes them. The Gateway keeps the digests each Node last received, and a converge re-applies only the artifacts whose digest changed. It takes the Node's update lock over SSH first. So even a converge that changes nothing runs a few lock commands on the Node.

`--force` re-applies every artifact; each one still leaves a matching live copy alone. A Caddyfile that the render or the validation refuses is `skipped`, not failed; the [fleet rollout](/reference/gateway-recovery#one-node) fails a refused validation on its first Node. A failed artifact fails the command with its own error code and `details.artifact`, and the next converge applies it again. The digest of all artifacts is the Node's footprint digest, which the fleet catch-up compares.

Metrics exporters, cAdvisor, and the FPM exporter are not part of the footprint: their converge always restarts them, and the Metrics fleet reconcile owns them. Reverb and the third-party pins keep their own update paths.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `node.converge_unsupported` | 422 | The Node is not an active, managed Linux Node |
| `node_role.node_busy` | 409 | Another role operation held the Node's lock for 2 minutes |
| `node.footprint_caddy_failed` | 502 | The Caddyfile could not be published |

## Role compatibility

Some roles never share a Node. The Gateway refuses a conflicting role before it claims or converges anything.

| Role | Never shares a Node with |
| --- | --- |
| `gateway` | `ingress`, `app-dev`, `app-prod`, `database`, `analytics` |
| `ingress` | `gateway`, `app-dev`, `database` |
| `vpn` | `database` |
| `app-dev` | `gateway`, `ingress`, `app-prod` |
| `app-prod` | `gateway`, `app-dev`, `database` |
| `database` | `gateway`, `vpn`, `ingress`, `app-prod` |
| `analytics` | `gateway` |

`router`, `metrics`, and `websocket` share a Node with any role. `node:role:add` and `node:role:relocate` answer `validation.failed` with `Role [ROLE] conflicts with assigned role [OTHER].` `node:add` answers `node.role_conflict` (HTTP 422), with `Role [ROLE] conflicts with requested role [OTHER].` when one request names both roles. [Doctor](/cli/doctor) reports each assignment of a conflicting pair as `role.assignment_conflict`.

Not every role can be added the same way.

| Role | `node:add --role` | `node:role:add` |
| --- | --- | --- |
| `app-dev`, `app-prod`, `database`, `metrics`, `websocket` | Yes | Yes |
| `gateway` | Yes, as a singleton | Yes, as a singleton |
| `vpn` | Yes, as a singleton | No |
| `ingress`, `analytics` | No | Yes |
| `router` | No | No. Use [`cluster:router:set`](/cli/cluster#orbit-clusterrouterset). |

## Package sources

A role installs its packages from the Ubuntu archive, except PHP and Caddy.

| Package | Source | Files Orbit owns |
| --- | --- | --- |
| PHP | Sury, `https://packages.sury.org/php/` | `/etc/apt/sources.list.d/orbit-php.sources`, `/usr/share/keyrings/orbit-sury-php.gpg` |
| Caddy | `https://dl.cloudsmith.io/public/caddy/stable/deb/debian` | `/etc/apt/sources.list.d/orbit-caddy.sources`, `/usr/share/keyrings/orbit-caddy.gpg` |

For both, the Gateway downloads the signing key and refuses it unless it matches a pinned SHA-256 digest and a pinned fingerprint. It writes the keyring and a deb822 source file as `root:root` mode `0644`, and restores the earlier pair when a later step fails. It refuses a package candidate from any other origin. Orbit never uses `apt-key` or `add-apt-repository`.

Caddy must be at least 2.9.0. A lower release fails the `caddy-package-source` step and names both releases. Doctor reports it as `role.caddy_version_unsupported`. Converging a role upgrades an archive Caddy in place. `/etc/caddy/Caddyfile` is a symlink into Orbit's own versions directory, so the upgrade keeps the live configuration.

The roles `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, and `analytics` install Caddy when they converge. ProxyCli publication does the same on its Node. On the Gateway machine, the bootstrap and `php artisan orbit:gateway-web` install Caddy through local `sudo`. A failure there stops at step `gateway-caddy-install` with `gateway.caddy_install_failed`, and the live Caddy configuration stays unchanged. [Caddy configuration](/reference/caddy-configuration) describes how the Gateway builds each Node's Caddyfile.

### Caddy reloads

The Caddy step also writes `/etc/sysctl.d/60-orbit-caddy.conf`, owned by `root:root` with mode `0644`:

```text
net.ipv4.tcp_migrate_req = 1
```

On each reload, Caddy opens a new listening socket and closes the old one. With this setting, the kernel moves the connections that wait on the old socket to the new one, instead of resetting them. The step loads a candidate file with `sysctl --load` first, so a kernel that refuses the setting fails the step and leaves no file. It applies the setting on every run, so a changed live value returns to `1`. It refuses a live file that is a symlink, not a regular file, or not `root:root` mode `0644`. Doctor does not check the setting. Converge the role to repair it.

HTTP/1.1 clients can still get an empty reply on a new connection during a reload. The CLI and the PHP SDK use HTTP/1.1. The SDK retries a `GET` or `HEAD` request once after 250 milliseconds when the connection fails without a response. A request that changes state is never retried, so it can fail while the Gateway's Caddy reloads. Run it again. Browsers and curl use HTTP/2 and do not see this.

### Caddy on the Gateway machine

Converging the `gateway` role runs the Caddy step over SSH before its firewall step. A failure returns `node_role.convergence_failed` for step `caddy-package-source`, and the role becomes `failed` with error code `gateway.caddy_install_failed`. Run `orbit node:role:add <node> gateway --converge` to repair it. While the role is `failed`, `php artisan orbit:gateway-web` refuses with `gateway.web_node_missing`, because it needs an active `gateway` role.

Caddy also serves the Gateway API. A convergence that installs a newer Caddy restarts it, so the CLI can report `Could not reach the gateway.` while the Gateway finishes. `orbit activity:list` shows the real result. Run the command again after a minute.

## Role operations on one Node

The Gateway runs one role operation per Node at a time. The role lock covers the role's baseline convergence or removal, the Tool Manager setup for `app-dev` and `app-prod`, and the Node agent converge that follows.

`node:role:add`, `node:role:remove`, and the role steps of `node:add` take the lock before they claim the assignment. A second operation waits up to 2 minutes. Then it fails and leaves the assignment as it was.

| Command | Error |
| --- | --- |
| `node:role:add` | `node_role.convergence_failed`, with `details.step` `converge:node-lock` and `details.error_code` `node_role.node_busy` |
| `node:role:remove` | `node_role.remove_failed`, with `details.step` `node-lock` and `details.error_code` `node_role.node_busy` |

Every failed role operation returns its step's own code in `details.error_code`, such as `node_role.tool_manager_locked`, a `metrics.*` code, or `ingress.caddy_config_failed` for a Caddy removal. `node:add` and `node:remove` do the same when a role step failed.

`node:role:relocate` and Cluster Router changes take the lock only around the baseline step. The Metrics fleet reconcile runs inside the lock for `node:role:add`, `node:role:remove`, and `node:add`. It converges exporters on other Nodes without their locks, so two operations can never wait on each other. Operations on different Nodes run in parallel. An offline role removal runs outside the lock, because it changes nothing on the unreachable machine.

An operation that dies, such as a killed Gateway worker, leaves its role `provisioning` or `removing`. The claim is stale once it is 11 minutes old and no operation holds the lock. That is the role lock's request term plus a 1-minute margin. `node:role:add --converge` and `node:role:remove` then take it over, and Doctor reports `role.claim_stale`.

### Per-Node locks

Four locks guard work on one Node. They live in a file cache store under `ORBIT_HOME`, whatever `CACHE_STORE` says.

| Lock | Guards | Term | When it is busy |
| --- | --- | --- | --- |
| Tool | One package of one Tool Manager | 10 minutes | Fails at once with `tool.operation_locked` |
| Tool Manager | The shared scope of `apt`, `vp`, `composer`, or Homebrew; `brew` and `brew-cask` share the prefix lock | 10 minutes | Fails at once with `tool.operation_locked`, `node.tool_manager_locked` during `node:add`, or `node_role.tool_manager_locked` in a role operation |
| Role | Role operations | 10 minutes | Waits up to 2 minutes, then `node_role.node_busy` |
| Node agent | The [agent converge](/reference/node-agent#install-and-upgrade) | 7 minutes | Waits up to 2 minutes, then `agent.converge_busy` |

A Tool operation takes its Tool lock, then its manager lock. Several manager locks are taken in the order `apt`, `vp`, `composer`, `brew`. `brew-cask` takes the `brew` lock rather than a fifth lock. A role operation on `app-dev` or `app-prod` takes the `vp` and `composer` manager locks first, and then the role lock. The Node agent lock comes last. The locks cannot deadlock: the Tool and manager locks never wait, and no code takes the role lock while it holds the agent lock.

### Lock renewal

The 10-minute term matches the Gateway's PHP-FPM request limit. In an Artisan command, such as `orbit:node-provision`, the Tool, manager, and role locks have a 20-minute term. Each remote command times out after 15 minutes by default.

Before each command that a process runs, on the Node or on the Gateway, the Gateway renews every lock that the process holds for its full term. So a long operation keeps its locks, and a process that dies blocks the Node for at most one term after its last command started. A renewal fails only when the lock has expired. The command then does not run and fails with `node.lock_lost`, and so does every later command of the operation. A role operation that lost its lock stops, reports `details.error_code` `node.lock_lost`, and leaves the role `failed` with that code.

## SSH connections

The Gateway runs every remote command as a channel on one shared OpenSSH connection per Node. The first command opens the connection. Later commands reuse it, and it closes after 60 idle seconds. A command on a shared connection takes about 18 ms, and a command on a new connection about 190 ms.

The sockets live in `ORBIT_HOME/ssh/mux`, and the directory has mode `0700`. Every Gateway process runs as the `orbit` user. So web requests, the scheduler, and commands share the same connections. Each socket name hashes the user, the Node address, and the port. A Node with a new address or port therefore gets a new connection at once.

- A dead Node ends its connection within about 10 seconds, through `ServerAliveInterval=5` and `ServerAliveCountMax=2`.
- A key or host-key change on the Node applies when the connection closes.
- When the socket path is too long for a Unix socket, or the directory cannot be created, each command opens its own connection.
- When a Node refuses another channel, OpenSSH opens a direct connection for that command and writes two warning lines to its stderr.

A reachability check always opens a new connection. Doctor's Node inspection, the `--offline` probe of role and Node removal, and the Node probe of task cancellation use it. File copies between Nodes for Instance transfer and clone use `scp` on their own connections.

## Public SSH

The bootstrap adds the UFW rule `orbit:public-ssh-recovery` and enables UFW. Once SSH answers over WireGuard, the Gateway adds `orbit:wireguard-members` and keeps public SSH open. The first active role removes the public SSH rule, so the Gateway then reaches the Node only over WireGuard.

- A Node without an active role stays reachable over public SSH.
- A later `node:add` of such a Node connects over public SSH again. It finishes the WireGuard peer over the verified tunnel.
- Removing the last role restores `orbit:public-ssh-recovery` over WireGuard before the Gateway deletes the assignment.
- When that restore fails, the role stays `failed` at step `remove:firewall-recovery` with `node.firewall_recovery_failed`. A retry repeats the removal.
- Removing a role while another role remains keeps public SSH closed.
- [Node retarget](/reference/node-retarget) picks its path from the same boundary.

## WireGuard hub

The Node with the `vpn` role holds the WireGuard hub: its private key, address, listen port, and the list of peers. When a peer changes, the Gateway renders the hub configuration and installs it there.

When the `vpn` and `gateway` roles share a machine, or no `gateway` role is active, the Gateway installs the configuration locally with `sudo`. When they are on different Nodes, the Gateway sends the configuration over SSH to the `vpn` Node, with the private key on standard input, and activates `wg-quick@orbit` there. Without SSH, the projection fails with `vpn.server_config_install_failed` and never installs the configuration in `/etc/wireguard` on the Gateway machine. The Gateway always keeps the rendered hub configuration, private key included, at `ORBIT_HOME/generated/wireguard/orbit.conf`.

## Remove a Node

`orbit node:remove <node> [--offline] [--force]` removes the Node record and its Gateway configuration. It does not clean the machine. [macOS removal](#removal) also skips the Linux agent and firewall steps and reports the retained user, package managers, and host tunnel.

The Gateway refuses the removal until the Node is empty. Remove its Instances, Routes, Schedules, roles, Processes, [Database servers](/reference/database-servers#remove-a-server), and Orbit firewall rules first. Orbit never removes the caller's Node, the `gateway` Node, or the `vpn` Node.

| Code | Condition |
| --- | --- |
| `node.has_instances`, `node.has_routes`, `schedule.target_in_use`, `node.has_roles`, `node.has_processes`, `node.has_firewall_rules`, `node.has_database_servers` | The Node still owns that state. |
| `route.reconciliation_required` | An active Route depends on the Node. |
| `node.self_removal_forbidden`, `node.gateway_removal_forbidden`, `node.vpn_removal_forbidden` | The Node is protected. |
| `node.provisioning_busy` | Another lifecycle operation holds the Node name. |

Online removal runs these steps in order.

| Step | Result | Code when it fails |
| --- | --- | --- |
| `grafana-access-revocation` | The Gateway revokes the Node's Grafana access. | `node.grafana_access_revocation_failed` |
| `metrics-exporters` | The Gateway retires the Node's exporter state and reconciles the fleet. | `node.metrics_reconcile_failed` |
| Node agent | The Gateway stops and disables `orbit-agent.service` and deletes the unit, `/usr/local/bin/orbit-agent`, and `/etc/orbit/agent`. A failure only logs a warning. | none |
| `firewall-recovery` | The Gateway restores `orbit:public-ssh-recovery` over WireGuard. It skips this step for a Node without a WireGuard peer. | `node.firewall_recovery_failed` |
| `wireguard-projection` | The Gateway removes the WireGuard peer. | `node.wireguard_projection_failed` |
| `router-lan-ingress` | The Gateway removes the Node's Router LAN access in its Cluster. | `router.lan_ingress_failed` |
| `dns-projection` | The Gateway converges private DNS. | `node.dns_projection_failed` |
| `persistence` | The Gateway deletes the Node record. | `node.persistence_failed` |

A failed step returns the Node record to the status it had, restores the Metrics selection, and restores the WireGuard peer when the Gateway removed it. So a `failed` Node stays `failed`. When a rollback itself fails, the Gateway returns `node.removal_rollback_failed` with the step `wireguard-rollback`, `persistence-rollback`, or `metrics-exporters-rollback`.

### Offline removal

Use `--offline` only for a Node that the Gateway cannot reach. The Gateway probes the Node first. A Node that answers keeps every guard above, but the removal still skips `firewall-recovery`. So omit `--offline` for a Node that is up. A reachable Linux removal returns an empty `retained_on_node`. The Gateway still removes the Node agent, and public SSH stays as it was because firewall recovery did not run.

For a Node that does not answer, the API needs `force`, or the Gateway refuses with `node.confirmation_required`. The CLI sends it after `--force` or a yes at the prompt. The guards for Instances, Routes, Schedules, protected Nodes, and firewall rules still apply. The Gateway then removes every role on its own side, deletes the Node's Process records, removes the WireGuard peer, and deletes the record. It changes nothing on the machine. Caddy sites, checkouts, containers, Process units, Orbit UFW rules, the Metrics exporter, and the Node agent stay in place, and public SSH stays closed.

`retained_on_node` lists the Node agent, the Metrics exporter, and each shed role's leftovers. `follow_up` says those leftovers stay until you clear them. macOS does not use that list. [macOS removal](#removal) always reports `user`, `package-managers`, and `host-wireguard`.

### Add the machine again

After an online removal, the machine keeps its WireGuard tunnel, which can still point DNS at Orbit. When a new bootstrap cannot resolve the package sources for that reason, and the request names at least one role and no `--dns-server` override, it clears only the DNS server and route-all domain that match Orbit's ownership record on the `orbit` link.

The bootstrap then uses the machine's own DNS for the base packages and restores the earlier link values. It keeps operator overrides, refuses missing or changed ownership state, and changes no global resolver file or IP route. When the machine's own DNS cannot resolve the sources either, the bootstrap fails before it sets up the managed user. A Node added again without a role, or with a DNS server override, keeps its DNS as it is, and the bootstrap fails when the sources do not resolve.

Provisioning then writes a new tunnel configuration and restarts `wg-quick@orbit`. When the Node sends private DNS through WireGuard, the configuration has a `PostUp` hook that points the link at Orbit DNS. It never has a `PreDown` hook, because the AppArmor profile that Ubuntu 26.04 ships for wg-quick denies the resolver revert call.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Removal does not clean the machine

`node:remove` is a registry and VPN change, plus one firewall rule. Cleaning the machine during removal would change it while the command says it does not, and a failed cleanup would leave a half-removed Node. So you remove Processes, roles, and Instances with their own commands first. `node:add` covers both provisioning and convergence, so the pair is add and remove. Create and destroy stay reserved for machines at a hosting provider.

### One shared SSH connection per Node

Converges and removals run long chains of commands, and a new connection costs about ten times the command. A persistent SSH tunnel is rejected, because WireGuard already gives the private network. A higher `MaxSessions` on every Node is rejected, because OpenSSH already falls back to a direct connection. A reachability check cannot use the shared connection, because that connection outlives a stopped sshd and would report a Node as reachable.

### Public SSH before the peer goes

Role convergence closes public SSH. Without the recovery rule, a removed machine is reachable only through its provider console. So the Gateway reopens public SSH while the tunnel still works, and then removes the peer.

### The hub stays on the vpn Node

When `gateway` runs on another machine, that machine is itself a WireGuard peer. Installing the hub key there would overwrite its peer configuration and cut the Gateway off the network. So the hub configuration always goes to the `vpn` Node, and a missing SSH path fails instead of falling back to a local install. When both roles share a machine, a local install avoids SSH to itself.

### A kernel setting for Caddy reloads

Caddy's `grace_period` and `shutdown_delay`, a reload through the admin API, and a certificate cache that survives reloads leave the reset count unchanged in measurements. Handing Caddy a systemd socket would change every listener for the same effect. `net.ipv4.tcp_migrate_req` cut the resets by about 93%. It needs Linux 5.14 or newer, which every supported Ubuntu release has.

### The footprint converge, not node:add

After a Gateway release, a Node needs only what the Gateway renders from its own code and pins. `node:add` refuses a Node that owns Instances, and a role converge does far more than the Orbit footprint. So the footprint converge re-applies only the artifacts whose digest changed, and it never changes an Instance.

### A Mac needs no service role

A Mac is a managed Node for tools, not a service host. It uses the account, WireGuard identity, and SSH access that already exist. Enrollment pins the approved host key and checks the platform, architecture, account, and tunnel before it succeeds. It creates no account, installs no Ubuntu packages, and does not change host DNS or the firewall.

An application role was rejected because tools do not need application services and the role list stays empty. Package operations through the Node agent were rejected because SSH already makes the changes and the agent stays observation-only. The Ubuntu bootstrap was rejected because its packages, users, resolver, firewall, and systemd units do not apply to macOS. A second Homebrew or Vite+ install was rejected because the packages already on the machine would stay outside the scope Orbit manages.

Platform support is checked per operation. Tool management and Doctor run on the enrolled Mac. Linux roles, exporters, Processes, Schedules, and the Node agent do not. A role assignment fails before any remote change. macOS OS updates, firewall management, application hosting, and a macOS Node agent are separate features. A roleless Ubuntu Node proves the same empty-role boundary on Linux, including apt adoption without a reinstall. A real Mac proves enrollment and the Homebrew and Vite+ lifecycle. Low free space on that Mac makes the Node drift with `node.disk_low` while informational package findings stay informational.
