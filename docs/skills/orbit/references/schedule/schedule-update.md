---
title: "schedule:update"
description: "Replace one App Schedule definition."
---

# schedule:update

Replace one App Schedule definition with a complete specification.

```bash
orbit schedule:update <name> --app=APP --for=ENV[,ENV] --calendar=CALENDAR --command=COMMAND [--timeout=SECONDS] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Schedule definition name. |

Changing a definition affects later copies only. To change an existing App instance Schedule, destroy it and create its replacement.
