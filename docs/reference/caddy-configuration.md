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
    order abort first
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
    @orbit_outside not remote_ip 10.44.0.0/24
    abort @orbit_outside
    ...
}
```

The global options block is Orbit's, and it is the same on every Node. A change to it changes every Node's file, and Doctor reports `role.caddy_build_drift` on a Node until its next build. `auto_https disable_certs` keeps Caddy's HTTP-to-HTTPS redirects but stops Caddy from obtaining certificates on its own. A private site serves the Orbit CA certificate Orbit publishes for it. A public Ingress site opts back in, as [public Ingress certificates](#public-ingress-certificates) describes.

`order abort first` runs `abort` before every other handler, so the client guard of a site runs first, as [listener addresses](#listener-addresses) describes.

`metrics { per_host }` makes Caddy count requests, errors, and durations per hostname. On the Node, `curl http://localhost:2019/metrics` shows them. Prometheus scrapes them only on Ingress, as [service metrics](/reference/service-metrics#caddy-traffic) describes. [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) records this choice and its cost.

A version holds only its `Caddyfile`. It has no fragments and imports nothing. The version name is the first 32 hexadecimal characters of the file's SHA-256 digest.

## Node Caddy build

The Gateway renders the sites from committed database state only, so the same state always gives the same file. Route transitions are [stored state](/reference/routes#stored-transitions): a Route that is being published or withdrawn, an Instance that is being removed, a placement change, and a Router replacement each have a database record that the build reads.

A role's sites render while the role is provisioning or active, and after a failed convergence, because they may already be live. They stop rendering when the role's removal starts.

The `websocket`, `analytics`, ProxyCli, and Metrics sites also wait for their certificate: the Gateway records when the role's certificate step has placed the certificate on the Node, and forgets it at the removal that withdraws the site. A build during a role's first convergence or relocation therefore leaves that site out instead of failing for every site on the Node.

When `websocket` moves, the old Node keeps `reverb.orbit` until the new Node serves it, private DNS answers with the new Node, and cached answers can have expired, which is the 31-second grace that Route moves use. Only then does the move withdraw the site and the certificate on the old Node.

Private DNS names the new Node only after its build is live. Until then, any private DNS publication, such as one from a Route created during the move, still answers with the old Node, so a client never reaches a Node that does not serve the site yet.

The two Reverb servers share nothing, so the Gateway serves both while they hold clients. From the moment the new Node's build is live until the old Node's withdrawal build is done, the Gateway sends every broadcast to both servers, and the agent view subscriber keeps a link to each. The subscriber takes each Node's state from the link with the newest agent event. While an agent moves between the servers, its Node keeps its stored view, which goes stale on its own, instead of reading as missing.

The withdrawal build reloads Caddy on the old Node, and the reload closes that Node's Reverb connections with a WebSocket close. Browsers reconnect at once through private DNS, which already names the new Node, and reload their data when they subscribe again. Agents reconnect after their own backoff, which [Node agent](/reference/node-agent) describes.

The Gateway stops publishing to the old server, and its subscriber closes that link, only after the withdrawal build has succeeded and the old Node's Reverb has stopped. When either step fails, or the new Node's convergence fails, the move is incomplete: the command fails and names `orbit node:role:relocate NEW websocket --from OLD --force`, which needs the old Node reachable, and the Gateway keeps serving both servers until that command finishes the move.

A send to the old server gets 0.3 seconds to connect and 0.5 seconds in total, and after a failure the Gateway skips that server for 30 seconds, so an unreachable old Node never slows broadcasts to the serving server.

| Site source | Nodes | Listener |
| --- | --- | --- |
| `app-dev` and `app-prod` workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, Vite, and hibernation wake sites | Workload and Router Nodes | The WireGuard address and the LAN address when the Node has one |
| Public Ingress sites | The Cluster's Ingress Node | `0.0.0.0`, the WireGuard address, and the LAN address when the Node has one |
| `gateway.orbit` | The Node with the `gateway` role | WireGuard address |
| `metrics.orbit` | The Node with the `gateway` role, while a Metrics role renders | WireGuard address |
| Service metrics scrape site on port 9103 | A selected Ingress Node | WireGuard address |
| `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The Node that runs the role or collector | WireGuard address |

Only public Ingress sites bind `0.0.0.0`, so no private site joins the public listener. The `ingress` and `gateway` roles never share a Node, so `gateway.orbit` and `metrics.orbit` never run beside a public site. [ADR 0157](/decisions/0157-keep-private-caddy-sites-off-the-public-listener) records both rules.

A Node gets at most one site for each domain, port, and listener. When a Route's current and transition placements render the same site on one Node, the build keeps the current one. Any other duplicate fails the build and names both sites.

### Listener addresses

Caddy sends a connection for a specific address only to the sites bound to that address, and every other connection to the `0.0.0.0` sites. A public Ingress site therefore binds the WireGuard and LAN addresses as well as `0.0.0.0`: a Router forwards to those addresses, and public traffic can arrive on the LAN address behind NAT. Every other site binds only the addresses its clients use. Routers, Ingress, and private DNS clients reach Router and workload sites on a Node's LAN or WireGuard address, so those sites never bind `0.0.0.0`, on an Ingress Node or elsewhere.

A production Node that is the Router, the Ingress, and app-prod then serves its private Router sites on the WireGuard and LAN addresses, and only its public sites on every address:

```caddy
https://shop.test {
    bind 10.44.0.3 192.168.1.3
    @orbit_outside not remote_ip private_ranges 100.64.0.0/10 10.44.0.0/24
    abort @orbit_outside
    # Router site of a private Route
}

