---
title: "Database connections"
description: "The Gateway-owned registry of mysql, pgsql, sqlite, and redis connections, the databases that Instances own, their test databases, and how an operator attaches a connection to an Instance."
covers:
  - apps/gateway/app/Actions/DatabaseConnections/**
  - apps/gateway/app/Domain/DatabaseConnections/**
  - apps/gateway/app/Infrastructure/DatabaseConnections/**
  - apps/gateway/app/Http/Controllers/Api/{DatabaseConnectionsController,DatabaseConnectionAttachmentsController}.php
  - apps/gateway/app/Http/Requests/DatabaseConnections/**
  - apps/gateway/app/Models/{DatabaseConnection,DatabaseConnectionTarget}.php
  - apps/cli/app/{Commands/Internal/InternalDatabaseLocalCommand.php,Services/Database/**}
  - apps/gateway/app/{Actions/Instances/CloneInstanceDatabaseAction.php,Domain/Instances/DatabaseClone/**,Infrastructure/Instances/RemoteInstanceSqliteCloner.php}
---

# Database connections

A Database connection is a Gateway record that describes one database: its driver, where it is, and how to log in. The Gateway stores the password encrypted. You can register an existing database, create one on a [Database server](/reference/database-servers), inspect it, and attach it to an Instance. The registry never starts or stops a database. [`database`](/cli/database) lists the commands.

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

Responses contain `id`, `slug`, `driver`, `node_id`, `host`, `port`, `database`, `path`, `username`, `has_password`, `server`, `owner_instance_id`, and `test_database`. Show also returns `users_count`. `server` is the slug of the [Database server](/reference/database-servers) that holds the database, or null. `owner_instance_id` names the Instance that owns the database, or null. No response, Activity entry, or error contains a password.

## API

The registry routes need an access grant to the Gateway Node. A duplicate slug returns `database.slug_conflict` (409), and an unknown slug returns 404.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List the records, ordered by slug. |
| `POST` | `/api/v1/database-connections` | Register an existing database, or create one on a server with `server`. |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record. |
| `PATCH` | `/api/v1/database-connections/{slug}` | Change the given fields. A `node_id` of null removes the Node link. |
| `DELETE` | `/api/v1/database-connections/{slug}` | Delete the record. `database.connection_attached` (409) while an Instance still uses it. A database on a server is dropped with its record. |
| `GET` | `/api/v1/database-connections/{slug}/users` | List the users that Orbit created for the connection. |
| `POST` | `/api/v1/database-connections/{slug}/users` | Add a user through the connection's [server](/reference/database-servers#add-a-user). |
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

## Create a database on a server

`POST /api/v1/database-connections` with `slug`, `server`, and an optional `instance_id` creates a MySQL database and user on that [Database server](/reference/database-servers#create-a-database-on-a-server). `server` excludes `driver`, `node_id`, `host`, `port`, `database`, `username`, `password`, and `path`, and `instance_id` needs `server`. The Gateway returns 201 with the new record.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_missing` | 404 | No server has that slug. |
| `database.server_inactive` | 409 | The server is not active yet. |
| `database.slug_conflict` | 409 | Another connection has the slug. |
| `database.name_conflict` | 409 | The server already has a database or user with the derived name. |
| `database.server_command_failed` | 502 | The server could not create the database or user. |

## Owned databases

An Instance owns a database that Orbit created for it: by `database:create --server --instance`, by [`instance:create --database-server`](/domains/applications#database-on-a-server), or by the [clone](/domains/applications#database-clone) that `instance:create` runs. The record keeps the owner in `owner_instance_id`. A clone that cannot run returns `instance.database_clone_unsupported`, and a copy that fails returns `instance.database_clone_failed`. [Database clone](/domains/applications#database-clone) describes both.

[`instance:destroy`](/reference/instance-removal#owned-databases) drops each database the Instance owns, with its test databases and user, and deletes the record. Deleting the record of a database on a server drops the database the same way. Orbit never drops a database that it only registered.

## Test databases

An owned database has a test database of the same kind, named in `test_database`.

| Driver | Test database |
| --- | --- |
| `mysql` | `<name>_test` on the same server. The user also gets every database whose name starts with `<name>_test`, so Laravel's parallel testing can create `<name>_test_test_1` and the rest. |
| `sqlite` | `:memory:` |

For an Instance that owns its `DB` database, [synchronization](/reference/environment-variables#synchronize) also sets the `DB_*` keys in `.env.testing` to the test database, with the connection's host and port. A missing file is created from the same values as `.env`, with `APP_ENV=testing`. In an existing untracked file, other lines stay. Orbit never writes a `.env.testing` that Git tracks; it records the test database name in the activity instead. Laravel loads `.env.testing` when `APP_ENV` is `testing`, which a Laravel `phpunit.xml` sets. A `phpunit.xml` entry with `force="true"` for a `DB_*` key still overrides it, so remove such entries to use the test database.

## Add a connection on an Instance

`PUT /api/v1/instances/{instance}/database-connections/{slug}` writes the connection into the Instance's stored environment configuration. `{instance}` is an Instance ID or an exact Route domain. The body can hold `prefix`, an uppercase name of at most 32 characters that starts with a letter. The default is `DB`. The route needs an access grant to the Instance's Node.

| Driver | Keys with prefix `DB` |
| --- | --- |
| `mysql`, `pgsql` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| `redis` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, and `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` when stored |
| `sqlite` | `DB_CONNECTION`, `DB_DATABASE` as the file path, and `DB_USERNAME`, `DB_PASSWORD` when stored |

The Gateway removes the other keys of the prefix, for example `DB_HOST` when a sqlite connection replaces a mysql one. A second add with the same prefix replaces the earlier mapping.

The Gateway writes the stored host and port, also when the Instance runs on the record's Node. It never derives them from the Node's Processes. A database on a [Database server](/reference/database-servers) stores the Node's WireGuard address and the published port, so every Instance in the fleet reaches it at that address.

The result names the Instance, slug, prefix, written keys, host, port, whether stored configuration changed, and the key count. It never holds a value.

`DELETE /api/v1/instances/{instance}/database-connections/{slug}` removes the mapping and the six keys of its prefix. Other keys stay. An unknown mapping returns `database.attachment_missing` (404).

Add and remove change stored configuration only. Run [`env:sync`](/reference/environment-variables#synchronize) to write the Instance's `.env`.

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

The registry records how to reach a database, so a connection works for an external host too. Orbit changes data only for a database it created on a [Database server](/reference/database-servers): it creates that database, clones into it, and drops it with its owner. Deleting the record of a registered database never touches data.

### Tests get their own database

A test run with `RefreshDatabase` empties the database it uses. A separate test database of the same kind keeps an Instance's data safe, and `.env.testing` lets Laravel use it without a change in each repository. Making each repository read a `DB_TEST_*` key was rejected, because every repository would need that change first.

### One PDO path for every SQL driver

Every SQL driver runs through PHP PDO, so query results have one shape. The Gateway reaches mysql and pgsql directly. A SQLite file exists only on its Node, so the Node runs PDO through the hidden CLI command. `sqlite3` on the Node is a rejected alternative, because it is a second engine with its own output format. Opening the file from the Gateway is impossible.

### Secrets stay off the command line

The SQL, the path, and the token travel on protected standard input, so they never show in a process list. SSH from the Gateway is the authorization boundary for the hidden command, and WireGuard membership is the boundary for the fleet. The token does not add protection by default.
