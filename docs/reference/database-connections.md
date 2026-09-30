---
title: "Database connections"
description: "The Gateway-owned registry of mysql, pgsql, sqlite, and redis connections and how an operator attaches one to an Instance."
covers:
  - apps/gateway/app/Actions/DatabaseConnections/**
  - apps/gateway/app/Domain/DatabaseConnections/**
  - apps/gateway/app/Infrastructure/DatabaseConnections/**
  - apps/gateway/app/Http/Controllers/Api/{DatabaseConnectionsController,DatabaseConnectionAttachmentsController,DatabaseUsersController}.php
  - apps/gateway/app/Http/Requests/DatabaseConnections/**
  - apps/gateway/app/Models/{DatabaseConnection,DatabaseConnectionTarget,DatabaseUser}.php
  - apps/cli/app/Commands/Internal/InternalDatabaseLocalCommand.php
  - apps/cli/app/Services/Database/**
---

# Database connections

A Database connection is a Gateway record that describes one database: its driver, where it is, and how to log in. The Gateway stores the password encrypted. You can inspect a registered database, create a MySQL user through a Docker Process, and attach a connection to an Instance. The registry never starts or stops a database. A shared database server runs as a Docker [Node Process](/reference/processes-and-schedules#owners). [`database`](/cli/database) lists the commands.

## Drivers and fields

Each record has a unique slug: lowercase words joined by hyphens, at most 63 characters. Each driver takes its own fields.

| Driver | Required | Optional | Default port |
| --- | --- | --- | --- |
| `mysql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `3306` |
| `pgsql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `5432` |
| `sqlite` | `path` | `node_id`, `username`, `password` | none |
| `redis` | `host` | `node_id`, `port`, `database`, `username`, `password` | `6379` |

`host` is a hostname or an IP address, without a user or a port. `port` is 1 through 65535. `path` is an absolute Unix path of at most 1,024 characters. A redis `database` is the numeric database index. The Gateway returns `validation.failed` for a field that the driver does not take, such as `path` on mysql or `host` on sqlite.

`node_id` links the record to a Node. The Node needs no `database` role. SQLite inspection needs this link, and it lets an Instance on the same Node reach a Docker Process locally.

Responses contain `id`, `slug`, `driver`, `node_id`, `host`, `port`, `database`, `path`, `username`, and `has_password`. Show also returns `users_count`. No response, Activity entry, or error contains a password.

## API

The registry routes need an access grant to the Gateway Node. A duplicate slug returns `database.slug_conflict` (409), and an unknown slug returns 404.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List the records, ordered by slug. |
| `POST` | `/api/v1/database-connections` | Create one record. |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record. |
| `PATCH` | `/api/v1/database-connections/{slug}` | Change the given fields. A `node_id` of null removes the Node link. |
| `DELETE` | `/api/v1/database-connections/{slug}` | Delete the record. `database.connection_attached` (409) while an Instance still uses it. |
| `GET` | `/api/v1/database-connections/{slug}/users` | List the users that `database:user:create` recorded. |
| `POST` | `/api/v1/database-connections/{slug}/query` | Run one SQL statement. |
| `GET` | `/api/v1/database-connections/{slug}/tables` | List the tables. |
| `GET` | `/api/v1/database-connections/{slug}/schema` | List the columns of every table. |
| `GET` | `/api/v1/database-connections/{slug}/describe/{table}` | List the columns of one table. |

A change to a record does not change the keys that an earlier attachment stored. Attach the connection again to update them.

## Inspect a registered connection

Query, tables, schema, and describe run only against a registered slug. The request never takes its own host, path, user, or password.

A query takes `sql`, at most 16,384 characters, and `write`. It runs one statement; a stacked statement returns `database.sql_multiple_statements`.

Without `write: true`, the Gateway refuses a statement whose leading verb writes, such as `INSERT`, `UPDATE`, `DELETE`, `CREATE`, or `DROP`, with `database.write_required`. That check reads only the leading verb. For mysql and pgsql the statement then runs in a normal session, so a statement with side effects that does not start with a write verb, such as `SET` or a `SELECT` that calls a function, runs without `write`. Only SQLite opens the file read-only.

`write` is permission, not proof that rows changed. The result holds `columns`, at most 500 `rows`, `row_count`, and `truncated` when more rows exist. For a read, `row_count` is the number of returned rows. For a write, it is the count that the driver reports. The Gateway replaces the stored password with `[REDACTED]` wherever it appears in a result.

| Driver | Where it runs |
| --- | --- |
| `mysql`, `pgsql` | On the Gateway, through PDO, with the stored host, port, database, user, and password. |
| `sqlite` | On the linked Node, through the hidden command `orbit internal:database-local`, which opens the file with PDO. |
| `redis` | Not supported. `database.driver_unsupported` (422). |

For SQLite, the Gateway connects to the linked Node over SSH and sends a JSON envelope on protected standard input: a random 64-character token, the path, the SQL, and the write flag. None of these values appear on the command line.

The hidden command checks only that the token is 64 hexadecimal characters. It compares the token with `ORBIT_INTERNAL_DATABASE_TOKEN` only when that variable is set on the Node, and Orbit does not set it. So any user who can run `orbit internal:database-local` on the Node can open a local SQLite file that the user can read.

A read-only request opens the file read-only. The Node must be active, with a WireGuard address and an `orbit` binary on its `PATH`. A record without a Node returns `database.sqlite_node_required`.

An unknown table returns `database.table_missing` (404). A failed query on the database or the Node returns `database.query_failed` (502).

## Create a managed MySQL user

`POST /api/v1/processes/{process}/database-users` creates a MySQL database and user through a running Docker Process, and then creates or refreshes the connection record. It takes `slug`, `database`, `username`, and `password`. The database and user names are identifiers of 1 to 32 characters. The route needs an access grant to the Process's Node.

The Process must be a Node Process with the Docker runtime and a `mysql` or `mysql-server` image. It must publish container port `3306` and store `MYSQL_ROOT_PASSWORD` in its environment. The SQL creates the database and user when they are missing, sets the password, and grants all privileges on that database from any host.

The record gets driver `mysql`, the Node's WireGuard address as host, the published host port as port, and the Process's Node as `node_id`. A new record returns 201. An existing `mysql` record with the same slug is refreshed and returns 200.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.process_not_node` | 422 | The Process belongs to an Instance. |
| `database.process_not_docker` | 422 | The Process is not Docker. |
| `database.process_not_mysql` | 422 | The image is not MySQL, or container port `3306` is not published. |
| `database.root_password_missing` | 422 | The Process environment has no `MYSQL_ROOT_PASSWORD`. |
| `database.user_create_failed` | 502 | The Process could not create the user. |
| `database.slug_conflict` | 409 | The slug names a connection with another driver. |

The Gateway records one row for each user of a connection: `username`, `privileges`, `created_by` (the calling Node's name), and `created_at`. Creating the same user again updates its row.

## Add a connection on an Instance

`PUT /api/v1/instances/{instance}/database-connections/{slug}` writes the connection into the Instance's stored environment configuration. `{instance}` is an Instance ID or an exact Route domain. The body can hold `prefix`, an uppercase name of at most 32 characters that starts with a letter. The default is `DB`. The route needs an access grant to the Instance's Node.

| Driver | Keys with prefix `DB` |
| --- | --- |
| `mysql`, `pgsql` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| `redis` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, and `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` when stored |
| `sqlite` | `DB_CONNECTION`, `DB_DATABASE` as the file path, and `DB_USERNAME`, `DB_PASSWORD` when stored |

The Gateway removes the other keys of the prefix, for example `DB_HOST` when a sqlite connection replaces a mysql one. A second add with the same prefix replaces the earlier mapping.

When the record has a `node_id` and a port, the Gateway looks for a Docker Node Process on that Node that publishes the port, as host port or container port. When it finds one and the Instance runs on the same Node, it writes host `127.0.0.1`, or the explicit bind address of that port, and the published host port. Otherwise it writes the stored host and port.

The result names the Instance, slug, prefix, written keys, host, port, whether stored configuration changed, and the key count. It never holds a value.

`DELETE /api/v1/instances/{instance}/database-connections/{slug}` removes the mapping and the six keys of its prefix. Other keys stay. An unknown mapping returns `database.attachment_missing` (404).

Add and remove change stored configuration only. Run [`env:sync`](/reference/environment-variables#synchronize) to write the Instance's `.env`.

## Attachments on a development copy

A development [copy](/reference/instance-copies) duplicates each database attachment of the source. It does not create a database server or a connection record. MySQL and PostgreSQL stay shared. The create result lists those attachments in `shared_databases`.

SQLite files inside the checkout are not left as copied bytes. Each one is replaced by a consistent snapshot taken without stopping the source. A stored path or domain that names the source checkout is rewritten to the target when it sits inside a longer value. The path must end at `/` or at the end of the value, and the domain must be a whole host. [Instance copies](/reference/instance-copies#values-that-name-the-source) states those boundaries.

## Inspect attachments with Doctor

[Doctor](/cli/doctor) checks database connections in its `database_connection` family. It compares each record and mapping with the Instance's stored keys, and changes nothing.

| Code | Kind | Meaning |
| --- | --- | --- |
| `database_connection.missing` | Drift | A mapping names a record that does not exist. |
| `database_connection.unhealthy` | Drift | A record lacks a required field or names a Node that is gone. |
| `database_connection.env_mismatch` | Drift | A mapping's stored keys are missing, left over, or different. |
| `database_connection.inspection_failed` | Unverifiable | Doctor could not read the password or the stored configuration. |

To repair an `env_mismatch`, add the connection again with the same prefix, then synchronize.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A registry, not a database manager

The registry records how to reach a database. Node Processes run database servers, and the `database` role only prepares Docker. So a connection works for an external host too, and deleting a record never touches data.

### One PDO path for every SQL driver

Every SQL driver runs through PHP PDO, so query results have one shape. The Gateway reaches mysql and pgsql directly. A SQLite file exists only on its Node, so the Node runs PDO through the hidden CLI command. `sqlite3` on the Node is a rejected alternative, because it is a second engine with its own output format. Opening the file from the Gateway is impossible.

### Secrets stay off the command line

The SQL, the path, and the token travel on protected standard input, so they never show in a process list. SSH from the Gateway is the authorization boundary for the hidden command, and WireGuard membership is the boundary for the fleet. The token does not add protection by default.
