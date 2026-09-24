---
title: "ADR 0141: Build each Node's Caddyfile on the Gateway"
sidebarTitle: "0141 Build each Node's Caddyfile on the Gateway"
description: "Proposed. The Gateway renders a Node's whole Caddyfile from its database in one build and pushes it atomically. Role publishers change stored state and request a build; they do not write Caddy files. A non-Orbit Caddyfile is backed up and replaced. Supersedes ADR 0137."
---

# ADR 0141: Build each Node's Caddyfile on the Gateway

The Gateway renders the complete Caddyfile for one Node from stored state in one step: Orbit's global options block, then every site of every role on that Node. It pushes that file atomically: write a new version, validate it, swap the live symlink, reload Caddy, and restore the previous version on failure. Role publishers stop writing Caddy files. They change stored state and request a Node Caddy build. The Gateway serializes builds for one Node. Nothing on the Node survives a build unless the Gateway rendered it.

## Status

Proposed.

## Context

Nine publishers write Caddy configuration today: `app-dev`, `app-prod`, service metrics, Herdr observers, Metrics, `websocket`, ProxyCli, `analytics`, and the Gateway web converger. Each one reads the live version under `/etc/caddy/orbit-versions`, copies every fragment it does not own, writes its own fragment, validates, swaps `/etc/caddy/Caddyfile`, and reloads. A tenth step, public Ingress staging, writes a file that nothing reads. [Caddy configuration](/reference/caddy-configuration) describes that layout.

Every publisher except the Gateway web converger holds `/run/lock/orbit/caddy.lock` from before it reads the live version until Caddy reloads. That lock stops two publishers from losing each other's fragments. The read-modify-write still has two structural problems:

- The Node's live files are an input. A copied fragment carries forward whatever it holds: an adopted `00-unmanaged.caddy`, a hand-placed site, or a stale site from an earlier release. [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) added a shell guard for carried global blocks. The service metrics fragment then broke every publication on `app-prod` with its own global block, until [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) moved `metrics { per_host }` into Orbit's block.
- Each script decides Node-wide facts from partial knowledge. The `analytics` and ProxyCli scripts copy `bind 0.0.0.0` when a sibling fragment has it; the `websocket` script prefers the WireGuard address when a sibling binds it. Nothing checks that choice again when another publisher then adds a wildcard site.

A code survey of the current Gateway found these further inputs that do not come from stored state or that a whole-Node render must replace:

1. The `app-dev` site list takes four call arguments. `pendingRoute` forces in a saved Route that is still `pending`. `unavailableInstance` adds the "unavailable" site while an Instance removal runs; the Route and its open `AppInstanceRemovalMember` row are saved, but the stored path suppresses the site while that row exists. `routerOverrides` renders the candidate Router, whose `router` role row is saved as `provisioning`; the Ingress site ignores it and keeps the old Router. `additionalRoute` adds the hostname-change sites; for a domain change the replacement Route is already saved, but for a placement change the candidate is an unsaved copy of the Route with a new Node and Cluster.
2. The `bind` choice described above.
3. Service metrics snapshots the live `00-metrics-service.caddy` from the Node and republishes it when another step of its lifecycle fails.
4. The Gateway web converger runs local `sudo` steps on the Gateway machine. It renders `gateway.caddy` from bootstrap input, writes no global block, takes no lock, does not roll back a failed reload, and copies `/etc/caddy/orbit.d/*.caddy` when no versioned layout exists. No Orbit code writes `/etc/caddy/orbit.d`. The Gateway machine has a Node row with the `gateway` and `vpn` roles; `metrics.orbit` is published on its Caddy, and the `websocket` role may also run there.
5. The public Ingress step writes `/etc/caddy/orbit-versions/staged/route-<id>-ingress.caddy`. Nothing imports it. The live Ingress site comes from the `app-dev` render.
6. Only the Herdr observer script bundles non-Caddy work with its fragment: the observer systemd unit and its certificate. The `app-dev` script also creates the hibernation marker and log directories. The Caddy service ordering drop-in after `wg-quick@orbit` is a separate command from `app-dev`, `app-prod`, and the Gateway converger. PHP-FPM pools, dnsmasq, ufw, and the FPM exporter are separate steps of their roles.
7. No Orbit code installs Caddy on the Gateway machine or checks its release there. The `gateway` role has no `caddy` package, and bootstrap assumes Caddy is present. The [quickstart](/quickstart) installs Caddy by hand from the Caddy project's source and asks the operator to check for 2.9.0 or newer, but its key has no digest or fingerprint pin. A Gateway set up another way keeps the Ubuntu archive's Caddy 2.6.2, which rejects `metrics { per_host }` and is below the 2.9.0 floor from [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation).
8. The `ingress` role lists no packages, although Ingress sites and the service metrics site run there. Herdr observer Nodes need no role at all, and the Herdr script prefers a Linuxbrew Caddy at `/home/linuxbrew/.linuxbrew/bin/caddy` when one exists.

