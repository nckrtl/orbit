---
title: "ADR 0190: Run MySQL servers with a Gateway-held admin password"
sidebarTitle: "0190 MySQL servers"
description: "In progress. database:server:create runs MySQL as a Docker Node Process and records a Database server. The generated root password is used only for the first start and then lives only encrypted in the Gateway. database:create --server creates a database and user on that server."
---

# ADR 0190: Run MySQL servers with a Gateway-held admin password

`orbit database:server:create` runs a MySQL server as a Docker Node Process and records it as a **Database server**. Orbit generates the root password. The container gets it only for its first start, so afterwards the password exists only encrypted on the server record. `orbit database:create <slug> --server=<server>` uses it to create a database and its user, and records the connection. `database:user:create` adds more users through the same server instead of through a Process environment.

## Status

In progress.

Principle: this decision serves [one way, one name](/mission#principles) and [security fits the real threat model](/mission#principles). MySQL has one supported setup path, and the admin password stays in the one place that already holds every other database password.

## Context

A MySQL server today is a Docker [Node Process](/reference/processes-and-schedules#owners) that an operator creates with `process:create`. `database:user:create` creates a database and user through that Process. It reads the root password from `MYSQL_ROOT_PASSWORD` in the Process environment.

That design has two problems. A Process created without that variable can never get a database from Orbit. The MySQL Process on beast is one such case. And a Process environment is stored unencrypted in the Gateway and stays in the container environment, so `docker inspect` on the Node shows the root password, on development and production alike.

The MySQL image reads `MYSQL_ROOT_PASSWORD` only when it initializes an empty data directory. After that, MySQL keeps the password in its own data.

## Decision

The Gateway owns Database servers. A server record holds a slug, its Node, its Process, the published port, the MySQL image tag, and the encrypted root password.

### Create a server

`orbit database:server:create <slug> --node=NODE [--tag=TAG] [--port=PORT]` does this in order:

1. It generates a random root password and stores it encrypted on a new server record.
2. It creates a Docker Node Process with image `mysql:<tag>` (default `8.4`), port `<port>` (default `3306`) published on the Node's WireGuard address to container port `3306`, and a named volume for `/var/lib/mysql`.
3. It starts the Process once with `MYSQL_ROOT_PASSWORD` set and waits until MySQL accepts the root login.
4. It removes the variable from the Process and recreates the container. The data volume keeps the password.

A retry continues from the first step that did not finish. A server is always a Docker Process, so it runs the same way on every Linux Node.

### Create a database on a server

`orbit database:create <slug> --server=SERVER [--instance=INSTANCE]` creates a database and a user with a generated password on the server. The database name comes from the slug, with hyphens replaced by underscores. A name longer than MySQL allows gets a short hash suffix. Each Instance has one user on a server, named after the Instance, with access to the databases it owns and their test databases. Without `--instance`, the database gets its own user named after the database. The command records a `mysql` connection that points to the server. With `--instance`, it also attaches the connection to that Instance under prefix `DB` and records that the Instance owns the database. `--server` excludes `--driver`, `--host`, `--port`, `--database`, `--username`, and `--password`. Without `--server`, `database:create` still registers an existing database.

Every admin command reaches MySQL with `docker exec` over SSH. The root password travels on protected standard input and reaches `docker exec` as `MYSQL_PWD` through the environment, never as an argument or a file.

### Remove a server

`orbit database:server:destroy <slug>` refuses while a connection points to the server. Otherwise it removes the Process and the server record, and keeps the data volume.

### More users

`orbit database:user:create <connection> --username=NAME --password=SECRET [--read-only]` adds a user to a database on a server, for example a read-only user in production. `database:user:list` lists the users of a connection, as before. The `--process` form and the `POST /api/v1/processes/{process}/database-users` route are removed. A MySQL Process that has no server record stays as it is. Its databases can be registered as before, but Orbit does not create databases or users on it.

## Rejected alternatives

- Keep the root password in the Process environment: it stays unencrypted in the Gateway and visible in the container environment on every Node, also in production.
- Encrypt Process environment values: the Gateway copy is safe, but the container environment still shows the password.
- Record the server as a connection with root credentials: one record would then mean two things, a database and a server.
- Adopt an existing MySQL Process: nobody knows the root password of the one on beast, so adopting it means resetting MySQL's grants. A new server and a one-time move of its databases is simpler.

## Consequences

- Orbit can always create a database on a server it created.
- The root password exists only encrypted in the Gateway database and briefly in memory during a command.
- An operator moves each database on an older MySQL Process to a server once.
- The Gateway backup becomes the only copy of each root password. A lost backup means resetting MySQL by hand.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: none
- Detail: [Database servers](/reference/database-servers), [Database connections](/reference/database-connections)
- Verify: Gateway tests for server creation, retry, and the root password removal; database creation on a server; an Incus proof that creates a server, a database, and inspects `docker inspect` for the password
