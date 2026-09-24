---
title: "Caddy configuration"
description: "How Orbit owns a Node's Caddyfile today, with per-role fragments under one lock, and the proposed Node Caddy build that renders the whole file on the Gateway."
---

# Caddy configuration

Orbit owns `/etc/caddy/Caddyfile` on a Node that serves sites through Caddy. This page tells an operator how Orbit publishes that file, how publishers avoid dropping each other's sites, what happens to a Caddyfile that existed before Orbit, and how to fix a Node whose publication fails on global options. [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) records the global options rule. [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) proposes the [Node Caddy build](#node-caddy-build), which replaces per-role fragments. The sections before it describe publication as it works now.

## Published layout

Each publication writes a new version under `/etc/caddy/orbit-versions/<version>` and then points `/etc/caddy/Caddyfile` at that version's `Caddyfile`. The version's `Caddyfile` has two parts:

```caddy
{
    auto_https disable_certs
    metrics {
        per_host
    }
}
import /etc/caddy/orbit-versions/<version>/fragments/*.caddy
```

The global options block is Orbit's, and it is the same on every Node.

`auto_https disable_certs` keeps Caddy's HTTP-to-HTTPS redirects but stops Caddy from obtaining certificates on its own. A private site serves the Orbit CA certificate Orbit publishes for it. A public Ingress site opts back in, as [public Ingress certificates](#public-ingress-certificates) describes.

