# Database connections

This page tells an operator how the Gateway stores named mysql, pgsql, and sqlite connection records, which fields each driver requires, and how list, show, create, update, destroy, add, remove, and Doctor inspection behave. [ADR 0069](../decisions/0069-allow-node-process-targets.md) owns Node Process targets for shared Docker database servers, and [ADR 0070](../decisions/0070-keep-the-database-role-as-a-docker-baseline.md) owns the `database` role as a Docker baseline; this page owns the connection registry.

A Database connection is a Gateway-owned registry record. The operator registers a remote host or a sqlite path without assigning the `database` role. Node Processes start and stop Docker database servers. The registry does not start, stop, or query a database.

The operator adds a connection on an AppInstance only. Add writes prefixed keys into the Gateway-owned stored AppInstance environment under [ADR 0044](../decisions/0044-own-appinstance-environment-configuration-in-orbit.md). It does not write the workload `.env`. Run `orbit env:sync` after add or remove when the workload file must match stored configuration. [AppInstance environment variables](environment-variables.md) owns import, update, and synchronization.

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
| `orbit database:create SLUG --driver=DRIVER` | Create one connection and encrypt the supplied password. |
| `orbit database:update SLUG` | Replace the supplied fields on one connection. |
| `orbit database:destroy SLUG --force` | Destroy the connection record. |
| `orbit instance:database:add SLUG --instance=SELECTOR` | Add the connection on one AppInstance and write prefixed stored environment keys. |
| `orbit instance:database:remove SLUG --instance=SELECTOR --force` | Remove the connection from one AppInstance and clear the prefixed stored environment keys. |

Every command also accepts `--json`. Human and JSON results include the Gateway request ID. `database:destroy` and `instance:database:remove` require interactive confirmation or `--force` before they send the delete request. `database:update` requires at least one field option.

The create command accepts `--host`, `--port`, `--database`, `--path`, `--username`, `--password`, and `--node`. The update command accepts the same field options except the slug. An empty `--node` on update clears the stored Node association.

## API

The Gateway exposes the registry at `/api/v1/database-connections`. Access to the Gateway is fleet-wide.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List records ordered by slug |
| `POST` | `/api/v1/database-connections` | Create one record |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record |
| `PATCH` | `/api/v1/database-connections/{slug}` | Update supplied fields |
| `DELETE` | `/api/v1/database-connections/{slug}` | Destroy the record |

A duplicate slug returns `database.slug_conflict` (HTTP 409) and leaves the existing record unchanged. An unknown slug returns `http.404`. Destroying a record deletes that row when no AppInstance attachment exists. The Gateway answers `database.connection_attached` (HTTP 409) when an attachment still exists. Recovery of a stored password depends on retaining the Gateway encryption key material.

## Add a connection on an AppInstance

Add writes stored environment keys for one AppInstance. The target is an AppInstance ID or exact Route hostname. Orbit accepts no Workspace target.

```text
orbit instance:database:add app --instance=12
```

The optional `--prefix` value defaults to `DB`. A prefix is an uppercase name that starts with a letter and then uses letters, digits, or underscores, at most 32 characters. Add replaces an existing mapping that already uses that prefix on the same AppInstance.

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

Responses, activity records, errors, and debug output omit environment values and the password. The add result names the AppInstance, slug, prefix, written key names, resolved host and port, whether stored configuration changed, and the total stored key count.

Add changes stored configuration only. The workload `.env` stays unchanged until the operator runs `orbit env:sync`.

## Same-node Docker Process host and port

A connection with a stored `node_id` can align with a Node-owned Docker Process on that Node. Alignment holds when one published port mapping on that Process uses the connection's port as the published host port or the container port.

When the AppInstance lives on that same Node, the Gateway writes host `127.0.0.1` and the mapping's published host port. When the AppInstance lives on another Node, or the connection has no aligned Docker Process, the Gateway writes the registry host and port.

## Remove a connection from an AppInstance

Remove deletes the mapping and the related stored keys for that prefix.

```text
orbit instance:database:remove app --instance=12 --force
```

`--prefix` defaults to `DB`. Remove deletes `PREFIX_CONNECTION`, `PREFIX_HOST`, `PREFIX_PORT`, `PREFIX_DATABASE`, `PREFIX_USERNAME`, and `PREFIX_PASSWORD` when they are stored. Other stored keys stay in place. An unknown attachment returns `database.attachment_missing` (HTTP 404).

## Add and remove API

The Gateway exposes add and remove on the AppInstance.

| Method | Path | Result |
| --- | --- | --- |
| `PUT` | `/api/v1/instances/{instance}/database-connections/{slug}` | Add the connection and write stored environment keys |
| `DELETE` | `/api/v1/instances/{instance}/database-connections/{slug}` | Remove the connection and clear the prefixed stored keys |

The `{instance}` selector is a positive AppInstance ID or an exact Route hostname, as [AppInstance environment variables](environment-variables.md) describes. The optional JSON body accepts `prefix`. Omission uses `DB`. Access uses the AppInstance owning Node.

## Inspect attachments with Doctor

Doctor inspects database connections as the explicit `database_connection` family. It compares Gateway registry records and AppInstance attachment mappings with stored AppInstance environment keys. It does not write stored environment, start a database, or change a Node.

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

Run `orbit database:attach SLUG --instance=SELECTOR` with the same prefix. The Gateway re-projects stored keys from the registry using the same attach rules, including same-node Docker host `127.0.0.1` and the published host port, sqlite path keys, and leftover host or port removal on prefix reuse. Doctor does not write those keys. The workload `.env` stays unchanged until the operator runs `orbit env:sync`.
