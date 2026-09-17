---
title: "schedule:run"
description: "Run one Schedule now without changing its timer."
---

Start the installed oneshot service once without waiting for completion and without changing the desired timer state.

```bash
orbit schedule:run <schedule> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `schedule` | yes | Schedule UUID. |

The timer never overlaps an active execution. A failed start returns `schedule.run_failed`.
