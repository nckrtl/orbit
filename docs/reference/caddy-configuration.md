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
| `metrics.caddy` | [Metrics](/reference/metrics) route on the Gateway |
| `websocket.caddy` | `websocket` role |
| `proxycli.caddy` | [ProxyCLI](/reference/proxycli) collector |
| `analytics.caddy` | [Analytics](/reference/analytics) role |
| `00-unmanaged.caddy` | An [adopted Caddyfile](#adopted-caddyfile) |

The `websocket`, `proxycli`, `analytics`, and Metrics publishers also switch a certificate directory, such as `/etc/caddy/orbit-websocket-cert-current`, and reload Caddy.

The `app-dev.caddy` publisher renders every Route site from stored state only, Route transitions included. Any publication on a Node therefore renders the same Route sites, whichever command requested it. [Stored transitions](/reference/routes#stored-transitions) lists the state each transition stores.

## Listener addresses

Each publisher chooses its `bind` addresses with the listener rule of the [Node Caddy build](#node-caddy-build), so the fragments and a build agree:

| Sites | Node without `ingress` | Node with `ingress` |
| --- | --- | --- |
| Private Route sites in `app-dev.caddy`: workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, and Vite | The WireGuard address, and the LAN address when the Node has one | `0.0.0.0` |
| Public Ingress sites | None | `0.0.0.0` |
| `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The WireGuard address | `0.0.0.0` when the Node has a private Route site; otherwise the WireGuard address |
| `gateway.orbit`, `metrics.orbit`, and the service metrics scrape site | The WireGuard address | The WireGuard address |

Caddy sends a connection for a specific address only to the sites bound to that address. If one site binds the WireGuard address and another binds `0.0.0.0` on the same port, a WireGuard client that asks for the second hostname gets an empty response. The rule puts every site that WireGuard clients use on the same listener.

The Gateway decides the addresses from stored state: the Node's `ingress` role, its WireGuard and LAN addresses, and its Route sites. The `app-dev`, `websocket`, `analytics`, and ProxyCli publishers also rewrite the `bind` lines of the fragments they carry to this rule:

- The private `https://` sites in `app-dev.caddy`.
- Every site in `websocket.caddy`, `analytics.caddy`, and `proxycli.caddy`.

One publication therefore corrects a listener that another publisher wrote earlier. A fragment whose `bind` lines already follow the rule keeps its exact bytes. Public sites, Unix socket sites, and all other fragments keep their `bind` lines. The Metrics, service metrics, and Gateway web publishers bind the WireGuard address and carry other fragments unchanged.

When every fragment of the new version matches the live version byte for byte, a publisher changes nothing. It writes no version and does not reload Caddy, so open WebSocket streams stay connected.

Before the `app-dev`, `websocket`, `analytics`, and ProxyCli publishers swap the live Caddyfile, they check that every specific address they bind exists on the Node, as the [Node Caddy build](#how-a-build-is-pushed) does. When a stored LAN address is missing, for example after a DHCP lease changed, the publisher stops, leaves the live Caddyfile unchanged, and fails with its usual error code. The message names the address:

```text
Caddy would bind 192.168.6.30, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then publish again.
```

Give a Node with a stored LAN address a fixed address or a DHCP reservation.

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

The Gateway renders the sites from committed database state only. A role's sites render while the role is provisioning or active, and after a failed reconvergence, because they were live before that attempt. They stop rendering when the role's removal starts or when its first convergence fails.

The same state always gives the same file. Route transitions are already [stored state](/reference/routes#stored-transitions): a Route that is being published or withdrawn, an Instance that is being removed, a placement change, and a Router replacement each have a database record that the build reads. A Node gets at most one site for each domain and port. When a Route's current and transition placements render the same site on one Node, the build keeps the current one. Any other duplicate fails the build.

| Site source | Nodes | Listener |
| --- | --- | --- |
| `app-dev` and `app-prod` workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, and Vite | Workload and Router Nodes | `0.0.0.0` on a Node with `ingress`; otherwise the WireGuard address and the LAN address when the Node has one |
| Public Ingress sites | The Cluster's Ingress Node | `0.0.0.0` |
| `gateway.orbit` | The Node with the `gateway` role | WireGuard address |
| `metrics.orbit` | The Node with the `gateway` role | WireGuard address |
| Service metrics scrape site on port 9103 | A selected Ingress Node | WireGuard address |
| `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The Node that runs the role or collector | WireGuard address, or `0.0.0.0` when a site from the first row binds `0.0.0.0` on the same port |

Caddy sends a connection for the WireGuard address only to the sites bound to that address, and every other connection to the `0.0.0.0` sites. Routers, Ingress, and private DNS clients reach first-row sites only on a Node's LAN or WireGuard address, so a Node without `ingress` binds them there and has no wildcard listener. A Gateway that is also a Router therefore serves `gateway.orbit` and its Router sites on the same port.

On a Node with `ingress`, first-row sites bind `0.0.0.0`, `gateway.orbit` stays off that public listener, and a build fails when a WireGuard-only site shares a port with a first-row site, because the first-row site would be unreachable over WireGuard.

### When a build runs

A command that changes Caddy sites commits its change and then requests a build for each affected Node. That covers Route and Instance commands, deploys, role convergence, Metrics, ProxyCli, and Gateway web convergence. When a command adds a site, it publishes the site's certificate before the build. When it removes a site, it builds first and removes the certificate afterwards. A certificate step that replaces a certificate a live site already uses reloads Caddy itself.

The Gateway refuses to remove a certificate that a site in its stored state still renders on the Node, because `caddy validate` would then fail for every later build. It checks stored state, not the live file on the Node. The step fails with `app-dev.certificate_in_use`, names the blocking site's domain, and keeps the certificate, so the command can be retried.

The Gateway runs one build at a time for each Node. A second build waits up to 30 seconds and then reads the latest committed state. When a build renders the same file that is already live, it changes nothing and does not reload Caddy.

### How a build is pushed

The Gateway sends one script to the Node over SSH. On the Node that runs the Gateway process, it runs the same script through local `sudo`. The Gateway knows that Node from the serving Node that bootstrap records. Before bootstrap records it, the Node whose `gateway` role renders the Gateway site counts as that Node. The script:

1. Takes `/run/lock/orbit/caddy.lock` with the [path checks](#publication-lock) above.
2. Checks that the packaged `/usr/bin/caddy` is at least the [release floor](/reference/node-provisioning#package-sources), 2.9.0.
3. Checks that every specific address the file binds exists on the Node.
4. Stops without a change when `/etc/caddy/Caddyfile` already points at an unchanged copy of this version.
5. Writes `/etc/caddy/orbit-versions/<version>/Caddyfile` and runs `caddy validate` on it as the `caddy` user.
6. Backs up a live Caddyfile that Orbit did not build, as [replaced configuration](#replaced-configuration) describes.
7. Points `/etc/caddy/Caddyfile` at the new version, then enables and reloads the `caddy` service.
8. Keeps the live version and the nine newest others, and removes older versions. It never removes the `staged` directory.

The addresses are the WireGuard and LAN addresses that sites bind, because Caddy cannot start with a missing listen address. The version name is the first 32 hexadecimal characters of the file's SHA-256 digest. Validation runs as the `caddy` user, so log files that it creates stay writable by the service. When a build's version file differs from its digest, someone edited it by hand. The script backs that version up before it replaces or prunes it, whatever the new version is.

A version holds only its `Caddyfile`. It has no fragments and imports nothing. Every Node with Caddy sites, the Gateway machine included, installs Caddy from the pinned source before its first build.

### When a build fails

A build either publishes the whole file or changes nothing. It fails when a site cannot be rendered from stored state, when two sites collide, when Caddy is below the floor, when the Node lacks an address the file binds, when `caddy validate` rejects the file, or when Caddy fails to reload. After a reload failure the script points `/etc/caddy/Caddyfile` back at the previous version and reloads again. The live configuration keeps serving in every case.

The command that requested the build fails with its usual error code, such as `app-dev.caddy_config_failed` or `websocket.caddy_publication_failed`. The error details and the activity record name the Node, the failed stage, and Caddy's message. A missing certificate file fails validation; converge the role or Route that owns the site to publish it again. One broken site blocks every Caddy change on its Node until it is fixed, because each build renders every site.

The failed stage is one of `lock`, `release`, `addresses`, `write`, `validate`, `backup`, `swap`, or `reload` on the Node. On the Gateway it is `render` or `gateway-lock` before the Gateway contacts the Node, `connect` when the Gateway cannot reach the Node or the Node has no WireGuard address, and `read-live` when `--diff` cannot read the live file.

A stored LAN address must stay on its Node. When the address goes away after a build, Caddy fails at its next restart, and the next build fails at `addresses` and names it. Correct the Node's stored address, then build again.

[`orbit doctor`](/cli/doctor) reports `role.caddy_build_drift` when the live Caddyfile differs from a fresh render. The issue lists the site sources on that Node. Repeat any command that publishes one of them, such as `orbit node:role:add NODE app-dev --converge`, to build the Node again.

### Render without pushing

The Gateway can already render a Node's build, but no command pushes it yet. Role publishers still write the fragment layout above. On the Gateway machine, an operator or a reviewer can render one Node and compare it with that Node's live configuration:

```bash
php artisan orbit:caddy-build NODE --dry-run
php artisan orbit:caddy-build NODE --dry-run --diff
```

| Option | Result |
| --- | --- |
| `--dry-run` | Required. Prints the rendered Caddyfile and changes nothing. |
| `--diff` | Reads the live `/etc/caddy/Caddyfile` and its fragments, and prints one line for each site: `same`, `changed`, `build only`, or `live only`. A `changed` site lists the lines that differ. |

The command exits with status 1 and prints `Build refused:` with the reason when the render has a problem, such as a duplicate address or a WireGuard-only site on a wildcard port. The output names hostnames and certificate paths; Orbit's Caddy sites hold no secrets.

### Replaced configuration

The build replaces a Caddyfile that does not start with its marker line. It never adopts it. The first build on such a Node copies what it replaces to `/etc/caddy/orbit-backups/<UTC timestamp>/`, after the new version passes validation:

| Live `/etc/caddy/Caddyfile` | Backup |
| --- | --- |
| The unmodified package default | None |
| Any other regular file | The file |
| A symlink outside `/etc/caddy/orbit-versions` | The file it points at |
| A version with a `fragments` directory | The whole version directory |
| A build's version whose file differs from its digest | The whole version directory, also when prune removes it |

Sites in the replaced file stop serving after that build. Move a hand-placed site into Orbit before the first build, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes). The build never deletes a backup; remove it by hand when you do not need it.