shop.example.com {
    bind 0.0.0.0 10.44.0.3 192.168.1.3
    tls force_automate
    # public Ingress site
}

http://10.44.0.3:9103 {
    bind 10.44.0.3
    @orbit_outside not remote_ip 10.44.0.0/24
    abort @orbit_outside
    # service metrics scrape site
}
```

Every site that is not public aborts a client outside the ranges it serves, right after its `bind` line. Orbit's global options order `abort` before every other handler, so the guard runs first.

| Site | Admitted clients |
| --- | --- |
| `gateway.orbit`, `metrics.orbit`, the service metrics scrape site, `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The VPN subnet |
| Router and workload sites on an Ingress Node | Private and shared address space (`private_ranges` and `100.64.0.0/10`) and the VPN subnet |
| Router and workload sites on any other Node, and public Ingress sites | Every client |

The guard covers two paths that the listener alone leaves open. Linux accepts a packet for the WireGuard address on any interface, so a LAN neighbour can route to it through the LAN address when the firewall admits HTTPS to any destination, as an Ingress firewall does. A port forward can send public traffic to the LAN address of an Ingress Node.

The TLS handshake still completes before the abort. Caddy's certificate cache is shared across listeners. A client that names a private hostname in SNI on the public listener therefore completes TLS with that site's certificate. It then gets Caddy's empty `200` response, because no private site is on that listener.

A public Ingress site and another site for the same host and port share the WireGuard address, so the build fails on them as a duplicate address.

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
8. Keeps the live version and the nine newest others, and removes older versions.

Validation runs as the `caddy` user, so log files that it creates stay writable by the service. When a build's version file differs from its digest, someone edited it by hand. The script backs that version up before it replaces or prunes it, whatever the new version is.

Every build and every certificate step that reloads Caddy holds `/run/lock/orbit/caddy.lock`. A second holder waits up to 30 seconds for the lock and then fails without changing the Node. Before it takes the lock, the script checks the lock path:

| Path | Requirement |
| --- | --- |
| `/run/lock/orbit` | A real directory, not a symlink, owned by `root:root` with mode `0700`. Orbit creates it with that mode when it is missing. |
| `/run/lock/orbit/caddy.lock` | A regular file, not a symlink, owned by `root:root`. Orbit sets mode `0600` after it opens the file. |

A failed check stops the build before any change. `/run/lock` is a tmpfs, so the lock and its directory are recreated after a reboot.

Every Node with Caddy sites, the Gateway machine included, installs Caddy from the pinned source before its first build.

