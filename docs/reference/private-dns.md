# Private DNS

This page tells an operator how a managed Linux Node selects a Domain Name System (DNS) resolver, how to inspect that selection, and what remains unchanged when the Gateway converges DNS. [ADR 0061](../decisions/0061-use-vpn-dns-by-default-on-managed-peers.md) owns the default resolver policy.

## Resolver selection

The Gateway selects the resolver policy when it provisions a managed Linux peer.

| Peer configuration | DNS server | Routing domains | Result |
| --- | --- | --- | --- |
| No per-Node DNS override | Orbit VPN DNS | `~.` | The normal operating-system resolver sends private and ordinary queries to Orbit VPN DNS. The Node TLD and Cluster TLD do not change this selection. |
| Per-Node `--dns-server` override | The supplied address | The private VPN domain and the Node TLD when present | The explicit resolver keeps suffix-only routing, including when its address is inside the WireGuard subnet. |

The `~.` routing domain makes Orbit VPN DNS the preferred default for the peer without changing the system-wide `/etc/resolv.conf` owner. The peer does not run a local DNS server and does not need local hostname records or a list of private Route suffixes.

The default resolver policy for managed peers does not apply to operator-owned clients.

Existing peers keep their working resolver configuration until the Gateway provisions them again or an operator repairs one peer. Orbit does not apply this policy to the fleet automatically.

## Inspect a peer

Use the saved state to find the managed resolver link, server, and routing domains before you inspect live systemd-resolved state.

| Command | Expected result for the managed default |
| --- | --- |
| `sudo cat /etc/wireguard/orbit.dns-link` | Line 1 is the resolver link, line 2 is the Orbit VPN DNS address, and the remaining state is `.`. |
| `resolvectl status orbit` | The `orbit` link lists the Orbit VPN DNS address and routing domain `~.`. |
| `sudo grep -E '^(PostUp|PreDown) =' /etc/wireguard/orbit.conf` | `PostUp` selects the DNS server and `~.`. `PreDown` is absent. |
| `getent ahostsv4 <route-hostname>` | The normal operating-system resolver returns the private Route address. |
| `dig +noall +answer @<vpn-dns-address> <route-hostname> A` | A direct query returns the same authoritative private answer. |
| `getent ahostsv4 example.com` | An ordinary name resolves through the same default selection. |
| `ip route` | Application routes remain independent from DNS server selection. |

Provisioning and repair omit `PreDown` because systemd-resolved removes the link settings when WireGuard deletes the interface. If a managed `PreDown` hook is present, repair accepts it as input and removes it.

An explicit underlay override can use a link other than `orbit`. Read line 1 of `orbit.dns-link`, then run `resolvectl status <link>` for that link.

## Repair one peer

Run the DNS-only repair on the Gateway when one active managed Linux peer has an older resolver selection. The command uses the peer's stored WireGuard address and pinned Secure Shell (SSH) identity. It does not provision roles or restart the WireGuard tunnel.

```bash
php artisan orbit:node-dns-repair <node-name>
```

The command refuses a missing or inactive Node, an operator-owned client with no roles, and the Node that hosts the VPN role before it opens SSH. It also refuses a peer without a complete managed WireGuard and SSH identity.

Before the repair, record the commands in [Inspect a peer](#inspect-a-peer), `systemctl is-active wg-quick@orbit`, each role service state, and a fingerprint of `wg show orbit public-key`. Record the same values after the repair. The DNS server, routing domains, managed hooks, and saved DNS state can change. The public-key fingerprint, role services, WireGuard service state, application placement, and `ip route` output stay the same.

The repair holds `/run/lock/orbit-wireguard-peer.lock`, validates a candidate configuration, saves the preceding managed files, publishes the new hooks and DNS state, and then applies the live resolver selection. Running the command again produces the same resolver state.

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

A peer that uses Orbit VPN DNS as its default loses both private and ordinary DNS resolution while the VPN DNS listener is unavailable. Existing IP connections and the Node's general IP routes do not change, but new hostname lookups can fail until the listener or tunnel recovers.

Orbit VPN DNS forwards ordinary queries through independent uplink resolvers. It excludes loopback and the `orbit` interface so forwarding cannot return to its own listener. [VPN dnsmasq uplink resolvers](../solutions/vpn-dnsmasq-uplink-resolvers.md) owns upstream selection, fallback behavior, and verification.

DNS answers select an application address; they do not select or rewrite the application traffic route. [Routes](routes.md) explains how a resolved private Route reaches its workload through a Node or Router.
