---
title: "process:start"
description: "Start one Process and record the running desired state."
---

# process:start

Start an installed Process and record the running desired state.

```bash
orbit process:start <process> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `process` | yes | Numeric Process ID. |

Starting an App instance Process requires an active App instance on a reachable active Node. Starting a Node Process requires a reachable active managed Node. A competitor for the same App instance waits for at most 30 seconds and then receives `process.operation_busy`; a competitor for the same Process receives `process.runtime_lock_failed`.
