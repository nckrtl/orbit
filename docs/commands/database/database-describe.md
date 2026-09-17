---
title: "database:describe"
description: "Show columns for one table on a registered connection."
---

Show columns for one table on a registered connection. An unknown table returns `database.table_missing`.

```bash
orbit database:describe <slug> <table> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Connection slug. |
| `table` | yes | Table name of letters, digits, and underscores, at most 64 characters. |

```bash
orbit database:describe app users
```
