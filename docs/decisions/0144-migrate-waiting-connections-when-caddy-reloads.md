---
title: "ADR 0144: Migrate waiting connections when Caddy reloads"
sidebarTitle: "0144 Migrate waiting connections when Caddy reloads"
description: "Proposed. Every Node that runs Caddy sets net.ipv4.tcp_migrate_req to 1, so a Caddy reload hands waiting connections to the new listener instead of resetting them."
---

# ADR 0144: Migrate waiting connections when Caddy reloads

Every Node that runs Caddy sets the kernel setting `net.ipv4.tcp_migrate_req` to `1`. A Caddy reload then hands the connections that wait on the old listening socket to the new one, instead of resetting them. The Caddy install step owns the setting.

## Status

Proposed.

## Context

Every Orbit publication reloads Caddy. Independent review of PR #657 saw rare TLS handshake resets during plain reloads: `curl: (35) Recv failure: Connection reset by peer`, 5 in about 35,000 requests during 20 reloads on main.

A reproduction on Caddy 2.11.4 with Orbit's global options, two sites with certificate files, and one `force_automate` site found two causes. Neither is the certificate cache. Caddy logged `certificate already cached` and removed no certificate, and errors spread evenly over all three sites.

1. **Resets.** On Linux, Caddy opens a new `SO_REUSEPORT` listening socket on each reload and then closes the old one. The kernel resets every connection that still waits in the old socket's queue. A packet capture showed the reset answer a ClientHello at the moment Caddy logged `servers shutting down`. Linux 5.14 added `net.ipv4.tcp_migrate_req`. With it set to `1`, the kernel moves those waiting connections to another socket in the same group.
2. **Empty HTTP/1.1 replies.** A connection that the old server accepted, but whose first request it reads after shutdown starts, gets no response. Go's `net/http` has closed such connections since Go 1.25, and Caddy 2.11.4 is built with Go 1.26.3. HTTP/2 connections do not take that path.

Measured with 20 or more forced reloads per run, in a network namespace on the same kernel:

| Client and setting | Resets | Empty replies |
| --- | --- | --- |
| All clients, setting `0` | 28 in 268 reloads | — |
| All clients, setting `1` | 2 in 228 reloads | — |
| Go HTTP/1.1, about 7,000 new connections per second, setting `0` | 1 to 10 per 20 reloads | 317 to 413 per 20 reloads |
| Go HTTP/1.1, same load, setting `1` | 0 in 20 reloads | 351 in 20 reloads |

`grace_period 10s` (337 errors), `shutdown_delay 5s` (372, skipped on reload), and a forced reload through the admin API (333) all matched the baseline of 323 to 414. Caddy 2.11.4 is the newest release, and its main branch has no fix.

## Decision

- The Caddy install step, `CaddyPackageSourceProgram`, writes `/etc/sysctl.d/60-orbit-caddy.conf` with `net.ipv4.tcp_migrate_req = 1`, owned by `root:root` with mode `0644`. Every path that installs Caddy runs this step: role convergence, the Gateway's own install, ProxyCli, and analytics publication.
- The step applies a candidate file with `sysctl --load` before it installs the file. A kernel that refuses the setting fails the step, and no file is left behind. The step then installs the file only when it differs. It applies the setting on every run, so a changed live value returns to `1`.
- The step refuses a live file that is a symlink, not a regular file, or not `root:root` mode `0644`, the same as its apt source files.
- Doctor does not check the setting. Doctor checks no other kernel setting either. Role convergence repairs drift.
- This extends [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source): the step that installs Caddy also prepares the kernel for Caddy's reloads.

## Rejected alternatives

- `grace_period` or `shutdown_delay`: rejected because neither changed the failure count. Caddy skips `shutdown_delay` when a listener survives the reload.
- Reload through the admin API: rejected because `caddy reload` already posts to the same `/load` endpoint, and the failure count matched.
- Hand Caddy a systemd socket, so all configurations share one kernel socket: rejected because it changes the Caddy unit and every listener address for the same effect on resets.
- Load certificates through a `certificates` app that survives reloads: rejected because the certificate cache does not cause the failures.
- Wait for a Caddy fix: rejected because no release has one. An upstream issue for the HTTP/1.1 replies remains an option.

## Consequences

- Reloads reset about 93% fewer connections. Browsers and curl negotiate HTTP/2, so they see only these resets.
- The setting applies to every `SO_REUSEPORT` group on the Node, not only Caddy. It only moves connections that a closed socket would otherwise reset.
- HTTP/1.1 clients can still get an empty reply on a new connection during a reload. Orbit's own CLI and PHP SDK use HTTP/1.1, so a command can fail this way while the Gateway's Caddy reloads. No Caddy setting fixes this. Fewer reloads, such as ADR 0141's unchanged-render rule, reduce the exposure.
- Nodes need Linux 5.14 or newer. Every supported Ubuntu release meets that.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source)
- Detail: [Node provisioning](/reference/node-provisioning#package-sources)
- Verify: `apps/gateway` Pest tests for `CaddyPackageSourceProgram` and its callers; an Incus proof that role convergence sets the value to `1` on a Caddy Node and the Gateway, a second run changes nothing, and a curl sampler sees fewer resets during 20 or more reloads than on a Node with the setting at `0`
