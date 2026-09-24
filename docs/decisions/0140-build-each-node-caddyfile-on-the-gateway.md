---
title: "ADR 0140: Build each Node's Caddyfile on the Gateway"
sidebarTitle: "0140 Build each Node's Caddyfile on the Gateway"
description: "Proposed. The Gateway renders a Node's whole Caddyfile from its database in one build and pushes it atomically. Role publishers change stored state and request a build; they do not write Caddy files. A non-Orbit Caddyfile is backed up and replaced. Supersedes ADR 0137."
---

# ADR 0140: Build each Node's Caddyfile on the Gateway

The Gateway renders the complete Caddyfile for one Node from stored state in one step: Orbit's global options block, then every site of every role on that Node. It pushes that file atomically: write a new version, validate it, swap the live symlink, reload Caddy, and restore the previous version on failure. Role publishers stop writing Caddy files. They change stored state and request a Node Caddy build. The Gateway serializes builds for one Node. Nothing on the Node survives a build unless the Gateway rendered it.

## Status

Proposed.

## Context

Ten publishers write Caddy configuration today: `app-dev`, `app-prod`, service metrics, Herdr observers, Metrics, `websocket`, ProxyCli, `analytics`, the Gateway web converger, and the public Ingress staging step. Each one reads the live version under `/etc/caddy/orbit-versions`, copies every fragment it does not own, writes its own fragment, validates, swaps `/etc/caddy/Caddyfile`, and reloads. [Caddy configuration](/reference/caddy-configuration) describes that layout.

That read-modify-write has three structural problems:

