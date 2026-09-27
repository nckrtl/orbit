---
title: "Private DNS"
description: "How a managed Node selects its resolver, how the Gateway answers Cluster Router addresses, and how to inspect and repair one peer."
covers:
  - apps/gateway/app/Infrastructure/AppDev/{DnsmasqPrivateDnsManager,AppDevDnsConfigRenderer,PrivateDns*,*PrivateDns*,*DnsRequester*,DnsAddress,DecodedDnsQuery,VpnDnsmasqBackendListen,NativeClusterRouterDnsSelectionReconciler}.php
  - apps/gateway/app/Domain/AppDev/{ClusterRouterDnsSelection*,Dns*,PrivateDns*}.php
  - apps/gateway/app/Infrastructure/WireGuard/{NativeWireGuardPeerConverger,NativeWireGuardPeerDnsRepairer,UplinkDnsResolvers}.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php
  - apps/gateway/app/Domain/Nodes/GatewayPrivateDnsRoute.php
  - apps/gateway/app/{Actions/Nodes/RepairNodeDnsAction,Console/Commands/RepairNodeDnsCommand}.php
---

# Private DNS

Orbit VPN DNS answers private names, such as Route domains and `gateway.orbit`, and forwards every other query to ordinary resolvers. It runs on the Node with the `vpn` role. Managed Linux Nodes use it as their resolver by default. This page explains resolver selection, the Gateway machine's resolver, the listener, Cluster Router addresses, and how to inspect or repair one peer. The active WireGuard manager projects VPN DNS behavior without creating local dnsmasq snippet files.

## Resolver selection

The Gateway selects the resolver policy when it provisions a managed Linux peer.

| Peer configuration | DNS server | Routing domains | Result |
| --- | --- | --- | --- |
| No per-Node DNS override | Orbit VPN DNS | `~.` | The normal resolver sends every query, private and ordinary, to Orbit VPN DNS. Node and Cluster TLDs do not change this. |
| Per-Node `--dns-server` override | The supplied address | The private VPN domain, and the Node TLD when the Node has one | Only those suffixes go to the supplied server, also when its address is inside the WireGuard subnet. |

