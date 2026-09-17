---
title: "database:query"
description: "Run one SQL statement against a registered connection."
---

Run one SQL statement against a registered connection. The command is read-only unless `--write` is set. The Gateway refuses SQL that is not sent against a stored slug.

```bash
orbit database:query <slug> <sql> [--write] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Connection slug. |
| `sql` | yes | One SQL statement of at most 16384 characters. |

| Option | Meaning |
| --- | --- |
| `--write` | Allow a write statement. Omission returns `database.write_required` for write SQL. |

The Gateway answers `database.sql_multiple_statements` when the statement is stacked. SQLite query runs on the associated Node through the hidden Orbit CLI command that opens the file with PDO, and answers `database.sqlite_node_required` when that Node is missing. Query returns at most 500 rows and sets `truncated` when more remain. `--write` is write permission, not proof that rows mutated. Human output shows write permission, the reported row count, an empty-read message or Statement completed for a successful no-rowset write-enabled statement, and a truncation warning after the rowset without inventing omitted totals. JSON keeps `write`, `row_count`, and `truncated`.

Human query cells keep SQL null, empty string, and other scalars distinct: null renders as `NULL`, an empty string as `""`, booleans as `true` or `false`, and other values as safe text. Table headers stay uppercase. JSON cells stay exact, including JSON `null`.

```bash
orbit database:query app "SELECT id, email FROM users"
orbit database:query app "DELETE FROM users WHERE id = 1" --write
```
