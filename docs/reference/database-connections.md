---
title: "Database connections"
description: "The Gateway-owned registry of mysql, pgsql, sqlite, and redis connections and how an operator attaches one to an Instance."
---

# Database connections

This page tells an operator how the Gateway stores named mysql, pgsql, sqlite, and redis connection records and which fields each driver requires. It also covers list, show, create, update, destroy, managed user create, managed user list, add, remove, query, tables, schema, describe, and Doctor inspection. [ADR 0069](/decisions/0069-allow-node-process-targets) owns Node Process targets for shared Docker database servers, and [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline) owns the `database` role as a Docker baseline; this page owns the connection registry.

A Database connection is a Gateway-owned registry record. The operator registers a remote host or a sqlite path without assigning the `database` role. Node Processes start and stop Docker database servers. The registry does not start or stop a database. The Gateway can create a MySQL user and database through an existing Node-targeted Docker MySQL Process and then register or refresh the connection. Query, tables, schema, and describe run against a registered connection only.

The operator adds a connection on an Instance only. Add writes prefixed keys into the Gateway-owned stored Instance environment under [ADR 0044](/decisions/0044-own-appinstance-environment-configuration-in-orbit). It does not write the workload `.env`. Run `orbit env:sync` after add or remove when the workload file must match stored configuration. [Instance environment variables](/reference/environment-variables) owns import, update, and synchronization.

The CLI uses the shared table and detail tree, shows progress on Gateway calls, and never prints a password. Destroy and attachment removal require default-No confirmation or `--force`. Human query cells render SQL null as `NULL` and an empty string as `""` so those values stay distinct from each other and from a literal em dash. JSON query cells stay exact.

## Create a connection

Create one record with a unique slug:

```text
orbit database:create app --driver=mysql --host=db.example.test --database=app --username=app --password=secret
```

The slug is a lowercase kebab name of at most 63 characters. The Gateway encrypts the password with its application encryption key before it writes the row. Responses, activity records, errors, and debug output omit the password and replace a password-shaped value with `[REDACTED]`.

The optional `--node` value is a numeric Node ID or a registered Node name. The Gateway stores that Node as an association. It does not require the `database` role on that Node, and it accepts a record with no Node for a remote or external host.

SQLite uses a Unix absolute path instead of host, port, and database name:

```text
orbit database:create local --driver=sqlite --path=/var/lib/app/database.sqlite
```

## Drivers and fields

Each driver stores one complete connection profile.

| Driver | Required fields | Optional fields | Default port |
| --- | --- | --- | --- |
| `mysql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `3306` |
| `pgsql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `5432` |
| `sqlite` | `path` | `node_id`, `username`, `password` | none |
| `redis` | `host` | `node_id`, `port`, `database`, `username`, `password` | `6379` |

The Gateway answers `validation.failed` when a mysql, pgsql, or redis record includes `path`, when a sqlite record includes `host`, `port`, or `database`, when a redis record omits `host`, or when the request includes an unsupported key. A sqlite `path` is a nonempty Unix absolute path of at most 1024 characters. A `host` is a hostname or IP address without userinfo or a port. A `port` is an integer from 1 through 65535. `database` and `username` are bounded printable names; a redis `database` is its numeric database index, stored as a 1-3 digit string. Redis stores a `username` or `password` only when the caller supplies one; it never requires either.

Redis has no PDO inspector. `query`, `tables`, `schema`, and `describe` refuse a redis connection with `database.driver_unsupported` (HTTP 422) before they touch the connection.

The API and PHP software development kit (SDK) return this identity for each record.

| Field | Meaning |
| --- | --- |
| `id` | Numeric registry ID |
| `slug` | Unique connection name |
| `driver` | `mysql`, `pgsql`, `sqlite`, or `redis` |
| `node_id` | Optional associated Node ID |
| `host` | Hostname or IP for mysql, pgsql, and redis |
| `port` | TCP port for mysql, pgsql, and redis |
| `database` | Database name for mysql and pgsql, or the database index for redis |
| `path` | Unix absolute sqlite path |
| `username` | Stored username, or null |
| `has_password` | Whether a password is stored |
| `users_count` | Recorded user count. Present only on `database:show`. |