`metrics { per_host }` makes Caddy count requests, errors, and durations per hostname. On the Node, `curl http://localhost:2019/metrics` shows them. Prometheus scrapes them only on Ingress, as [service metrics](/reference/service-metrics#caddy-traffic) describes. [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) records this choice and its cost.

Every role keeps its sites in its own fragment, such as `app-dev.caddy` or `metrics.caddy`. A publisher replaces only its own fragment and copies every other fragment into the new version. It runs `caddy validate` on the candidate before it switches the symlink, and it restores the previous file or symlink when Caddy fails to reload.

| Fragment | Publisher |
| --- | --- |
| `app-dev.caddy` | App development sites and [Route](/reference/routes) ingress |
| `app-prod.caddy` | App production sites |
| `00-metrics-service.caddy` | [Service metrics](/reference/service-metrics) monitoring site |
| `herdr-<session>.caddy` | [Herdr session](/reference/herdr-sessions) observer |
| `metrics.caddy` | [Metrics](/reference/metrics) route on the Gateway |
| `websocket.caddy` | `websocket` role |
| `proxycli.caddy` | [ProxyCLI](/reference/proxycli) collector |
| `analytics.caddy` | [Analytics](/reference/analytics) role |
| `00-unmanaged.caddy` | An [adopted Caddyfile](#adopted-caddyfile) |

The `websocket`, `proxycli`, `analytics`, and Metrics publishers also switch a certificate directory, such as `/etc/caddy/orbit-websocket-cert-current`, and reload Caddy.

## Publication lock

Every publisher holds `/run/lock/orbit/caddy.lock` from before it reads the live version until Caddy has reloaded. A second publisher waits up to 30 seconds for the lock and then fails without changing the Node. The fragment and certificate publishers above all use this lock.

Without one shared lock, two publishers could each copy the fragments they saw. The later switch would then drop the fragment that the earlier one had just published.

Before it takes the lock, a publisher checks the lock path:

| Path | Requirement |
| --- | --- |
| `/run/lock/orbit` | A real directory, not a symlink, owned by `root:root` with mode `0700`. Orbit creates it with that mode when it is missing. |
| `/run/lock/orbit/caddy.lock` | A regular file, not a symlink, owned by `root:root`. Orbit sets mode `0600` after it opens the file. |

A failed check stops the publication before any change. `/run/lock` is a tmpfs, so the lock and its directory are recreated after a reboot.

The Gateway's own `gateway.caddy` fragment is published during Gateway convergence in several separate steps. That publication does not take the lock.

## Public Ingress certificates

A public Ingress site gets its certificate from Let's Encrypt, not from Orbit. Its site block carries `tls force_automate`:

```caddy
shop.example.com {
    bind 0.0.0.0
    tls force_automate
    reverse_proxy https://10.0.0.20 {
        # Router forwarding settings
    }
}
```

`force_automate` makes Caddy manage the certificate for that hostname even though the global block disables certificate management. Caddy uses its default issuers, Let's Encrypt first, and renews the certificate on its own. Let's Encrypt validates the hostname on port 80 or 443. The hostname's public DNS must point at the Ingress Node. Orbit opens both ports on the Ingress firewall while the Cluster has a live public Route.

Private sites never carry `force_automate`, so Caddy never asks a public CA for a private hostname, even when a pinned certificate does not match its site. `force_automate` needs Caddy 2.9.0 or newer, which is the [release floor](/reference/node-provisioning#package-sources). [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) records this rule.

[`orbit doctor`](/cli/doctor) reports `instance.public_tls_mismatch` when the live public site pins an Orbit CA leaf, or when its block lacks `tls force_automate` while the live Caddyfile disables certificate management. Converge the public Route to publish the site again.

## Adopted Caddyfile

On the first publication, a Node's `/etc/caddy/Caddyfile` can be a regular file. When that file is the unmodified package default, Orbit replaces it. Otherwise Orbit keeps it as `fragments/00-unmanaged.caddy` and carries it into every later version. A legacy `fragments/unmanaged.caddy` becomes `00-unmanaged.caddy` on the next publication.

## Carried global options

Caddy accepts one global options block, and only as the first block. Orbit writes that block, so a carried fragment must not start with its own. Before validation, each publisher checks every carried fragment. When one starts with a block that has no site address, the publisher stops, leaves the live Caddyfile and Caddy unchanged, and fails with its usual error code:

| Publisher | Error code |
| --- | --- |
| `app-dev` sites, including public Ingress sites | `app-dev.caddy_config_failed` |
| `app-prod` sites | `app-prod.caddy_config_failed` |
| `websocket` site | `websocket.caddy_publication_failed` |
| `analytics` site | `analytics.caddy_publication_failed` |
| ProxyCli collector site | `proxycli.caddy_publication_failed` |
| Herdr observer site | `herdr.observer_failed` |
| Metrics site on the Gateway | `metrics.caddy_publication_failed` |

Role convergence, such as `orbit node:role:add NODE app-dev --converge`, fails with `node_role.convergence_failed`; `orbit node:role:list` shows the publisher's code as the underlying error.

The refusal message names the fragment, the options in the block, and the file to edit:

```text
Caddy fragment 00-unmanaged.caddy opens its own global options block (local_certs, email). Orbit writes the only global options block. Remove that block from /etc/caddy/Caddyfile, then publish again.
```

The activity record keeps that message when a Route or Instance command fails on an `app-dev` or `app-prod` site publication. Role convergence and the other publishers record only the error code, because they do not keep command output. After one of those codes, check the start of the adopted `/etc/caddy/Caddyfile` and of every fragment that Orbit did not write in the live version.

On first adoption the file to edit is the Node's own `/etc/caddy/Caddyfile`. When Orbit already carries the fragment, it is that fragment in the live version, under `/etc/caddy/orbit-versions/<version>/fragments/`. Remove the whole block, keep the site blocks, and repeat the command that failed. Orbit does not support operator global options; it never merges, strips, or rewrites them.

A site block, a snippet such as `(common) {`, and an address that starts with an environment placeholder such as `{$SITE} {` are not global blocks.

## Node Caddy build

This section describes the target behavior that [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) proposes. It is not live yet. When it ships, it replaces the fragment layout, the adopted Caddyfile, and the carried global options check described above.

The Gateway builds the whole `/etc/caddy/Caddyfile` for a Node from its database and pushes it in one step. No role writes Caddy files on a Node.

### What a build contains

A build renders one file. It starts with an Orbit marker line and Orbit's global options block, and then lists every site of every role on that Node:

```caddy
# Managed by Orbit: Node Caddy build
{
    auto_https disable_certs
    metrics {
        per_host
    }
}

# orbit: app-dev
e2e-dev.test {
    bind 0.0.0.0
    ...
}

# orbit: websocket
reverb.orbit {
    bind 0.0.0.0
    ...
}
```

The Gateway renders the sites from committed database state only. The same state always gives the same file. Route transitions are stored state too: a Route that is being published or withdrawn, an Instance that is being removed, a placement change, and a Router replacement each have a database record that the build reads. A Node gets at most one site for each domain and port. When a Route's current and transition placements render the same site on one Node, the build keeps the current one. Any other duplicate fails the build.

| Site source | Nodes | Listener |
| --- | --- | --- |
| `app-dev` and `app-prod` workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, and Vite | Workload and Router Nodes | `0.0.0.0` |
| Public Ingress sites | The Cluster's Ingress Node | `0.0.0.0` |
| `gateway.orbit` | The Node with the `gateway` role | WireGuard address |
| `metrics.orbit` | The Node with the `gateway` role | WireGuard address |
| Service metrics scrape site on port 9103 | A selected Ingress Node | WireGuard address |
| `reverb.orbit`, `analytics.orbit`, `collector.proxycli.orbit`, and Herdr observer sites | The Node that runs the role, collector, or session | WireGuard address, or `0.0.0.0` when a site from the first row shares the port |

Caddy sends a connection for the WireGuard address only to the sites bound to that address, and every other connection to the `0.0.0.0` sites. So `gateway.orbit` stays off the public listener of a Node that also holds `ingress`. A build fails when a WireGuard-only site and a site from the first row share a port on one Node, because the first-row site would be unreachable over WireGuard.

### When a build runs

A command that changes Caddy sites commits its change and then requests a build for each affected Node. That covers Route and Instance commands, deploys, role convergence, Metrics, ProxyCli, Herdr observers, and Gateway web convergence. When a command adds a site, it publishes the site's certificate before the build. When it removes a site, it builds first and removes the certificate afterwards. A certificate step that replaces a certificate a live site already uses reloads Caddy itself.

The Gateway runs one build at a time for each Node. A second build waits up to 30 seconds and then reads the latest committed state. When a build renders the same file that is already live, it changes nothing and does not reload Caddy.

### How a build is pushed

The Gateway sends one script to the Node over SSH. On the Node that runs the Gateway process, it runs the same script through local `sudo`. The script:

1. Takes `/run/lock/orbit/caddy.lock` with the [path checks](#publication-lock) above.
2. Checks that the packaged `/usr/bin/caddy` is at least the [release floor](/reference/node-provisioning#package-sources), 2.9.0.
3. Backs up a live Caddyfile that Orbit did not build, as [replaced configuration](#replaced-configuration) describes.
4. Writes `/etc/caddy/orbit-versions/<version>/Caddyfile`. The version name comes from a digest of the file.
5. Runs `caddy validate` as the `caddy` user, so log files that validation creates stay writable by the service.
6. Points `/etc/caddy/Caddyfile` at the new version, then enables and reloads the `caddy` service.
7. Keeps the live version and the nine newest others, and removes older versions.

A version holds only its `Caddyfile`. It has no fragments and imports nothing. Every Node with Caddy sites, the Gateway machine included, installs Caddy from the pinned source before its first build.

### When a build fails

A build either publishes the whole file or changes nothing. It fails when a site cannot be rendered from stored state, when two sites collide, when Caddy is below the floor, when `caddy validate` rejects the file, or when Caddy fails to reload. After a reload failure the script points `/etc/caddy/Caddyfile` back at the previous version and reloads again. The live configuration keeps serving in every case.

The command that requested the build fails with its usual error code, such as `app-dev.caddy_config_failed` or `websocket.caddy_publication_failed`. The error details and the activity record name the Node, the failed stage, and Caddy's message. A missing certificate file fails validation; converge the role or Route that owns the site to publish it again. One broken site blocks every Caddy change on its Node until it is fixed, because each build renders every site.

[`orbit doctor`](/cli/doctor) reports `role.caddy_build_drift` when the live Caddyfile differs from a fresh render. The issue lists the site sources on that Node. Repeat any command that publishes one of them, such as `orbit node:role:add NODE app-dev --converge`, to build the Node again.

### Replaced configuration

The build replaces a Caddyfile that does not start with its marker line. It never adopts it. The first build on such a Node copies what it replaces to `/etc/caddy/orbit-backups/<UTC timestamp>/`:

| Live `/etc/caddy/Caddyfile` | Backup |
| --- | --- |
| The unmodified package default | None |
| Any other regular file | The file |
| A symlink outside `/etc/caddy/orbit-versions` | The file it points at |
| A version with a `fragments` directory | The whole version directory |

Sites in the replaced file stop serving after that build. Move a hand-placed site into Orbit before the first build, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes). The build never deletes a backup; remove it by hand when you do not need it.