## Decision

The Gateway owns every Caddy file on a Node through one build per Node. The rules below cover the build, publishers, transitions, listener addresses, the Gateway machine, Caddy installation, and foreign configuration.

### One build per Node

- The Gateway owns a Node Caddy build. A build reads stored state for one Node and renders one Caddyfile: a fixed Orbit marker line, Orbit's global options block from `CaddyGlobalOptions`, then the sites of every site source that applies to that Node. The site sources are `gateway`, Metrics, service metrics, Router and workload sites for `app-dev` and `app-prod` (including custom proxy Routes, analytics tracking hosts, Agentation, and Vite), public Ingress, `websocket`, `analytics`, ProxyCli, and Herdr observers.
- The render is deterministic. The same stored state gives the same bytes. The version name is derived from a digest of the file, so an unchanged render writes nothing and does not reload Caddy.
- A Node gets at most one site block for each address, which is a domain with its port and listener. When a Route's current placement and its second placement render the same address on one Node, the build keeps the current placement's site and skips the other. Any other duplicate fails the build before it contacts the Node, and the error names both sources.
- The Gateway pushes the file with one root script: take `/run/lock/orbit/caddy.lock` with its path checks, check the Caddy release, back up a foreign Caddyfile, write `/etc/caddy/orbit-versions/<version>/Caddyfile`, run `/usr/bin/caddy validate` as the `caddy` user, swap `/etc/caddy/Caddyfile` to the new version, enable and reload the `caddy` service, and restore the previous target when the reload fails. It keeps the live version and the nine newest others and removes older versions.
- A version holds one file. There is no `fragments` directory, no `import`, and nothing copied from the previous version.
- The Gateway serializes builds for one Node with a Gateway lock per Node. A build waits up to 30 seconds for that lock and reads stored state only after it holds it, so the last build always reflects the latest committed state. The Node lock stays as a second guard.
- A build has no partial result. When any site source cannot render, or validation or reload fails, the live Caddyfile and Caddy stay as they were. The calling operation keeps its current error code. The error details name the Node, the failed build stage, and Caddy's message, and the activity record keeps them.

### Publishers change state, then request a build

- A publisher commits its state change, publishes any certificate or other files the new sites need, and then requests a build for each affected Node. It never requests a build inside a database transaction. Otherwise a concurrent build on the same Node reads state without the change and can push last, and a rollback leaves a pushed site with no database record.
- Removal runs in the opposite order: commit the state change, build, then remove the certificate and other files.
- Certificates stay a separate step. It must finish before the build, because `caddy validate` loads every certificate file the Caddyfile names. A certificate step that replaces a certificate file a live site already uses reloads Caddy itself under the Node lock, because a build whose render did not change does not reload. The `websocket` and Metrics certificate steps already reload this way. The `app-dev` certificate step reloads today without the Node lock and starts to take it. The Herdr and Gateway certificate steps start to reload this way.
- Non-Caddy work stays with its role: PHP-FPM pools, dnsmasq, ufw, the FPM exporter and its unit, the `orbit-websocket` unit, and the Herdr observer unit and certificate. The `app-dev` role creates the hibernation marker and log directories before it requests a build.

