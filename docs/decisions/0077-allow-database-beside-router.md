---
title: "ADR 0077: Allow the database role beside router"
sidebarTitle: "0077 Allow the database role beside router"
description: "Proposed. Amends ADR 0070 for router compatibility."
---

# ADR 0077: Allow the database role beside router

In the context of shared development Nodes that already run Router and Docker, facing a `database` role conflict with `router` that had no technical rationale, we decided to accept those roles together and against keeping the refusal or clearing other database conflicts in the same change, so operators can mark Docker database hosts without changing routing, accepting that remaining database conflicts stay until separately justified.

## Status

Proposed. Amends [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline) for the `router` compatibility rule only.

## Context

[ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline) keeps `database` as a Docker baseline. The role ensures the Node's Docker host prerequisite. It does not start, stop, or own containers. Node Processes own Docker database lifecycle under [ADR 0069](/decisions/0069-allow-node-process-targets).

The accepted record refused `database` beside `router` without explaining a technical interaction. Shared development Nodes already carry `router` with `app-dev` and `metrics`. Docker is already present there for Metrics and other services. Operators need to assign `database` on those Nodes so the role matches the Docker baseline they already run, without replacing Router configuration or existing Node Processes.

The remaining ADR 0070 refusals mix dedicated control-plane, VPN, public production ingress, or production application ownership with a shared database-host marker. Those pairings have no current operator request and remain refused until a dedicated decision changes them.

## Decision

- The Gateway must accept `database` beside `router` in either assignment order.
- The Gateway must keep `database` convergence limited to ensuring the Node's Docker host prerequisite.
- Database role add, converge, and remove must not rewrite Router configuration, mutate existing Docker services, or change Node Process ownership.
- The Gateway must continue to refuse `database` beside `gateway`, `vpn`, `ingress`, or `app-prod` unless a dedicated decision changes those pairings.
- The Gateway must continue to accept `database` beside `app-dev` or `metrics`.
- This record amends ADR 0070 only for the `router` pairing. ADR 0070 still owns the Docker-baseline role boundary.

## Rejected alternatives

- Keep refusing `router` with `database`: rejected because the original refusal had no technical rationale and blocked a shared development-node configuration that already runs Docker beside Router.
- Remove the remaining database conflicts in this change: rejected because `gateway`, `vpn`, `ingress`, and `app-prod` still mix dedicated infrastructure or production ownership with the database-host marker and need a separate justification.

## Consequences

- Operators can assign `database` on a Node that already has `router`, and can set a Cluster Router on a Node that already has `database`.
- Doctor no longer reports that pairing as an assignment conflict.
- Adding or converging `database` on a live shared development Node remains an operator follow-up after this change is deployed.

## Affects

- Components: apps/gateway
- ADRs: amends [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline) for router compatibility
- Detail: [Database role](/reference/database-role)
- Verify: `composer docs-lint`; Gateway RoleRegistry, AssignRoleAction, node-role, Cluster Router, RoleDoctorProbe, and DatabaseRoleBaseline tests
