---
title: "schedule:enable"
description: "Enable and start an installed App instance timer."
---

# schedule:enable

Enable and start one installed App instance Schedule timer without replacing the Schedule. The command is idempotent.

```bash
orbit schedule:enable <schedule> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `schedule` | yes | Schedule UUID. |

Use it after `schedule:create --no-start` or for a production copy that preparation installed stopped. A failed activation restores the prior timer state or returns `schedule.rollback_failed` without claiming success.
