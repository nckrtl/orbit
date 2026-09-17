---
title: "database"
description: "Register, inspect, update, and destroy the mysql, pgsql, and sqlite connections that App instances can attach, and create a MySQL user through a Node Process."
commands:
  - database:create
  - database:list
  - database:show
  - database:update
  - database:user:create
  - database:destroy
  - database:query
  - database:tables
  - database:schema
  - database:describe
---

A Database connection is a Gateway-owned registry record with an encrypted password. The `database` family manages that registry and inspects a registered connection. It does not start or stop a database; a shared database server on a Node is a Docker [Node Process](/cli/process), and the optional [Database role](/reference/database-role) converges Docker for it. `database:user:create` creates a MySQL user and database through that Process, then registers or refreshes the connection.

The [Database connections reference](/reference/database-connections) owns the driver fields, the API, and the keys that an attachment writes. Attach a registered connection to an App instance with [`instance:database:add`](/cli/instance#orbit-instancedatabaseadd).

## Commands

| Command | Result |
| --- | --- |
| [`database:create`](#orbit-databasecreate) | Create one connection record. |
| [`database:list`](#orbit-databaselist) | List registered connections. |
| [`database:show`](#orbit-databaseshow) | Show one connection. |
| [`database:update`](#orbit-databaseupdate) | Replace the supplied fields on one connection. |
| [`database:user:create`](#orbit-databaseusercreate) | Create a MySQL user and database through a Node Docker Process, then register or refresh the connection. |
| [`database:destroy`](#orbit-databasedestroy) | Destroy one connection record. |
| [`database:query`](#orbit-databasequery) | Run one SQL statement against a registered connection. |
| [`database:tables`](#orbit-databasetables) | List tables on a registered connection. |
| [`database:schema`](#orbit-databaseschema) | Show columns for every table on a registered connection. |
| [`database:describe`](#orbit-databasedescribe) | Show columns for one table on a registered connection. |

Every command accepts `--json`. No response ever includes the password; `has_password` reports whether one is stored.

Human list and inspection commands use the shared table. Show, create, update, destroy, user-create, add, and remove use the shared detail tree. Gateway calls show progress until the request settles. `database:destroy` and `instance:database:remove` require default-No confirmation or `--force`. JSON and piped calls never imply consent.

{/* commands */}

## Related

- [`instance:database:add`](/cli/instance#orbit-instancedatabaseadd) and [`instance:database:remove`](/cli/instance#orbit-instancedatabaseremove) attach and detach a connection on one App instance.
- [`process`](/cli/process) creates the Docker Node Process that runs a shared database server. `database:user:create` uses that Process to create a MySQL user and database.
- [`doctor --family=database_connection`](/cli/doctor) reports missing, unhealthy, or mismatched attachments.
