---
title: "Analytics role"
description: "How the analytics role runs Plausible Community Edition, where it keeps its data, and how an Instance publishes a tracking host."
---

# Analytics role

This page tells an operator how Orbit runs Plausible Community Edition for the fleet, how an Instance sends it visits, and how the Gateway reads those visits for the Orbit web page. [ADR 0096](/decisions/0096-run-plausible-through-an-analytics-role) owns the role and its storage, [ADR 0097](/decisions/0097-publish-analytics-tracking-hosts-for-app-instances) owns the public tracking host, and [ADR 0102](/decisions/0102-read-app-instance-analytics-through-a-fleet-driver) owns the stats driver and the Instance panel. 

## Prepare the database Processes

The analytics role owns one Docker Process named `plausible` on its own Node, and you can read its logs and restart it like any other Process. Its data lives in two Docker Processes that you create first on an active Node with the `database` role.

| Process | Image | Holds |
| --- | --- | --- |
| PostgreSQL | `postgres:16-alpine` | Plausible accounts and site settings. |
| ClickHouse | `clickhouse/clickhouse-server:24.12-alpine` | Every tracked event. |

A Process needs a command. For PostgreSQL use `postgres`. The ClickHouse image already passes its own configuration file, so give it a server argument that starts with two dashes, such as `-- --logger.level=warning`; `--config-file` makes it restart forever.

