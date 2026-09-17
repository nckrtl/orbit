---
title: "database:update"
description: "Replace the supplied fields on one connection."
---

Replace the supplied fields on one connection. The command requires at least one field option and accepts the same field options as `database:create`.

```bash
orbit database:update <slug> [options]
```

| Option | Meaning |
| --- | --- |
| `--driver=DRIVER` | `mysql`, `pgsql`, or `sqlite`. |
| `--node=NODE` | Node ID or registered name. An empty value, `--node=`, clears the association. |
| `--host=HOST` | Hostname or IP for mysql and pgsql. |
| `--port=PORT` | TCP port for mysql and pgsql. |
| `--database=NAME` | Database name for mysql and pgsql. |
| `--path=PATH` | Unix absolute sqlite path. |
| `--username=USER` | Username. |
| `--password=SECRET` | Password. |

```bash
orbit database:update app --password=rotated-secret
orbit database:update app --node=
```

Updating a record does not rewrite the keys already stored on attached App instances. Run `instance:database:add` again with the same prefix to re-project them, then `env:sync` to install them.
