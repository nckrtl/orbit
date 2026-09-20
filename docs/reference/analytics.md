---
title: "Analytics role"
description: "How the analytics role runs Plausible Community Edition, where it keeps its data, and how an App instance publishes a tracking host."
---

# Analytics role

This page tells an operator how Orbit runs Plausible Community Edition for the fleet and how an App instance sends it visits. [ADR 0095](/decisions/0095-run-plausible-through-an-analytics-role) owns the role and its storage, and [ADR 0096](/decisions/0096-publish-analytics-tracking-hosts-for-app-instances) owns the public tracking host. This page describes planned behavior: neither decision is implemented yet.

## Prepare the database Processes

The analytics role owns the Plausible container only. Its data lives in two Docker Processes that you create first on an active Node with the `database` role.

| Process | Image | Holds |
| --- | --- | --- |
| PostgreSQL | `postgres:16-alpine` | Plausible accounts and site settings. |
| ClickHouse | `clickhouse/clickhouse-server:24.12-alpine` | Every tracked event. |

Both Processes publish their port on the Node's WireGuard address only. Plausible connects with the credentials in each Process's environment: `POSTGRES_USER` and `POSTGRES_PASSWORD`, and `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB`. The ClickHouse container creates that database and user itself. Give analytics a PostgreSQL Process of its own, because Plausible connects as that Process's own user. ClickHouse assumes a large server by default, so give its Process the low-resource configuration that Plausible documents when the Node is small. Plan about 2 GB of memory for the three services together, and disk that grows with traffic.

## Assign the role

`orbit node:role:add NODE analytics --postgres-process=ID --clickhouse-process=ID` assigns the role. The role is a singleton, it conflicts with `gateway`, and it combines with `database`, so one services Node can hold both.

Assignment names the two Processes by ID and refuses when one is missing, is not a Docker Process on an active database Node, or is not the supported engine. The Gateway then runs the Plausible container on the role's Node and publishes `https://analytics.orbit`.

| Step | Result |
| --- | --- |
| Connect storage | The two connection URLs, derived from the environment of the two Processes. |
| Run Plausible | One container at the pinned version, published on the Node's WireGuard address. It creates and migrates its PostgreSQL database each time it starts. |
| Publish the dashboard | An Orbit CA certificate, a Caddy site on the role's Node, and a private DNS record for `analytics.orbit`. |

The first person to open `https://analytics.orbit` registers the Plausible owner account. Orbit does not create Plausible accounts, sites, or API tokens.

## Update and remove the role

`orbit analytics:update --requested-version=VERSION` changes the pinned Plausible version and converges the container again.

`orbit node:role:remove NODE analytics` stops and removes the container, the Caddy site, the certificate, and the DNS record. It never touches the two databases and never removes the PostgreSQL or ClickHouse Process; remove those Processes yourself to remove the data. Removal refuses while an App instance still has a tracking host.

## Publish a tracking host

`orbit instance:analytics enable INSTANCE` publishes `analytics.<instance domain>` as a public Route that belongs to the App instance. The App instance needs a public domain first, and the cluster needs an active Router and Ingress, as every public Route does. `--host=HOST` names another host, and you can repeat it up to ten times.

The host answers two paths and nothing else.

| Path | Goes to |
| --- | --- |
| `/js/*` | The Plausible tracking script. |
| `/api/event` | The Plausible event endpoint. |
| Every other path | 404, so the dashboard never becomes public. |

`orbit instance:analytics show INSTANCE` returns each host with its script URL, its event URL, and the DNS record to create. `orbit instance:analytics disable INSTANCE` removes the hosts. You still create the site in Plausible and add the script tag to the App yourself.

## Verify a tracking host

`orbit instance:analytics verify INSTANCE` runs on your machine and never through the Gateway, as `orbit profile` does. For each host it resolves the name in public DNS, expects 200 from `https://HOST/js/script.js`, and expects 404 from `https://HOST/`.
