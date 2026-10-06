---
title: "Caddy configuration"
description: "How the Gateway builds each Node's whole Caddyfile from its database and pushes it in one step, and what happens when a build fails."
covers:
  - apps/gateway/app/Infrastructure/Caddy/**
  - apps/gateway/app/Infrastructure/Instances/{NativeAppProjectionServingRuntime,DevelopmentCaddyAccessCommand}.php
  - apps/gateway/app/Console/Commands/CaddyBuildCommand.php
  - apps/gateway/app/Infrastructure/Doctor/NativeCaddyBuildInspector.php
  - apps/gateway/app/Infrastructure/Nodes/Roles/CaddyRoleFailure.php
  - apps/gateway/app/Domain/Nodes/CaddyRelease.php
---

# Caddy configuration

For a Laravel root of `apps/site/public`, Caddy serves that nested web root, not the application directory or repository root. Development access grants validate `apps/site/public/storage` against `apps/site/storage/app/public`, using the shared [application directory](/reference/projects#application-directory). They grant access to the paths that serving needs without making `.git` public. Root `public` keeps Laravel's storage link at `public/storage` in the repository root.

Orbit owns `/etc/caddy/Caddyfile` on every Node that serves sites through Caddy. The Gateway builds that whole file for one Node from its database and pushes it in one step. No role writes Caddy files on a Node. Doctor compares the live Caddyfile with a fresh Node Caddy build. This page describes what a build contains, when it runs, how it reaches the Node, and how to fix a Node whose build fails. [Node provisioning](/reference/node-provisioning#package-sources) describes how Orbit installs Caddy.

## Published layout

Each build writes one file, `/etc/caddy/orbit-versions/<version>/Caddyfile`, and points `/etc/caddy/Caddyfile` at it. The file starts with an Orbit marker line and Orbit's global options block. Then it lists every site of every role on that Node:

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

The global options block is the same on every Node. Nothing else on a Node can add global options.

| Option | Effect |
| --- | --- |
| `auto_https disable_certs` | Caddy keeps its HTTP-to-HTTPS redirects but obtains no certificate on its own. Only a public Ingress site opts back in. See [public Ingress certificates](#public-ingress-certificates). |
| `order abort first` | `abort` runs before every other handler, so the client guard of a site runs first. See [listener addresses](#listener-addresses). |
| `metrics { per_host }` | Caddy counts requests, errors, and durations per hostname on every Node. `curl http://localhost:2019/metrics` on the Node shows them. Prometheus scrapes them only on Ingress, as [service metrics](/reference/service-metrics#caddy-traffic) describes. |

A version holds only its `Caddyfile`. It imports nothing. The version name is the first 32 hexadecimal characters of the file's SHA-256 digest.

## Node Caddy build

The Gateway renders the sites from committed database state only, so the same state always gives the same file. Route transitions are [stored state](/reference/routes#stored-transitions): a Route that is being published or withdrawn, an Instance that is being removed, a placement change, and a Router replacement each have a record that the build reads.

A role's sites render while the role is provisioning or active, and after a failed convergence, because they can already be live. They stop rendering when the role's removal starts.

The `websocket`, `analytics`, ProxyCli, and Metrics sites also wait for their certificate. The Gateway records when the role's certificate step has placed the certificate on the Node. It forgets the record at the removal that withdraws the site. A build during a role's first convergence or relocation therefore leaves that site out instead of failing for every site on the Node.

| Site source | Nodes | Listener |
| --- | --- | --- |
| `app-dev` and `app-prod` workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, Vite, and hibernation wake sites | Workload and Router Nodes | The WireGuard address, and the LAN address when the Node has one |
| Public Ingress sites | The Cluster's Ingress Node | `0.0.0.0`, the WireGuard address, and the LAN address when the Node has one |
| `gateway.orbit` | The Node with the `gateway` role | The WireGuard address |
| `metrics.orbit` | The Node with the `gateway` role, while a Metrics role renders | The WireGuard address |
| Service metrics scrape site on port 9103 | A selected Ingress Node | The WireGuard address |
| `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The Node that runs the role or collector | The WireGuard address |

A Node gets at most one site for each domain, port, and listener. When a Route's current placement and its transition placement render the same site on one Node, the build keeps the current one. Any other duplicate fails the build and names both sites.

### Move the websocket site

When `websocket` moves to another Node, the old Node keeps `reverb.orbit` until three things are true: the new Node's build is live, private DNS answers with the new Node, and cached answers can have expired. That wait is the 31-second [withdrawal grace period](/reference/routes#withdrawal-grace-period) that Route moves use. Then the move withdraws the site and the certificate on the old Node. Private DNS names the new Node only after its build is live, so a client never reaches a Node that does not serve the site yet.

The two Reverb servers share nothing. From the moment the new Node's build is live until the old Node's withdrawal build is done, the Gateway sends every broadcast to both servers, and its agent view subscriber keeps a link to each. A send to the old server gets 0.3 seconds to connect and 0.5 seconds in total. After a failed send, the Gateway skips the old server for 30 seconds. The withdrawal build reloads Caddy on the old Node, which closes its Reverb connections. Clients reconnect through private DNS, which already names the new Node.

When the withdrawal build fails, or the old Reverb does not stop, or the new Node's convergence fails, the command fails and names `orbit node:role:relocate NEW websocket --from OLD --force`. That command needs the old Node reachable. The Gateway serves both servers until it finishes the move.

### Listener addresses

Caddy sends a connection for a specific address only to the sites bound to that address, and every other connection to the `0.0.0.0` sites. Only public Ingress sites bind `0.0.0.0`, so no private site joins the public listener. A public Ingress site also binds the WireGuard and LAN addresses, because a Router forwards to those addresses and public traffic can arrive on the LAN address behind NAT. Router and workload sites never bind `0.0.0.0`, on an Ingress Node or elsewhere.

A Node that is the Router, the Ingress, and `app-prod` serves its private sites on the WireGuard and LAN addresses, and only its public sites on every address:

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
```

Every site that is not public aborts a client outside the ranges it admits, right after its `bind` line.

| Site | Admitted clients |
| --- | --- |
| `gateway.orbit`, `metrics.orbit`, the service metrics scrape site, `reverb.orbit`, `analytics.orbit`, and `collector.cli-proxy-api.orbit` | The VPN subnet |
| Router and workload sites on an Ingress Node | Private and shared address space (`private_ranges` and `100.64.0.0/10`) and the VPN subnet |
| Router and workload sites on any other Node, and public Ingress sites | Every client |

The guard closes two paths that the listener alone leaves open. Linux accepts a packet for the WireGuard address on any interface, so a LAN neighbour can reach it through the LAN address when the firewall admits HTTPS to any destination, as an Ingress firewall does. A port forward can also send public traffic to the LAN address of an Ingress Node.

The TLS handshake completes before the abort, and Caddy shares its certificate cache across listeners. A client that names a private hostname on the public listener therefore completes TLS with that site's certificate. Then it gets Caddy's empty `200` response, because no private site is on that listener.

Caddy cannot start with a missing listen address. So every build first checks that each specific address it binds exists on the Node. When a stored LAN address is missing, for example after a DHCP lease changed, the build stops at stage `addresses`, leaves the live Caddyfile unchanged, and names the address:

```text
The build binds 192.168.6.30, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then build again.
```

The `websocket` role runs this check before it changes anything on the Node. Give a Node with a stored LAN address a fixed address or a DHCP reservation.

### When a build runs

A command that changes Caddy sites commits its change first. Then it requests a build for each Node whose sites changed. Route and Instance commands, deploys, Metrics, service metrics, ProxyCli, Gateway web convergence, and the convergence and removal of `app-dev`, `app-prod`, `router`, `ingress`, `websocket`, and `analytics` all do this. No publisher requests a build inside a database transaction.

A command that adds a site publishes the site's certificate before the build, because `caddy validate` loads every certificate file the Caddyfile names. A command that removes a site builds first and removes the certificate afterwards. A certificate step that replaces a certificate that a live site uses reloads Caddy itself under the [Node lock](#how-a-build-is-pushed), because a build with an unchanged render does not reload.

The Gateway refuses to remove a certificate that a site in its stored state still renders on the Node. A Route certificate removal then fails with `app-dev.certificate_in_use`, names the blocking site's domain, and keeps the certificate, so the command can be retried.

The `app-dev` role creates the hibernation marker and log directories before it requests a build, because `caddy validate` opens those logs as the `caddy` user. The convergence of `app-dev`, `app-prod`, `router`, and `ingress` first runs the step `caddy-service-ordering`, which orders the Caddy service after `wg-quick@orbit`, and then builds the Node.

The Gateway runs one build at a time for each Node. A second build waits up to 30 seconds and then reads the latest committed state, so the last build always renders every committed change. A build that renders the file that is already live changes nothing and does not reload Caddy, so open WebSocket streams stay connected.

### Committed app candidates

During a Project app-list or Instance override update, the development site repository reads the committed app-projection journal. Its render side selects old or candidate effective paths, source profiles and app Route intents. Ordinary public configuration readers still see published maps and profiles. A build requested independently of the update reads the same committed phase; no in-memory override or temporary public-row rewrite is used. [Projects](/reference/projects#candidate-rendering-and-public-configuration) owns that selection and the parent publication boundary.

The native serving adapter uses this whole-Node build, the existing development projection and Node service locks, native source inspection/access checks and certificate/DNS/Route owners. It prepares candidate document roots and access, FPM socket references and cached APP_URL with protected owned recovery. Retained apps keep Route/domain/provenance/port identities; additions reserve candidate app Routes through the same internal Route contract, and removals withdraw only the removed app's generated artifacts. Certificates exist before a site is rendered, and are removed only after no committed render uses them. No second publisher or unmanaged fragment is introduced. Remote builds run outside database transactions.

Before publication, recovery commits old-side rendering and rebuilds from the current committed desired state. It preserves unrelated sites, including changes committed after preparation; it does not restore a stale whole-Node Caddyfile snapshot. After publication, recovery verifies the candidate side and cleans owned preparation artifacts forward. Each mutating step has a committed intent and protected receipt; a lost build/reload response is verified against actual state before retry, rather than treated as failure. The serving adapter is complete before either lifecycle integration uses it; a fake or database-only adapter is not runtime acceptance.

### How a build is pushed

The Gateway sends one script to the Node over SSH. On the Node that runs the Gateway process, it runs the same script through local `sudo`. The script runs these stages:

| Stage | Work |
| --- | --- |
| `lock` | Takes `/run/lock/orbit/caddy.lock`, after the path checks below. |
| `release` | Checks that the packaged `/usr/bin/caddy` is at least the release floor, 2.9.0. |
| `addresses` | Checks that every specific address the file binds exists on the Node. |
| — | Stops without a change when `/etc/caddy/Caddyfile` already points at an unchanged copy of this version. |
| `write` | Writes `/etc/caddy/orbit-versions/<version>/Caddyfile`. |
| `validate` | Runs `caddy validate` on the new file as the `caddy` user, so log files that it creates stay writable by the service. |
| `backup` | Backs up a live Caddyfile that Orbit did not build, as [replaced configuration](#replaced-configuration) describes. |
| `swap` | Points `/etc/caddy/Caddyfile` at the new version. |
| `reload` | Enables and reloads the `caddy` service. |
| — | Keeps the live version and the nine newest others, and removes older versions. |

Every build and every certificate step that reloads Caddy holds `/run/lock/orbit/caddy.lock`. A second holder waits up to 30 seconds and then fails without a change. Before the script takes the lock, it checks the lock path:

| Path | Requirement |
| --- | --- |
| `/run/lock/orbit` | A real directory, not a symlink, owned by `root:root` with mode `0700`. Orbit creates it when it is missing. |
| `/run/lock/orbit/caddy.lock` | A regular file, not a symlink, owned by `root:root`. Orbit sets mode `0600` after it opens the file. |

A failed check stops the build before any change. `/run/lock` is a tmpfs, so the lock and its directory come back after a reboot.

### When Caddy is absent

A Node without `/usr/bin/caddy` and without a running `caddy` service serves nothing. The build then reports `unchanged` at stage `release` in two cases: the render has no site, or no Caddy role on the Node (`gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, `analytics`) is active or converging. In every other case a missing Caddy fails the build at stage `release`. A running `caddy` service keeps serving the configuration it loaded, so a missing binary alone never skips the build.

On that skip, the live `/etc/caddy/Caddyfile` can name sites and certificates that the render does not have. So when the bytes behind the live path are not the render, the build moves the live path into `/etc/caddy/orbit-backups/<UTC timestamp>/`. A symlink moves as a symlink, and a regular file moves as a file. A later `systemctl start caddy` then cannot load a removed certificate. The build does not validate, start, or reload Caddy, and it deletes nothing. A live file whose bytes are the render stays in place. When the build cannot move the live path, it fails at stage `release` and leaves the path in place.

The next convergence of a Caddy role on the Node installs Caddy and builds the Node. That build validates the new file before the live path points at it.

### When a build fails

A failed build does not publish a new file. A build fails when a site cannot render from stored state, when two sites collide, when Caddy is below the floor, or when the Node lacks an address the file binds. It also fails when `caddy validate` rejects the file, or when Caddy fails to reload.

A failed reload leaves Caddy on the configuration it already runs. The script points `/etc/caddy/Caddyfile` back at the previous version and asks Caddy to load it again. It never restarts a running Caddy. It starts Caddy only when Caddy is not running.

One broken site blocks every Caddy change on its Node until it is fixed, because each build renders every site. A certificate file that someone removed by hand fails validation, for example. Converge the role or Route that owns the site again to publish the certificate.

The command that requested the build fails with its own error code:

| Publisher | Error code |
| --- | --- |
| Route publication, including a public Ingress site, and the `app-dev` role | `app-dev.caddy_config_failed` |
| `app-prod` role | `app-prod.caddy_config_failed` |
| `router` role | `router.caddy_config_failed` |
| `ingress` role | `ingress.caddy_config_failed` |
| `websocket` role | `websocket.caddy_publication_failed` |
| `analytics` role | `analytics.caddy_publication_failed` |
| ProxyCli collector | `proxycli.caddy_publication_failed` |
| Metrics and service metrics | `metrics.caddy_publication_failed` |
| Gateway web convergence | `gateway.caddy_config_invalid` at `render` or `validate`, `gateway.caddy_start_failed` at `reload`, and `gateway.caddy_config_install_failed` at any other stage |

A role convergence fails with `node_role.convergence_failed`, and a role removal fails with `node_role.remove_failed`. The error response carries the step's own code as `details.error_code` next to `details.step`, and `orbit node:role:list` shows it. For example, an `ingress` removal that stops in the build has `details.step` `remove:caddy-config` and `details.error_code` `ingress.caddy_config_failed`. A package or prerequisite step keeps its own code, such as `ingress.prerequisite_failed`.

The error message and the `node`, `stage`, and `message` fields of the error details name the Node, the failed stage, and Caddy's message. Each message is at most 2,000 bytes of UTF-8, and `…` marks a cut. The CLI prints the details with `--json`:

```text
The Caddy build for Node [app-prod] failed at stage [validate]: Error: loading certificates: open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory
```

On the Node, the failed stage is one of the stages in [How a build is pushed](#how-a-build-is-pushed). On the Gateway, it is `render` before the Gateway contacts the Node, `connect` when the Gateway cannot reach the Node or the Node has no WireGuard address, and `read-live` when `--diff` cannot read the live file.

## Public Ingress certificates

A public Ingress site gets its certificate from Let's Encrypt, not from Orbit. Its site block carries `tls force_automate`:

```caddy
shop.example.com {
    bind 0.0.0.0 10.44.0.3
    tls force_automate
    reverse_proxy https://10.44.0.20 {
        # Router forwarding settings
    }
}
```

`force_automate` makes Caddy manage the certificate for that hostname, although the global block disables certificate management. Caddy uses its default issuers, Let's Encrypt first, and renews the certificate on its own. Let's Encrypt validates the hostname on port 80 or 443, so the hostname's public DNS must point at the Ingress Node. Orbit opens both ports on the Ingress firewall while the Cluster has a live public Route.

Private sites never carry `force_automate`. They pin their Orbit CA files, so Caddy never asks a public CA for a private hostname, even when a pinned certificate does not match its site. `force_automate` needs Caddy 2.9.0 or newer, which is the release floor.

[`orbit doctor`](/cli/doctor) reports `instance.public_tls_mismatch` when the live public site pins an Orbit CA leaf, or when it lacks `tls force_automate` while the live Caddyfile disables certificate management. Converge the public Route to build the Ingress Node again.

## Build a Node by hand

On the Gateway machine, an operator can build one Node, or render it and compare the render with the Node's live configuration:

```bash
php artisan orbit:caddy-build NODE
php artisan orbit:caddy-build NODE --dry-run
php artisan orbit:caddy-build NODE --dry-run --diff
```

| Option | Result |
| --- | --- |
| None | Builds the Node and pushes the file, as any publisher does. It prints whether it published a new file or found the live file current. |
| `--dry-run` | Prints the rendered Caddyfile and changes nothing. |
| `--diff` | With `--dry-run`, reads the live `/etc/caddy/Caddyfile` and prints one line for each site: `same`, `changed`, `build only`, or `live only`. A `changed` site lists the lines that differ. |

A failed build exits with status 1 and prints the error message. `--dry-run` exits with status 1 and prints `Build refused:` with the reason when the render has a problem, such as a duplicate address. The output names hostnames and certificate paths. Orbit's Caddy sites hold no secrets.

Repeating any command that publishes one of a Node's sites, such as `orbit node:role:add NODE app-dev --converge`, also builds the Node again.

## Replaced configuration

The build replaces a Caddyfile that does not start with its marker line. It never adopts it. The first build on such a Node copies what it replaces to `/etc/caddy/orbit-backups/<UTC timestamp>/`, after the new version passes validation:

| Live `/etc/caddy/Caddyfile` | Backup |
| --- | --- |
| The unmodified package default | None |
| Any other regular file | The file |
| A symlink outside `/etc/caddy/orbit-versions` | The file it points at |
| A build's version whose file differs from its digest | The whole version directory, also when the build prunes it |

Sites in a replaced file stop serving after that build. Move a hand-placed site into Orbit before the first build, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes). The build never deletes a backup. Remove it by hand when you do not need it.

## Check a Node with Doctor

[`orbit doctor`](/cli/doctor) reads a Node's sites only from the one live `/etc/caddy/Caddyfile`. It never reads a file that the live Caddyfile imports.

The `role` family renders the Node's build from stored state and compares it byte for byte with the live file. It reports `role.caddy_build_drift` once per Node, on the first active role that publishes Caddy sites, and lists the Node's site sources in the summary:

| `expected` | `observed` | Meaning |
| --- | --- | --- |
| The version of a fresh build | The version of the live file | Someone edited the live file, or stored state changed without a build. |
| The version of a fresh build | `not_built` | No build wrote the live file. |
| `buildable` | `refused` | Stored state renders no buildable file, as `Build refused:` in `orbit:caddy-build NODE --dry-run` shows. |

To repair drift, run `php artisan orbit:caddy-build NODE` on the Gateway machine, or converge a role that publishes one of the listed sites. When Doctor cannot read the live file, it reports `role.inspection_failed`.

Doctor compares only while no build holds the Gateway's build lock for the Node. It waits up to 5 seconds for a running build. If the build still holds the lock, Doctor reports `role.inspection_failed` with `observed` set to `building`, so the check reads as unverifiable, never healthy. Doctor reads the live file with a 10-second limit. A command that has saved its change but has not started its build yet can show `role.caddy_build_drift` for a moment. Run Doctor again. A drift that remains is real.

For a production Instance, the `instance` family checks that each of the Instance's own site blocks is in the live file exactly as a build renders it, and reports `instance.caddy_projection_mismatch` otherwise.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One build per Node, from stored state

One deterministic render per Node makes the Caddy configuration reproducible from the database. Doctor can compare it, and Gateway recovery can restore it. Nothing on the Node is an input, so no hand-placed site, stale site, or foreign global option survives a build, and no publisher decides Node-wide facts, such as listener addresses, from partial knowledge. The cost is that one broken site blocks every change on its Node.

Rejected alternatives: per-role fragments under a shared lock, a `fragments` directory that only the build writes, adopting a foreign Caddyfile as a carried fragment, rendering on the Node, and a build queue.

### Orbit owns the global options

The global options decide certificate automation for every site on a Node. An operator option such as `auto_https off`, `local_certs`, or an ACME issuer would contradict Orbit's certificate model, and Caddy has no safe merge for repeated options. So a setting that must apply Node-wide needs an Orbit change.

### Certificate automation per public site

`auto_https disable_certs` stops Caddy from asking a public CA for a private hostname when a pinned certificate does not match. But it disables management for every site on the server, public sites included. `tls force_automate` opts only public Ingress sites back in. Rejected alternatives: remove `disable_certs`, name an ACME issuer per site (an issuer does not make Caddy manage the name), list public hostnames in the global block (per-Route data in a static block), and let Orbit run ACME itself.

### HTTP metrics on every Node

Caddy allows one global block, and service metrics needs the `metrics` option. Putting it in Orbit's shared block keeps the block the same on every Node. The measured cost is about 3 microseconds of CPU per request, a small fraction of a PHP request. A per-Node block that depends on service metrics state is the rejected alternative.

### Private sites off the public listener

A private Router or workload site on `0.0.0.0` would answer its hostname on the public address of an Ingress Node. Binding private sites to the WireGuard and LAN addresses separates them by listener. The client guard covers the paths that a listener alone leaves open. Limiting the Ingress firewall to public addresses is rejected, because Orbit does not know those addresses. A firewall deny for the WireGuard address on other interfaces is rejected, because UFW rules have no guaranteed order. For the same reason `ingress` never shares a Node with `gateway`: the Gateway stays private.

### Set a stale Caddyfile aside when Caddy is absent

The absent-Caddy skip lets a removal finish on a Node without Caddy. Leaving the live file in place would make the next `systemctl start caddy` fail on a removed certificate. Writing the render without `caddy validate` would start an unvalidated file. Deleting the file would lose the only copy of a hand-placed configuration. Moving it into the backup directory avoids all three.

### A pinned Caddy source and a release floor

The Ubuntu archive ships Caddy 2.6.2, which lacks directives that Orbit renders, such as `log_skip` and `tls force_automate`. The pinned Caddy apt source keeps security updates flowing through `unattended-upgrades`. The floor fails convergence early with a clear error instead of a failed publication later. Rejected alternatives: render only what 2.6.2 understands, vendor a binary, pin an exact version, and render one directive set per Caddy version.
