---
title: "schedule:show"
description: "Show one Schedule or one definition."
---

Show one authorized Schedule, including its command text, or one definition by name.

```bash
orbit schedule:show <schedule> [--app=APP] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `schedule` | yes | Schedule UUID, or the definition name with `--app`. |

| Option | Meaning |
| --- | --- |
| `--app=APP` | Numeric App ID. Selects a definition. |

The result includes `desired_timer_state`, `last_run_at`, and `last_run_status` for an installed Schedule.
