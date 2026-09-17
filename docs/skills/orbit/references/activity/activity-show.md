---
title: "activity:show"
description: "Show one Gateway command activity attempt."
---

# activity:show

Show one Activity attempt.

```bash
orbit activity:show <activity> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `activity` | yes | Numeric Activity ID from `activity:list`. |

```bash
orbit activity:show 481
```

Human output uses a detail tree with the command and status, the request ID the Gateway assigned to that attempt, the time, duration, exit code, and error code. JSON output returns every field below.
