---
title: "process:logs"
description: "Return a bounded log tail."
---

Return one non-streaming log tail for a Process. Docker environment values and credential-shaped data are redacted.

```bash
orbit process:logs <process> [--lines=COUNT] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `process` | yes | Numeric Process ID. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--lines=COUNT` | `100` | Number of lines, from 1 through 1,000. |

```bash
orbit process:logs 41 --lines=250
```
