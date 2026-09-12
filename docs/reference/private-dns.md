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

Existing peers keep their working resolver configuration until the Gateway provisions them again. Orbit does not apply this policy to the fleet automatically.

## Inspect a peer

Use the saved state to find the managed resolver link, server, and routing domains before you inspect live systemd-resolved state.

| Command | Expected result for the managed default |
| --- | --- |
| `sudo cat /etc/wireguard/orbit.dns-link` | Line 1 is the resolver link, line 2 is the Orbit VPN DNS address, and the remaining state is `.`. |
| `resolvectl status orbit` | The `orbit` link lists the Orbit VPN DNS address and routing domain `~.`. |
| `sudo grep -E '^(PostUp|PreDown) =' /etc/wireguard/orbit.conf` | `PostUp` selects the DNS server and `~.`; `PreDown` clears the managed DNS server and domains from the `orbit` link. |
| `getent ahostsv4 <route-hostname>` | The normal operating-system resolver returns the private Route address. |
| `dig +noall +answer @<vpn-dns-address> <route-hostname> A` | A direct query returns the same authoritative private answer. |
| `getent ahostsv4 example.com` | An ordinary name resolves through the same default selection. |
| `ip route` | Application routes remain independent from DNS server selection. |

An explicit underlay override can use a link other than `orbit`. Read line 1 of `orbit.dns-link`, then run `resolvectl status <link>` for that link.

## Persistence and recovery

The WireGuard `PostUp` and `PreDown` hooks restore and remove the managed live selection when the `orbit` interface starts and stops. Repeated peer convergence writes the same intended hooks and retained DNS state.

Before publication, the Gateway saves the live WireGuard configuration, DNS state, service activity, and service enablement. A failed immediate convergence restores that preceding state. A recoverable operation retains its transaction until the caller completes, then either commits the new state or restores the preceding state. A saved state record remains valid when its domain is the root token `.`. Older valid records that list several domains also remain valid inputs for recovery.

Do not edit `/etc/wireguard/orbit.key` or the peer private key to repair DNS. Resolve the reported provisioning failure and retry the same supported operation. Recovery artifacts under `/etc/wireguard` mean the previous operation did not finish cleanly; preserve them for diagnosis instead of starting an unrelated peer mutation.

## Availability and upstream resolution

A peer that uses Orbit VPN DNS as its default loses both private and ordinary DNS resolution while the VPN DNS listener is unavailable. Existing IP connections and the Node's general IP routes do not change, but new hostname lookups can fail until the listener or tunnel recovers.

Orbit VPN DNS forwards ordinary queries through independent uplink resolvers. It excludes loopback and the `orbit` interface so forwarding cannot return to its own listener. [VPN dnsmasq uplink resolvers](../solutions/vpn-dnsmasq-uplink-resolvers.md) owns upstream selection, fallback behavior, and verification.

DNS answers select an application address; they do not select or rewrite the application traffic route. [Routes](routes.md) explains how a resolved private Route reaches its workload through a Node or Router.
