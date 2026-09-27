---
title: "Database role"
description: "What the database role converges on a Node, which roles it shares a Node with, and how add, converge, and remove behave."
covers:
  - apps/gateway/app/Infrastructure/Nodes/Roles/DatabaseRoleBaseline.php
  - apps/gateway/app/Domain/Nodes/DatabaseRoleSettings.php
---

# Database role

The `database` role makes sure a Node can run Docker. It marks the Node as a host for shared database Processes. The role owns no database container: shared MySQL or Postgres containers are Node [Processes](/reference/app-processes-and-schedules). A Node without this role can still run such a Process when Docker is present. The [database connection](/reference/database-connections) registry does not need the role either.

## Add and converge

Add the role to an active Node:

```bash
orbit node:role:add <node> database
```

`node` is a numeric ID or a Node name. Retry a failed or active assignment with `--converge`. `node:add --role=database` assigns the role during provisioning.

Convergence installs the Ubuntu `docker.io` package, unless Docker CE is already installed and its `docker` service runs. The Gateway creates no Tool for Docker. The role accepts no options: a request with any other member, such as `settings`, fails with `validation.failed`.

Add, converge, and remove only ensure Docker. They do not change Router configuration, existing Docker services, or Process ownership.

## Shared Nodes

The role shares a Node with `app-dev`, `router`, `metrics`, `websocket`, and `analytics`, in either order. It never shares a Node with `gateway`, `vpn`, `ingress`, or `app-prod`. A conflicting request fails with `validation.failed` before it changes anything. [Role compatibility](/reference/node-provisioning#role-compatibility) lists every pair.

## Remove

```bash
orbit node:role:remove <node> database --force
```

Removal deletes the assignment. Docker, its service, and every Node Process stay on the Node. `--purge-data` changes nothing more.

While the assignment is active, [Doctor](/cli/doctor) expects Docker to be installed and the `docker` service to run.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Processes own the containers

Node Processes already own the lifecycle of Docker containers on a Node. A role that also owned database containers would give them two owners. So the role only ensures Docker.

### Removal keeps Docker

Processes on the Node can still use Docker after the role is gone. Removing Docker would break them.

### The remaining conflicts

`gateway`, `vpn`, `ingress`, and `app-prod` are dedicated control-plane, network, public, or production Nodes. A shared database host does not belong on them. `router` has no such reason, so the role shares a Node with it.
