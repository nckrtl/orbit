---
title: "instance:update"
description: "Change the deployment branch of a production App instance."
---

Change the deployment branch of a production App instance. The Gateway stores the branch and does not change deploy steps or start a deployment.

The human result shows the accepted deployment branch and the currently selected source branch. JSON returns the existing App instance response; its `selected_branch` describes the source, not the newly stored deployment setting.

```bash
orbit instance:update <instance> --branch=BRANCH [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |

| Option | Meaning |
| --- | --- |
| `--branch=BRANCH` | Deployment branch. A later App default change does not replace it. |

The Gateway refuses a development App instance with a bounded conflict before it stores a branch.

## Use it when

Use this command when the next production deployment must use another branch. It changes nothing until [`instance:deploy`](instance-deploy.md) runs.

## Check

The JSON `selected_branch` describes the current source, not the new setting. Confirm the new branch after the next deployment.
