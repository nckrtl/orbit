---
title: "Database servers"
description: "How Orbit runs a MySQL server as a Docker Node Process, keeps its root password only in the Gateway, and creates databases on it."
covers:
  - apps/gateway/app/Actions/DatabaseServers/**
  - apps/gateway/app/Actions/DatabaseConnections/{CreateServerDatabaseAction,CreateDatabaseUserAction,DropServerDatabaseAction}.php
  - apps/gateway/app/{Domain,Infrastructure}/DatabaseServers/**
  - apps/gateway/app/Http/Controllers/Api/DatabaseServersController.php
  - apps/gateway/app/Http/Requests/DatabaseServers/**
  - apps/gateway/app/Models/DatabaseServer.php
  - apps/cli/app/Commands/Database/{CreateDatabaseServerCommand,ListDatabaseServersCommand,ShowDatabaseServerCommand,DestroyDatabaseServerCommand,CreateDatabaseUserCommand}.php
  - packages/php-sdk/src/{Requests,Responses}/DatabaseServers/**
---

# Database servers

A Database server is a MySQL server that Orbit runs and administers. It runs as a Docker [Node Process](/reference/processes-and-schedules#owners), and the Gateway keeps its root password. Orbit uses that password to create a database for an Instance and to [clone](/domains/applications#database-clone) the default Instance's database. [`database:server`](/cli/database#orbit-databaseservercreate) lists the commands.

## Create a server

`database:server:create` creates the server and its Process in one command.

```bash
orbit database:server:create beast-mysql --node=beast
orbit database:server:create beast-mysql --node=beast --tag=8.4 --port=3306
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=NODE` | required | Numeric Node ID or Node name. The Node must be an active Linux Node with Docker. |
| `--tag=TAG` | `8.4` | MySQL image tag. The image is `mysql:<tag>`. |
| `--port=PORT` | `3306` | Port that the server publishes on the Node's WireGuard address. |

The Gateway creates the server in this order:

1. It generates a random root password and stores it encrypted on the server record.
2. It creates a Docker Node Process named `<slug>` with image `mysql:<tag>`, the port published on the Node's WireGuard address to container port `3306`, and the volume `orbit-<slug>-data` for `/var/lib/mysql`.
3. It starts the Process with `MYSQL_ROOT_PASSWORD` set and waits until MySQL accepts the root login.
4. It removes the variable from the Process and recreates the container. The data volume keeps the password.

After step 4, the root password exists only encrypted in the Gateway. It is not in the Process record, the container environment, `docker inspect`, or a file on the Node. Orbit waits for the root login over TCP inside the container, which only the final MySQL server accepts, so it never recreates the container while MySQL still initializes its data directory.

A retry of the same request continues from the first step that did not finish. A request with the slug of an existing server and another Node, tag, or port returns `database.server_slug_conflict`. A repeat of a finished request returns the server with status 200.

The response holds `id`, `slug`, `node_id`, `process_id`, `tag`, `port`, `status`, and `databases_count`. `database:server:show` also returns `databases`, the connections of the databases on the server. No response, Activity entry, or error contains the password.

The server's Process is an ordinary Docker Node Process: read its logs, stop it, or restart it with the [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; remove the server instead.

## Create a database on a server

`database:create` with `--server` creates a database and a user on the server, then records the [connection](/reference/database-connections).

```bash
orbit database:create dlf-leden --server=beast-mysql --instance=60
```

The database name comes from the slug, with hyphens replaced by underscores. A database name longer than 64 characters, or a user name longer than 32, ends in an underscore and an 8-character hash. Orbit generates the user's password.

Each Instance has one user on a server, named `<project>_<instance>` like its databases. A second database for the same Instance reuses that user and its password. That user can reach only the databases the Instance owns and their test databases. A database created without `--instance` gets its own user, named like the database. The connection gets driver `mysql`, the server's Node address and port, and a link to the server.

Orbit refuses a name with `database.name_conflict` when the server already has that database or user, or when the name starts with the test database name of another database on the server. So one Instance's user never reaches another database through its test database grant. A create that fails drops what it made and records nothing.

With `--instance`, Orbit also creates the test database `<name>_test`, attaches the connection to the Instance under prefix `DB`, and records the Instance as the owner. [Test databases](/reference/database-connections#test-databases) describes how tests use it.

`database:create --instance` needs an existing Instance, so it comes after that Instance's setup steps. To have the database before setup runs, pass the server to [`instance:create --database-server`](/domains/applications#database-on-a-server) instead.

## Add a user

`database:user:create` adds a user to a database on a server, for example a read-only user in production. `database:user:list` lists the users that Orbit created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --password=SECRET --read-only
```

A read-only user gets `SELECT` on the database. Any other user gets all privileges on it. Running the command again sets the password and replaces the privileges. A connection without a server returns `database.server_required` (422). The user of a connection on the same server returns `database.name_conflict` (409), so the command never changes the password that a connection uses.

## How Orbit runs admin commands

Every admin command runs `mysql` or `mysqldump` inside the server's container with `docker exec`, over SSH. The root password travels on protected standard input, and the remote script hands it to `docker exec` as `MYSQL_PWD` through the environment. The SQL follows it on standard input. Neither appears as an argument, in a file, or in a log.

## Remove a database

`database:destroy` of a connection on a server drops the database, every database whose name starts with its test database name, and each user Orbit created for it that no other connection on the server uses. Then it deletes the record. A failed drop keeps the record, and a repeat drops again.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while a connection points to the server. Otherwise it removes the Process and the server record. It keeps the volume `orbit-<slug>-data`, so the data survives. Remove the volume on the Node by hand when its data must go.

A Node that runs a server cannot be removed. [Node removal](/reference/node-provisioning#remove-a-node) refuses it with `node.has_database_servers`, also offline.

## Errors

The server commands return these codes.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_slug_conflict` | 409 | Another server has the slug. |
| `database.server_port_in_use` | 409 | Another Process on the Node publishes the port. |
| `database.server_start_failed` | 502 | MySQL did not accept the root login within 120 seconds. |
| `database.server_in_use` | 409 | A connection still points to the server. |
| `database.server_inactive` | 409 | The server is not active yet. Run `database:server:create` again to finish it. |
| `database.server_required` | 422 | The connection has no server, so Orbit cannot create a user on it. |
| `database.name_conflict` | 409 | `database:user:create` names the user of a connection on the server. |
| `process.required_by_database_server` | 409 | `process:destroy` names the Process of a server. |
| `database.server_command_failed` | 502 | An admin command on the server failed. The message holds no SQL output or password. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The root password lives only in the Gateway

The MySQL image reads `MYSQL_ROOT_PASSWORD` only when it creates an empty data directory. So Orbit passes it once and then removes it. A password in the Process environment would stay unencrypted in the Gateway and visible through `docker inspect` on every Node, production included. Encrypting the Process environment was rejected, because the container would still show the password.

### One way to set up MySQL

A server is always a Docker Process, so it runs the same way on every Linux Node. Creating databases and users through any MySQL Process with a root password in its environment was removed, because a Process without that variable could never get a database. Adopting an existing MySQL Process was rejected, because its root password may be unknown. Create a server and move the databases once instead.

### One user per Instance

An Instance's user reaches only that Instance's databases, so one Instance cannot read another's data. A user per Project was rejected, because every Instance of the Project could then read every clone.

### A server is not a connection

A connection describes one database that an Instance uses. A server holds the admin password that creates those databases. Recording the server as a connection with root credentials would give one record two meanings.