- Two publishers that read the same live version at the same time lose one update. The publishers use two different lock files, and the Gateway web converger takes none. [PR #639](https://github.com/nckrtl/orbit/pull/639) proposes one shared lock.
- The Node's live files are an input. A copied fragment carries forward whatever it holds: an adopted `00-unmanaged.caddy`, a hand-placed site, or a stale site from an earlier release. [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) added a shell guard for carried global blocks. The service metrics fragment then broke every publication on `app-prod` with its own global block, until [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) moved `metrics { per_host }` into Orbit's block.
- Each script decides Node-wide facts from partial knowledge. The `analytics` and ProxyCli scripts copy `bind 0.0.0.0` when a sibling fragment has it; the `websocket` script prefers the WireGuard address when a sibling binds it. Nothing checks that choice again when another publisher then adds a wildcard site.

A code survey of the current Gateway found these further inputs that do not come from stored state or that a whole-Node render must replace:

1. The `app-dev` site list takes four call arguments. `pendingRoute` forces in a saved Route that is still `pending`. `unavailableInstance` adds the "unavailable" site while an Instance removal runs; the Route and its open `AppInstanceRemovalMember` row are saved, but the stored path suppresses the site while that row exists. `routerOverrides` renders the candidate Router, whose `router` role row is saved as `provisioning`; the Ingress site ignores it and keeps the old Router. `additionalRoute` adds the hostname-change sites; for a domain change the replacement Route is already saved, but for a placement change the candidate is an unsaved copy of the Route with a new Node and Cluster.
2. The `bind` choice described above.
3. Service metrics snapshots the live `00-metrics-service.caddy` from the Node and republishes it when another step of its lifecycle fails.
4. The Gateway web converger runs local `sudo` steps on the Gateway machine. It renders `gateway.caddy` from bootstrap input, writes no global block, takes no lock, does not roll back a failed reload, and copies `/etc/caddy/orbit.d/*.caddy` when no versioned layout exists. No Orbit code writes `/etc/caddy/orbit.d`. The Gateway machine has a Node row with the `gateway` and `vpn` roles; `metrics.orbit` is published on its Caddy, and the `websocket` role may also run there.
5. The public Ingress step writes `/etc/caddy/orbit-versions/staged/route-<id>-ingress.caddy`. Nothing imports it. The live Ingress site comes from the `app-dev` render.
6. Only the Herdr observer script bundles non-Caddy work with its fragment: the observer systemd unit and its certificate. The `app-dev` script also creates the hibernation marker and log directories. The Caddy service ordering drop-in after `wg-quick@orbit` is a separate command from `app-dev`, `app-prod`, and the Gateway converger. PHP-FPM pools, dnsmasq, ufw, and the FPM exporter are separate steps of their roles.
7. The Gateway machine never installs Caddy through `CaddyPackageSourceProgram`. The `gateway` role has no `caddy` package, and bootstrap assumes Caddy is present. A rebuilt Gateway therefore gets the Ubuntu archive's Caddy 2.6.2. That release rejects `metrics { per_host }` in Orbit's global block and is below the 2.9.0 floor from [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation).

## Decision

The Gateway owns every Caddy file on a Node through one build per Node. The rules below cover the build, publishers, transitions, Node-wide choices, the Gateway machine, Caddy installation, and foreign configuration.

### One build per Node

- The Gateway owns a Node Caddy build. A build reads stored state for one Node and renders one Caddyfile: Orbit's global options block from `CaddyGlobalOptions`, then the sites of every site source that applies to that Node. The site sources are `gateway`, Metrics, service metrics, Router and workload sites for `app-dev` and `app-prod` (including custom proxy Routes, analytics tracking hosts, Agentation, and Vite), public Ingress, `websocket`, `analytics`, ProxyCli, and Herdr observers.
- The render is deterministic. The same stored state gives the same bytes. The version name is derived from a digest of the file, so an unchanged render is a no-op that neither writes nor reloads.
- The Gateway pushes the file with one root script: take the Node lock, check the Caddy release, back up a foreign Caddyfile, write `/etc/caddy/orbit-versions/<version>/Caddyfile`, run `caddy validate` as the `caddy` user, swap `/etc/caddy/Caddyfile` to the new version, enable and reload Caddy, and restore the previous target when the reload fails. It keeps the live version and the nine newest others and removes older versions.
- A version holds one file. There is no `fragments` directory, no `import`, and nothing copied from the previous version.
- The Gateway serializes builds for one Node with a Gateway lock per Node. A build waits up to 30 seconds for that lock and reads stored state only after it holds it, so the last build always reflects the latest state. The Node keeps `/run/lock/orbit/caddy.lock` around the push as a second guard.
- A build has no partial result. When any site source cannot render, or validation or reload fails, the live Caddyfile and Caddy stay as they were. The calling operation keeps its current error code. The error details name the Node, the failed build stage, and Caddy's message, and the activity record keeps them.

### Publishers change state, then request a build

- A publisher saves the state change, publishes any certificate or other files the new sites need, and then requests a build for each affected Node. Removal runs in the opposite order: save the state change, build, then remove the certificate and other files.
- Certificates stay a separate step. It must finish before the build, because `caddy validate` loads every certificate file the Caddyfile names.
- Non-Caddy work stays with its role: PHP-FPM pools, dnsmasq, ufw, the FPM exporter and its unit, the `orbit-websocket` unit, and the Herdr observer unit and certificate. The `app-dev` role creates the hibernation marker and log directories before it requests a build.

### Transitions are stored state

- A Route stores whether its sites are published. Creation sets that record before the first build, so the build renders a `pending` Route that is being created. Removal and a failed creation clear it before the build that withdraws the sites. The Route row is deleted after that build.
- The build renders the "unavailable" site for a Route whose targets are gone while an open `AppInstanceRemovalMember` row names the departing Instance.
- A Router replacement renders from the candidate `router` role row and the replacement step the Cluster already records. Router sites move to the candidate Node, and the Ingress upstream follows the same step.
- A placement change saves its candidate as a `pending` replacement Route with the new Node and Cluster, in the same way a domain change does. The build renders hostname-change sites from saved replacement Routes only.

### Node-wide choices come from the full site list

- The build chooses each listener's `bind` address from all sites on that Node and port. When any site on a port needs `0.0.0.0`, every site on that port binds `0.0.0.0`. Otherwise sites bind the Node's WireGuard address. Unix socket listeners keep their own addresses.
- Service metrics is a site source. It renders the WireGuard scrape site on a selected Ingress Node. Collection is on through Orbit's global block, as ADR 0139 decides. Service metrics does not read Caddy files from the Node or restore a Caddy snapshot. When its lifecycle fails, it restores its stored state and requests a build.

### The Gateway machine is a Node like the others

- The Gateway's Node gets the same build. The `gateway` site source renders the Gateway web site from the Gateway's configuration and its Node row. The Metrics site source renders `metrics.orbit` on the Node that holds the `gateway` role.
- The transport is the only difference. When the build targets the Node that runs the Gateway process, it runs the same push script through local `sudo`. For every other Node it uses SSH.
- `/etc/caddy/orbit.d` is not read. The Gateway web converger stops writing Caddy files and requests a build.

### Every Node that runs Caddy installs it the same way

- Every role with a Caddy site source lists the `caddy` package, including `gateway`. Role convergence and Gateway bootstrap run `CaddyPackageSourceProgram` before the first build. The Caddy service ordering drop-in after `wg-quick@orbit` belongs to that install step, so it is set once per Node.
- The push script checks the installed Caddy release against the floor in `CaddyRelease` before it writes a version. A Node below the floor fails the build and keeps its live configuration.

### Foreign configuration is replaced, not adopted

- A build starts its file with a fixed Orbit marker line. When the live `/etc/caddy/Caddyfile` does not start with that line, the first build copies it to `/etc/caddy/orbit-backups/<UTC timestamp>/` and replaces it. That covers a regular file, a symlink outside Orbit's versions, and a version in the old fragment layout, whose whole directory is copied. The package default is replaced without a backup. The build never deletes a backup.
- Sites in a replaced file stop serving. An operator moves them into Orbit first, for example as a [custom proxy Route](/reference/routes#custom-proxy-routes).
- The public Ingress staging step and its `staged` directory are removed.
- Doctor reads sites from the single live Caddyfile. It reports `role.caddy_build_drift` when the live Caddyfile differs from a fresh render. Converging any role on that Node with Caddy sites requests a build and repairs the drift.

### Effect on other decisions

- This decision supersedes [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options). No fragment is carried, so the carried global options guard is removed. Orbit still writes the only global options block.
- [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) and [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) keep their global block and per-site certificate rules. The build renders them.
- It amends [ADR 0099](/decisions/0099-collect-role-specific-service-metrics): the Node Caddy build, not a shared Caddy publisher, owns composition, validation, publication, and recovery for the scrape site.
- It extends [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source) to the Gateway machine and adds the release check to every build.
- It amends a consequence of [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes): Orbit no longer leaves unmanaged Caddy fragments in place. They are backed up and stop serving at the first build.
- The sites that [ADR 0009](/decisions/0009-clustered-app-instance-routing) and [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) describe as fragments in one composed Caddy service become sites in one rendered Caddyfile. Their ownership does not change.

## Rejected alternatives

- Keep per-role fragments with one shared lock: rejected because the lock removes only the race. Publishers still carry forward unmanaged content and still decide Node-wide facts from sibling files.
- Keep a `fragments` directory, all written by the build: rejected because it keeps an `import` glob, its ordering rules, and two places to read, with no benefit once one writer owns every file.
- Adopt a foreign Caddyfile as a carried fragment: rejected because it keeps the Node's files as an input to every build, which is the problem this decision removes. A backup keeps the content without serving it.
- Render on the Node from data the Gateway sends: rejected because the Node would need Orbit's renderers, and [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) keeps Nodes free of agents that run commands.
- Coalesce build requests in a queue: rejected because the Gateway runs infrastructure steps synchronously and has no queue. Deterministic renders make a repeated build a cheap no-op.
- Give the Gateway machine its own renderer and publisher: rejected because it keeps a second publication path with its own gaps, as the missing global block and rollback show.

## Consequences

- Concurrent publishers cannot lose each other's sites. The last build on a Node always reflects stored state.
- A Node's Caddy configuration is reproducible from the Gateway database. Doctor can compare the live file with a fresh render, and Gateway recovery restores Caddy by building every Node.
- Publishers get simpler: they save state and request a build. Error codes stay the same for API and CLI callers.
- Operators lose hand-placed Caddy sites on Orbit Nodes. Before the first build on a Node, each live unmanaged site must move into Orbit or it stops serving. The backup keeps the content.
- Every build renders every site on the Node, so a Node with many sites runs more database queries and a larger `caddy validate` per change. Unchanged renders skip validation and reload.
- A failing site source blocks every Caddy change on that Node until it is fixed. Before, only that publisher's fragment was blocked.
- Route transitions need small schema changes: a stored publication record on each Route and a saved placement-change candidate.
- The Gateway machine installs Caddy from the pinned source. A Gateway that still runs the archive package upgrades on its next bootstrap or web converge.
- Migration is ordered. The stored transition state and every site source must exist before any publisher uses the build, because a build replaces every site it cannot render. All publishers then switch to the build in one change.

## Affects

- Components: apps/gateway, apps/e2e, apps/docs
- ADRs: supersedes [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options); amends [ADR 0099](/decisions/0099-collect-role-specific-service-metrics) and [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes); extends [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source); keeps [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) and [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node)
- Detail: [Caddy configuration](/reference/caddy-configuration), [Node provisioning](/reference/node-provisioning#package-sources), [Service metrics](/reference/service-metrics), [Routes](/reference/routes)
- Verify: `apps/gateway` Pest tests for the renderer per site source, deterministic output, bind selection, stored transition states, and the push script against a temporary `/etc/caddy`; Incus proofs that concurrent publishers on one Node keep both sites, that a foreign Caddyfile is backed up and replaced, that a failed reload restores the previous version, and that a rebuilt Gateway installs Caddy 2.9.0 or newer before its first build