### Transitions are stored state

- A Route stores whether its sites are published. Creation sets that record once the certificate its sites name exists and before the first build, so the build renders a `pending` Route that is being created. Removal and a failed creation clear it before the build that withdraws the sites. The Route row is deleted after that build.
- The build renders the "unavailable" site for a Route while all three hold: its targets are gone, its publication record is set, and an open development `AppInstanceRemovalMember` row names the departing Instance. Instance removal clears the publication record and builds before it removes the Instance certificate and deletes the Route. A build between those steps therefore never names a removed certificate.
- A placement change stores its second placement on the Route in two nullable columns, `transition_node_id` and `transition_cluster_id`. The Route keeps its domain, so the unique `routes.domain` index does not change. From the `workload-caddy` step until `database-cutover`, the columns hold the candidate placement. At `database-cutover` the Route takes the candidate placement, and the columns take the old one. Private DNS answers with the current placement, so its host records move when cleanup publishes private DNS after cutover, and clients can hold the old answer. `cleanup` issues the live certificates for the current placement and publishes private DNS. It waits until cached answers can have expired, stores its step, which stops rendering the second placement, builds, removes the staging and old certificates, and then clears the columns, so a retry still knows the old placement. A failure before the stored `cleanup` step keeps both placements serving. From `router-caddy`, private DNS can answer Cluster members with the candidate Router. A restore before that step clears the columns and builds before it removes the candidate certificates. A restore from that step on first publishes private DNS for the current placement and waits for cached answers to expire while both placements serve; when that publication fails, it stops and both keep serving. It then clears the columns, builds, and removes the candidate certificates. While the columns are set and the step is not `cleanup`, the build renders the second placement's workload and Router sites next to the current sites. The candidate placement's Router sites use the hostname-change certificate scope, before cutover in the columns and after cutover as the current placement. The old placement keeps its live scopes. A domain change keeps its saved `pending` replacement Route, as today. Its replacement keeps the hostname-change scopes until its cleanup has issued the live certificates and stored the `cleanup` step.
- A Router replacement reads the candidate `router` role row and the step stored in its `failed_step` column. From `router-caddy` until `cleanup`, both the old Router and the candidate render the Cluster's Router sites, because private DNS still points at the old Router until `dns-publication` and clients can hold that answer. Once `dns-publication` completes, every private DNS publication answers with the candidate. The Ingress upstream follows the active `router` role row, so it switches at `database`. `cleanup` publishes private DNS and waits for cached answers to expire, then stops rendering the Router sites on the old Router, builds it, and removes its Router certificates and firewall rules. A restore before `dns-publication` completes first marks the candidate so private DNS answers with the old Router again while the candidate still renders. After a failed `dns-publication` it waits for cached answers to expire. It then stops rendering the Router sites on the candidate, builds it, and removes the candidate certificates, so the old Router keeps serving. When that DNS publication fails, the candidate keeps serving.
- Private DNS answers carry a 30-second TTL. A transition waits a grace period of 31 seconds after it moves an answer before it withdraws the old target. One operation waits once for all its Routes and does not hold the development projection owner while it waits; it reads each Route again before the withdrawal. The grace period covers resolvers that honor the TTL. Caddy finishes in-flight requests on reload and closes idle keep-alive connections, but a client with a longer DNS cache of its own can reach the withdrawn target until that cache expires.

### Listener addresses are fixed per site source

- Each site source has one of four listener rules:

  | Site source | Listener |
  | --- | --- |
  | `app-dev` and `app-prod` workload and Router sites, custom proxy Routes, analytics tracking hosts, Agentation, and Vite | `0.0.0.0`, because Routers and workloads reach them over WireGuard and LAN |
  | Public Ingress sites | `0.0.0.0`, because clients reach them on the public address |
  | `gateway.orbit`, `metrics.orbit`, and the service metrics scrape site | The Node's WireGuard address only |
  | `websocket`, `analytics`, ProxyCli, and Herdr observers | The WireGuard address, or `0.0.0.0` when a site from the first row shares the port on that Node |

