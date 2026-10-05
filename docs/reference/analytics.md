---
title: "Analytics role"
description: "How the analytics role runs Plausible Community Edition, where it keeps its data, and how an Instance publishes a tracking host."
covers:
  - apps/gateway/app/Domain/Analytics/**
  - apps/gateway/app/Infrastructure/Analytics/**
  - apps/gateway/app/Actions/Analytics/**
  - apps/gateway/app/Infrastructure/Nodes/Roles/AnalyticsRoleBaseline.php
  - apps/gateway/app/Actions/Routes/ConvergeAnalyticsTrackingPlacementAction.php
  - apps/gateway/resources/analytics/**
  - apps/cli/app/Commands/Instances/*InstanceAnalyticsCommand.php
---

# Analytics role

The `analytics` role runs Plausible Community Edition for the fleet. An Instance sends visits to it through a tracking host. The Gateway reads the visits back for the Orbit web Instance page. [`analytics`](/cli/analytics) lists the role commands. [Instance analytics stats](/reference/instance-analytics-stats) owns the read.

## Prepare the database Processes

The role keeps its data in two Docker Processes. Create them first on an active Node with the `database` role.

| Process | Image | Holds |
| --- | --- | --- |
| PostgreSQL | `postgres:16`, any tag | Plausible accounts and site settings |
| ClickHouse | `clickhouse/clickhouse-server`, any tag | Every tracked event |

A Process needs a command. For PostgreSQL, use `postgres`. The ClickHouse image passes its own configuration file, so give it a server argument after two dashes, such as `-- --logger.level=warning`.

Publish PostgreSQL's container port 5432 and ClickHouse's HTTP port 8123 on the database Node's WireGuard address only. Orbit refuses either storage Process if its published port binds to any other address, including an empty bind address (`0.0.0.0`). The WireGuard boundary is the real threat boundary; do not expose analytics storage on a public or LAN address.

Plausible connects with the credentials in each Process's environment: `POSTGRES_USER` (default `postgres`) and `POSTGRES_PASSWORD`, and `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB`. The ClickHouse container creates that database and user. Plausible connects to PostgreSQL as that Process's own user, so give analytics its own PostgreSQL Process. Plan about 2 GB of memory for the three services, and disk that grows with traffic.

## Assign the role

Assign the role with `orbit node:role:add NODE analytics --postgres-process=ID --clickhouse-process=ID`. In a terminal, the CLI asks for a missing Process. The role is a singleton. It conflicts with `gateway` and combines with `database`, so one services Node can hold both. It cannot be assigned during `node:add`, and it cannot be relocated.

Assignment refuses a storage Process that does not fit:

| Error code | Cause |
| --- | --- |
| `analytics.postgres_process_missing`, `analytics.clickhouse_process_missing` | The Process does not exist. |
| `analytics.process_not_docker` | The Process is not a Docker Process. |
| `analytics.process_not_node` | The Process does not belong to a Node. |
| `analytics.process_not_database_node` | The Process's Node is not an active `database` Node. |
| `analytics.postgres_unsupported`, `analytics.clickhouse_unsupported` | The image is not the supported engine. |
| `analytics.storage_credentials_missing` | A required credential variable is missing from the Process environment. |
| `analytics.storage_port_missing` | The Process does not publish port 5432 or 8123. |
| `process.wireguard_ip_missing` | The Process's Node has no WireGuard address. |

The role then converges in this order.

| Step | Result |
| --- | --- |
| Configure ClickHouse | Plausible's ClickHouse files on the ClickHouse Process's Node, and their read-only mounts in that Process. See [ClickHouse configuration](#clickhouse-configuration). |
| Admit local storage | The rule `orbit:analytics-postgres-local` or `orbit:analytics-clickhouse-local` for each storage Process on the role's own Node. Storage on another Node needs no rule. |
| Run Plausible | The `plausible` Docker Process at the pinned version, published on the Node's WireGuard address. Its environment holds the two connection URLs and a stored `SECRET_KEY_BASE`. |
| Publish the dashboard | After Plausible answers `/api/health`: an Orbit CA certificate, a Caddy site on the role's Node, and a private DNS record for `analytics.orbit`. |

Plausible creates and migrates its PostgreSQL database each time it starts. The storage passwords, the two connection URLs, and `SECRET_KEY_BASE` reach Plausible through the Process environment. The API hides and log reads redact that environment, but the Gateway database stores it unencrypted. The `plausible` container reaches a storage Process on its own Node through the Docker bridge, not through WireGuard. That is why the local rule exists.

You can read the logs of `plausible` and restart it like any other Process. You cannot remove it, or either storage Process, while the role is assigned (`process.required_by_analytics`).

`https://analytics.orbit` has no Gateway check in front of Plausible. Every WireGuard peer can reach it, because WireGuard membership is the security boundary. The first person to open it registers the Plausible owner account and owns the dashboard. Open it yourself right after you assign the role. Orbit does not create Plausible accounts, sites, or API tokens. To show visits on an Instance page, create a Stats API key in Plausible and store it with `orbit analytics:credentials --set`.

## ClickHouse configuration

ClickHouse's defaults assume a large server. On a small Node, its system log tables grow and its background merges run out of memory. So each role convergence applies the four ClickHouse files that Plausible Community Edition ships, unchanged.

The role writes each file on the Node that runs the ClickHouse Process, owned by root with mode `0644`. It writes a file only when the content differs. It mounts each file read-only into the ClickHouse Process at the path that Plausible's own setup uses.

| Host file | Container path |
| --- | --- |
| `/etc/orbit/analytics/clickhouse/config.d/logs.xml` | `/etc/clickhouse-server/config.d/logs.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml` | `/etc/clickhouse-server/config.d/ipv4-only.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/low-resources.xml` | `/etc/clickhouse-server/config.d/low-resources.xml` |
| `/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml` | `/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml` |

`logs.xml` keeps only `query_log`, for 30 days, and turns off ClickHouse's other system log tables. To diagnose ClickHouse itself, use its console log and `query_log`. Tables that ClickHouse wrote earlier keep their data. Orbit does not drop them.

The role adds only the mounts that the Process lacks. It keeps the Process's ID, name, image, command, environment, ports, and other volumes. When another volume uses one of the four container paths, convergence fails with `analytics.clickhouse_mount_conflict` before it changes anything. Remove that volume first.

| What changed | What happens to ClickHouse |
| --- | --- |
| A mount was added | The Process runtime replaces the container, which reads the files as it starts. |
| Only a file changed | The Process runtime restarts the container. A stopped Process stays stopped and reads the files at its next start. |
| Nothing | ClickHouse keeps running. |

Each ClickHouse restart drops Plausible's ClickHouse connection until ClickHouse is back.

To apply the configuration to an existing install, converge the role again with the same two Processes:

```bash
orbit node:role:add NODE analytics --converge --postgres-process=ID --clickhouse-process=ID
```

A failure stops convergence at the `clickhouse-config` step, before Plausible runs, and marks the ClickHouse Process `failed`. The next convergence repeats the step and restarts that Process. Removing the role leaves the files and mounts in place.

## Update and remove the role

`orbit analytics:update VERSION` changes the pinned Plausible version and replaces the `plausible` Process.

`orbit node:role:remove NODE analytics` removes the `plausible` Process, the Caddy site, the certificate, the DNS record, and the role's firewall rules. It deletes the stored `SECRET_KEY_BASE` and the role settings. It never touches the two databases or their Processes. Remove those Processes yourself to remove the data. Orbit does not back up or prune Plausible's event data. Removal fails with `analytics.tracking_hosts_exist` while any Instance has a tracking host.

## Single-app boundary

Tracking and stats remain single-app only in this group. Enable, show and stats requests on a Project with several apps return `app.analytics_multi_app_unsupported` (HTTP 409) before deriving a domain or contacting Plausible. They accept no `app` selector: CLI/MCP schemas reject that option/argument and API requests with it return `validation.failed`. SDK analytics requests retain their Instance selector without `$app`. The sole app must have an authoritative Route; enable without it returns existing `analytics.domain_required`. There is no primary-app fallback.

Adding a second Project app is refused with `app.analytics_multi_app_unsupported` while any of its Instances owns a tracking host. Removing the tracked app's name is refused with `project.app_in_use`, even when the replacement list still has one app. Disable tracking on every affected Instance before changing the app list.

Disable is always allowed as cleanup, including a multi-app Instance with inconsistent stored tracking. It removes recorded hosts without resolving an app domain or selecting a Plausible site. It clears the stored app binding only after every tracking Route is withdrawn and deleted. Incomplete disable retains its binding and host records for identical retry; those records still block adding a second app.

### Migration and domain changes

Stored tracking configuration associates an Instance with its sole app name. Migration records `web` on existing tracking configurations, preserving tracking Route IDs, host names, publication, scope and credentials. `analytics_tracking` Route `app` remains null because it is not an application target Route; its owning Instance's tracking configuration supplies the app association. Analytics enable/show responses add `app` as the sole name; SDK `InstanceAnalyticsResponse` exposes `$app` and emits `app`. Disable returns that name for a sole app and null during multi-app cleanup. Analytics-specific `domain` is derived from `app_runtime[app].domain`, not a removed scalar Instance field.

Migration or a change to the sole app's domain updates the returned CNAME targets, snippet `data-domain` and stats site selection to that app's new authoritative domain. Existing tracking hosts remain explicit and keep their names. Orbit does not rename or copy sites or recorded events in Plausible. The operator must create the site for the new domain, update external CNAMEs and replace the snippet; until that site exists, stats return `analytics.stats_site_missing`, never another site's counts. An unchanged explicit app domain needs no new Plausible site. Tracking placement follows that app's Route replacement checkpoints and never a first Route.

### Web behavior

The web app shows tracking controls and the stats panel only for single-app Projects. For multiple apps it displays the unsupported-analytics explanation, makes no stats read and offers no enable control. If inconsistent stored hosts exist, it still offers Disable and calls only cleanup. The app editor displays the same adding-second-app guard and requires tracking to be disabled first. A race or stale view displays the API's 409 code rather than selecting an app automatically.

## Publish a tracking host

A tracking host is a Route of kind `analytics_tracking` that belongs to one Instance. It proxies only Plausible's script and event paths.

`orbit instance:analytics:enable INSTANCE` publishes `analytics.<sole-app-domain>`. The analytics role must be active (`analytics.role_missing`). Its sole app must already have an authoritative domain (`analytics.domain_required`). `--host=HOST` names another host. You can repeat it up to ten times. The command sets the exact host set, so it removes a host that you leave out. A host that another Route owns fails with `analytics.host_taken`.

A tracking Route copies the Node, Cluster and publication of the sole app's authoritative Route when created.

| The sole app's Route | Who serves the tracking host |
| --- | --- |
| Node-scoped | The Instance's own Node, behind whatever edge fronts that Node. |
| Cluster-scoped and private | The Cluster's Router. |
| Cluster-scoped and public | The Router. The Ingress forwards the host to it, as for every public Route. |

When a Cluster attach, detach, activation, or deactivation moves the Instance, the tracking host moves with it. Both placements serve the host until private DNS answers for the old placement can have expired. Then the old placement stops. A move that fails before private DNS moves leaves the host on its old placement, and a retry moves it again.

When another edge terminates public TLS for the Instance, point the tracking host at that same edge.

The host answers two paths and nothing else:

| Path | Goes to |
| --- | --- |
| `/js/*` | The Plausible tracking script |
| `/api/event` | The Plausible event endpoint |
| Every other path | 404, so the dashboard never becomes public |

The serving Node reaches Plausible over WireGuard. While the analytics role converges, the host keeps its Caddy site and its private DNS record. A tracking Route has no target and no upstream of its own. `route:update`, `route:target:set`, and `route:target:unset` refuse it with `route.kind_unsupported`, and `route:create` cannot create one. `route:destroy` refuses direct removal with `route.tracking_managed`; disable analytics on the Instance instead. If the Route kind is not valid for the analytics removal path, the Gateway returns `route.kind_invalid`.

`orbit instance:analytics:show INSTANCE` returns each host with its Route, script URL, event URL, and the DNS record to create. The Gateway knows no public address. The record is a `CNAME` from the tracking host to the sole app's authoritative domain. The answer carries the script tag for the first tracking host, not a first application Route; its `data-domain` is that same sole app domain. `orbit instance:analytics:disable INSTANCE` removes the hosts. You still create the site in Plausible and add the script tag to the Project.

An Instance with a tracking host cannot be removed (`analytics.tracking_hosts_exist`). Disable its analytics first.

## Instance page panel

The Orbit web Instance page shows live visitors, visitors for Plausible's day, 7 days, and 30 days, and the top ten pages. It shows the panel only for a single-app Project when the analytics role is active and the Instance has a tracking host. The Plausible site is that app's authoritative domain from `app_runtime`, not a scalar Instance domain. When the Gateway cannot read the Stats API, the panel says so and shows no counts. See [Instance analytics stats](/reference/instance-analytics-stats).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Storage on database Nodes

Database servers belong on `database` Nodes, where the operator sizes, inspects, and backs them up. So the role names two Processes that the operator created, by ID. A Node can run several PostgreSQL Processes, so naming only a Node would be ambiguous. The role never creates a database server itself.

### No database provisioners

Plausible creates and migrates its own PostgreSQL database. The ClickHouse image creates its own database and user from its environment. So Orbit adds no PostgreSQL or ClickHouse administration.

### Plausible as a Process

The Process runtime already converges a Docker container on a Node, with logs, restarts, and Doctor checks. A second container runtime, Compose, or an Instance would duplicate that work.

### Plausible's own ClickHouse files

Without them, ClickHouse ran a merge loop on a small Node. Orbit uses Plausible's tested files unchanged, instead of its own variant. It mounts single files, because the ClickHouse image keeps its own files in `config.d` and `users.d`.

### A dedicated Route kind with two paths

Browsers must reach Plausible's script and event endpoints from the public internet, but they do not need the dashboard or the rest of its API. A dedicated Route kind derives its upstream from the active analytics role and exposes only `/js/*` and `/api/event`. Every other path returns 404. This fixed surface is small to verify and keeps the dashboard private.

General path proxying on any Route is rejected, because nothing else needs it and it would broaden the [custom proxy upstream rules](/reference/routes#custom-proxy-routes). A reserved prefix on the Instance's own site is also rejected, because it puts fleet infrastructure inside every Project's site, can collide with the Project's paths, and makes tracking depend on the Instance's runtime being awake. Publishing `analytics.orbit` is rejected, because it would expose the whole fleet's dashboard and login.

### A tracking host that mirrors the Instance's Route

The fleet does not have to own its public edge. A tracking host mirrors the sole app's authoritative Route, including its scope and publication, so it works behind a CDN or another proxy without changing how the Instance is served. Always making tracking hosts public and Cluster-scoped is rejected, because it would demand an Ingress and a Cluster that the fleet does not otherwise need. A host under the sole app's domain also keeps requests first-party, so content blockers that list Plausible's domains do not drop them.

### The operator owns the Plausible site

Orbit publishes the tracking endpoint, but the operator creates the site in Plausible and adds the script to the Project. Creating Plausible accounts or sites and injecting markup are rejected, because Orbit does not manage Plausible accounts and the Project owns its markup.

### Stats read on the Gateway

The browser never holds the Stats API key, and it cannot ask for another Instance's site. One Gateway-scoped key serves every Instance, because Plausible scopes each read by site domain. A failed read never shows zeros, because a missing key must not look like an empty site.