A Node without `/usr/bin/caddy` serves nothing, so a build there that has nothing to publish changes nothing. That is a file with no site, or any file while no Caddy role on the Node (`gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, `analytics`) is active or converging. The script then stops at step 2 and reports the build as unchanged. So when two Caddy roles both failed to converge before Caddy was installed, removing either one succeeds, although the other still renders its sites. While a Caddy role is active or converging, a missing Caddy still fails the build at step 2.

### When a build fails

A build either publishes the whole file or changes nothing. It fails when a site cannot be rendered from stored state, when two sites collide, when Caddy is below the floor, when the Node lacks an address the file binds, when `caddy validate` rejects the file, or when Caddy fails to reload.

A failed reload leaves Caddy on the configuration it already runs. The script then points `/etc/caddy/Caddyfile` back at the previous version and asks Caddy to load it again. It never restarts a running Caddy; it starts Caddy only when Caddy is not running. The live configuration keeps serving unless Caddy itself had stopped.

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

Role convergence, such as `orbit node:role:add NODE app-dev --converge`, fails with `node_role.convergence_failed`; `orbit node:role:list` shows the publisher's code as the underlying error. The error message, the activity record, and the `node`, `stage`, and `message` fields of the error details name the Node, the failed stage, and Caddy's message. Each stage bounds the message at 2,000 bytes of UTF-8 and marks a cut with `…`. The CLI prints them under `error.details` with `--json`, next to the `step` that requested the build:

```text
The Caddy build for Node [app-prod] failed at stage [validate]: Error: loading certificates: open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory
```

The failed stage is one of `lock`, `release`, `addresses`, `write`, `validate`, `backup`, `swap`, or `reload` on the Node. On the Gateway it is `render` or `gateway-lock` before the Gateway contacts the Node, `connect` when the Gateway cannot reach the Node or the Node has no WireGuard address, and `read-live` when `--diff` cannot read the live file.

One broken site blocks every Caddy change on its Node until it is fixed, because each build renders every site. A certificate file removed by hand fails validation, for example. Converge the role or Route that owns the site again to publish the certificate.

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
| `--diff` | With `--dry-run`, reads the live `/etc/caddy/Caddyfile` and prints one line for each site: `same`, `changed`, `build only`, or `live only`. A `changed` site lists the lines that differ. It reads no file that the live Caddyfile imports. |

A failed build exits with status 1 and prints the error message. `--dry-run` exits with status 1 and prints `Build refused:` with the reason when the render has a problem, such as a duplicate address. The output names hostnames and certificate paths; Orbit's Caddy sites hold no secrets.

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

A Node whose Caddyfile no build wrote, such as one restored from an earlier release's backup, keeps it until the first command that builds it. That first build backs up the old file or version and serves the Orbit sites from one file. Sites in a replaced file that Orbit does not render stop serving after that build, including an adopted `00-unmanaged.caddy` fragment. Move a hand-placed site into Orbit before the first build, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes). The build never deletes a backup; remove it by hand when you do not need it.

## Check a Node with Doctor

[`orbit doctor`](/cli/doctor) reads a Node's sites only from the one live `/etc/caddy/Caddyfile`. It never reads a file that the live Caddyfile imports.

The `role` family renders the Node's build from stored state and compares it byte for byte with the live file. It reports `role.caddy_build_drift` once per Node, on the first active role that publishes Caddy sites, and lists the Node's site sources in the summary:

| `expected` | `observed` | Meaning |
| --- | --- | --- |
| The version of a fresh build | The version of the live file | Someone edited the live file, or stored state changed without a build |
| The version of a fresh build | `not_built` | No build wrote the live file: a foreign file, the package default, or the fragment layout of an earlier release |
| `buildable` | `refused` | Stored state renders no buildable file, as `Build refused:` in `orbit:caddy-build NODE --dry-run` shows |

Doctor checks every Linux Node that renders a Caddy site or holds a `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, or `analytics` role. To repair drift, build the Node again with `php artisan orbit:caddy-build NODE` on the Gateway machine, or converge a role that publishes one of the listed sites, such as `orbit node:role:add NODE app-prod --converge`. The build backs up a hand-edited or foreign file before it replaces it. When Doctor cannot read the live file, it reports `role.inspection_failed`.

Doctor compares only while no build holds the Gateway's build lock for the Node. When a build runs, Doctor waits up to 5 seconds for it. If the build still holds the lock, Doctor reports `role.inspection_failed` with `observed` set to `building`, so the Node's check reads as unverifiable, never healthy. Doctor reads the live file with a 10-second limit, so a build never waits long for Doctor. A command that has saved its change but has not started its build yet can still show `role.caddy_build_drift` for a moment. Run Doctor again; a drift that remains is real.

For a production Instance, the `instance` family checks that each of the Instance's own site blocks is in the live file exactly as a build renders it, and reports `instance.caddy_projection_mismatch` otherwise. Route families check their Route's sites in the same file. A change elsewhere in the file shows only as `role.caddy_build_drift`.