The `~.` routing domain makes Orbit VPN DNS the preferred resolver. It leaves `/etc/resolv.conf` alone and needs no local DNS server, host records, or list of Route suffixes on the peer. On a Mac outside the fleet, use [`dns:resolve`](/cli/dns) for a local override. A peer changes its resolver only when you provision it again or [repair](#repair-one-peer) it.

A [Node without roles](/reference/node-provisioning#nodes-without-roles) uses neither row. Its tunnel configuration has a `DNS =` line with the WireGuard address of the `vpn` Node, and no `PostUp` hook or `orbit.dns-link`. It ignores a `--dns-server` override.

If Orbit VPN DNS is down, a peer that uses it by default loses both private and ordinary name resolution. Existing IP connections and routes stay. New lookups can fail until the DNS service or the tunnel recovers.

### Inspect a peer

Read the saved state first to find the resolver link, server, and routing domains. Then inspect the live systemd-resolved state.

| Command | Expected result for the default policy |
| --- | --- |
| `sudo cat /etc/wireguard/orbit.dns-link` | Line 1 is the resolver link, line 2 is the Orbit VPN DNS address, and the last line is `.`. |
| `resolvectl status orbit` | The `orbit` link lists the Orbit VPN DNS address and routing domain `~.`. |
| `sudo grep -E '^(PostUp|PreDown) =' /etc/wireguard/orbit.conf` | `PostUp` sets the DNS server and `~.`. There is no `PreDown`. |
| `getent ahostsv4 <route-domain>` | The normal resolver returns the private Route address. |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | A direct query returns the same answer. |
| `getent ahostsv4 example.com` | An ordinary name resolves through the same policy. |
| `ip route` | Application routes do not depend on the DNS selection. |

The `PostUp` hook restores the selection whenever the `orbit` interface starts. There is no `PreDown` hook, because systemd-resolved drops the link settings when WireGuard deletes the interface. An explicit override can use a link other than `orbit`. Read line 1 of `orbit.dns-link`, then run `resolvectl status <link>` for that link.

## The Gateway machine

The Gateway machine is not a managed peer. When it also holds `vpn`, its tunnel is the hub. When `gateway` moved to another machine, that machine keeps the tunnel it joined with. So the Gateway sets its own resolver in a separate step.

Gateway bootstrap and every `gateway` role convergence send queries for the private VPN domain to Orbit VPN DNS over the `orbit` link. The address is the configured VPN DNS server, or the WireGuard address of the `vpn` Node. Every other query, including names under Node and Cluster TLDs, stays on the uplink resolvers. So the Gateway still resolves package mirrors, GitHub, and ACME endpoints while Orbit VPN DNS is down. The Orbit CLI on the Gateway machine resolves `reverb.orbit` and stays live.

The drop-in `/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf` applies the route whenever the tunnel starts. It sets the routing domain and turns off the link's default DNS route before it sets the server, so the link never routes every name. A failure there never fails the tunnel. When `/etc/wireguard/orbit.dns-link` exists, the machine is a managed peer, and the step leaves the peer's policy in place.

If Orbit VPN DNS is unreachable, a private-name lookup on the Gateway machine takes about 40 seconds to fail, because systemd-resolved has no per-link timeout. Ordinary names still resolve at once.

| Command | Expected result on the Gateway machine |
| --- | --- |
| `resolvectl domain orbit` | `~orbit` |
| `resolvectl default-route orbit` | `no` |
| `resolvectl dns orbit` | The Orbit VPN DNS address |
| `getent hosts reverb.orbit` | The address of the Node that holds `websocket` |
| `getent ahostsv4 example.com` | An ordinary name resolves through the uplink resolvers. |

A failure of this step does not fail bootstrap or the role. The role stays `active`, and the response's `follow_up` names the failed route and the command that retries it. The CLI prints it as a warning. The Gateway logs the underlying code, `vpn.dns_resolver_failed` when the command fails on the machine. `orbit doctor --family=role` reports `role.private_dns_route_mismatch` until the route matches. Run `orbit node:role:add <gateway-node> gateway --converge` to retry.

Removing the `gateway` role removes the drop-in and reverts the `orbit` link. When that step fails, the role stays `failed` with `failed_step=remove:gateway-private-dns-resolver`, and running the removal again finishes it. `--offline` removal changes nothing on the machine and lists the drop-in under `retained_on_node`. [Relocating the gateway role](/solutions/relocate-gateway-role) adds the route on the target and removes it from the source.

## The listener

The listener and the published catalog live on the Node that holds `vpn`. The listener answers private names from the catalog on the VPN WireGuard DNS address. It forwards every other query to a dnsmasq backend on `127.0.0.55`. When `gateway` and `vpn` share a Node, the Gateway writes the files locally. Otherwise it sends the same publication to the `vpn` Node over SSH.

Gateway bootstrap starts `orbit-private-dns.service` after the dnsmasq backend is bound, so the first managed peer can resolve ordinary names during its role setup.

### Upstream resolvers

The dnsmasq backend sets `no-resolv` and forwards only to the IPv4 resolvers that the `vpn` Node itself uses on its uplink. At convergence the Gateway reads them from `/run/systemd/resolve/resolv.conf`. When that file lists none, it reads the DHCP lease of the default-route interface. It skips loopback addresses and the `orbit` interface, so forwarding never returns to the VPN DNS listener. When it finds no resolver, the backend uses `1.1.1.1` and `8.8.8.8`.

Convergence also moves the stock `/etc/dnsmasq.d/ubuntu-fan` snippet to `/var/lib/orbit/dnsmasq/disabled`, because it would add listen addresses beside the backend.

### Release and sockets

The Gateway installs the listener itself. The listener is a small release: `serve.php` and the Gateway classes it uses, with no Composer dependencies. Every publication that activates the listener sends the release from the Gateway's own code to the `vpn` Node. It lands in `/var/lib/orbit/private-dns/releases/<id>/`, where `<id>` is a digest of its files. So a Gateway deploy that changes the listener code produces a new id, and the next publication installs it. The publication keeps the previous release for rollback and removes older ones.

Each release holds a `.manifest` of file digests. Before a publication installs a release, it runs `serve.php --self-test`, which checks `ext-sockets` and `ext-pcntl` and loads every class. When an installed file differs from the manifest, the publication installs the release again and restarts the listener on it.

`orbit-private-dns.socket` binds UDP and TCP port 53 on the VPN DNS address and passes both sockets to `orbit-private-dns.service`. A service restart never closes them. Queries that arrive during a restart wait in the socket for the next listener. On `SIGTERM` the listener finishes the query in hand and exits. The unit gives it 5 seconds.

| Unit | Content |
| --- | --- |
| `/etc/systemd/system/orbit-private-dns.socket` | `ListenDatagram` and `ListenStream` on the VPN DNS address, with `FreeBind=yes`, and no ordering on the WireGuard tunnel, which would form a boot cycle |
| `/etc/systemd/system/orbit-private-dns.service` | `php8.5 /var/lib/orbit/private-dns/releases/<id>/serve.php --listen=… --port=53 --catalog=… --upstream=127.0.0.55:53`, `Sockets=orbit-private-dns.socket`, and `After=wg-quick@orbit.service` without `Requires=` or `Wants=`, so a query never starts a stopped tunnel |

### Catalog confirmation

The listener reads the catalog again on every query and once a second while idle. It compares file contents, not cached file status. After each load it writes the catalog's SHA-256 digest to `/var/lib/orbit/private-dns/catalog.json.loaded`. A catalog change never restarts a current listener.

Every publication checks that confirmation while the listener runs.

| Listener state | Publication result |
| --- | --- |
| The confirmation matches the published catalog within 5 seconds | The listener keeps running. |
| The catalog changed and the confirmation does not match within 5 seconds | The publication removes the confirmation and restarts the service behind its socket. |
| The catalog is unchanged and the confirmation names another catalog | The publication removes the confirmation and restarts the service once. |

A failed start or restart restores the previous units, DNS files, and services.

### Publication target

The Gateway finds the `vpn` and `gateway` Nodes by their role. A holder can be `active` or `provisioning`, and its role assignment can be `active` or `provisioning`. An `active` Node wins over a `provisioning` Node. A `failed` or `removing` Node is never a holder.

This matters during these commands:

- `node:add` on an existing `gateway` or `vpn` Node marks the Node `provisioning` and keeps its assignments active.
- `node:role:add NODE ROLE --converge` marks the assignment `provisioning` and keeps the Node active.

In both cases, publication still finds the `vpn` Node and publishes there over SSH. The reserved records also keep their holder: `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, the analytics tracking hosts, and `collector.cli-proxy-api.orbit`.

## Cluster Router addresses

The requester's registered Node decides which Router address it gets for a Cluster name. Private DNS answers are selections of an address. They do not change how application traffic is routed. [Routes](/reference/routes#set-up-private-traffic) explains how a resolved Route reaches its workload.

The Gateway answers with the Router's LAN address when all of these hold:

- the Cluster is active, and its Router has a LAN address;
- the requester is an active member of that Cluster with a LAN address and a registered WireGuard identity.

Every other requester gets the Router's WireGuard address. That includes a member without a LAN address, a member of another Cluster, and a source that is not an active registered WireGuard Node. The Gateway identifies the requester by the WireGuard source address that delivered the query. A shared LAN subnet or an identity in the DNS message does not count.

The rule covers the Cluster TLD and every exact Cluster-scoped Route domain, also a domain outside the Cluster TLD. Other names keep their own answers:

| Name | Answer |
| --- | --- |
| A Node-scoped Route | The workload Node's WireGuard address |
| A custom proxy Route | The serving Node's WireGuard address |
| A name under an `app-dev` Node's TLD | That Node's WireGuard address, unless the TLD is also a Cluster TLD |
| `gateway.orbit` | The WireGuard address of the Node with the `gateway` role, active or converging |
| `metrics.orbit` | The same address as `gateway.orbit`, and only while a Node holds the `metrics` role |
| `reverb.orbit` | The Node that holds `websocket` |
| `analytics.orbit` | The Node that holds `analytics` |
| `collector.cli-proxy-api.orbit` | The ProxyCli collector Node. See [proxycli](/reference/proxycli). |

These five platform names are reserved, so no Route can own them. `cli-proxy-api.orbit` is not reserved, and a custom proxy Route can publish it. Relocating the `gateway` role republishes `gateway.orbit`. Renaming a Node does not change it.

### Inspect the selection

Inspect the published catalog and the listener on the `vpn` Node. Then query from the Node whose answer you want to explain.

| Command | Expected result |
| --- | --- |
| `sudo cat /var/lib/orbit/private-dns/catalog.json` | `requesters` maps each registered WireGuard address to a Node id. `records` and `suffixes` hold the WireGuard answers. `overrides` lists LAN answers by `node:<id>`. |
| `systemctl is-active orbit-private-dns.socket orbit-private-dns.service` | Both units are active. |
| `sudo cat /var/lib/orbit/private-dns/catalog.json.loaded` | The digest equals `sudo sha256sum /var/lib/orbit/private-dns/catalog.json`, so the listener serves the published catalog. |
| `systemctl show -p ExecStart orbit-private-dns.service` | The service runs `serve.php` from the current release. |
| `ss -ulpn sport = :53` and `ss -tlpn sport = :53` | `orbit-private-dns` owns the WireGuard address on UDP and TCP port 53. dnsmasq owns `127.0.0.55:53`. |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | The answer selected for the querying Node. Add `+tcp` to check TCP. |

| Observation | Meaning |
| --- | --- |
| `overrides` has `node:<id>` for the name | That Node gets the Router's LAN address. |
| The name is only under `records` or `suffixes` | The answer is the Router's WireGuard address. |
| The query source is not in `requesters` | The Gateway treats the source as unknown and answers with the WireGuard address. |

To remove wrong LAN intent, run the Node's provision operation again without `lan_ip`, or with the right one. The Gateway republishes the affected answers before the new Node, Cluster, Router, or Route state becomes authoritative. A refused Cluster or Router change stays refused.

An unreachable LAN address stays selected. Orbit does not fall back to WireGuard. HTTPS connections fail until you repair the LAN path, or remove the LAN address and provision again.

## Repair one peer

Run this command on the Gateway to update the resolver settings of one active managed Linux peer. It uses the saved WireGuard address and pinned SSH identity. Roles and the running tunnel stay the same.

```bash
php artisan orbit:node-dns-repair <node-name>
```

The command refuses a missing or inactive Node, a [Node without roles](/reference/node-provisioning#nodes-without-roles), and the Node that holds `vpn`, before it opens SSH. It also refuses a peer without a complete managed WireGuard and SSH identity.

Record the [inspect](#inspect-a-peer) commands, `systemctl is-active wg-quick@orbit`, each role's service state, and a fingerprint of `wg show orbit public-key` before and after the repair. The DNS server, routing domains, hooks, and saved DNS state can change. The key fingerprint, role services, tunnel state, application placement, and `ip route` stay the same.

The repair takes `/run/lock/orbit-wireguard-peer.lock` and validates the proposed configuration. It backs up managed files, writes the hooks and DNS state, and then applies the resolver settings. Repeating the command gives the same result. It changes only the DNS servers and routing domains of each resolver link. Other link settings stay, such as the default-route preference, LLMNR, mDNS, DNSSEC, DNS over TLS, and negative trust anchors.

Each failure reports one code without remote command output.

| Failure code | Result and recovery |
| --- | --- |
| `node.dns_repair_missing`, `node.dns_repair_inactive` | Nothing changes. Correct the Node name, or restore the Node through its own lifecycle. |
| `node.dns_repair_operator_owned`, `node.dns_repair_vpn_server`, `node.dns_repair_platform_unsupported`, `node.dns_repair_identity_missing` | The Node is outside this command. Use its own resolver or provisioning workflow. |
| `vpn.peer_dns_busy` | Another peer operation holds the lock. Wait, then retry. |
| `vpn.peer_recovery_pending` | Another peer operation owns the saved candidate or backup. Keep the recovery files and finish that operation first. |
| `vpn.peer_dns_state_unsupported`, `vpn.peer_dns_candidate_invalid` | Nothing is published. Inspect `orbit.conf` and `orbit.dns-link`, then fix the peer through normal provisioning. |
| `vpn.peer_dns_apply_failed` | The command restored the previous files and resolver selection. Fix the DNS or systemd-resolved fault, then retry. |
| `vpn.peer_dns_recovery_failed` | Recovery files stay under `/etc/wireguard`. Keep them, fix the fault, and retry the same repair. The retry restores the previous state first. |
| `vpn.peer_dns_repair_failed`, `vpn.configuration_invalid` | The command reports no success. Fix SSH reachability or the Gateway VPN configuration, then retry. |

Peer convergence and repair save the live WireGuard configuration, DNS state, and service state before they publish. A failed change restores that state without resetting unrelated link settings. Recovery files under `/etc/wireguard` mean the last operation did not finish. Keep them for diagnosis. Do not edit `/etc/wireguard/orbit.key`, replace a private key, or restart the tunnel to repair DNS.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### VPN DNS as the default resolver on peers

A peer that resolves through Orbit VPN DNS needs no list of private suffixes. So a Cluster or Node naming change needs no client change. Rejected alternatives: send every private suffix to every peer, run a DNS server on every peer, and send all application traffic through the VPN. The cost is that a VPN DNS outage also stops ordinary lookups on those peers.

### Suffix-only routing on the Gateway machine

The Gateway repairs VPN DNS, so it must resolve ordinary names while VPN DNS is down. It therefore routes only the private domain to VPN DNS. `~.` on the Gateway machine is rejected for that reason. Resolving private names inside the CLI is rejected, because every other tool on the machine would still fail. Static `/etc/hosts` entries are rejected, because the records move with their roles.

### Router LAN answers from registered intent

A registered LAN address is operator intent. The Gateway selects the address centrally from registered Node data. Returning a LAN address to every client is rejected, because remote clients cannot reach that LAN. Detecting reachability or roaming on each client is rejected, because it needs client monitoring and automatic path changes. An unreachable LAN path shows as a failure instead of a silent WireGuard fallback.

### A listener release from the Gateway

The listener needs no framework: it reads a JSON catalog and answers DNS messages. A release built from the Gateway's own code reaches the `vpn` Node with the next publication, so the listener never runs stale code.

Syncing a full checkout on the `vpn` Node is rejected, because every deploy would then need GitHub, Packagist, and a Composer install there. The socket unit keeps the DNS address open during a restart, so a restart never stops VPN DNS. Two listeners that hand over with `SO_REUSEPORT` are rejected, because systemd runs one main process per service, and the kernel drops datagrams queued on a closing socket.

### Uplink resolvers for the backend

The backend must not forward to the systemd-resolved stub on `127.0.0.53` or `127.0.0.54`, because that path can loop back once the VPN DNS listener is bound. It forwards to the uplink resolvers instead, which a default-deny host firewall already admits.
