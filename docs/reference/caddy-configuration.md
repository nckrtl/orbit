---
title: "Caddy configuration"
description: "How the Gateway builds each Node's whole Caddyfile from its database, pushes it atomically, and replaces a Caddyfile that Orbit did not write."
---

# Caddy configuration

The Gateway builds the whole `/etc/caddy/Caddyfile` for a Node from its database and pushes it in one step. No role writes Caddy files on a Node. This page tells an operator what a build contains, when it runs, what happens when it fails, and what happens to a Caddyfile that Orbit did not write. [ADR 0140](/decisions/0140-build-each-node-caddyfile-on-the-gateway) records the design.

## What a build contains

A Node Caddy build renders one file. It starts with Orbit's global options block and then lists every site of every role on that Node:

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

The global options block is Orbit's, and it is the same on every Node. Orbit does not support operator global options.

`auto_https disable_certs` keeps Caddy's HTTP-to-HTTPS redirects but stops Caddy from obtaining certificates on its own. A private site serves the Orbit CA certificate Orbit publishes for it. A public Ingress site opts back in, as [public Ingress certificates](#public-ingress-certificates) describes.

`metrics { per_host }` makes Caddy count requests, errors, and durations per hostname. On the Node, `curl http://localhost:2019/metrics` shows them. Prometheus scrapes them only on Ingress, as [service metrics](/reference/service-metrics#caddy-traffic) describes. [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) records this choice and its cost.

The Gateway renders the sites from stored state only:

| Site source | Nodes | Sites |
| --- | --- | --- |
| `gateway` | The Node with the `gateway` role | `gateway.orbit`: the web app, the API, and `/grafana` |
| Metrics | The Node with the `gateway` role | `metrics.orbit` |
| Service metrics | A selected Ingress Node | The WireGuard scrape listener on port 9103 |
| `app-dev` and `app-prod` | Workload, Router, and Ingress Nodes | Instance Routes, custom proxy Routes, analytics tracking hosts, Agentation, Vite, and public Ingress sites |
| `websocket` | The Node with the `websocket` role | `reverb.orbit` |
| `analytics` | The Node with the `analytics` role | `analytics.orbit` |
| ProxyCli | The Node that runs the collector | `collector.proxycli.orbit` |
| Herdr observers | The Node of each observed session | One site per observed session |

The file starts with the line `# Managed by Orbit: Node Caddy build`, which marks it as a build. The same stored state always gives the same file. Route transitions are stored state too: a Route that is being published, an Instance that is being removed, a Router replacement, and a placement change each have a database record that the build reads.

### Listener addresses

The build chooses the `bind` address of each site from all sites on that Node and port. When any site on a port needs `0.0.0.0`, every site on that port binds `0.0.0.0`, because Caddy does not mix a wildcard and a specific address on one port. Otherwise sites bind the Node's WireGuard address. The firewall still decides who reaches each port.

## When a build runs

A command that changes Caddy sites saves its change and then requests a build for each affected Node. That covers Route and Instance commands, deploys, role convergence, Metrics, ProxyCli, Herdr observers, and Gateway web convergence. When a command adds a site, it publishes the site's certificate before the build. When it removes a site, it builds first and removes the certificate afterwards.

The Gateway runs one build at a time for each Node. A second build waits up to 30 seconds and then reads the latest stored state. When a build renders the same file that is already live, it changes nothing and does not reload Caddy.

## How a build is pushed

The Gateway sends one script to the Node over SSH. On the Node that runs the Gateway process, it runs the same script through local `sudo`. The script:

1. Takes `/run/lock/orbit/caddy.lock`.
2. Checks that the installed Caddy is at least the [release floor](/reference/node-provisioning#package-sources), 2.9.0.
3. Backs up a live Caddyfile that Orbit did not build, as [replaced configuration](#replaced-configuration) describes.
4. Writes `/etc/caddy/orbit-versions/<version>/Caddyfile`. The version name comes from a digest of the file.
5. Runs `caddy validate` as the `caddy` user, so log files that validation creates stay writable by the service.
6. Points `/etc/caddy/Caddyfile` at the new version, then enables and reloads Caddy.
7. Keeps the live version and the nine newest others, and removes older versions.

A version holds only its `Caddyfile`. It has no fragments and imports nothing.

## When a build fails

A build either publishes the whole file or changes nothing. It fails when a site cannot be rendered from stored state, when Caddy is below the floor, when `caddy validate` rejects the file, or when Caddy fails to reload. After a reload failure the script points `/etc/caddy/Caddyfile` back at the previous version and reloads again. The live configuration keeps serving in every case.

The command that requested the build fails with its usual error code, such as `app-dev.caddy_config_failed` or `websocket.caddy_publication_failed`. The error details and the activity record name the Node, the failed stage, and Caddy's message. A missing certificate file fails validation; converge the role or Route that owns the site to publish it again.

One broken site blocks every Caddy change on its Node until it is fixed, because each build renders every site.

[`orbit doctor`](/cli/doctor) reports `role.caddy_build_drift` when the live Caddyfile differs from a fresh render. Converge any role on that Node that has Caddy sites, for example `orbit node:role:add NODE app-dev --converge`, to build it again.

## Replaced configuration

Orbit replaces a Caddyfile that a Node Caddy build did not write. It never adopts it. The first build on such a Node copies what it replaces to `/etc/caddy/orbit-backups/<UTC timestamp>/`:

| Live `/etc/caddy/Caddyfile` | Backup |
| --- | --- |
| The unmodified package default | None |
| Any other regular file | The file |
| A symlink outside `/etc/caddy/orbit-versions` | The file it points at |
| A version with a `fragments` directory, from an older Orbit | The whole version directory |

Sites in the replaced file stop serving after that build. Move a hand-placed site into Orbit before the first build, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes). The build never deletes a backup; remove it by hand when you do not need it.

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

Private sites never carry `force_automate`, so Caddy never asks a public CA for a private hostname, even when a pinned certificate does not match its site. `force_automate` needs Caddy 2.9.0 or newer. [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) records this rule.

[`orbit doctor`](/cli/doctor) reports `instance.public_tls_mismatch` when the live public site pins an Orbit CA leaf, or when its block lacks `tls force_automate`. Converge the public Route to build the Ingress Node again.
