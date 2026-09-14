# Database connections

This page tells an operator how the Gateway stores named mysql, pgsql, and sqlite connection records, which fields each driver requires, and how list, show, add, update, and remove behave. [ADR 0069](../decisions/0069-allow-node-process-targets.md) owns Node Process targets for shared Docker database servers, and [ADR 0070](../decisions/0070-keep-the-database-role-as-a-docker-baseline.md) owns the `database` role as a Docker baseline; this page owns the connection registry.

A Database connection is a Gateway-owned registry record. The operator registers a remote host or a sqlite path without assigning the `database` role. Node Processes start and stop Docker database servers. The registry does not start, stop, or query a database.

## Register a connection

Add one record with a unique slug:

```text
orbit database:add app --driver=mysql --host=db.example.test --database=app --username=app --password=secret
```

The slug is a lowercase kebab name of at most 63 characters. The Gateway encrypts the password with its application encryption key before it writes the row. Responses, activity records, errors, and debug output omit the password and replace a password-shaped value with `[REDACTED]`.

The optional `--node` value is a numeric Node ID or a registered Node name. The Gateway stores that Node as an association. It does not require the `database` role on that Node, and it accepts a record with no Node for a remote or external host.

SQLite uses a Unix absolute path instead of host, port, and database name:

```text
orbit database:add local --driver=sqlite --path=/var/lib/app/database.sqlite
```

## Drivers and fields

Each driver stores one complete connection profile.

| Driver | Required fields | Optional fields | Default port |
| --- | --- | --- | --- |
| `mysql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `3306` |
| `pgsql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `5432` |
| `sqlite` | `path` | `node_id`, `username`, `password` | none |

The Gateway answers `validation.failed` when a mysql or pgsql record includes `path`, when a sqlite record includes `host`, `port`, or `database`, or when the request includes an unsupported key. A sqlite `path` is a nonempty Unix absolute path of at most 1024 characters. A `host` is a hostname or IP address without userinfo or a port. A `port` is an integer from 1 through 65535. `database` and `username` are bounded printable names.

The API and PHP software development kit (SDK) return this identity for each record.

| Field | Meaning |
| --- | --- |
| `id` | Numeric registry ID |
| `slug` | Unique connection name |
| `driver` | `mysql`, `pgsql`, or `sqlite` |
| `node_id` | Optional associated Node ID |
| `host` | Hostname or IP for mysql and pgsql |
| `port` | TCP port for mysql and pgsql |
| `database` | Database name for mysql and pgsql |
| `path` | Unix absolute sqlite path |
| `username` | Stored username, or null |
| `has_password` | Whether a password is stored |

Item and collection responses never include the password.

## Commands

The CLI sends each operation through the Gateway.

| Command | Result |
| --- | --- |
| `orbit database:list` | List every registered connection without passwords. |
| `orbit database:show SLUG` | Show one connection without the password. |
| `orbit database:add SLUG --driver=DRIVER` | Create one connection and encrypt the supplied password. |
| `orbit database:update SLUG` | Replace the supplied fields on one connection. |
| `orbit database:remove SLUG --force` | Delete the connection record. |

Every command also accepts `--json`. Human and JSON results include the Gateway request ID. `database:remove` requires interactive confirmation or `--force` before it sends the delete request. `database:update` requires at least one field option.

The add command accepts `--host`, `--port`, `--database`, `--path`, `--username`, `--password`, and `--node`. The update command accepts the same field options except the slug. An empty `--node` on update clears the stored Node association.

## API

The Gateway exposes the registry at `/api/v1/database-connections`. Access to the Gateway is fleet-wide.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List records ordered by slug |
| `POST` | `/api/v1/database-connections` | Create one record |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record |
| `PATCH` | `/api/v1/database-connections/{slug}` | Update supplied fields |
| `DELETE` | `/api/v1/database-connections/{slug}` | Remove the record |

A duplicate slug returns `database.slug_conflict` (HTTP 409) and leaves the existing record unchanged. An unknown slug returns `http.404`. Removing a record deletes that row. Recovery of a stored password depends on retaining the Gateway encryption key material.
