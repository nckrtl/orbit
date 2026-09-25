---
title: "Caddy configuration"
description: "How the Gateway builds each Node's whole Caddyfile from its database and pushes it in one step, and what happens when a build fails."
---

# Caddy configuration

Orbit owns `/etc/caddy/Caddyfile` on every Node that serves sites through Caddy. The Gateway builds that whole file for one Node from its database and pushes it in one step. No role writes Caddy files on a Node. [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) records this decision. This page tells an operator what a build contains, when it runs, how it reaches the Node, and how to fix a Node whose build fails.

## Published layout

Each build writes one file, `/etc/caddy/orbit-versions/<version>/Caddyfile`, and points `/etc/caddy/Caddyfile` at it. The file starts with an Orbit marker line and Orbit's global options block, and then lists every site of every role on that Node:

```caddy
# Managed by Orbit: Node Caddy build
{
    auto_https disable_certs
    metrics {
        per_host
    }
}

# orbit: app-dev app-instance-12
https://e2e-dev.test {
    bind 10.44.0.3
    ...
}

# orbit: websocket reverb.orbit
reverb.orbit {
    bind 10.44.0.3
    ...
}
```

The global options block is Orbit's, and it is the same on every Node. `auto_https disable_certs` keeps Caddy's HTTP-to-HTTPS redirects but stops Caddy from obtaining certificates on its own. A private site serves the Orbit CA certificate Orbit publishes for it. A public Ingress site opts back in, as [public Ingress certificates](#public-ingress-certificates) describes.

`metrics { per_host }` makes Caddy count requests, errors, and durations per hostname. On the Node, `curl http://localhost:2019/metrics` shows them. Prometheus scrapes them only on Ingress, as [service metrics](/reference/service-metrics#caddy-traffic) describes. [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) records this choice and its cost.

A version holds only its `Caddyfile`. It has no fragments and imports nothing. The version name is the first 32 hexadecimal characters of the file's SHA-256 digest.

## Node Caddy build

The Gateway renders the sites from committed database state only, so the same state always gives the same file. Route transitions are [stored state](/reference/routes#stored-transitions): a Route that is being published or withdrawn, an Instance that is being removed, a placement change, and a Router replacement each have a database record that the build reads.

A role's sites render while the role is provisioning or active, and after a failed convergence, because they may already be live. They stop rendering when the role's removal starts.

| Site source | Nodes | Listener |
| --- | --- | --- |
| `app-dev` and `app-prod` workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, Vite, and hibernation wake sites | Workload and Router Nodes | `0.0.0.0` on a Node with `ingress`; otherwise the WireGuard address and the LAN address when the Node has one |
| Public Ingress sites | The Cluster's Ingress Node | `0.0.0.0` |
| `gateway.orbit` | The Node with the `gateway` role | WireGuard address |
| `metrics.orbit` | The Node with the `gateway` role, while a Metrics role renders | WireGuard address |
| Service metrics scrape site on port 9103 | A selected Ingress Node | WireGuard address |
| `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The Node that runs the role or collector | WireGuard address, or `0.0.0.0` when a site from the first row binds `0.0.0.0` on the same port |

A Node gets at most one site for each domain, port, and listener. When a Route's current and transition placements render the same site on one Node, the build keeps the current one. Any other duplicate fails the build and names both sites.

### Listener addresses

Caddy sends a connection for the WireGuard address only to the sites bound to that address, and every other connection to the `0.0.0.0` sites. If one site bound the WireGuard address and another bound `0.0.0.0` on the same port, a WireGuard client that asked for the second hostname would get an empty response. The listener rule above puts every site that WireGuard clients use on the same listener.

Routers, Ingress, and private DNS clients reach first-row sites only on a Node's LAN or WireGuard address, so a Node without `ingress` binds them there and has no wildcard listener. A Gateway that is also a Router therefore serves `gateway.orbit` and its Router sites on the same port. On a Node with `ingress`, first-row sites bind `0.0.0.0` and `gateway.orbit` stays off that public listener. A build fails when a WireGuard-only site shares a port with a first-row site on such a Node, because the first-row site would be unreachable over WireGuard.

The Gateway decides the addresses from stored state: the Node's `ingress` role, its WireGuard and LAN addresses, and its Route sites. Caddy cannot start with a missing listen address, so every build first checks that each specific address it binds exists on the Node. When a stored LAN address is missing, for example after a DHCP lease changed, the build stops at stage `addresses`, leaves the live Caddyfile unchanged, and names the address:

```text
The build binds 192.168.6.30, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then build again.
```

The `websocket` role runs this check before it changes anything on the Node. A refused `orbit node:role:add NODE websocket --converge` leaves a running Reverb and its site as they were.

Give a Node with a stored LAN address a fixed address or a DHCP reservation. When the address goes away after a build, Caddy fails at its next restart, and the next build fails at `addresses` and names it. Correct the Node's stored address, then build again.

### When a build runs

A command that changes Caddy sites commits its change and then requests a build for each Node whose sites changed. Every publisher does this: Route and Instance commands, deploys, `app-dev`, `app-prod`, `router`, `websocket`, and `analytics` role convergence and removal, Metrics, service metrics, ProxyCli, and Gateway web convergence. No publisher requests a build inside a database transaction.

When a command adds a site, it publishes the site's certificate before the build, because `caddy validate` loads every certificate file the Caddyfile names. When it removes a site, it builds first and removes the certificate afterwards. A certificate step that replaces a certificate a live site already uses reloads Caddy itself under the [Node lock](#how-a-build-is-pushed), because a build whose render did not change does not reload.

The Gateway refuses to remove a certificate that a site in its stored state still renders on the Node. It checks stored state, not the live file on the Node. A Route certificate removal fails with `app-dev.certificate_in_use`, names the blocking site's domain, and keeps the certificate, so the command can be retried. Metrics keeps the `metrics.orbit` certificate while a Metrics role still renders the site, for example after the role moved to another Node.

The `app-dev` role creates the hibernation marker and log directories before it requests a build, because hibernation wake sites log there and `caddy validate` opens those logs as the `caddy` user. The `app-dev`, `app-prod`, and `router` roles also order the Caddy service after `wg-quick@orbit`.

The Gateway runs one build at a time for each Node. A second build waits up to 30 seconds and then reads the latest committed state, so the last build always renders every committed change. When a build renders the same file that is already live, it changes nothing and does not reload Caddy, so open WebSocket streams stay connected.

### How a build is pushed

The Gateway sends one script to the Node over SSH. On the Node that runs the Gateway process, it runs the same script through local `sudo`. The Gateway knows that Node from the serving Node that bootstrap records. Before bootstrap records it, the Node whose `gateway` role renders the Gateway site counts as that Node. The script:

1. Takes `/run/lock/orbit/caddy.lock` with the path checks below.
2. Checks that the packaged `/usr/bin/caddy` is at least the [release floor](/reference/node-provisioning#package-sources), 2.9.0.
3. Checks that every specific address the file binds exists on the Node.
4. Stops without a change when `/etc/caddy/Caddyfile` already points at an unchanged copy of this version.
5. Writes `/etc/caddy/orbit-versions/<version>/Caddyfile` and runs `caddy validate` on it as the `caddy` user.
6. Backs up a live Caddyfile that Orbit did not build, as [replaced configuration](#replaced-configuration) describes.
7. Points `/etc/caddy/Caddyfile` at the new version, then enables and reloads the `caddy` service.
8. Keeps the live version and the nine newest others, and removes older versions and the `staged` directory an earlier release left.

Validation runs as the `caddy` user, so log files that it creates stay writable by the service. When a build's version file differs from its digest, someone edited it by hand. The script backs that version up before it replaces or prunes it, whatever the new version is.

Every build and every certificate step that reloads Caddy holds `/run/lock/orbit/caddy.lock`. A second holder waits up to 30 seconds for the lock and then fails without changing the Node. Before it takes the lock, the script checks the lock path:

| Path | Requirement |
| --- | --- |
| `/run/lock/orbit` | A real directory, not a symlink, owned by `root:root` with mode `0700`. Orbit creates it with that mode when it is missing. |
| `/run/lock/orbit/caddy.lock` | A regular file, not a symlink, owned by `root:root`. Orbit sets mode `0600` after it opens the file. |

A failed check stops the build before any change. `/run/lock` is a tmpfs, so the lock and its directory are recreated after a reboot.

Every Node with Caddy sites, the Gateway machine included, installs Caddy from the pinned source before its first build.

### When a build fails

A build either publishes the whole file or changes nothing. It fails when a site cannot be rendered from stored state, when two sites collide, when Caddy is below the floor, when the Node lacks an address the file binds, when `caddy validate` rejects the file, or when Caddy fails to reload. After a reload failure the script points `/etc/caddy/Caddyfile` back at the previous version and reloads again. The live configuration keeps serving in every case.

The command that requested the build fails with its usual error code:

| Publisher | Error code |
| --- | --- |
| `app-dev` sites, including public Ingress sites | `app-dev.caddy_config_failed` |
| `app-prod` role | `app-prod.caddy_config_failed` |
| `websocket` role | `websocket.caddy_publication_failed` |
| `analytics` role | `analytics.caddy_publication_failed` |
| ProxyCli collector | `proxycli.caddy_publication_failed` |
| Metrics and service metrics | `metrics.caddy_publication_failed` |
| Gateway web convergence | `gateway.caddy_config_invalid` at `render` or `validate`, `gateway.caddy_start_failed` at `reload`, and `gateway.caddy_config_install_failed` at any other stage |

Role convergence, such as `orbit node:role:add NODE app-dev --converge`, fails with `node_role.convergence_failed`; `orbit node:role:list` shows the publisher's code as the underlying error. The error message and the activity record name the Node, the failed stage, and Caddy's message:

```text
The Caddy build for Node [app-prod] failed at stage [validate]: Error: loading certificates: open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory
```

The failed stage is one of `lock`, `release`, `addresses`, `write`, `validate`, `backup`, `swap`, or `reload` on the Node. On the Gateway it is `render` or `gateway-lock` before the Gateway contacts the Node, `connect` when the Gateway cannot reach the Node or the Node has no WireGuard address, and `read-live` when `--diff` cannot read the live file.

One broken site blocks every Caddy change on its Node until it is fixed, because each build renders every site. A missing certificate file fails validation. This happens, for example, when a role's first convergence failed before it published its certificate: the failed role still renders its site. Converge the role or Route that owns the site again to publish the certificate, or remove the role.

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

[`orbit doctor`](/cli/doctor) reports `instance.public_tls_mismatch` when the live public site pins an Orbit CA leaf, or when its block lacks `tls force_automate` while the live Caddyfile disables certificate management. Converge the public Route to build the Ingress Node again.

## Build a Node by hand

On the Gateway machine, an operator can build one Node, or render it and compare the render with that Node's live configuration:

```bash
php artisan orbit:caddy-build NODE
php artisan orbit:caddy-build NODE --dry-run
php artisan orbit:caddy-build NODE --dry-run --diff
```

| Option | Result |
| --- | --- |
| None | Builds the Node and pushes the file, as any publisher does. It prints whether it published a new file or found the live file current. |
| `--dry-run` | Prints the rendered Caddyfile and changes nothing. |
| `--diff` | With `--dry-run`, reads the live `/etc/caddy/Caddyfile`, and the fragments of a Node that no build replaced yet, and prints one line for each site: `same`, `changed`, `build only`, or `live only`. A `changed` site lists the lines that differ. |

A failed build exits with status 1 and prints the error message. `--dry-run` exits with status 1 and prints `Build refused:` with the reason when the render has a problem, such as a duplicate address or a WireGuard-only site on a wildcard port. The output names hostnames and certificate paths; Orbit's Caddy sites hold no secrets.

Repeating any command that publishes one of a Node's sites, such as `orbit node:role:add NODE app-dev --converge`, also builds the Node again.

## Replaced configuration

The build replaces a Caddyfile that does not start with its marker line. It never adopts it. The first build on such a Node copies what it replaces to `/etc/caddy/orbit-backups/<UTC timestamp>/`, after the new version passes validation:

| Live `/etc/caddy/Caddyfile` | Backup |
| --- | --- |
| The unmodified package default | None |
| Any other regular file | The file |
| A symlink outside `/etc/caddy/orbit-versions` | The file it points at |
| A version with a `fragments` directory, which Orbit's per-role publishers wrote before the build | The whole version directory, fragments included |
| A build's version whose file differs from its digest | The whole version directory, also when prune removes it |

A Node that no build replaced yet keeps its fragment layout until the first command that builds it. That first build backs up the old version and serves the same Orbit sites from one file. Sites in a replaced file that Orbit does not render stop serving after that build, including an adopted `00-unmanaged.caddy` fragment. Move a hand-placed site into Orbit before the first build, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes). The build never deletes a backup; remove it by hand when you do not need it.

[`orbit doctor`](/cli/doctor) reads a Node's Route sites from the one live Caddyfile, or from the fragments of a Node that no build replaced yet. For a production Instance it compares the live build with a fresh render of the Node, so a hand edit reports `instance.caddy_projection_mismatch`.
