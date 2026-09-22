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

Both Processes publish their port on the Node's WireGuard address only. Plausible connects with the credentials in each Process's environment: `POSTGRES_USER` and `POSTGRES_PASSWORD`, and `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB`. The ClickHouse container creates that database and user itself. Give analytics a PostgreSQL Process of its own, because Plausible connects as that Process's own user. ClickHouse assumes a large server by default, so give its Process the low-resource configuration that Plausible documents when the Node is small. Plan about 2 GB of memory for the three services together, and disk that grows with traffic.

## Assign the role

`orbit node:role:add NODE analytics --postgres-process=ID --clickhouse-process=ID` assigns the role. The role is a singleton, it conflicts with `gateway`, and it combines with `database`, so one services Node can hold both.

When a storage Process runs on the role's own Node, the role adds a firewall rule that admits the Docker bridge to its published port, because the `plausible` container reaches it through that bridge and not through WireGuard. Storage on another Node needs no rule.

Assignment names the two Processes by ID and refuses when one is missing, is not a Docker Process on an active database Node, or is not the supported engine. The Gateway then runs the Plausible container on the role's Node and publishes `https://analytics.orbit`.

| Step | Result |
| --- | --- |
| Connect storage | The two connection URLs, derived from the environment of the two Processes. |
| Admit local storage | One `orbit:analytics-*-local` firewall rule for each storage Process on the same Node. |
| Run Plausible | The `plausible` Process at the pinned version, published on the Node's WireGuard address. It creates and migrates its PostgreSQL database each time it starts. |
| Publish the dashboard | Only after Plausible answers `/api/health`: an Orbit CA certificate, a Caddy site on the role's Node, and a private DNS record for `analytics.orbit`. |

The first person to open `https://analytics.orbit` registers the Plausible owner account. Orbit does not create Plausible accounts, sites, or API tokens. To show visits on an Instance page, create a Stats API key in that Plausible account and store it with `orbit analytics:credentials --set`. The Gateway keeps the key as a protected setting and never returns it. `orbit analytics:credentials` reports only whether a key is stored. [Instance analytics stats](/reference/instance-analytics-stats) owns the read.

## Update and remove the role

`orbit analytics:update VERSION` changes the pinned Plausible version and replaces the `plausible` Process.

`orbit node:role:remove NODE analytics` removes the `plausible` Process, the Caddy site, the certificate, and the DNS record. It never touches the two databases and never removes the PostgreSQL or ClickHouse Process; remove those Processes yourself to remove the data. Removal refuses while an Instance still has a tracking host.

## Publish a tracking host

`orbit instance:analytics:enable INSTANCE` publishes `analytics.<instance domain>` as a Route that belongs to the Instance. The Instance must already serve a domain. `--host=HOST` names another host, and you can repeat it up to ten times. The command sets the exact host set, so a host you leave out is removed.

Omit `--host` to use the default host. An explicit empty or whitespace-only host is invalid, including within a list of valid hosts. The CLI sends every supplied host unchanged for Gateway validation; it does not discard an invalid entry or replace it with the default.

A tracking host is served wherever the Instance's own domain is served, because its Route mirrors that Route's scope and publication.

| The Instance's Route | The tracking host |
| --- | --- |
| Cluster-scoped and public | Public too: the Ingress forwards it to the Router, as for every public Route. |
| Node-scoped or private | The same: the Instance's own Node serves it, behind whatever edge already fronts that Node. |

When something other than Orbit terminates the public TLS, point the tracking host at the same edge as the Instance's domain, and let that edge reach the Node the same way.

The host answers two paths and nothing else.

| Path | Goes to |
| --- | --- |
| `/js/*` | The Plausible tracking script. |
| `/api/event` | The Plausible event endpoint. |
| Every other path | 404, so the dashboard never becomes public. |

The Router serves the host and reaches Plausible over WireGuard; the Ingress forwards the host to the Router as it does for every public Route. A tracking Route has no target and no upstream of its own, and the generic `route:*` commands refuse to create or change one.

`orbit instance:analytics:show INSTANCE` returns each host with its Route, its script URL, its event URL, and the DNS record to create. The Gateway knows no public address, so the record is a `CNAME` from the tracking host to the Instance's own domain, which already resolves to your Ingress. The answer also carries the script tag for the Project. `orbit instance:analytics:disable INSTANCE` removes the hosts. You still create the site in Plausible and add the script tag to the Project yourself.

Disabling tracking requires confirmation, which defaults to No. Use `--yes` for noninteractive or JSON calls. The CLI keeps the selected Gateway fixed while confirmation waits, even if another command changes the active Gateway profile.

The Orbit web Instance page shows live visitors, visitors for the past day, 7 days, and 30 days, and the top ten pages when the analytics role is active and this Instance has a tracking host. The site is the Instance's own domain, the same `data-domain` as the script tag. If the Gateway cannot read the Stats API, the panel says so and shows no counts. See [Instance analytics stats](/reference/instance-analytics-stats).

Removing the analytics role refuses with `analytics.tracking_hosts_exist` while an Instance still has a tracking host.

## Verify a tracking host

`orbit instance:analytics:verify INSTANCE` is planned and not implemented yet. It runs on your machine and never through the Gateway, as `orbit profile` does. For each host it resolves the name in public DNS, expects 200 from `https://HOST/js/script.js`, and expects 404 from `https://HOST/`.