- Caddy accepts a wildcard and a specific listener on one port. A connection to the specific address reaches only the sites bound to it. Connections to every other address reach the wildcard sites. A run on Caddy 2.9.1 and 2.11.4 showed this behavior; no official Caddy 2.9.0 image exists.
- Because of that rule, a WireGuard-only site must not share a port with a site from the first row. The first-row site would be unreachable over WireGuard. The build fails on such a Node and names both sites. A WireGuard-only site may share a port with public Ingress sites, so `gateway.orbit` stays on the WireGuard address and never joins the public listener. Unix socket listeners keep their own addresses.
- Service metrics is a site source. It renders the WireGuard scrape site on a selected Ingress Node. Collection is on through Orbit's global block, as ADR 0139 decides. Service metrics does not read Caddy files from the Node or restore a Caddy snapshot. When its lifecycle fails, it restores its stored state and requests a build.

### The Gateway machine is a Node like the others

- The Gateway's Node gets the same build. The `gateway` site source renders the Gateway web site from the Gateway's configuration and its Node row. The Metrics site source renders `metrics.orbit` on the Node that holds the `gateway` role.
- The transport is the only difference. When the build targets the Node that runs the Gateway process, it runs the same push script through local `sudo`. For every other Node it uses SSH.
- `/etc/caddy/orbit.d` is not read. The Gateway web converger stops writing Caddy files and requests a build.

### Every Node that runs Caddy installs it the same way

- Every Node with a Caddy site source installs Caddy through `CaddyPackageSourceProgram` before its first build. The `gateway`, `ingress`, `router`, `app-dev`, `app-prod`, `websocket`, and `analytics` roles list the `caddy` package, and role convergence runs the program. Gateway bootstrap and Gateway web convergence both run it on the Gateway machine. ProxyCli and Herdr observer publication run it on their Node before they request a build.
- The quickstart stops installing Caddy by hand. Gateway bootstrap installs it with the pinned key digest and fingerprint and checks the floor. On a Gateway that the old quickstart set up, the program publishes the pinned keyring and source file over the manual ones.
- The build uses only the packaged `/usr/bin/caddy` and the `caddy` systemd service. It does not use a Linuxbrew or other Caddy binary. The install step also writes the Caddy service ordering drop-in after `wg-quick@orbit`, so each Node gets it once.
- The push script checks the release of `/usr/bin/caddy` against the floor in `CaddyRelease` before it writes a version. A Node below the floor fails the build and keeps its live configuration.

### Foreign configuration is replaced, not adopted

- When the live `/etc/caddy/Caddyfile` does not start with the Orbit marker line, the first build copies it to `/etc/caddy/orbit-backups/<UTC timestamp>/` and replaces it. That covers a regular file, a symlink outside Orbit's versions, and a version in the old fragment layout, whose whole directory is copied. The package default is replaced without a backup. The build never deletes a backup.
- Sites in a replaced file stop serving. An operator moves them into Orbit first, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes).
- The public Ingress staging step and its `staged` directory are removed.
- The Incus harness stops writing Caddy files. Its `converge-app-prod-internal-tls.sh` step and the `internal-tls` step of `converge-sample-app.sh` are removed with the cutover. The first build on a snapshot Node backs up any fragment they left behind.
- Doctor reads sites from the single live Caddyfile. It reports `role.caddy_build_drift` when the live Caddyfile differs from a fresh render and lists the site sources on that Node. Repeating any command that publishes one of those sites builds the Node again.

### Effect on other decisions

- This decision supersedes [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options). No fragment is carried, so the carried global options guard is removed. Orbit still writes the only global options block.
- [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) and [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) keep their global block and per-site certificate rules. The build renders them.
- It amends [ADR 0099](/decisions/0099-collect-role-specific-service-metrics): the Node Caddy build, not a shared Caddy publisher, owns composition, validation, publication, and recovery for the scrape site.
- It extends [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source) to the Gateway machine, `ingress`, ProxyCli, and Herdr observer Nodes, and adds the release check to every build.
- It amends a consequence of [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes): unmanaged Caddy fragments do not stay in place. They are backed up and stop serving at the first build.
- The sites that [ADR 0009](/decisions/0009-clustered-app-instance-routing) and [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) describe as fragments in one composed Caddy service become sites in one rendered Caddyfile. Their ownership does not change.

