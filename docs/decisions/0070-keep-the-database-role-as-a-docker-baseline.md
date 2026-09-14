---
title: "ADR 0070: Keep the database role as a Docker baseline"
sidebarTitle: "0070 Keep the database role as a Docker baseline"
description: "Accepted on 2026-09-14. Extends ADR 0069."
---

# ADR 0070: Keep the database role as a Docker baseline

In the context of dedicated Nodes that host shared Docker databases, facing a restored `database` role after the rewrite, we decided for a Docker-only node role and against role-owned database containers, to keep container lifecycle under Node Processes, accepting that a Node can run those Processes without this role.

## Status

Accepted on 2026-09-14. Extends [ADR 0069](0069-allow-node-process-targets.md). Extends [ADR 0001](0001-tool-management.md).

## Context

Shared Docker databases have a Node Process owner under ADR 0069. The rewrite has no `database` role. Operators need a dedicated role that converges Docker on a Node without taking ownership of MySQL or Postgres containers. Role removal already leaves packages and Tool intent in place.

## Decision

- The Gateway must expose `database` as a mutable, non-singleton Node role.
- The Gateway must converge the `database` role by ensuring the Node's Docker host prerequisite.
- The Gateway must not start, stop, or own MySQL or Postgres containers when it adds, converges, or removes the `database` role.
- Node Processes own Docker database container lifecycle under ADR 0069.
- The Gateway must not create Tool intent for Docker during `database` role convergence.
- The Gateway must not remove Docker packages, Docker service state, or Tool intent when it removes the `database` role.
- The Gateway must refuse `database` on a Node that already carries `gateway`, `vpn`, `router`, `ingress`, or `app-prod`.
- The Gateway must accept `database` beside `app-dev` or `metrics`.
- The Gateway must refuse a `database` role assignment that includes settings members.
- Orbit must not require the `database` role before an operator adds a Node Process.

## Rejected alternatives

- Role-owned MySQL and Postgres containers: rejected because ADR 0069 already assigns Node-scoped Docker lifecycle to Processes.
- Create a public Docker Tool row during convergence: rejected because roles do not own Tools.
- Remove Docker when the role is removed: rejected because role removal leaves packages and Tool intent in place.
- Require the role before Process creation: rejected because Docker can already exist on app-dev, app-prod, and metrics Nodes.

## Consequences

- Operators can mark a Node as a dedicated Docker database host through existing node-role commands.
- A Node without this role can still run Docker database Processes when Docker is present.
- Removing the role leaves Docker installed, so a Process that remains on the Node can keep using it.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0069](0069-allow-node-process-targets.md); extends [ADR 0001](0001-tool-management.md)
- Detail: [Database role](../reference/database-role.md)
- Verify: `composer docs-lint`; Gateway RoleRegistry, DatabaseRoleBaseline, and node-role conflict tests
