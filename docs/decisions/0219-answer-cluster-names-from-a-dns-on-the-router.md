---
title: "ADR 0219: Answer Cluster names from a DNS server on the Router"
sidebarTitle: "0219 Cluster DNS"
description: "In progress. Each Cluster Router with a LAN address runs dnsmasq that answers the Cluster TLD and exact Cluster Route domains with that LAN address. LAN members ask it first through a systemd-resolved delegate. The VPN DNS becomes plain dnsmasq with WireGuard answers, and the PHP listener is deleted."
---

# ADR 0219: Answer Cluster names from a DNS server on the Router

This proposed design picks the Router address by where a query goes, not by who sends it. Each active Cluster with a Router LAN address gets a Cluster DNS: dnsmasq on the Router LAN address that answers its TLD, when set, and every exact Cluster-scoped Route domain with that address. This includes Route domains outside the TLD. A LAN member uses the Cluster DNS first and the VPN DNS second. The Cluster DNS forwards all remaining names to VPN DNS. Other requesters use VPN DNS directly. VPN DNS becomes plain dnsmasq that gives everyone WireGuard addresses. The PHP listener, its codec, and its catalog of requesters are deleted.

## Status

In progress.

Principle: [Lean](/mission#principles). Two stock dnsmasq instances replace a custom DNS server, its release, its socket units, and its catalog of requesters. It also serves [Deterministic first](/mission#principles): the configuration, not a lookup of the query's source, decides which server answers first. No principle exception is proposed.

Design proposal only; no implementation or fleet change is authorized by this ADR.

All new units, programs, files, digest storage, lifecycle hooks, and Doctor codes below are planned. Existing code names describe integration points, not completed implementation. Fleet observations below were recorded during design on 2026-10-16 and must be checked again before rollout.


## Context

- The `vpn` Node answers private names with a PHP listener in front of a dnsmasq backend on `127.0.0.55` ([Private DNS: The listener](/reference/private-dns#the-listener)). The listener is about 2,200 lines of code and 2,100 lines of tests: a DNS codec, a transport server, inherited sockets, a release, a catalog store, and a catalog confirmation.
- The listener gives a Cluster member the Router LAN address and everyone else the Router WireGuard address. It knows the requester by the WireGuard source address of the query ([Cluster Router addresses](/reference/private-dns#cluster-router-addresses)). dnsmasq alone cannot do that: `--localise-queries` keys only on the receiving interface, and every WireGuard query arrives on the `orbit` interface.
- The backend records, `orbit-records.conf`, already hold the WireGuard answer for every private name. The catalog adds only the LAN answers for members and the map of requesters.
- Managed Linux Nodes run Ubuntu 26.04 with systemd 259. The [systemd-resolved manual](https://www.freedesktop.org/software/systemd/man/259/systemd-resolved.html), [delegate manual](https://www.freedesktop.org/software/systemd/man/259/systemd.dns-delegate.html), and [v259 resolver source](https://github.com/systemd/systemd/tree/v259/src/resolve) establish these constraints. These facts decide how a member can hold two servers:
  - A DNS server set on a link is bound to that link with `IP_UNICAST_IF` (`dns_server_ifindex`: "The link ifindex always takes precedence"). A LAN address on the `orbit` link goes into the tunnel. No peer `AllowedIPs` covers it, so WireGuard drops it.
  - In one scope, resolved uses one current server. It moves to the next server after a timeout, an ICMP error, a REFUSED answer, or a SERVFAIL without an extended error code. It stays there until a reload, a restart, or a failure of that server.
  - When two scopes tie on the best routing domain, resolved asks both and returns the first successful answer.
  - A DNS delegate (`/etc/systemd/dns-delegate.d/*.dns-delegate`, since systemd 258) is a scope with its own servers and routing domains. Its servers are not bound to a link, so the routing table picks the interface. `systemctl reload systemd-resolved` builds each delegate again from its first server, keeps the link settings, flushes the caches, and restarts the stub listener.
- The live fleet has one Cluster with a LAN Router: `dev`, TLD `test`, Router beast. Its LAN members are beast, shark, and sabre, which has no roles. Its task VMs have no LAN address, and their firewall drops private ranges. The Router of the `prod` Cluster has no LAN address. All 39 `dev` Routes use names under `test`. Three custom proxy Routes on beast also use names under `test` and get beast's WireGuard address.
- On beast, operator rules admit every port from 192.168.6.0/24 and 192.168.1.0/24, and an unmanaged rule, `Orbit E2E Incus DNS`, admits port 53 from any source.
- Before 2026-09-13, dnsmasq answered on the VPN DNS address itself, with `bind-dynamic`, and on the private interface of the `vpn` Node. Bootstrap firewall rules still open port 53 on that interface, but nothing listens there. app-prod's `--dns-server` override, 10.0.0.2, points at it.

## Decision

The Gateway renders the VPN DNS, each Cluster DNS, and each member delegate from the same records. This changes two rationales for member DNS: [LAN first, without fallback](/reference/routes#lan-first-without-fallback) and [Router LAN answers from registered intent](/reference/private-dns#router-lan-answers-from-registered-intent). A member that cannot reach its Cluster DNS gets the WireGuard answer. The Router-to-workload hop keeps no fallback.

### Where each answer comes from

| Requester | Cluster TLD and exact Cluster Route domains | Other names |
| --- | --- | --- |
| A LAN member that holds the delegate | The Cluster DNS gives the Router LAN address, or for an exception the address that the VPN DNS gives. The VPN DNS is the second server. | Forwarded through Cluster DNS to VPN DNS, or VPN DNS directly after fallback |
| Any other Node, sabre included | The resolver of the Node, as today. The VPN DNS gives the Router WireGuard address. | The resolver of the Node, as today |

An outside-TLD Route is an exact name, not a new wildcard. For example, `app.example.com` gets the Router LAN address, while `child.app.example.com` goes to VPN DNS unless another record covers it.

### The Cluster DNS

- A Cluster has a Cluster DNS while it is active and its Router has an IPv4 LAN address.
- It runs as `orbit-cluster-dns.service` on the Router: `/usr/sbin/dnsmasq` from `dnsmasq-base`, with its own files in `/etc/orbit/cluster-dns/`. It never reads `/etc/dnsmasq.d` and never uses the stock `dnsmasq.service`.
- It listens only on the Router LAN address, with `bind-dynamic`, so it starts before the address exists at boot and never binds the wildcard address. The unit orders itself before `nss-lookup.target`, as the stock dnsmasq unit does.
- It answers the Cluster TLD and exact Cluster Route domains locally. It forwards remaining queries only to the explicit VPN DNS address, never to the Router's resolved stub. This avoids a forwarding loop.

| File | Content | On change |
| --- | --- | --- |
| `dnsmasq.conf` | `listen-address`, `bind-dynamic`, `no-resolv`, `no-hosts`, `server=<VPN DNS address>`, `local-ttl=30`, `addn-hosts=/etc/orbit/cluster-dns/hosts`, the exact-name rule pairs below, and, when a TLD is set, `local=/<tld>/`, `address=/<tld>/<Router LAN>`, an `address=` line with the owning Node's WireGuard address for each more specific Node TLD below the Cluster TLD | Restart |
| `hosts` | Exact Cluster Routes with the Router LAN address; other exact VPN records under the TLD with their VPN addresses | Reload with `SIGHUP` |

- `local=/<tld>/` prevents non-A queries from falling through to upstream DNS: an IPv4-only `address=` does not stop forwarding other record types. Pair each more specific Node TLD wildcard with `local=` too. Exact IPv4 hosts entries alone do not stop non-A forwarding. For each exact Cluster Route outside the Cluster TLD, render both `local=/<route>/` and `server=/*.<route>/<VPN DNS address>`. The first keeps the exact name local; the second restores forwarding for descendants at every depth. Never emit the local rule without its descendant rule, or an outside-TLD `address=/route/` wildcard. More specific local records and TLDs retain their precedence. Render a pair for each nested outside-TLD Route too. With no Cluster TLD, every exact Cluster Route gets a pair and the hosts file remains enabled.
- At an exact Route name, A returns the LAN address. Queries for absent ordinary record types, including AAAA, MX, TXT, HTTPS and CNAME, return local NODATA: NOERROR with no answers, not NXDOMAIN or REFUSED. No upstream IPv6 address may bypass the Router. ANY is a query for available records, not an absent record type; dnsmasq returns the local A record. Reverse PTR queries use a different name and retain normal dnsmasq hosts behavior. Zone-transfer and other protocol control queries are not covered by the NODATA promise. These local negative answers have no SOA; do not claim the 30-second positive TTL provides negative caching ([RFC 2308 sections 2.2 and 5](https://www.rfc-editor.org/rfc/rfc2308)).
- A name in `hosts` wins over the TLD address, and a more specific `address=` wins over a less specific one. A test with a real dnsmasq checks both.
- `SIGHUP` reads `hosts` again and clears the cache without closing a socket. An address-only change of an exception therefore does not stop the Cluster DNS. Adding, renaming or removing an outside-TLD Route also changes its rule pair and restarts Cluster DNS. Stage and validate the hosts and config files together, then restart once; restore both on failure. Removing a pair must restore forwarding for the removed name while preserving nested Route pairs. Test these changes and rollback against the real daemon. A TLD change can change which Routes need pairs. These restarts can trigger sticky fallback; Doctor and the member repair handle it, without a new per-Route member step.

### The VPN DNS

- The VPN DNS is the stock `dnsmasq.service` on the `vpn` Node, as before the listener. `orbit-vpn.conf` has one `listen-address` line, the VPN DNS address, an `interface=` line for the private interface, and `bind-dynamic`.
- Before any role assignment, Router activation, address change, or cutover, enumerate the actual VPN bind set: the VPN DNS address, every address on the private interface, and loopback added implicitly by `interface=`. Refuse co-location when the Router LAN address overlaps that set, or either required socket has another owner. Check both UDP and TCP. Do not exclude an address from VPN DNS silently: existing clients may use it. Disjoint bind sets permit both roles on one Node. Repeat this preflight on convergence; address drift leaves the last working configuration intact and raises Doctor drift.
- Its records are today's WireGuard answers. The TLD wildcards stay in `orbit-records.conf`. The exact names move to an `addn-hosts` file, which that file names. A change only to the hosts file sends `SIGHUP`. A config change, including a TLD wildcard change, restarts dnsmasq because SIGHUP does not read config again. Ordinary exact Route changes therefore do not restart VPN DNS. Test TLD add, change, removal, and rollback with a real daemon, including the restart interruption.
- `local-ttl=30` keeps the 30-second TTL of today's answers, which the [withdrawal grace period](/reference/routes#withdrawal-grace-period) relies on.
- It keeps the `server=` lines of the `vpn` Node, which bootstrap wrote from that Node's uplink resolvers. The publication rewrites only the lines that Orbit owns.
- `bind-dynamic` cannot run next to a snippet with `bind-interfaces`, such as the stock `ubuntu-fan` snippet. The cutover moves every such snippet in `/etc/dnsmasq.d` to `/var/lib/orbit/dnsmasq/disabled`.
- The listener, its release, its socket units, `catalog.json`, the requester map, and the LAN overrides are deleted.

### Record boundary

[PR #1299](https://github.com/nckrtl/orbit/pull/1299), now merged into main, added `ContributedPrivateDnsRecords` and `ContributedReservedHostnames`. The implementation must preserve that contract. Core and extension records pass one validation boundary before either server is rendered:

- Accept canonical lowercase DNS labels only (1–63 characters per label, at most 253 per name). Reject whitespace, newlines, commas, slashes, wildcards, control characters, and config separators. Validate stored data as well as API input.
- Accept only IPv4 host addresses. VPN records, including extension records and Node TLD targets, must be usable addresses in the configured VPN subnet, excluding network and broadcast addresses. A Cluster wildcard target must equal the registered Router LAN address and pass the existing LAN-address validation. Never accept raw dnsmasq fragments from a contributor.
- Refuse duplicate exact names across contributors and core, even with equal addresses, before merging contribution maps. Refuse new duplicate suffix owners at the request and migration boundary. An exact Route overriding a TLD wildcard and a more specific Node TLD are intentional precedence, not duplicate owners.
- Reserve core names and extension names before accepting Routes. As in #1299, extension names must belong to their registered reserved `.orbit` namespace; no Cluster or Node TLD may cover it or the configured private VPN namespace. Refuse new conflicts at the request boundary and in migrations that introduce or rename reserved names. Existing stored Route or TLD conflicts remain published with the current renderer precedence and Doctor findings, including `route.domain_reserved`; they never fail fleet-wide DNS publication. Preserve the existing Node/Cluster TLD skip and core/contributed record precedence. Do not reinterpret a stored reserved-name conflict as a duplicate contribution.
- Validate the full record set before the first remote write. A malformed or duplicate extension contribution fails publication with its owner and reason and leaves the previous projections intact. Run `dnsmasq --test` on staged files too; syntax checks do not replace boundary validation or live socket checks.

For non-conflicting state, extension records go only to VPN DNS. Cluster DNS derives exceptions from the effective VPN records under its TLD, never from an independent extension renderer. If a legacy stored TLD covers a reserved extension name, preserve that name's effective VPN answer as a Cluster exception too and report the stored conflict. This compatibility path does not permit a new conflicting TLD.

Contributions follow the placement/setup record, never the enabled switch. Reserved names remain reserved. Under [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary#enable-and-disable), setup and teardown own fleet work. For AI router, withdrawal belongs to the `disable()` hook in [ADR 0212 build step 4 and amendment A3](/decisions/0212-turn-proxycli-into-the-ai-router-extension#build-order). Until that step is implemented, `extension:disable` leaves the record published. When an authorized teardown or that hook withdraws placement, it republishes the snapshot and clears the server caches with `SIGHUP`; it reports a failed publication and supports retry. Clients may retain an answer for its 30-second TTL. Tests cover switch-off with placement retained, actual placement withdrawal, failed publication and retry, malformed contributions, duplicate owners, and stored reserved-name conflicts that still publish.

### Publication

- One publication renders the VPN DNS and each Cluster DNS from the same database state and overrides, under the projection lock `$ORBIT_HOME/.dnsmasq-projections.lock`. It writes the VPN DNS first, then the Cluster DNS of each Cluster in Cluster order.
- For each Cluster that has a Cluster DNS in the render, it writes the Router that `ClusterRouterDnsSelection` names: the active Router, or a replacement candidate after its `dns-publication` step. It never writes an old Router or a `Failed` row. An explicit transition render remains authoritative until commit or restore; intermediate Route publications must carry that same override. Simply omitting deletion does not protect an expanded TLD or Router selection from being overwritten. It never removes a Cluster DNS. Only the explicit removals in the lifecycle do that.
- The Gateway stores the digest of the last Cluster DNS it wrote to each Router Node. It clears the digest before a write and after a removal, and stores it after the program confirms the write. A Router with an unchanged digest gets no connection, so a down Router blocks only publications that change its own Cluster DNS. A Router with no digest always gets a write.
- Remote writes are sequential, not atomic across Nodes. On partial failure, invalidate the affected digests, restore every touched projection from the previous snapshot, and report any failed restore as drift with a repair target. When a write fails, the publication fails with `app-dev.dns_config_failed`, and the caller's restore publishes the previous state, as today. A command with `--offline` skips a Router write that it cannot make, and Doctor reports the drift.
- The planned ADR 0211 remote program `dns.converge-cluster-dns` owns the Cluster DNS on a Router. With a render, it installs and enables the unit when missing, writes the files, restarts for a `dnsmasq.conf` change, sends `SIGHUP` for a `hosts` change, starts an inactive unit, and confirms that the unit owns UDP and TCP port 53 on the LAN address. With `absent`, it stops and disables the unit and deletes `/etc/orbit/cluster-dns`. It returns `package_missing` when `/usr/sbin/dnsmasq` is missing.
- The publication, the Router baseline and its removal, and a new `cluster-dns` footprint artifact run that program. The artifact ignores the stored digest. The fleet rollout runs it once on each Router that has a Cluster DNS, and `node:converge <router> --force` repairs drift.
- The planned ADR 0211 remote program `dns.converge-vpn-dns` writes the VPN DNS.
- `dnsmasq-base` joins the `router` role packages and its recipe entry. Recipe convergence installs it on existing Routers.

### The member step

- A LAN member is an active member of a Cluster that has a Cluster DNS, with an IPv4 LAN address, a WireGuard address, and a WireGuard key. The Router is a LAN member of its own Cluster. The firewall sources are the LAN members other than the Router.
- A LAN member holds the delegate when it is a managed Linux Node with the default DNS policy: it has a role, it has no `--dns-server` override, and it holds neither `vpn` nor `gateway`.
- The member step is the ADR 0211 remote program `dns.select-cluster-dns`. On a Node that holds the delegate, it writes `/etc/systemd/dns-delegate.d/orbit-cluster.dns-delegate`. On every other managed Linux Node, it deletes that file when it exists.

```ini
[Delegate]
DNS=<Router LAN address> <VPN DNS address>
Domains=~.
```

- It then runs `systemctl reload systemd-resolved`, also when the file did not change, so that a repair moves a member back from the second server. It creates the directory when it is missing.
- The delegate is the only catch-all scope for an eligible member. Remove `~.` from the `orbit` link, set that link's `DefaultRoute=no`, and suppress competing routing domains that would select VPN DNS for Cluster names. Refuse unsupported competing resolver scopes before changing anything. Keep the link's VPN DNS address for restoration. Update the persistent DNS policy used by `PostUp` and `orbit.dns-link`, as well as live `resolvectl` state, so a tunnel restart cannot recreate a tie. Keys, peers and `AllowedIPs` never change.
- Stage and restore the delegate, persistent link policy, and live resolver settings as one member step. Remove all Orbit-owned link routing domains while the delegate is installed; a more specific private suffix would otherwise bypass it. On withdrawal or rollback restore the Node's ordinary DNS policy before deleting the delegate. A reload failure restores all three and reports any failed restore as drift. The default eligible policy is VPN DNS with `~.`; override and Gateway policies restore their own routing domains.

- The step takes the peer lock `/run/lock/orbit-wireguard-peer.lock` on the Node and the Router lock of the Cluster on the Gateway. The Router lock is re-entrant, so the Cluster actions that hold it can run the step.
- Node provisioning, `php artisan orbit:node-dns-repair <node>`, and the lifecycle steps below run it.

### Failover

- When the Cluster DNS times out, refuses, or returns SERVFAIL without EDE after resolved exhausts feature retries, resolved moves the delegate to the VPN DNS. The member then gets the Router WireGuard address, which the Router also serves.
- The delegate stays on the VPN DNS until a member step, a repair, a reload or restart of systemd-resolved, or a failure of the VPN DNS, which wraps it back to the first server. A member that boots before its Router's Cluster DNS listens can fall back at its first lookup. Doctor reports a member on the second server.
- A forwarded public or private lookup can time out or return SERVFAIL and move the whole delegate to VPN DNS, even while Cluster DNS is healthy. Subsequent Cluster lookups then get WireGuard answers. A VPN DNS outage can therefore also cause loss of local answers after a forwarded failure. A down Router delays other lookups until fallback; it does not provide a separate healthy path for public queries. This coupling is the cost of preserving the forwarding scope.
- The two servers answer the same names differently. That breaks the systemd-resolved rule that the servers of one scope be equivalent, on purpose: the design relies on ordered failover that stays on the second server.
- Acceptance must test this resolver behavior on systemd 259; the approval choice is listed below. SERVFAIL with an extended error code, NXDOMAIN, and NODATA need not trigger failover. DNS fallback is not Router high availability: if the whole Router is down, its WireGuard address cannot serve traffic either. It helps when the DNS service or LAN path fails but the Router remains reachable over WireGuard. If both DNS servers are down, uncached names fail.

### Lifecycle

A change is either up or down for its members:

- **Up.** The Gateway writes the Cluster DNS and opens port 53 first, then makes the change authoritative. The member steps run after the last publication of the action.
- **Down.** The member steps run first, with the proposed state of the action. They run a second time after the commit, with the stored state. The Cluster DNS removal and the port 53 prune come last.

An action validates before its first member step. When it fails after a member step ran, its failure path runs the member step again with the stored state. The planned member program restores both persisted and live resolver policy on apply failure. Failed expansion steps leave members on their previous resolver and appear in the new `follow_up` response field, as role responses do. A failed removal or replacement step must block old Router cleanup and DNS removal: a stale delegate can keep returning successful old answers, so timeout fallback cannot make it safe. Retry the action or repair those members before cleanup. An offline destructive operation must explicitly report retained DNS state and affected members; it cannot claim safe convergence. OpenAPI, CLI, and MCP response contracts gain `follow_up`. Doctor reports the remaining drift.

| Event | Kind | Members | Cluster DNS and port 53 |
| --- | --- | --- | --- |
| Activate a Cluster | Up | Every LAN member | Written by the expand publication; port 53 rules with the LAN ingress expand |
| Deactivate a Cluster | Down | Every LAN member | Removed after the commit; port 53 rules pruned |
| Set, change, or remove the Cluster TLD | Up | Every LAN member after publication, to reset fallback | Written by the expand publication; exact Routes remain |
| Attach a member, or a member gains its LAN address | Up | That member | Its port 53 rules with the LAN ingress expand |
| Detach a member, or a member loses its LAN address | Down | That member | Its port 53 rules pruned |
| A member gains its first role, or loses its override | Up | That member | None |
| A member loses its last role, or gains an override | Down | That member | None |
| A member gains `vpn` or `gateway`, even with other roles | Down | That member before the role policy changes | None |
| A member loses `vpn` or `gateway` and becomes eligible | Up | That member after its default VPN DNS policy is ready | None |
| Set the first Router | Up | Every LAN member | Installed by the Router baseline |
| Replace the Router | Up | Every LAN member | Installed by the candidate baseline; never written on the old Router |
| Clear the Router | Down | Every LAN member | Removed by the baseline removal |
| The Router gains or changes its LAN address | Up | Every LAN member | Written; a change restarts the unit on the new address |
| The Router loses its LAN address | Down | Every LAN member | Removed after the commit |
| The VPN DNS address changes | Up | Every LAN member | Update upstream before selecting members; retain old VPN address until all projections and members confirm |
| Add, change, or remove a Cluster Route | None | None | Publish exact hosts records and outside-TLD rule pairs; changed pairs restart Cluster DNS. Route withdrawal retains its existing grace period |
| Remove a member Node | Down | Remove its delegate while reachable | Its port 53 rules pruned at `router-lan-ingress` |
| Remove a Router Node or delete its Cluster | Down | Every LAN member before removal | Remove DNS and prune rules; offline residue is reported |
| Extension switch changes | None | None | Placement retains its record; see Record boundary |
| Extension setup/teardown or AI router step 4 withdraws placement | None | None | Republish VPN DNS; see Record boundary |

- In `node:add`, the member step runs after `activate`, when the Node is active.
- A Router replacement keeps the Cluster DNS of the old Router. The old Router answers with its own LAN address and serves the Router sites until cleanup. The member steps run after `database` and before the cleanup of the old Router, in the first run, in a resumed run, and in a Cluster without Routes. With Routes, the cleanup grace wait starts after the last member step.
- A failed candidate gets the Cluster DNS removal and the port 53 prune in its restore. The members never moved.
- Offline Node removal can leave the delegate on the machine and lists it in `retained_on_node`. Router residue is also listed there. Online removal deletes it before losing access.
- A LAN address changes only through `node:add` on a Node without Instances. For beast or shark, this ADR does not change that.
- Member work follows its owning action: [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [ADR 0218 in PR #1302](https://github.com/nckrtl/orbit/blob/2686037bfbf93f6774c37530b8a0a609e5e1c526/docs/decisions/0218-take-long-commands-off-the-request-path.md) move Node and Cluster changes into operations. Until those conversions land, the existing request deadline bounds the work and a partial failure uses the same cleanup gates. This ADR adds no competing execution mechanism.

### Firewall

- The `router` role adds two rule families beside `orbit:router-lan-https`: `orbit:router-lan-dns-udp:<node-id>` and `orbit:router-lan-dns-tcp:<node-id>`. Each is one IPv4 UFW rule: `allow in proto udp|tcp from <member LAN> to <Router LAN> port 53`.
- The sources are the sources of the HTTPS family. The same reconciler applies them, in the same expand and prune calls.
- The Router's own queries arrive on loopback, which the stock Ubuntu UFW rules accept.

The rollout must narrow overlapping operator and unmanaged rules before enabling Cluster DNS. The member-only allow rules do not override a broader allow. Preserve required Incus DNS access by binding its rules to the bridge destination/interface; verify an unauthorized LAN source cannot query the Router LAN address over UDP or TCP. This is planned rollout work, not a mutation made by this ADR PR. VPN DNS on the private interface also needs managed UDP/TCP rules for `vpn.private_interface` and Doctor coverage, replacing the existing bootstrap-only rules.

### Doctor

Every finding below is `drift`. A Router finding needs the Router in scope, and a member finding needs the member.

| Code | Family | Finding |
| --- | --- | --- |
| `role.dns_projection_mismatch` | role | Changed: the records, the hosts file, or the Orbit lines of `orbit-vpn.conf` on the `vpn` Node differ from the render. |
| `role.dns_listener_mismatch` | role | New: dnsmasq does not own UDP and TCP port 53 on the VPN DNS address and the private interface address. |
| `role.dns_listener_retained` | role | New: listener units, the catalog, or listener releases remain on the `vpn` Node. |
| `role.cluster_dns_inactive` | role | New: `orbit-cluster-dns.service` is not active on a Router that has a Cluster DNS. |
| `role.cluster_dns_projection_mismatch` | role | New: a Cluster DNS file on the Router differs from the render. |
| `role.cluster_dns_listener_mismatch` | role | New: the unit does not own UDP and TCP port 53 on the Router LAN address. |
| `role.cluster_dns_retained` | role | New: an active Router whose Cluster has no Cluster DNS keeps the unit or its files. |
| `node.cluster_dns_retained` | node | New: a Node without an active `router` role keeps the unit or its files. |
| `role.firewall_projection_mismatch` | role | Changed: it also reports a Router LAN rule whose source is not eligible, for the HTTPS and DNS families. |
| `role.dns_bind_overlap` | role | New: VPN and Cluster DNS bind sets overlap, or a required socket belongs to another service. Refuse convergence without stopping the working service. |
| `node.cluster_dns_selection_mismatch` | node | New: the delegate or live/persistent link policy differs from the render, a competing scope exists, or a delegate exists on an ineligible Node. |
| `node.cluster_dns_fallback` | node | New: the delegate has a current server, and it is not the Cluster DNS. An unset current server is not a fallback. |
| `instance.private_dns_mismatch` | instance | Changed: it expects the Router LAN address when the workload Node holds the delegate, else the Router WireGuard address. A member on the fallback reports this and `node.cluster_dns_fallback`. |

### Build order

Each step is one pull request. Each step preserves a working resolver path, apart from the stated reload and cutover interruptions, and names its rollback. Roll back in reverse order.

1. **Firewall families.** Generalize the Router LAN families, which are hard-coded to TCP port 443, and add the two DNS families. Live: the next Router LAN reconcile adds four rules on beast, for sabre and shark. That is the next task VM enrollment, attach, detach, or `node:add`. Before enabling DNS, narrow the overlapping rules described under Firewall and verify denied sources too. Check the [live firewall read](/cli/firewall#read-the-managed-and-live-rules) for beast and Doctor. Record the previous operator rules so rollback restores them. Rollback: revert, then delete the four `orbit:router-lan-dns-*` rules on beast with `ufw delete`. The reverted code does not know their names.
2. **Cluster DNS on Routers.** Add the renderer, the stored digests, `dns.converge-cluster-dns`, the unit, the `cluster-dns` artifact, `dnsmasq-base` for the `router` role, and the `role.cluster_dns_*`, `role.dns_bind_overlap`, and `node.cluster_dns_retained` findings. No member uses it yet. Precondition: install `dnsmasq-base` and pass the bind-set preflight, including the planned VPN cutover bindings. Ship the Record boundary checks in this step before either new renderer accepts data. Live: the first DNS publication after the deploy installs it on beast. That is a task VM enrollment, a Route change, or the `private-dns` converge of the `vpn` Node, which the rollout visits before beast. Check with Doctor, and query 192.168.6.20 from shark for an inside-TLD Route, an outside-TLD Route and its child name, `x.test`, and a custom proxy name with a short `python3` query. Require A to return the LAN address and AAAA to return local NODATA for an outside-TLD Route, also with no Cluster TLD. Check descendant and public forwarding, nested Routes, pair addition/removal and rollback, and no upstream query for the exact Route's absent record types. Repeat through a disposable systemd 259 member delegate: outside-TLD AAAA must return NODATA and the member must stay on Cluster DNS. This rehearsal is a step 2 acceptance gate, not a live member switch. A query from shark does not test the firewall, because the operator rules admit shark. Rollback: roll back step 3 first. Then revert, and run `systemctl disable --now orbit-cluster-dns` on beast.
3. **Member step.** Add `dns.select-cluster-dns`, the lifecycle steps, `follow_up`, the repair change, the `node.cluster_dns_*` findings, and the new `instance.private_dns_mismatch` rule. Precondition: Doctor reports no `role.cluster_dns_*` finding on beast. The deploy changes no member. Live: run `orbit:node-dns-repair shark`. Check `resolvectl status`, `getent ahostsv4` for a Route domain, a custom proxy name, and a public name, and Doctor. Then do beast. Inventory every eligible member and record successful selection before step 5, using the approved eligibility policy in the single list below. Require an outside-TLD Route to return LAN A and local AAAA NODATA through the member stub, with the delegate still on Cluster DNS after repeated dual-stack lookups. Repeat without a Cluster TLD and verify descendants and public names still forward. Test forwarding failure, sticky fallback and repair, tunnel restart, competing-scope refusal, DNS-service failure and LAN-path failure separately from a whole-Router outage, then repair and confirm return to Cluster DNS. While the listener runs, direct member queries to VPN DNS still get source-selected LAN answers. Forwarded queries arrive with the Router's source address, so rehearsal must check exceptions and other Clusters too. Rollback: revert, then on each member restore its ordinary persistent and live link DNS policy, remove the delegate, and reload systemd-resolved. Verify resolution after a tunnel restart too.
4. **Rollback bridge.** Change the listener publication so that it moves dnsmasq off the VPN DNS address before it starts the listener socket, whenever that socket is not active. Today the socket start comes first and fails while dnsmasq holds TCP port 53. A test runs this publication on the files that step 5 leaves and starts a real dnsmasq. This is a short-lived edit of an inline script that ADR 0211 still lists, and step 6 deletes it. Live: no change. Rollback: revert.
5. **VPN DNS cutover.** Add `dns.converge-vpn-dns` and the `role.dns_listener_*` findings. Each publication runs the cutover while listener units exist, under `/run/lock/orbit-dnsmasq.lock`. The cutover installs the files, moves the `bind-interfaces` snippets away, stops the listener service and socket, and restarts dnsmasq at once. It confirms with `ss` that dnsmasq owns UDP and TCP port 53 on both addresses, and then deletes the units, the catalog, and the releases. On a failure it restores the backend files, restarts dnsmasq, and starts the listener again. Precondition: Doctor reports no `node.cluster_dns_*`, `role.cluster_dns_*`, or `role.firewall_projection_mismatch` finding. Live: merge it while someone watches the `vpn` Node, and merge nothing else until it is verified. The first publication after the deploy runs the cutover. Lookups through VPN DNS can fail between stopping the listener and confirming dnsmasq. Measure this interval in rehearsal; it has no proven sub-second bound. Preserve backups until confirmation, and restore all changed snippets and listener assets on failure. A successful cutover rollback must restore the disabled snippets recorded in its manifest too. From here, sabre gets WireGuard answers. Check `getent` from shark, beast, a task VM, the services Node, and app-prod, and run Doctor. Rollback: run `gateway:release:rollback` to the release before step 5, which holds the bridge, or merge a revert. The next publication moves dnsmasq back to `127.0.0.55` and starts the listener. A rollback pauses automatic releases until `gateway:release:auto:resume`.
6. **Delete and absorb.** Delete the listener, the codec, the catalog, the requester code, the bridge, and their tests. Remove `DnsmasqPrivateDnsManager.php` from the ADR 0211 inline script list when its last script goes. Delete `role.dns_listener_retained` after Doctor reports it clean on the live `vpn` Node. Absorb this ADR into [Private DNS](/reference/private-dns) and [Routes](/reference/routes), and retire it. Live: no change. Rollback: revert.

### Decisions for the maintainer

The decision of 2026-10-16 fixes the location rule, the Cluster TLD and exact Cluster Route scope, forwarding to VPN DNS, the two servers, plain dnsmasq on the `vpn` Node, the port 53 rule, and the member step on join and leave. This is the single approval list. Entries marked required compatibility restate existing contracts; all other entries are recommendations requiring maintainer approval before implementation.

1. **One catch-all delegate, with persistent link policy changes.** Recommendation: accept. It preserves the required TLD, exact outside-TLD Routes, and forwarding scope without per-Route member updates. The delegate must be the sole catch-all scope; unsupported competing scopes refuse selection.
2. **Ordered servers with different answers and coupled upstream failures.** Recommendation: accept only after the systemd 259 acceptance tests. LAN-first means first after selection/reset, not preferred on every query. A public failure can switch subsequent lookups to VPN DNS. NXDOMAIN, NODATA and EDE failures described above do not switch servers. Strict automatic preference would need another design decision.
3. **Keep Cluster DNS without a TLD.** Recommendation: accept, so exact Cluster Routes still receive LAN answers. Removing a TLD removes its wildcard, not the service.
4. **No automatic return after a fallback.** A member returns at the next member step, repair, or reload, and Doctor reports it as drift. Recommendation: accept. If Router reboots make this drift frequent, revisit the policy with the maintainer; removing fallback would change Nick's requirement.
5. **Router, TLD, and Cluster changes run the member step on every LAN member.** The decision named only join and leave on one machine. Recommendation: accept, because the delegate holds the Router address and a reload restores its initial server.
6. **Failed expansion steps produce `follow_up`; failed withdrawal or replacement steps hold cleanup.** Recommendation: accept. Successful stale answers do not trigger fallback, so cleanup must wait for members to move.
7. **Members without roles and members with a `--dns-server` override get no delegate.** sabre gets WireGuard answers from step 5. The firewall still admits sabre, so an operator can add the delegate by hand. Recommendation: accept.
8. **The Router resolves through its own Cluster DNS**, unless it holds `vpn` or `gateway`. Recommendation: accept, so that one rule covers every LAN member.
9. **A separate `orbit-cluster-dns.service` from `dnsmasq-base`, with overlap refusal.** Recommendation: accept the bind-set preflight above; sharing a Node does not imply sharing an address. A Router can also hold `vpn` or Incus bridges, and on beast the stock unit is failed while `/etc/dnsmasq.d` holds an Incus snippet and a stale Orbit file.
10. **Exact names live in a hosts file that dnsmasq reads again on `SIGHUP`, on both servers.** Recommendation: accept. Hosts-only updates avoid a restart and clear cached answers. Outside-TLD Route name changes also change Cluster DNS rule pairs and require a restart, with possible sticky fallback. Accept this interruption and repair requirement; a change only to addresses uses a reload. VPN DNS exact records remain hosts-only updates.
11. **Both servers keep the 30-second TTL with `local-ttl=30`.** Recommendation: accept.
12. **The Gateway writes a Router only when its stored digest differs, and publications never remove a Cluster DNS.** Recommendation: accept. A down Router then blocks only publications that change its projection, and a publication from stored state during an activation cannot undo the expand.
13. **The VPN DNS answers on the private interface of the `vpn` Node again.** Recommendation: accept. The override of app-prod points at that interface, but `interface=` includes loopback and requires the overlap preflight.
14. **A rollback bridge before the cutover.** Recommendation: accept. Without it, a rollback needs a manual step on the `vpn` Node and several seconds without DNS.
15. **Doctor reports Router LAN rules whose source is not eligible**, for the HTTPS family too. Recommendation: accept.
16. **Narrow overlapping port 53 rules before enabling Cluster DNS.** Recommendation: include this in rollout, preserving Incus access on bridge interfaces. Without it, Nick's member-only requirement is not met.
17. **Use the owning action's execution and deadline.** Recommendation: follow ADR 0209 and ADR 0218 conversions; do not add a separate DNS queue or unbounded request.
18. **Preserve the existing contribution contract.** Required compatibility, not a new approval: placement controls publication; Doctor reports stored conflicts with reserved names while publication continues. Withdrawal follows ADR 0212 step 4/A3. Recommendation: extend these same rules to the new projections.
19. **Transition overrides remain authoritative across intermediate publications; partial writes require compensating restore.** Recommendation: accept. A digest alone does not provide distributed consistency.

## Rejected alternatives

- **Unbound on the `vpn` Node with `access-control-view`.** One daemon and no change on members, but it adds a package and still answers by source identity.
- **Two dnsmasq listeners on the `vpn` Node.** Each member would need the address of its own listener, which is selection by identity again. Every LAN answer would still cross the hub and depend on the `vpn` Node.
- **No LAN answers.** Traffic between LAN members would go through the hub in the datacenter.
- **Keep the PHP listener.** It keeps a custom DNS server, its release, and its socket units to answer what two stock dnsmasq instances answer by location.
- **The Cluster DNS as a second server on the `orbit` link.** systemd-resolved sends each query to a link server out of that link, and WireGuard drops the LAN query.
- **TLD-only, non-forwarding Cluster DNS.** It removes exact outside-TLD Route support and narrows the required scope. It is not the proposed design.
- **Two scopes with `~.`.** resolved asks both and takes the first success, so a Cluster name gets the LAN answer or the WireGuard answer by chance.
- **The Cluster DNS on the LAN link.** networkd drops the setting when it restarts, NetworkManager overwrites it, and the VPN DNS cannot be a second server there.
- **A global `resolved.conf` drop-in instead of a delegate.** It takes over the global `DNS=` list of the machine and needs the same reload.

## Consequences

- The listener code and its tests are deleted. The VPN DNS is stock dnsmasq again.
- A LAN member resolves the TLD and exact Cluster Routes over the LAN while Cluster DNS remains its current server. Forwarded failures can move it to VPN DNS and leave it there.
- Each Router of an active Cluster with a LAN address runs a DNS server.
- A member step reloads systemd-resolved, which flushes its cache, restarts the stub listener, and fails the lookups in flight on that member.
- A change to outside-TLD Route rule pairs, a TLD change, or a LAN address change of the Router can move a LAN member to the second server between the publication and its member step. TLD and Router address changes run the member step to move it back; Route changes require repair if fallback occurred.
- Software that sends DNS to the VPN DNS address itself, outside systemd-resolved, gets WireGuard answers on a LAN member.
- When an uplink does not answer, dnsmasq sends no reply and the client times out. The listener answered NXDOMAIN after about 4 seconds. A Node TLD nested below a Cluster TLD follows the dnsmasq rule that the more specific domain wins.
- A LAN member needs systemd 258 or newer for the delegate. Ubuntu 26.04 ships systemd 259.

### Local design evidence

Disposable containers with no external network ran dnsmasq 2.91 on 2026-10-16. The first test confirmed that `interface=private0` also binds loopback, overlapping listeners fail with `Address in use`, and disjoint listeners work. The second compared `host-record` and `addn-hosts`: both forwarded outside-TLD AAAA and accepted the upstream IPv6 answer. Neither alone meets the exact-name contract.

With hosts entries plus the paired `local=/name/` and `server=/*.name/<VPN DNS>` rules, wire responses and query logs confirmed local A and NODATA for absent record types, including AAAA, MX, TXT, HTTPS, CNAME, NS and SOA. ANY returned local A. Child and deeper descendant A/AAAA queries and public queries reached the disposable upstream. Nested exact Routes retained local answers, while their children forwarded. The same checks passed over UDP and TCP, with and without a TLD. Removing a parent Route and its pair restored upstream answers for that name while keeping a nested Route local; restoring the files and restarting restored the local answers. The [dnsmasq manual](https://thekelleys.org.uk/dnsmasq/docs/dnsmasq-man.html) specifies that `/*.name/` matches descendants but excludes the name itself. This is an exact-name overlay, not a new local zone or a Route suffix.

This verifies daemon behavior, not the planned renderer, publication or preflight implementation. The inspected disposable images did not provide systemd-resolved; no supported disposable systemd 259 member environment was available. Required acceptance remains: outside-TLD AAAA NODATA must leave the member on Cluster DNS, with and without a TLD; verify forwarding, restart interruptions, failover, repair and the lifecycle on the target packages before rollout.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0210](/decisions/0210-give-each-extension-its-own-code-boundary), whose plug-in point feeds the shared record boundary; [0211](/decisions/0211-ship-node-changes-as-remote-programs), which the three new programs follow; [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension), whose step 2b DNS record is a VPN DNS record
- Detail: [Private DNS](/reference/private-dns), with a Cluster DNS section in place of "Cluster Router addresses" and "The listener", and their "Why it works this way" sections; [Routes: Cluster scope](/reference/routes#cluster-scope), [Private network trust](/reference/routes#private-network-trust), and "LAN first, without fallback"; [Node provisioning: Converge the Orbit footprint](/reference/node-provisioning#converge-the-orbit-footprint); [cluster](/cli/cluster); [node](/cli/node); [doctor](/cli/doctor); [firewall](/cli/firewall); [Relocate the gateway role](/solutions/relocate-gateway-role)
- Verify: `php artisan test --compact --filter='ClusterDns|PrivateDns|ClusterRouterDnsSelection|RouterLanIngress|WireGuardPeerDns|Doctor'` in apps/gateway, `bin/remote-programs-check`, a test that runs the rendered Cluster DNS and VPN DNS files in a real dnsmasq, and the live checks of each build step
