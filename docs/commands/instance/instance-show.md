---
title: "instance:show"
description: "Show one App instance with its Route, source, and deploy steps."
---

Show one App instance. Production output includes the recorded user, home, selected branch, and deploy steps in phase and placement order.

```bash
orbit instance:show <instance> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |

## Use it when

Use this command to check the result of another `instance` command, or to read the Route domain, source, or production settings of one App instance.
