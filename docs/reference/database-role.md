---
title: "Database role"
description: "What the database role converges on a Node, which roles it may share, and how add, converge, and remove behave."
---

# Database role

This page tells an operator what the `database` role converges on a Node, which other roles it may share, and how add, converge, and remove behave. [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline) records the role boundary, [ADR 0077](/decisions/0077-allow-database-beside-router) records router compatibility, and [ADR 0069](/decisions/0069-allow-node-process-targets) owns Node Process targets for shared Docker databases; this page states what the operator observes.

The role ensures Docker on the assigned Node. Shared MySQL or Postgres containers are Node Processes. The role add request accepts no settings members. The [Database connection](/reference/database-connections) registry does not require this role; a remote or external host is registered without it.

## Add and converge

Add the role on an active Ubuntu Node:

```text
orbit node:role:add <node> database
```

The node is a numeric ID or a registered node name. Retry a failed or active assignment with `--converge`.

The Gateway installs the Ubuntu `docker.io` package when Docker CE is not already healthy on the Node. It does not create a Tool row for Docker.

The role may share a Node with `app-dev`, `metrics`, `router`, or `websocket`. Either assignment order is accepted. Add, converge, and remove still only ensure Docker; they do not rewrite Router configuration, change existing Docker services, or take Node Process ownership.

The Gateway refuses the assignment when the Node already carries one of these roles:

| Conflicting role | Result |
| --- | --- |
| `gateway` | `validation.failed` |
| `vpn` | `validation.failed` |
| `ingress` | `validation.failed` |
| `app-prod` | `validation.failed` |

The Gateway answers `validation.failed` when the request includes a `settings` member or another unsupported key.

## Remove

Remove the role with the generic command:

```text
orbit node:role:remove <node> database --force
```

Removal deletes the role assignment. Docker packages, the Docker service, and any Node Processes stay on the Node. `--purge-data` does not delete Docker.

Doctor expects the `docker.io` package and the `docker` service while the assignment is active.
