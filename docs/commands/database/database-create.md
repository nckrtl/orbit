---
title: "database:create"
description: "Create one connection record."
---

Create one connection with a unique slug. The Gateway encrypts the password before it writes the row.

```bash
orbit database:create <slug> --driver=DRIVER [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Lowercase kebab name of at most 63 characters. |

| Option | Meaning |
| --- | --- |
| `--driver=DRIVER` | `mysql`, `pgsql`, or `sqlite`. |
| `--node=NODE` | Numeric Node ID or registered Node name to associate. The `database` role is not required. |
| `--host=HOST` | Hostname or IP address for mysql and pgsql, without userinfo or port. |
| `--port=PORT` | TCP port for mysql and pgsql. Defaults to `3306` for mysql and `5432` for pgsql. |
| `--database=NAME` | Database name for mysql and pgsql. |
| `--path=PATH` | Unix absolute sqlite path of at most 1024 characters. |
| `--username=USER` | Username. Required for mysql and pgsql. |
| `--password=SECRET` | Password. Required for mysql and pgsql. |

| Driver | Required fields | Optional fields |
| --- | --- | --- |
| `mysql` | `--host`, `--database`, `--username`, `--password` | `--node`, `--port` |
| `pgsql` | `--host`, `--database`, `--username`, `--password` | `--node`, `--port` |
| `sqlite` | `--path` | `--node`, `--username`, `--password` |

```bash
orbit database:create app --driver=mysql --host=db.example.test --database=app --username=app --password=secret
orbit database:create local --driver=sqlite --path=/var/lib/app/database.sqlite
orbit database:create postgres --driver=pgsql --node=beast --host=127.0.0.1 --database=app --username=app --password=secret
```

The Gateway answers `validation.failed` when a mysql or pgsql record includes `--path`, when a sqlite record includes `--host`, `--port`, or `--database`, or when the request includes an unsupported field. A duplicate slug returns `database.slug_conflict` and leaves the existing record unchanged.

<Note>
A connection with `--node` can align with a Node-owned Docker Process on that Node. When an App instance on the same Node attaches it, the Gateway writes `127.0.0.1` and the published host port instead of the stored host and port.
</Note>
