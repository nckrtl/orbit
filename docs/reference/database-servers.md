---
title: "Database servers"
description: "How Orbit runs a MySQL or Valkey server as a Docker Node Process, keeps its admin password only in the Gateway, and creates databases and ACL users on it."
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

A Database server is a MySQL or Valkey server that Orbit runs and administers. It runs as a Docker [Node Process](/reference/processes-and-schedules#owners), and the Gateway keeps its admin password. Orbit uses that password to create a database or an ACL user, and to [clone](/domains/applications#database-clone) the default Instance's MySQL database. [`database:server`](/cli/database#orbit-databaseservercreate) lists the commands.

A server's `driver` names its kind. A `mysql` server runs MySQL, and a `redis` server runs Valkey. A Valkey server's clients use the [`redis` driver](/reference/database-connections#drivers-and-fields), so the server uses the same name.

## Create a server

`database:server:create` creates the server and its Process in one command.

```bash
orbit database:server:create beast-mysql --node=beast
orbit database:server:create beast-valkey --node=beast --driver=redis
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=NODE` | required | Numeric Node ID or Node name. The Node must be an active Linux Node with Docker. |
| `--driver=DRIVER` | `mysql` | `mysql` or `redis`. |
| `--tag=TAG` | `8.4` for mysql, `8.1` for redis | Image tag. The image is `mysql:<tag>` or `valkey/valkey:<tag>`. |
| `--port=PORT` | `3306` for mysql, `6379` for redis | Port that the server publishes on the Node's WireGuard address. |

### A MySQL server

The Gateway creates a MySQL server in this order:

1. It generates a random root password and stores it encrypted on the server record.
2. It creates a Docker Node Process named `<slug>` with image `mysql:<tag>`, the port published on the Node's WireGuard address to container port `3306`, and the volume `orbit-<slug>-data` for `/var/lib/mysql`.
3. It starts the Process with `MYSQL_ROOT_PASSWORD` set and waits until MySQL accepts the root login.
4. It removes the variable from the Process and recreates the container. The data volume keeps the password.

After step 4, the root password exists only encrypted in the Gateway. It is not in the Process record, the container environment, `docker inspect`, or a file on the Node. Orbit waits for the root login over TCP inside the container, which only the final MySQL server accepts, so it never recreates the container while MySQL still initializes its data directory.

### A Valkey server

The Gateway creates a Valkey server in this order:

1. It generates a random admin password and stores it encrypted on the server record.
2. It writes the ACL file `/data/users.acl` into the volume `orbit-<slug>-data` with a short-lived container. The file has one line, `user default on #<sha256 of the admin password> ~* &* +@all`.
3. It creates and starts a Docker Node Process named `<slug>` with image `valkey/valkey:<tag>`, the volume at `/data`, and no environment.
4. It waits until the user `default` can log in with the admin password.

The Process publishes its port on the Node's WireGuard address to container port `6379`, and runs `valkey-server --appendonly yes --dir /data --aclfile /data/users.acl`. `default` is the admin user, and it always has the Gateway's password. The ACL file holds a SHA-256 hash of it, never the password. Valkey refuses to start when the ACL file is missing or has a wrong line, so a damaged server stays closed. The append-only file in the volume keeps the data across a restart.

### Retry and response

A retry of the same request continues from the first step that did not finish. A request with the slug of an existing server and another Node, driver, tag, or port returns `database.server_slug_conflict`. A repeat of a finished request returns the server with status 200.

The response holds `id`, `slug`, `driver`, `node_id`, `process_id`, `tag`, `port`, `status`, and `databases_count`. `database:server:show` also returns `databases`, the connections on the server. No response, Activity entry, or error contains the password.

The server's Process is an ordinary Docker Node Process: read its logs, stop it, start it, or restart it with the [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; remove the server instead. `process:update` refuses the Process of a `redis` server with the same code, so the command always keeps `--aclfile`.

## Adopt a Valkey Process

`database:server:create` with `--process` and `--connection` brings an existing Valkey Process under Orbit, without a new volume and without a data move. The option exists only to adopt the `valkey` Processes on `services` and `beast` ([ADR 0221](/decisions/0221-run-valkey-database-servers-with-orbit-owned-acl-users)). A later version removes it.

```bash
orbit database:server:create services-valkey --node=services --driver=redis --process=162 --connection=services-valkey
```

The Gateway refuses with `database.server_adoption_refused` and changes nothing unless all of these hold:

- The driver is `redis`, and the Process is a Docker Process of that Node with a `valkey/valkey` image and a volume at `/data`.
- The Process publishes container port `6379` on the Node's WireGuard address. That host port becomes the server's port.
- No server owns the Process yet.
- The live ACL has no user other than `default`. Adoption would remove other users.
- The connection is a `redis` connection to that address and port.
- The connection logs in as `default`, with its password or without a password.

The adoption never locks out a client that works today:

- When the connection has a password, that password becomes the admin password, and the ACL file's `default` line holds its hash.
- When the connection has no password, the Gateway generates the admin password. The ACL file's `default` line is `user default on nopass ~* &* +@all`.
- A server adopted without a password stays as open as it was until you [close it](#close-an-adopted-server).

Then the Gateway writes `/data/users.acl` into the Process's volume, and replaces the Process specification with the one above. The Process keeps its ID, name, volume, and port. It waits for the admin login, and links the connection to the server. Valkey restarts once and reads its append-only file again, so its data stays. The connection's clients keep logging in as `default` as before.

## Close an adopted server

`database:server:close` gives `default` the admin password, so a client without a password gets no access. Run it after each client of an open server has [its own user](#create-an-acl-user-on-a-valkey-server).

```bash
orbit database:server:close beast-valkey
```

The Gateway runs `ACL SETUSER default resetpass #<sha256 of the admin password>` and `ACL SAVE`. It refuses with `database.server_in_use` (409) and changes nothing while either of these holds:

- A connection on the server has no password. Its clients would be locked out. Destroy it, or move its clients first.
- A client other than the Gateway is logged in as `default`. The message gives the number of clients and their addresses.

A server whose `default` already has the admin password returns at once, so the command is safe to repeat. A client that is not connected at that moment is not seen, so move every client before you close the server. Like adoption, the command exists only for the two adopted Processes.

## Create a database on a server

`database:create` with `--server` creates a database and a user on a MySQL server, then records the [connection](/reference/database-connections).

```bash
orbit database:create dlf-leden --server=beast-mysql --instance=60
```

The database name comes from the slug, with hyphens replaced by underscores. A database name longer than 64 characters, or a user name longer than 32, ends in an underscore and an 8-character hash. Orbit generates the user's password.

Each Instance has one user on a server, named `<project>_<instance>` like its databases. A second database for the same Instance reuses that user and its password. That user can reach only the databases the Instance owns and their test databases. A database created without `--instance` gets its own user, named like the database. The connection gets driver `mysql`, the server's Node address and port, and a link to the server.

Orbit refuses a name with `database.name_conflict` when the server already has that database or user, or when the name starts with the test database name of another database on the server. So one Instance's user never reaches another database through its test database grant. A create that fails drops what it made and records nothing.

With `--instance`, Orbit also creates the test database `<name>_test`, attaches the connection to the Instance under prefix `DB`, and records the Instance as the owner. [Test databases](/reference/database-connections#test-databases) describes how tests use it.

`database:create --instance` needs an existing Instance, so it comes after that Instance's setup steps. To have the database before setup runs, pass the server to [`instance:create --database-server`](/domains/applications#database-on-a-server) instead.

## Create an ACL user on a Valkey server

On a `redis` server, `database:create` with `--server` creates one Valkey ACL user and records a `redis` connection that logs in as that user. The user can reach only the keys that match its patterns, with only the commands it is allowed.

```bash
orbit database:create gateway-queue --server=services-valkey --database=1 \
  --pattern='orbit-gateway:*' --pattern='orbit-gateway-horizon:*' \
  --allow=@connection --allow=@transaction --allow=@keyspace --allow=@string --allow=@list \
  --allow=@hash --allow=@set --allow=@sortedset --allow=eval --allow=time
```

| Option | Meaning |
| --- | --- |
| `--pattern=PATTERN` | A key pattern, such as `orbit-gateway:*`. Required. Repeat it for more patterns. |
| `--allow=RULE` | A command category, such as `@list`, or a command, such as `eval`. Required. Repeat it for more rules. |
| `--database=INDEX` | The database index that the connection records for its clients. Optional. Must be below the live server's database count. |

The Gateway checks an explicit index against the live server before it creates or updates a connection. An out-of-range index returns `validation.failed`. New servers have 16 databases, numbered 0 through 15.

The user is named after the slug. Orbit generates its password and runs:

```text
ACL SETUSER <slug> reset on #<sha256 of the password> resetchannels ~<pattern> ... -@all +<rule> ... -@admin -@dangerous
ACL SAVE
```

Orbit adds every part except the patterns and the rules. So a user never has pub/sub channels or another user's password, and the final `-@admin -@dangerous` removes every admin and dangerous command, also one that a category such as `@keyspace` holds. No user can change the ACL or the server's configuration.

The connection gets driver `redis`, the server's Node address and port, the `--database` index, the username `<slug>`, the encrypted password, and a link to the server. Orbit also records the user with the rules that Valkey reports, so [Doctor](#check-a-valkey-server-with-doctor) can compare them. `database:user:list` shows those rules.

The slug `default` is refused. A user that the server already has, and that Orbit did not record, returns `database.name_conflict`. A rule that Valkey does not know returns `database.acl_rules_invalid` with Valkey's message. A create that fails removes the user and records nothing. Concurrent ACL changes return `database.server_in_use`; retry after the other command finishes.

`database:update` can change the database index, but cannot change a managed Valkey connection's driver, Node, address, port, username, or password. Such a change returns `database.server_connection_managed` (409). Create a new connection and move its clients instead.

Key patterns limit keys, not the database index. Valkey 8 has no ACL rule for an index, so a user can select any index, and its patterns apply in each one. `SCAN` and `DBSIZE` show the names and the count of all keys in an index, but no values. A user that can write can also fill the server's memory, because Orbit sets no memory limit.

`--instance`, [`instance:create --database-server`](/domains/applications#database-on-a-server), and the Instance database clone refuse a `redis` server with `database.driver_unsupported`.

## Add a user

`database:user:create` adds a user to a database on a MySQL server, for example a read-only user in production. `database:user:list` lists the users that Orbit created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --password=SECRET --read-only
```

A read-only user gets `SELECT` on the database. Any other user gets all privileges on it. Running the command again sets the password and replaces the privileges. A connection without a server returns `database.server_required` (422). A connection on a `redis` server returns `database.driver_unsupported` (422): on Valkey, each user is a connection of its own. The user of a connection on the same server returns `database.name_conflict` (409), so the command never changes the password that a connection uses. The Gateway stores the user's password encrypted, so it can [publish it to Vaultwarden](/reference/vaultwarden#database-user-passwords). No response returns it.

## How Orbit runs admin commands

On a MySQL server, every admin command runs `mysql` or `mysqldump` inside the server's container with `docker exec`, over SSH. The root password travels on protected standard input, and the remote script hands it to `docker exec` as `MYSQL_PWD` through the environment. The SQL follows it on standard input. Neither appears as an argument, in a file, or in a log.

On a Valkey server, the Gateway connects to the Node's WireGuard address and the server's port, as every client does, and logs in as `default`. A user's password goes to Valkey only as a SHA-256 hash. The only Node program is the one that writes the first ACL file. It receives the file on standard input and writes it through a short-lived container that mounts the volume. The file holds only the hash.

## Remove a database

`database:destroy` of a connection on a MySQL server drops the database, every database whose name starts with its test database name, and each user Orbit created for it that no other connection on the server uses. Then it deletes the record. A failed drop keeps the record, and a repeat drops again.

On a Valkey server, `database:destroy` deletes the connection's ACL user with `ACL DELUSER` and runs `ACL SAVE`. The user's keys stay. A connection without a username, such as one linked by adoption, logs in as `default`, so destroying it deletes only the record.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while a connection points to the server. Otherwise it removes the Process and the server record. It keeps the volume, so the data survives. Remove the volume on the Node by hand when its data must go.

A new Valkey server with the slug of a removed one finds the old volume. It writes a new ACL file there, so the old users are gone, but the old keys stay.

A Node that runs a server cannot be removed. [Node removal](/reference/node-provisioning#remove-a-node) refuses it with `node.has_database_servers`, also offline.

## Check a Valkey server with Doctor

The [Doctor](/cli/doctor) family `database_server` checks each Valkey server whose Process has the desired state `running`. It connects as the Gateway does and reports drift when:

| Code | Meaning |
| --- | --- |
| `database_server.open` | A client without a password can run a command. |
| `database_server.acl_file_missing` | Valkey does not run with the ACL file `/data/users.acl`. |
| `database_server.acl_mismatch` | The live users differ from the recorded users. |
| `database_server.inspection_failed` | Doctor could not connect, or `default` could not log in with the admin password. |

The recorded users are `default` with the admin password and all rights, and one user for each connection on the server that has a username, with its password and rules. A user that someone added by hand is drift too. An adopted server that is not closed yet reports `database_server.open` and `database_server.acl_mismatch`. The report names no user, rule, or hash. MySQL servers have no checks in this family yet.

## Errors

The server commands return these codes.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_slug_conflict` | 409 | Another server has the slug. |
| `database.server_port_in_use` | 409 | Another Process on the Node publishes the port. |
| `database.server_start_failed` | 502 | The server did not accept the admin login within 120 seconds. |
| `database.server_in_use` | 409 | A connection still points to the server, an ACL change is running, or closing would lock out a client. |
| `database.server_connection_managed` | 409 | A change would replace a managed Valkey connection's identity or credentials. |
| `database.server_inactive` | 409 | The server is not active yet. Run `database:server:create` again to finish it. |
| `database.server_adoption_refused` | 409 | The Process or the connection cannot be adopted. The message names the check that failed. |
| `database.server_required` | 422 | The connection has no server, so Orbit cannot create a user on it. |
| `database.driver_unsupported` | 422 | The command does not work on a `redis` server. |
| `database.acl_rules_invalid` | 422 | Valkey refused a key pattern or a rule. |
| `database.name_conflict` | 409 | The name is taken on the server. |
| `process.required_by_database_server` | 409 | `process:destroy` names a server's Process, or `process:update` names a Valkey server's Process. |
| `database.server_command_failed` | 502 | An admin command on the server failed. The message holds no SQL output, rule, or password. |

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

### Valkey starts only from Orbit's ACL file

With an ACL file, Valkey ignores `requirepass`. A file without a `default` line, or a start without `--aclfile`, gives every client full access without a password. So Orbit writes the file before the first start, always with a `default` line, and refuses `process:update` of the Process. A missing or broken file stops Valkey instead of opening it. New servers require a password. The [open adoption](#adopt-a-valkey-process) is the temporary exception. Doctor reports that exception until the operator closes it.

### One Valkey user is one connection

A Valkey user limits keys and commands, not databases. A client reaches Valkey through one connection with one username and password. So a user and its connection are one record, made by one command, and `database:user:create` stays MySQL-only.

### Orbit owns the frame of each user

The caller names only key patterns and allowed commands. Orbit adds the reset, the password, no channels, and the final deny of `@admin` and `@dangerous`. So no input can give a user the ACL commands, the configuration, or another password. A raw rule string as input was rejected for the same reason.

### Valkey admin over WireGuard

`valkey-cli` exits 0 on an error reply when it reads commands from standard input, so a script inside the container cannot tell a refused `ACL SETUSER` from a done one. The Gateway already reaches each server over WireGuard, like every client. So it runs the admin commands itself and reads each reply, and only the first ACL file needs a Node program.

### Adoption without a data move

The Valkey on `services` holds the Gateway queue and the collector's state, and the Gateway already holds its password. Adoption keeps the volume and the port and restarts Valkey once. A new server and a move of the data would need a queue outage or a copy tool, a release, and a new collector setup. Adoption is refused for MySQL, whose root password may be unknown.
