---
title: "ADR 0093: Run Plausible through an analytics role"
sidebarTitle: "0093 Run Plausible through an analytics role"
description: "Proposed. Plausible Community Edition runs as a singleton analytics role that owns one container and keeps its data in PostgreSQL and ClickHouse Processes on a database Node."
---

# ADR 0093: Run Plausible through an analytics role

The Gateway runs Plausible Community Edition itself, as a new singleton `analytics` node role. The role owns the Plausible container and nothing else: its PostgreSQL and ClickHouse servers are ordinary Docker Processes on a Node with the `database` role, and the analytics role provisions its own database and user inside each.

## Status

Proposed.

## Context

An operator wants privacy-friendly web analytics for the Apps that Orbit hosts, without sending visitor data to a third party and without hand-building the stack. Plausible Community Edition needs three services: the Plausible application, PostgreSQL for accounts and site settings, and ClickHouse for events.

Orbit already has a shape for fleet infrastructure that a Node owns and the Gateway operates directly. The `metrics` role ([ADR 0003](/decisions/0003-singleton-metrics-role)) and the `websocket` role ([ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)) are both singleton, mutable roles that converge a service, keep generated credentials as protected settings, and publish a reserved private hostname under the Orbit CA. Plausible fits that shape: it is one always-on service for the whole fleet, not an App with releases.

The `database` role is a Docker baseline that owns no containers ([ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline)). A database server is a Node-targeted Docker Process ([ADR 0069](/decisions/0069-allow-node-process-targets)) that the operator creates, and Orbit provisions users inside a managed MySQL Process today. Database servers belong on database Nodes, where an operator expects to find, back up, and size them. An earlier Orbit design let the analytics role name only a database Node. With two PostgreSQL Processes on that Node it had no way to choose one, and it needed an ambiguity error, a backfill migration, and a Doctor code to recover.

## Decision

- Add `RoleName::Analytics` (`analytics`) as a singleton, mutable role that conflicts with `gateway`. It combines with `database`, so one services Node can hold both roles.
- The role owns one container, Plausible Community Edition at a pinned version, run the way the metrics role runs its containers: a plain `docker run` over SSH with label-proven ownership, a health check, and a specification hash. The container publishes its port on the Node's WireGuard address only.
- The role keeps its data in two Processes that the operator names when the role is assigned: one PostgreSQL 16 Process and one ClickHouse Process, each a Node-targeted Docker Process on an active Node with the `database` role. The role settings record the two Process IDs, never only a Node, so the choice is never ambiguous. Assignment refuses with a clear error when either Process is missing, is not on a database Node, or is not the supported engine; the role never creates a database server on its own.
- Orbit gains a managed PostgreSQL Process and a managed ClickHouse Process beside the managed MySQL Process. The analytics role uses them to create its own database and a generated user in each server, and it connects over WireGuard. Removing the role with `purge-data` drops those two databases and users; without it they stay.
- Generated secrets are the two database passwords and Plausible's `SECRET_KEY_BASE`. They are stored as protected settings, reused each time the role converges, never logged, and never returned over the API.
- The Gateway reserves `analytics.orbit` as a private hostname beside `gateway.orbit`, `metrics.orbit`, and `reverb.orbit`. It issues an Orbit CA leaf certificate and renders a Caddy site on the role's own Node that reverse-proxies to the local container, and private DNS answers the name with that Node's WireGuard address, as the websocket role does. Plausible has its own accounts, so the site adds no Gateway authorization in front of it.
- `analytics:update` changes the pinned Plausible version and converges the container again. Orbit does not create Plausible sites, accounts, or API tokens, and it does not inject a tracking script into an App; [ADR 0094](/decisions/0094-publish-analytics-tracking-hosts-for-app-instances) owns the public tracking host.

## Rejected alternatives

- Let the analytics role own PostgreSQL and ClickHouse on its own Node: rejected because database servers belong on database Nodes, where the operator sizes, inspects, and backs them up, and because a second home for database containers would split that responsibility.
- Record only the database Node and find the PostgreSQL Process by its image: rejected because a Node can run several PostgreSQL Processes, and the earlier design needed an ambiguity error, a backfill migration, and a Doctor code to recover from exactly that.
- Create the two database Processes automatically when none exist: rejected because it would place long-lived data on a Node the operator did not choose and size, and because MySQL already works the other way: the operator creates the server and Orbit provisions inside it.
- Run the three services from one Compose file or a Swarm stack: rejected for the reasons in [ADR 0003](/decisions/0003-singleton-metrics-role), which keeps role-owned containers as plain, individually owned `docker run` services.
- Deploy Plausible as an App instance: rejected for the reason ADR 0087 rejected it for Reverb. The App model exists for customer code with releases and rollbacks, and it would make the operator assemble the service by hand.

## Consequences

- One command, after the two database Processes exist, gives the fleet a private analytics dashboard at `https://analytics.orbit`.
- Orbit gains PostgreSQL and ClickHouse provisioning, which other features can reuse; ClickHouse support starts from nothing.
- ClickHouse's defaults assume a large server. The role documents a low-resource configuration for the ClickHouse Process, and a small services Node has little room for other database servers beside it.
- The passwords of the two database servers live in their Process environment, as the MySQL root password does today, which is hidden from the API but not encrypted at rest. Encrypting Process secrets is a separate decision.
- Event data grows with traffic and Orbit does not yet back it up or prune it.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: extends [ADR 0003](/decisions/0003-singleton-metrics-role), [ADR 0069](/decisions/0069-allow-node-process-targets), [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline), and [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)
- Detail: [Analytics role](/reference/analytics)
- Verify: Gateway feature tests for role assignment and removal, and an Incus proof that assigns the role on a topology with a database Node and loads `https://analytics.orbit`
