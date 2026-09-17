---
title: "instance:deploy-step:destroy"
description: "Remove one named deploy step."
---

# instance:deploy-step:destroy

Remove one named deploy step. Remaining steps keep their relative order. Confirm the named target at a default-No prompt, or pass `--yes`. JSON and noninteractive calls require `--yes`.

```bash
orbit instance:deploy-step:destroy <instance> <name> [--yes] [--json]
```

## Check

Run [`instance:deploy-step:list`](instance-deploy-step-list.md). The step is gone.