## Rejected alternatives

- Keep per-role fragments under the shared lock: rejected because the lock fixes only the lost update. Publishers still carry forward unmanaged content and still decide Node-wide facts from sibling files.
- Keep a `fragments` directory, all written by the build: rejected because it keeps an `import` glob, its ordering rules, and two places to read, with no benefit once one writer owns every file.
- Adopt a foreign Caddyfile as a carried fragment: rejected because it keeps the Node's files as an input to every build, which is the problem this decision removes. A backup keeps the content without serving it.
- Save the placement candidate as a second Route row: rejected because `routes.domain` is unique and both rows would carry the same domain.
- Choose `0.0.0.0` for every site on a port whenever one site needs it: rejected because it would put `gateway.orbit` and `metrics.orbit` on the public listener of a Node that also holds `ingress`.
- Render on the Node from data the Gateway sends: rejected because the Node would need Orbit's renderers, and [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) keeps Nodes free of agents that run commands.
- Coalesce build requests in a queue: rejected because the Gateway runs infrastructure steps synchronously and has no queue. Deterministic renders make a repeated build cheap.
- Give the Gateway machine its own renderer and publisher: rejected because it keeps a second publication path with its own gaps, as the missing global block, lock, and rollback show.

## Consequences

- A Node's Caddy configuration is reproducible from the Gateway database. Doctor can compare the live file with a fresh render, and Gateway recovery restores Caddy by building every Node.
- Publishers get simpler: they commit state and request a build. Error codes stay the same for API and CLI callers.
- Operators lose hand-placed Caddy sites on Orbit Nodes. Before the first build on a Node, each live unmanaged site must move into Orbit or it stops serving. The backup keeps the content.
- Every build renders every site on the Node, so a Node with many sites runs more database queries and a larger `caddy validate` per change. Unchanged renders skip validation and reload.
- A failing site source blocks every Caddy change on that Node until it is fixed. Today only that publisher's fragment is blocked.
- Role combinations that put a WireGuard-only site and a first-row site on one port, such as `gateway` with `router`, fail the build. Those combinations already leave the first-row sites unreachable over WireGuard today.
- `websocket`, `analytics`, ProxyCli, and Herdr sites that share port 443 with first-row sites bind `0.0.0.0`, as they do today. On a Node that also holds `ingress`, they answer on the public listener for their own hostname.
- Route transitions need schema changes: a stored publication record on each Route and the two transition placement columns.
- Every Node with Caddy sites, the Gateway machine included, installs Caddy from the pinned source. A Gateway that still runs another Caddy upgrades on its next bootstrap or web convergence. A Node that serves Herdr observers through a Linuxbrew Caddy switches to the packaged service.
- Migration is ordered. The stored transition state and every site source must exist before any publisher uses the build, because a build replaces every site it cannot render. All publishers and the Incus harness then switch to the build in one change.

## Affects

- Components: apps/gateway, apps/e2e, apps/docs
- ADRs: supersedes [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options); amends [ADR 0099](/decisions/0099-collect-role-specific-service-metrics) and [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes); extends [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source); keeps [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) and [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node)
- Detail: [Caddy configuration](/reference/caddy-configuration#node-caddy-build), [Node provisioning](/reference/node-provisioning#package-sources), [Service metrics](/reference/service-metrics), [Routes](/reference/routes)
- Verify: `apps/gateway` Pest tests for the renderer per site source, deterministic output, duplicate addresses, listener rules, stored transition states, and the push script against a temporary `/etc/caddy`; Incus proofs that concurrent publishers on one Node keep both sites, that each Route and Router transition step serves the expected sites, that a foreign Caddyfile is backed up and replaced, that a failed reload restores the previous version, that a WireGuard-only site and a public Ingress site share port 443 on Caddy 2.9.x, and that a rebuilt Gateway installs Caddy 2.9.0 or newer before its first build
