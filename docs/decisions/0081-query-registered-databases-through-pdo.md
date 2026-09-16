---
title: "ADR 0081: Query registered databases through PDO"
sidebarTitle: "0081 Query registered databases through PDO"
description: "Proposed. Inspect mysql, pgsql, and sqlite through PDO. SQLite runs on the owning Node via a hidden Orbit CLI lane."
---

# ADR 0081: Query registered databases through PDO

Operators inspect a registered connection with one public surface: `database:query`, `database:tables`, `database:schema`, and `database:describe`. Every driver executes that SQL through PDO. MySQL and PostgreSQL run in-process on the Gateway. SQLite runs on the owning Node through a hidden Orbit CLI command that a lane token gates and that opens the file with PDO. The inspector never invokes `sqlite3`.

## Status

Proposed.

This proposal amends the binary-client consequence in [ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions) that said a published CLI binary does not restore a local SQLite or PDO query path on a Node. It preserves ADR 0079's artifact, builder, and Ops-owned fleet-distribution boundaries. It keeps the public verb surface in [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk).

## Context

The Gateway already inspects mysql and pgsql with `PdoDatabaseInspector`. SQLite files live on the owning Node, so the Gateway cannot open them. The previous Node path SSHed to that Node and ran `sqlite3 -json` with SQL on protected stdin.

`sqlite3` is a second execution engine. Its JSON shape, readonly flag, and host package are not the Laravel PDO path operators already use for the other drivers. Nick required one PDO tool for postgres, mysql, and sqlite.

[ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions) ships an `orbit` binary onto Nodes. The CLI can now execute PDO on that Node. The public CLI remains an HTTP client. A hidden internal command is the Node-side PDO process, not a new operator verb.

The CLI project does not add Eloquent, migrations, or a persisted database connection. The on-node lane builds a Laravel-shaped connection config and opens PHP PDO. That matches the Gateway inspector without adding CLI database state.

## Decision

- Public inspection stays on the current Gateway API and CLI verbs. The operator never names a host, path, username, or password on the query request.
- MySQL and PostgreSQL inspection must run in-process on the Gateway through `PdoDatabaseInspector`.
- SQLite inspection must run on the associated active Node. The Gateway answers `database.sqlite_node_required` when that connection has no Node.
- The Gateway's remote argv for SQLite must be the fixed command `orbit internal:database-local`. It must not invoke `sqlite3`.
- The hidden command must read a JSON envelope from protected stdin. The envelope carries a lane token, the stored absolute path, the SQL, and the write flag. Those values must not enter argv.
- The hidden command must open the SQLite file with PDO. A read-only request must open the file read-only. The command must return the same column, row, row-count, and truncated JSON the Gateway inspector already uses.
- The lane token is a 64-character hex value the Gateway generates per invocation. When `ORBIT_INTERNAL_DATABASE_TOKEN` is set on the Node process, the stdin token must match it. SSH from the Gateway remains the authorization boundary.
- Write permission, stacked-statement refusal, row limits, and password redaction stay on the Gateway. `--write` remains permission, not proof that rows mutated.
- The hidden command is not a public product verb and is not part of the ADR 0071 operator vocabulary. The CLI must keep it hidden.

## Rejected alternatives

- Keep `sqlite3 -json` on the Node: it is a second engine and contradicts the PDO requirement.
- Open SQLite from the Gateway: the file is not on the Gateway host.
- Send mysql and pgsql through the on-node CLI: those drivers are reachable from the Gateway and already use PDO in-process.
- Put SQL, the path, or the token on argv: secret and statement bytes must stay off process lists.
- Expose `database:add` or other retired verbs: ADR 0071 owns the public surface.
- Add Eloquent or a persisted CLI database connection: the CLI remains stateless except for `$ORBIT_HOME`.

## Consequences

- Operators keep one query, tables, schema, and describe surface for every registered driver.
- SQLite inspection requires an `orbit` binary or checkout on the owning Node. Fleet distribution stays with Orbit Ops under ADR 0079.
- A Node without `orbit` on `PATH` fails SQLite inspection with `database.query_failed`.
- The hidden command can open a local SQLite file if an operator crafts a valid stdin envelope. SSH remains the control-plane gate; the token binds the envelope.

## Affects

- Components: apps/cli, apps/gateway, apps/docs
- ADRs: amends [ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions); preserves [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk)
- Detail: [Database connections](/reference/database-connections)
- Verify: Gateway inspection tests that SQLite remote argv is `orbit internal:database-local` and never `sqlite3`; PDO inspector tests for mysql, pgsql, and local sqlite; CLI tests for the hidden token-gated PDO lane
