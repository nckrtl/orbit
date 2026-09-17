---
title: "database:destroy"
description: "Destroy one connection record."
---

# database:destroy

Destroy one connection record.

```bash
orbit database:destroy <slug> [--force] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Connection slug. |

| Option | Meaning |
| --- | --- |
| `--force` | Confirm destruction without prompting. Interactive confirmation defaults to No, and a JSON or non-interactive call without `--force` returns `database.confirmation_required`. Interactive decline, Ctrl-C, and EOF cancel with `input.cancelled`. |

```bash
orbit database:destroy app --force
```

> **Warning:** The Gateway answers `database.connection_attached` while any App instance still attaches the connection. Remove those attachments with `instance:database:remove` first.
