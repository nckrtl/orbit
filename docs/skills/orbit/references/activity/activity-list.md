---
title: "activity:list"
description: "List recent Gateway command activity."
---

# activity:list

List the most recent Activity records, newest first.

```bash
orbit activity:list [--limit=COUNT] [--request-id=UUID] [--json]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--limit=COUNT` | `25` | Maximum rows, from 1 through 200. The CLI rejects another value with `activity.limit_invalid`. |
| `--request-id=UUID` | none | Return only the attempt with this exact API request UUID. The CLI rejects a value that is not a UUID with `activity.request_id_invalid`. |

The Gateway excludes the list request itself from the result.

```bash
orbit activity:list --limit=50
orbit activity:list --request-id=9d6b1e5c-4a3f-4a1b-9f0e-6d2c3f5a7b81
```

Human output is one table with the columns ID, TIME, COMMAND, STATUS, CALLER, TARGET, and ERROR, followed by the request ID of the list call.
