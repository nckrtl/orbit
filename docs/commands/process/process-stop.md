---
title: "process:stop"
description: "Stop one Process and record the stopped desired state."
---

Stop an installed Process and record the stopped desired state. After a stop, hibernation wake leaves the Process stopped.

```bash
orbit process:stop <process> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `process` | yes | Numeric Process ID. |
