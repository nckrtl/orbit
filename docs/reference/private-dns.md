---
title: "Private DNS"
description: "How a managed Node selects its resolver, how the Gateway answers Cluster Router addresses, and how to inspect and repair one peer."
---

# Private DNS

Managed Linux Nodes use Orbit's Domain Name System (DNS) server over the VPN by default. This page explains resolver selection, Cluster Router addresses, and how to inspect or repair one peer. [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) defines the default policy.

## Resolver selection

The Gateway selects the resolver policy when it provisions a managed Linux peer.

| Peer configuration | DNS server | Routing domains | Result |
| --- | --- | --- | --- |
| No per-Node DNS override | Orbit VPN DNS | `~.` | The normal operating-system resolver sends private and ordinary queries to Orbit VPN DNS. The Node TLD and Cluster TLD do not change this selection. |
| Per-Node `--dns-server` override | The supplied address | The private VPN domain and the Node TLD when present | The explicit resolver keeps suffix-only routing, including when its address is inside the WireGuard subnet. |

The `~.` routing domain makes Orbit VPN DNS the preferred resolver. It leaves `/etc/resolv.conf` ownership unchanged and needs no local DNS server, hostname records, or Route suffix list. Operator-owned clients are excluded. A macOS operator installs caller-local TLD and exact Route overrides with [local resolver overrides](/reference/local-resolver-overrides). Existing peers keep their configuration until you provision them again or repair them individually.

## Inspect a peer

Use the saved state to find the managed resolver link, server, and routing domains before you inspect live systemd-resolved state.

| Command | Expected result for the managed default |
| --- | --- |
| `sudo cat /etc/wireguard/orbit.dns-link` | Line 1 is the resolver link, line 2 is the Orbit VPN DNS address, and the remaining state is `.`. |
| `resolvectl status orbit` | The `orbit` link lists the Orbit VPN DNS address and routing domain `~.`. |
| `sudo grep -E '^(PostUp|PreDown) =' /etc/wireguard/orbit.conf` | `PostUp` selects the DNS server and `~.`. `PreDown` is absent. |
| `getent ahostsv4 <route-domain>` | The normal operating-system resolver returns the private Route address. |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | A direct query returns the same authoritative private answer. |
| `getent ahostsv4 example.com` | An ordinary name resolves through the same default selection. |
| `ip route` | Application routes remain independent from DNS server selection. |

Provisioning and repair omit `PreDown` because systemd-resolved removes the link settings when WireGuard deletes the interface. If a managed `PreDown` hook is present, repair accepts it as input and removes it.

An explicit underlay override can use a link other than `orbit`. Read line 1 of `orbit.dns-link`, then run `resolvectl status <link>` for that link.

## The Gateway machine

The Gateway machine is not a managed peer. When it also holds `vpn`, its tunnel is the hub. When `gateway` moved to another machine, that machine keeps the tunnel it joined with. So the Gateway selects its resolver in a separate step. [ADR 0156](/decisions/0156-route-the-private-domain-on-the-gateway-machine-to-vpn-dns) records the decision.

Gateway bootstrap and every `gateway` role convergence send queries for the private VPN domain to Orbit VPN DNS over the `orbit` link. The address is the configured VPN DNS server, or the WireGuard address of the `vpn` Node. Other queries, including names under Node and Cluster TLDs, stay on the uplink resolvers, so the Gateway still resolves ordinary names while Orbit VPN DNS is down. The Orbit CLI on the Gateway machine then resolves `reverb.orbit` and stays live.

The drop-in `/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf` reapplies the route whenever the tunnel starts. A failure there never fails the tunnel. When `/etc/wireguard/orbit.dns-link` exists, the machine is a managed peer, and the step leaves the peer's resolver policy in place. Removing the `gateway` role removes the drop-in and reverts the `orbit` link.

| Command | Expected result on the Gateway machine |
| --- | --- |
| `resolvectl status orbit` | The `orbit` link lists the Orbit VPN DNS address and the routing domain `~orbit`. |
| `getent hosts reverb.orbit` | The address of the Node that holds `websocket`. |
| `getent ahostsv4 example.com` | An ordinary name resolves through the uplink resolvers. |

