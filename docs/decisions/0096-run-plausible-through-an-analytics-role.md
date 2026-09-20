---
title: "ADR 0096: Run Plausible through an analytics role"
sidebarTitle: "0096 Run Plausible through an analytics role"
description: "Proposed. Plausible Community Edition runs as a singleton analytics role that owns one container and keeps its data in PostgreSQL and ClickHouse Processes on a database Node."
---

# ADR 0096: Run Plausible through an analytics role

The Gateway runs Plausible Community Edition itself, as a new singleton `analytics` node role. The role owns the Plausible container and nothing else: its PostgreSQL and ClickHouse servers are ordinary Docker Processes on a Node with the `database` role, and Plausible connects to them with the credentials those Processes already carry.

## Status

Proposed.

## Context

An operator wants privacy-friendly web analytics for the Apps that Orbit hosts, without sending visitor data to a third party and without hand-building the stack. Plausible Community Edition needs three services: the Plausible application, PostgreSQL for accounts and site settings, and ClickHouse for events.

Orbit already has a shape for fleet infrastructure that a Node owns and the Gateway operates directly. The `metrics` role ([ADR 0003](/decisions/0003-singleton-metrics-role)) and the `websocket` role ([ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)) are both singleton, mutable roles that converge a service, keep generated credentials as protected settings, and publish a reserved private hostname under the Orbit CA. Plausible fits that shape: it is one always-on service for the whole fleet, not an App with releases.

The `database` role is a Docker baseline that owns no containers ([ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline)). A database server is a Node-targeted Docker Process ([ADR 0069](/decisions/0069-allow-node-process-targets)) that the operator creates, and Orbit provisions users inside a managed MySQL Process today. Database servers belong on database Nodes, where an operator expects to find, back up, and size them. An earlier Orbit design let the analytics role name only a database Node. With two PostgreSQL Processes on that Node it had no way to choose one, and it needed an ambiguity error, a backfill migration, and a Doctor code to recover.

## Decision

- Add `RoleName::Analytics` (`analytics`) as a singleton, mutable role that conflicts with `gateway`. It combines with `database`, so one services Node can hold both roles.
- The role owns one Node-targeted Docker Process named `plausible` on its own Node ([ADR 0069](/decisions/0069-allow-node-process-targets)), running Plausible Community Edition at a pinned version. The role creates, replaces, and removes that Process through the same actions an operator uses, so Plausible gets the existing start, stop, restart, log, and status surface without a second container runtime. The Process publishes its port on the Node's WireGuard address only. An operator cannot remove the Process while the role is assigned.
- The role keeps its data in two Processes that the operator names when the role is assigned: one PostgreSQL 16 Process and one ClickHouse Process, each a Node-targeted Docker Process on an active Node with the `database` role. The role settings record the two Process IDs, never only a Node, so the choice is never ambiguous. Assignment refuses with a clear error when either Process is missing, is not on a database Node, or is not the supported engine; the role never creates a database server on its own.
- Plausible connects over WireGuard with the credentials each Process already carries in its environment: `POSTGRES_USER` and `POSTGRES_PASSWORD` for PostgreSQL, and `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB` for ClickHouse, whose container creates that database and user itself. Plausible creates and migrates its own PostgreSQL database each time it starts. Orbit therefore adds no PostgreSQL or ClickHouse provisioner. Removing the role never touches either database; the operator removes the Processes to remove the data.
- The one generated secret is Plausible's `SECRET_KEY_BASE`. It is stored as a protected setting so that it survives a replaced Process, and it is reused each time the role converges. The two connection URLs are derived at converge time. All three reach the container through the Process environment, which the API hides and log reads redact, as they do for every Process.
- The Gateway reserves `analytics.orbit` as a private hostname beside `gateway.orbit`, `metrics.orbit`, and `reverb.orbit`. It issues an Orbit CA leaf certificate and renders a Caddy site on the role's own Node that reverse-proxies to the local container, and private DNS answers the name with that Node's WireGuard address, as the websocket role does. Plausible has its own accounts, so the site adds no Gateway authorization in front of it.
- `analytics:update` changes the pinned Plausible version and converges the container again. Orbit does not create Plausible sites, accounts, or API tokens, and it does not inject a tracking script into an App; [ADR 0097](/decisions/0097-publish-analytics-tracking-hosts-for-app-instances) owns the public tracking host.

## Rejected alternatives

- Let the analytics role own PostgreSQL and ClickHouse on its own Node: rejected because database servers belong on database Nodes, where the operator sizes, inspects, and backs them up, and because a second home for database containers would split that responsibility.
- Record only the database Node and find the PostgreSQL Process by its image: rejected because a Node can run several PostgreSQL Processes, and the earlier design needed an ambiguity error, a backfill migration, and a Doctor code to recover from exactly that.
- Provision a dedicated database and user for Plausible inside each server: rejected because the earlier Orbit design already worked without it. A PostgreSQL Process that the operator creates for analytics has one tenant, the ClickHouse image creates its own database and user from its environment, and two new provisioners would add ClickHouse administration to Orbit for no gain.
- Create the two database Processes automatically when none exist: rejected because it would place long-lived data on a Node the operator did not choose and size, and because MySQL already works the other way: the operator creates the server and Orbit provisions inside it.
- Run Plausible through a role-private container runtime, as the metrics role runs Prometheus and Grafana: rejected because the Process runtime already converges a Docker container on a Node, and a second runtime would duplicate its lifecycle, logs, and Doctor checks.
- Run the three services from one Compose file or a Swarm stack: rejected for the reasons in [ADR 0003](/decisions/0003-singleton-metrics-role).
- Deploy Plausible as an App instance: rejected for the reason ADR 0087 rejected it for Reverb. The App model exists for customer code with releases and rollbacks, and it would make the operator assemble the service by hand.

## Consequences

- One command, after the two database Processes exist, gives the fleet a private analytics dashboard at `https://analytics.orbit`.
- Orbit gains no database administration for PostgreSQL or ClickHouse; the role reads what the two Processes already declare.
- Plausible connects to PostgreSQL as that Process's own user, so the operator gives analytics a PostgreSQL Process of its own and does not share one that holds other data.
- ClickHouse's defaults assume a large server. The role documents a low-resource configuration for the ClickHouse Process, and a small services Node has little room for other database servers beside it.
- The database passwords and the connection URLs live in Process environments, as the MySQL root password does today: hidden from the API and redacted from logs, but not encrypted at rest. Encrypting Process secrets is a separate decision.
- Event data grows with traffic and Orbit does not yet back it up or prune it.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: extends [ADR 0003](/decisions/0003-singleton-metrics-role), [ADR 0069](/decisions/0069-allow-node-process-targets), [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline), and [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)
- Detail: [Analytics role](/reference/analytics)
- Verify: Gateway feature tests for role assignment and removal, and an Incus proof that assigns the role on a topology with a database Node and loads `https://analytics.orbit`