Item and collection responses never include the password.

## Commands

The CLI sends each operation through the Gateway.

| Command | Result |
| --- | --- |
| `orbit database:list` | List every registered connection without passwords. |
| `orbit database:show SLUG` | Show one connection without the password. |
| `orbit database:create SLUG --driver=DRIVER` | Create one connection and encrypt the supplied password. |
| `orbit database:update SLUG` | Replace the supplied fields on one connection. |
| `orbit database:user:create SLUG --process=ID` | Create a MySQL user and database through a Node Docker Process, then register or refresh the connection. |
| `orbit database:user:list SLUG` | List the users the Gateway recorded for the connection. |
| `orbit database:destroy SLUG --force` | Destroy the connection record. |
| `orbit database:query SLUG SQL` | Run one SQL statement against the registered connection. |
| `orbit database:tables SLUG` | List tables on the registered connection. |
| `orbit database:schema SLUG` | Show columns for every table on the registered connection. |
| `orbit database:describe SLUG TABLE` | Show columns for one table on the registered connection. |
| `orbit instance:database:add SLUG --instance=SELECTOR` | Add the connection on one Instance and write prefixed stored environment keys. |
| `orbit instance:database:remove SLUG --instance=SELECTOR --force` | Remove the connection from one Instance and clear the prefixed stored environment keys. |

Every command also accepts `--json`. Human and JSON results include the Gateway request ID. `database:destroy` and `instance:database:remove` require interactive confirmation or `--force` before they send the delete request. `database:update` requires at least one field option. `database:query` is read-only unless `--write` is set.

The create command accepts `--host`, `--port`, `--database`, `--path`, `--username`, `--password`, and `--node`. The update command accepts the same field options except the slug. An empty `--node` on update clears the stored Node association. `database:user:create` accepts `--process`, `--database`, `--username`, and `--password`.

## Create a managed MySQL user

Create a MySQL user and database through an existing Node-targeted Docker MySQL Process, then register or refresh the connection:

```text
orbit database:user:create app --process=12 --database=app --username=app --password=secret
```

The Process must be Node-owned, use the Docker runtime, use a `mysql` or `mysql-server` image, publish container port `3306`, and store `MYSQL_ROOT_PASSWORD` in its environment. The Gateway runs the create through that Process. The CLI does not open SSH to the Node.

The Gateway writes these connection fields from the Process. The host is the Node WireGuard address. The port is the published host port for container port `3306`. The Node association is the Process owner. The driver is `mysql`.

The SQL is idempotent: the Gateway creates the database and user when they are missing, then sets the password and grants privileges on that database from any host. A slug that already names a mysql connection is refreshed with those fields. A slug that names a pgsql or sqlite connection returns `database.slug_conflict` (HTTP 409) and does not change the Process.

The Gateway answers these process refusals before it writes a connection.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `http.404` | 404 | The Process ID is not present. |
| `database.process_not_node` | 422 | The Process is not Node-targeted. |
| `database.process_not_docker` | 422 | The Process is not Docker. |
| `database.process_not_mysql` | 422 | The image is not MySQL or container port `3306` is not published. |
| `database.root_password_missing` | 422 | The Process environment has no `MYSQL_ROOT_PASSWORD`. |
| `database.user_create_failed` | 502 | The Process could not create the user. |
| `database.slug_conflict` | 409 | The slug already names a non-mysql connection. |

Responses, activity records, errors, and debug output omit the user password and the Process root password.

## List recorded users