To add the route to an existing Gateway, run `orbit node:role:add <gateway-node> gateway --converge`. A failure stops at step `gateway-private-dns-resolver` with `vpn.dns_resolver_failed`. After [relocating the gateway role](/solutions/relocate-gateway-role), converge the role on the target the same way.

## Repair one peer

Run this DNS repair on the Gateway to update one active managed Linux peer's resolver settings. It uses the saved WireGuard address and pinned Secure Shell (SSH) identity. Roles and the running tunnel stay unchanged.

```bash
php artisan orbit:node-dns-repair <node-name>
```

The command refuses a missing or inactive Node, an operator-owned client with no roles, and the Node that hosts the VPN role before it opens SSH. It also refuses a peer without a complete managed WireGuard and SSH identity.

Before the repair, record the commands in [Inspect a peer](#inspect-a-peer), `systemctl is-active wg-quick@orbit`, each role service state, and a fingerprint of `wg show orbit public-key`. Record the same values after the repair. The DNS server, routing domains, managed hooks, and saved DNS state can change. The public-key fingerprint, role services, WireGuard service state, application placement, and `ip route` output stay the same.

The repair locks `/run/lock/orbit-wireguard-peer.lock` and validates the proposed configuration. It backs up managed files, writes the hooks and DNS state, then applies the resolver settings. Repeating the command produces the same result.

The repair changes only the managed DNS servers and routing domains on each resolver link. It keeps unrelated per-link settings such as the default-route preference, name resolution over local multicast (LLMNR), Multicast DNS (mDNS), DNS Security Extensions (DNSSEC), DNS over Transport Layer Security (TLS), and negative trust anchors.

Each failure reports one bounded code without remote command output.

| Failure code | Result and recovery |
| --- | --- |
| `node.dns_repair_missing`, `node.dns_repair_inactive` | The Gateway changes nothing. Correct the Node name or restore the Node through its owning lifecycle operation. |
| `node.dns_repair_operator_owned`, `node.dns_repair_vpn_server`, `node.dns_repair_platform_unsupported`, `node.dns_repair_identity_missing` | The target is outside this command. Use the target's owning resolver or provisioning workflow. |
| `vpn.peer_dns_busy` | Another peer operation still holds the shared lock. Wait for it to finish, then retry. |
| `vpn.peer_recovery_pending` | Another peer transaction owns the saved candidate or backup. Preserve the recovery files and finish or recover that operation before retrying. |
| `vpn.peer_dns_state_unsupported`, `vpn.peer_dns_candidate_invalid` | The command publishes nothing. Inspect `orbit.conf` and `orbit.dns-link`, then repair the owning peer state through normal provisioning before retrying. |
| `vpn.peer_dns_apply_failed` | The command restored the preceding files and live resolver selection. Remove the reported DNS or systemd-resolved fault, then retry. |
| `vpn.peer_dns_recovery_failed` | Shared recovery files remain under `/etc/wireguard`. Preserve them, remove the resolver or file fault, and retry the same DNS repair. |
| `vpn.peer_dns_repair_failed`, `vpn.configuration_invalid` | The command reports no success. Correct SSH reachability or Gateway VPN configuration, then retry. |

After `vpn.peer_dns_recovery_failed`, the retry restores its retained preceding state before it applies the intended policy.

Do not delete recovery files, edit `/etc/wireguard/orbit.key`, replace a private key, or restart the tunnel as part of DNS-only repair.

## Persistence and recovery

The WireGuard `PostUp` hook restores the managed live selection when the `orbit` interface starts. systemd-resolved removes that link selection when WireGuard deletes the interface, so the managed configuration does not need a `PreDown` hook. Repeated peer convergence writes the same intended `PostUp` hook and retained DNS state.

Before publication, the Gateway saves the live WireGuard configuration, DNS state, service activity, and service enablement. A failed immediate convergence restores that preceding state without resetting unrelated resolver-link settings. A recoverable operation retains its transaction until the caller completes, then either commits the new state or restores the preceding state. A saved state record remains valid when its domain is the root token `.`. Older valid records that list several domains also remain valid inputs for recovery.

Do not edit `/etc/wireguard/orbit.key` or the peer private key to repair DNS. Resolve the reported failure and retry the same supported operation. Recovery artifacts under `/etc/wireguard` mean the previous operation did not finish cleanly; preserve them for diagnosis instead of starting an unrelated peer mutation.

## Availability and upstream resolution

If Orbit VPN DNS is unavailable, peers using it as their default lose both private and ordinary hostname resolution. Existing IP connections and routes stay unchanged. New lookups can fail until the DNS service or tunnel recovers.

Gateway bootstrap starts `orbit-private-dns.service` after the VPN dnsmasq backend is bound, so the first managed peer can resolve ordinary names during role prerequisites.

Orbit VPN DNS answers private names from the published requester catalog on the VPN WireGuard DNS address, then forwards ordinary queries to a loopback dnsmasq backend. The backend uses independent uplink resolvers and excludes loopback and the `orbit` interface so forwarding cannot return to the public listener. [VPN dnsmasq uplink resolvers](/solutions/vpn-dnsmasq-uplink-resolvers) owns upstream selection, fallback behavior, and verification.

The listener and the published catalog live on the node that holds the `vpn` role. When `gateway` and `vpn` share a node, the Gateway writes those files locally. After [relocate](/solutions/relocate-gateway-role) splits the roles, the serving Gateway SSHes the same publication to the `vpn` node.

### Listener release and sockets

The Gateway installs the listener itself. The listener is a small release: `serve.php` and the Gateway classes it uses, with no Composer dependencies. Every publication that activates the listener sends the release from the Gateway's own code to the Node that holds `vpn`, over SSH when the roles split. The release lands in `/var/lib/orbit/private-dns/releases/<id>/`, where `<id>` is a digest of its files. A Gateway deploy that changes the listener code produces a new id. The next publication installs it and points the unit at it. The publication keeps the previous release for rollback and removes older ones. [ADR 0149](/decisions/0149-install-the-private-dns-listener-from-the-gateway) records this decision.

Each release holds a `.manifest` of file digests. Before a publication installs a release, it runs `serve.php --self-test`, which checks `ext-sockets` and `ext-pcntl` and loads every class. When an installed file differs from the manifest, the publication reinstalls the release and restarts the listener on it.

`orbit-private-dns.socket` binds UDP and TCP port 53 on the VPN DNS address and passes both sockets to `orbit-private-dns.service`. A service restart never closes them. Queries that arrive during a restart wait in the socket until the next listener reads them, so they do not fail.

| Unit | Content |
| --- | --- |
| `/etc/systemd/system/orbit-private-dns.socket` | `ListenDatagram` and `ListenStream` on the VPN DNS address, `FreeBind=yes`, and no ordering on the WireGuard tunnel, which would form a boot cycle |
| `/etc/systemd/system/orbit-private-dns.service` | `php8.5 /var/lib/orbit/private-dns/releases/<id>/serve.php --listen=… --port=53 --catalog=… --upstream=127.0.0.55:53`, `Sockets=orbit-private-dns.socket`, and `After=wg-quick@orbit.service` without `Requires=` or `Wants=`, so a query never starts a stopped tunnel |

The first publication after an upgrade moves the address from a listener that binds it itself to the socket unit. It stops the old listener and starts the socket unit right after, so lookups fail for a few milliseconds at most, once.

### Catalog confirmation

The listener rereads the catalog on every query and once a second while idle, and it reads the file contents, not cached file status. After each load it writes the catalog's SHA-256 digest to `/var/lib/orbit/private-dns/catalog.json.loaded`. A catalog change never restarts a current listener.

Every publication checks that confirmation while the listener runs.

| Listener state | Publication result |
| --- | --- |
| The confirmation matches the published catalog within 5 seconds | The listener keeps running. |
| The catalog changed and the confirmation does not match within 5 seconds | The publication removes the confirmation and restarts the service behind its socket. |
| The catalog is unchanged and the confirmation names another catalog | The publication removes the confirmation and restarts the service once. |
| The catalog is unchanged and there is no confirmation | The listener keeps running. A listener that never writes confirmations loaded the catalog when it started. |

A failed start or restart restores the previous units, DNS files, and services.

A role converge marks its assignment `provisioning` while it runs. The Gateway still counts that Node as the role holder, and an active holder wins when both exist. So a `gateway` or `vpn` converge keeps the publication target, and a `gateway`, `metrics`, `websocket`, or `analytics` converge keeps `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, and the analytics tracking hosts.

A publication or listener-activation failure restores the previous working DNS files and services on that node. It does not remove a Metrics runtime that already converged.

DNS answers select an application address; they do not select or rewrite the application traffic route. [Routes](/reference/routes) explains how a resolved private Route reaches its workload through a Node or Router.

## Cluster Router addresses

The requester's registered Node and local area network (LAN) settings determine which Router address it receives. [ADR 0062](/decisions/0062-select-cluster-router-dns-addresses-from-lan-intent) defines the rule.

The Gateway returns the Router's configured LAN address to an active, LAN-configured WireGuard member of the same active Cluster. It returns the Router's WireGuard address to every other permitted requester, including a member without a LAN address, a member of another Cluster, and a source it cannot identify as an active registered WireGuard Node.

The same rule applies to the Cluster TLD and to each exact Cluster-scoped Route domain. Node-scoped Project Routes, custom proxy Routes, `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` keep their established addresses. A custom proxy Route publishes an exact `host-record` for its domain and answers with the serving Node; the `websocket` role's `reverb.orbit` record answers with that role's own Node the same way. [Custom proxy Routes](/reference/routes#custom-proxy-routes) owns that Route kind. [proxycli](/reference/proxycli) owns `collector.cli-proxy-api.orbit`. Apex `cli-proxy-api.orbit` is not reserved; a custom proxy Route may publish it for CLIProxyAPI management.

`gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` are reserved platform names. A Route cannot own them. `gateway.orbit` answers with the WireGuard address of the Node that holds the active `gateway` role. Relocating that role republishes the record; see [Relocate the gateway role](/solutions/relocate-gateway-role). Renaming a Node does not change `gateway.orbit`.

| Observation | Meaning |
| --- | --- |
| `overrides` contains `node:<id>` for the Route domain or Cluster TLD | That registered Node receives the Router LAN address. |
| The name appears only under `records` or `suffixes` | The published default is the Router WireGuard address. |
| The query source is absent from `requesters` | The Gateway treats the source as unidentified and returns the WireGuard default. |

Inspect the published catalog and the live listener on the `vpn` node, then query from the Node whose address you need to explain. After a gateway relocate the serving Gateway and the `vpn` node are different machines.

| Command | Expected result |
| --- | --- |
| `sudo cat /var/lib/orbit/private-dns/catalog.json` | `requesters` maps each registered WireGuard address to a Node id. `records` and `suffixes` hold WireGuard defaults. `overrides` lists LAN answers by `node:<id>`. |
| `systemctl is-active orbit-private-dns.service` | The requester-aware listener is active on the VPN WireGuard DNS address. |
| `sudo cat /var/lib/orbit/private-dns/catalog.json.loaded` | The digest equals `sudo sha256sum /var/lib/orbit/private-dns/catalog.json`, so the listener serves the published catalog. |
| `systemctl is-active orbit-private-dns.socket` and `systemctl show -p ExecStart orbit-private-dns.service` | The socket unit holds the address. The service runs `serve.php` from the release that the Gateway's code builds. |
| `ss -ulpn sport = :53` and `ss -tlpn sport = :53` | `orbit-private-dns` owns the WireGuard address on UDP and TCP port 53. dnsmasq owns `127.0.0.55:53`. |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | A direct query from that Node returns the address selected for its registered WireGuard source. |
| `dig +tcp +noall +answer @<vpn-dns-address> <route-domain> A` | The TCP query returns the same selected address. |

Remove incorrect LAN intent through the Node's existing provision operation by omitting or replacing `lan_ip`, then retry that operation. The Gateway republishes affected selection before the new Node, Cluster, Router, or Route state becomes authoritative. The live listener rereads the published catalog without a manual restart. A publication restarts a listener that does not [confirm](#catalog-confirmation) the catalog, behind its socket. A publication or listener-activation failure restores the previous working DNS files and services or retains explicit recovery state, and a refused Cluster or Router transition remains refused.

An unreachable configured LAN address stays selected; Orbit does not fall back to WireGuard. HTTPS connections fail until you repair the LAN path or remove the LAN setting and retry provisioning.