Both Processes publish their port on the Node's WireGuard address only. Plausible connects with the credentials in each Process's environment: `POSTGRES_USER` and `POSTGRES_PASSWORD`, and `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB`. The ClickHouse container creates that database and user itself. Give analytics a PostgreSQL Process of its own, because Plausible connects as that Process's own user. The role applies Plausible's own ClickHouse configuration to the ClickHouse Process, as [ClickHouse configuration](#clickhouse-configuration) describes. Plan about 2 GB of memory for the three services together, and disk that grows with traffic.

## Assign the role

`orbit node:role:add NODE analytics --postgres-process=ID --clickhouse-process=ID` assigns the role. The role is a singleton, it conflicts with `gateway`, and it combines with `database`, so one services Node can hold both.

When a storage Process runs on the role's own Node, the role adds a firewall rule that admits the Docker bridge to its published port, because the `plausible` container reaches it through that bridge and not through WireGuard. Storage on another Node needs no rule.

Assignment names the two Processes by ID and refuses when one is missing, is not a Docker Process on an active database Node, or is not the supported engine. The Gateway then runs the Plausible container on the role's Node and publishes `https://analytics.orbit`.

| Step | Result |
| --- | --- |
| Configure ClickHouse | Plausible's ClickHouse files on the ClickHouse Process's Node and their read-only mounts in that Process. |
| Connect storage | The two connection URLs, derived from the environment of the two Processes. |
| Admit local storage | One `orbit:analytics-*-local` firewall rule for each storage Process on the same Node. |
| Run Plausible | The `plausible` Process at the pinned version, published on the Node's WireGuard address. It creates and migrates its PostgreSQL database each time it starts. |
| Publish the dashboard | Only after Plausible answers `/api/health`: an Orbit CA certificate, a Caddy site on the role's Node, and a private DNS record for `analytics.orbit`. |

The first person to open `https://analytics.orbit` registers the Plausible owner account. Orbit does not create Plausible accounts, sites, or API tokens. To show visits on an Instance page, create a Stats API key in that Plausible account and store it with `orbit analytics:credentials --set`. The Gateway keeps the key as a protected setting and never returns it. `orbit analytics:credentials` reports only whether a key is stored. [Instance analytics stats](/reference/instance-analytics-stats) owns the read.

## ClickHouse configuration

ClickHouse's defaults assume a large server. On a small Node its own system log tables grow and its background merges run out of memory. Each role converge therefore applies the four ClickHouse files that Plausible Community Edition ships, unchanged. [ADR 0142](/decisions/0142-apply-plausibles-clickhouse-configuration-from-the-analytics-role) owns this step.

The role writes each file on the Node that runs the ClickHouse Process. Each file is root-owned with mode `0644`. The role mounts each file read-only into the ClickHouse Process at the path that Plausible's own setup uses.

| Host file | Container path |
| --- | --- |
| `/etc/orbit/analytics/clickhouse/config.d/logs.xml` | `/etc/clickhouse-server/config.d/logs.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml` | `/etc/clickhouse-server/config.d/ipv4-only.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/low-resources.xml` | `/etc/clickhouse-server/config.d/low-resources.xml` |
| `/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml` | `/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml` |

`logs.xml` keeps only `query_log`, for 30 days, and removes ClickHouse's other system log tables. Tables that ClickHouse wrote before keep their data; Orbit does not drop them.

The role adds only the mounts that the Process lacks. It keeps the Process's ID, name, image, command, environment, ports, and other volumes. When another volume already uses one of the four container paths, the converge fails with `analytics.clickhouse_mount_conflict`. Remove that volume first.

| What changed | What happens to ClickHouse |
| --- | --- |
| A mount was added | The Process runtime replaces the container, which reads the files as it starts. |
| Only a file changed | The Process runtime restarts the container. |
| Nothing | ClickHouse keeps running. |
| The Process is stopped | It stays stopped and reads the files at its next start. |

To apply the configuration to an existing install, converge the role again with the same two Processes. The command asks for them when you leave them out in an interactive terminal.

```bash
orbit node:role:add NODE analytics --converge --postgres-process=ID --clickhouse-process=ID
```

A failure stops the converge at the `clickhouse-config` step, before Plausible runs. The next converge repeats the step and restarts a ClickHouse Process that the failure left `failed`. Removing the role leaves the files and mounts in place.

## Update and remove the role

`orbit analytics:update VERSION` changes the pinned Plausible version and replaces the `plausible` Process.

`orbit node:role:remove NODE analytics` removes the `plausible` Process, the Caddy site, the certificate, and the DNS record. It never touches the two databases and never removes the PostgreSQL or ClickHouse Process; remove those Processes yourself to remove the data. Removal refuses while an Instance still has a tracking host.

## Publish a tracking host

`orbit instance:analytics:enable INSTANCE` publishes `analytics.<instance domain>` as a Route that belongs to the Instance. The Instance must already serve a domain. `--host=HOST` names another host, and you can repeat it up to ten times. The command sets the exact host set, so a host you leave out is removed.

A tracking host is served wherever the Instance's own domain is served, because its Route mirrors that Route's scope and publication.

| The Instance's Route | The tracking host |
| --- | --- |
| Cluster-scoped and public | Public too: the Ingress forwards it to the Router, as for every public Route. |
| Node-scoped or private | The same: the Instance's own Node serves it, behind whatever edge already fronts that Node. |

When a Cluster attach, detach, activation, or deactivation moves the Instance's Route, the tracking host moves with it. Both placements serve the host until private DNS answers for the old placement can have expired, then the old placement stops serving. A move that fails before private DNS moves leaves the host on its old placement, and a retry moves it again.

When something other than Orbit terminates the public TLS, point the tracking host at the same edge as the Instance's domain, and let that edge reach the Node the same way.

The host answers two paths and nothing else.

| Path | Goes to |
| --- | --- |
| `/js/*` | The Plausible tracking script. |
| `/api/event` | The Plausible event endpoint. |
| Every other path | 404, so the dashboard never becomes public. |

The Router serves the host and reaches Plausible over WireGuard; the Ingress forwards the host to the Router as it does for every public Route. While the analytics role converges, the host keeps its Caddy site and its private DNS record. A tracking Route has no target and no upstream of its own, and the generic `route:*` commands refuse to create or change one.

`orbit instance:analytics:show INSTANCE` returns each host with its Route, its script URL, its event URL, and the DNS record to create. The Gateway knows no public address, so the record is a `CNAME` from the tracking host to the Instance's own domain, which already resolves to your Ingress. The answer also carries the script tag for the Project. `orbit instance:analytics:disable INSTANCE` removes the hosts. You still create the site in Plausible and add the script tag to the Project yourself.

The Orbit web Instance page shows live visitors, visitors for the past day, 7 days, and 30 days, and the top ten pages when the analytics role is active and this Instance has a tracking host. The site is the Instance's own domain, the same `data-domain` as the script tag. If the Gateway cannot read the Stats API, the panel says so and shows no counts. See [Instance analytics stats](/reference/instance-analytics-stats).

Removing the analytics role refuses with `analytics.tracking_hosts_exist` while an Instance still has a tracking host.

## Verify a tracking host

`orbit instance:analytics:verify INSTANCE` is planned and not implemented yet. It runs on your machine and never through the Gateway, as `orbit profile` does. For each host it resolves the name in public DNS, expects 200 from `https://HOST/js/script.js`, and expects 404 from `https://HOST/`.