`database:user:create` records one row per username on a connection: `username`, `privileges` (the granted SQL privileges as a description, such as `` ALL PRIVILEGES ON `app`.* ``), `created_by` (the calling Node's name, since the Gateway authorizes every command as a Node), and `created_at`. Recreating a user through `database:user:create` updates that row's `privileges` and `created_by` instead of adding a second one. `database:show` reports the row count as `users_count`.

```text
orbit database:user:list app
```

## API

The Gateway exposes the registry at `/api/v1/database-connections`. Access to those registry routes is fleet-wide. Managed user create uses the Process owning Node at `/api/v1/processes/{process}/database-users`.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List records ordered by slug |
| `POST` | `/api/v1/database-connections` | Create one record |
| `POST` | `/api/v1/processes/{process}/database-users` | Create a MySQL user and database through that Process, then register or refresh the connection |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record, with `users_count` |
| `GET` | `/api/v1/database-connections/{slug}/users` | List the connection's recorded users |
| `PATCH` | `/api/v1/database-connections/{slug}` | Update supplied fields |
| `DELETE` | `/api/v1/database-connections/{slug}` | Destroy the record |
| `POST` | `/api/v1/database-connections/{slug}/query` | Run one SQL statement |
| `GET` | `/api/v1/database-connections/{slug}/tables` | List tables |
| `GET` | `/api/v1/database-connections/{slug}/schema` | Show every table's columns |
| `GET` | `/api/v1/database-connections/{slug}/describe/{table}` | Show one table's columns |

A duplicate slug on registry create returns `database.slug_conflict` (HTTP 409) and leaves the existing record unchanged. Managed user create returns HTTP 201 for a new mysql row and HTTP 200 when it refreshes an existing mysql slug. An unknown slug returns `http.404`. Destroying a record deletes that row when no Instance attachment exists. The Gateway answers `database.connection_attached` (HTTP 409) when an attachment still exists. Recovery of a stored password depends on retaining the Gateway encryption key material.

## Inspect a registered connection

The operator inspects a registered connection with query, tables, schema, and describe. The Gateway refuses SQL that is not sent against a stored slug. The request never accepts a host, path, username, or password of its own.

Query is read-only by default. The Gateway answers `database.write_required` when the statement would write and the request omits `write: true`. `--write` on the CLI sends that permission flag; it is not proof that rows mutated. `row_count` is the inspector-reported count and is driver-specific. Query accepts one statement. Stacked statements return `database.sql_multiple_statements`.

SQLite query, tables, schema, and describe run on the associated Node. The Gateway answers `database.sqlite_node_required` when that connection has no Node. The remote command is the hidden Orbit CLI command `internal:database-local`, which opens the file with PDO. SQL and the lane token travel on protected stdin and do not enter argv. The inspector never invokes `sqlite3`. [ADR 0081](/decisions/0081-query-registered-databases-through-pdo) owns that split.

The local SQLite command reports `database.query_failed` if reading a result fails or its values cannot be encoded as JSON. It preserves representable values and does not replace invalid text. A result failure does not roll back a write that already completed.

MySQL and PostgreSQL inspection uses PDO on the Gateway with the stored host, port, database, username, and password. The password never enters a DSN, response, activity record, error, or debug output. Responses, activity records, errors, and debug output replace a password-shaped value with `[REDACTED]`. Query returns at most 500 rows and sets `truncated` when more remain.

An unknown table returns `database.table_missing` (HTTP 404). A failed remote or driver execution returns `database.query_failed` (HTTP 502).

```text
orbit database:query app "SELECT id, email FROM users"
orbit database:query app "DELETE FROM users WHERE id = 1" --write
orbit database:tables app
orbit database:schema app
orbit database:describe app users
```

## Add a connection on an Instance

Add writes stored environment keys for one Instance. The target is an Instance ID or exact Route domain. Orbit accepts no Workspace target.

```text
orbit instance:database:add app --instance=12
```

The optional `--prefix` value defaults to `DB`. A prefix is an uppercase name that starts with a letter and then uses letters, digits, or underscores, at most 32 characters. Add replaces an existing mapping that already uses that prefix on the same Instance.

The Gateway writes these keys for mysql and pgsql.

| Prefix `DB` key | Source |
| --- | --- |
| `DB_CONNECTION` | Driver (`mysql` or `pgsql`) |
| `DB_HOST` | Resolved hostname or IP |
| `DB_PORT` | Resolved TCP port |
| `DB_DATABASE` | Stored database name |
| `DB_USERNAME` | Stored username |
| `DB_PASSWORD` | Stored password |

SQLite writes `DB_CONNECTION=sqlite` and `DB_DATABASE` as the Unix absolute path. It writes `DB_USERNAME` and `DB_PASSWORD` only when those values are stored. It does not write `DB_HOST` or `DB_PORT`, and it removes those keys when a previous mysql or pgsql attachment used the same prefix.

Responses, activity records, errors, and debug output omit environment values and the password. The add result names the Instance, slug, prefix, written key names, resolved host and port, whether stored configuration changed, and the total stored key count.

Add changes stored configuration only. The workload `.env` stays unchanged until the operator runs `orbit env:sync`.

## Same-node Docker Process host and port

A connection with a stored `node_id` can align with a Node-owned Docker Process on that Node. Alignment holds when one published port mapping on that Process uses the connection's port as the published host port or the container port.

When the Instance lives on that same Node, the Gateway writes host `127.0.0.1` and the mapping's published host port. When the Instance lives on another Node, or the connection has no aligned Docker Process, the Gateway writes the registry host and port.

## Remove a connection from an Instance

Remove deletes the mapping and the related stored keys for that prefix.

```text
orbit instance:database:remove app --instance=12 --force
```

`--prefix` defaults to `DB`. Remove deletes `PREFIX_CONNECTION`, `PREFIX_HOST`, `PREFIX_PORT`, `PREFIX_DATABASE`, `PREFIX_USERNAME`, and `PREFIX_PASSWORD` when they are stored. Other stored keys stay in place. An unknown attachment returns `database.attachment_missing` (HTTP 404).

## Add and remove API

The Gateway exposes add and remove on the Instance.

| Method | Path | Result |
| --- | --- | --- |
| `PUT` | `/api/v1/instances/{instance}/database-connections/{slug}` | Add the connection and write stored environment keys |
| `DELETE` | `/api/v1/instances/{instance}/database-connections/{slug}` | Remove the connection and clear the prefixed stored keys |

The `{instance}` selector is a positive Instance ID or an exact Route domain, as [Instance environment variables](/reference/environment-variables) describes. The optional JSON body accepts `prefix`. Omission uses `DB`. Access uses the Instance owning Node.

## Inspect attachments with Doctor

Doctor inspects database connections as the explicit `database_connection` family. It compares Gateway registry records and Instance attachment mappings with stored Instance environment keys. It does not write stored environment, start a database, or change a Node.

| Code | Kind | Meaning |
| --- | --- | --- |
| `database_connection.missing` | Drift | An attachment names a registry connection that is not present. |
| `database_connection.unhealthy` | Drift | A registry connection is missing required fields or names a Node that is gone. |
| `database_connection.env_mismatch` | Drift | An attachment's stored keys are missing, leftover, or different from the attach projection. |
| `database_connection.inspection_failed` | Unverifiable | Doctor could not read the registry password or stored environment. |

```bash
orbit doctor --node=<node-id> --family=database_connection
```

Responses, activity records, errors, and debug output omit environment values and the password.

## Restore stored environment

Run `orbit instance:database:add SLUG --instance=SELECTOR` with the same prefix. The Gateway re-projects stored keys from the registry using the same rules as the first add, including same-node Docker host `127.0.0.1` and the published host port, sqlite path keys, and leftover host or port removal on prefix reuse. Doctor does not write those keys. The workload `.env` stays unchanged until the operator runs `orbit env:sync`.
