---
title: "instance:deploy-step:update"
description: "Change one named deploy step."
---

# instance:deploy-step:update

Change one named deploy step. Options you omit stay unchanged.

```bash
orbit instance:deploy-step:update <instance> <name> [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |
| `name` | yes | Existing step name. |

| Option | Meaning |
| --- | --- |
| `--command=COMMAND` | New command. |
| `--phase=PHASE` | `before_activation` or `after_activation`. |
| `--timeout=SECONDS` | Timeout from 1 through 900 seconds. |
| `--before=NAME` | Place before this step in the same phase. Exclusive with `--after`. |
| `--after=NAME` | Place after this step in the same phase. Exclusive with `--before`. |

```bash
orbit instance:deploy-step:update 15 migrate --timeout=600
```

## Check

Run [`instance:deploy-step:list`](instance-deploy-step-list.md) and confirm the change.
