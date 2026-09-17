---
title: "schedule:logs"
description: "Return a bounded log tail."
---

Return the newest complete lines that the Gateway reads from the exact Schedule service journal.

```bash
orbit schedule:logs <schedule> [--lines=COUNT] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `schedule` | yes | Schedule UUID. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--lines=COUNT` | `100` | Number of lines, from 1 through 1,000. |

The Gateway caps the response at 1 MiB with a 10-second deadline and sets `truncated` to `true` when the limit removed older or incomplete output. Orbit does not persist journal output.
