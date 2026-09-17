---
title: "node:role:add"
description: "Add or converge one role assignment."
---

# node:role:add

Add one role assignment to a Node, or converge an existing assignment again.

```bash
orbit node:role:add <node> <role> [--converge] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `node` | yes | Node ID or registered name. |
| `role` | yes | Role name. |

| Option | Meaning |
| --- | --- |
| `--converge` | Re-run convergence for an assignment that is active or whose failed step starts with `converge:`. |

```bash
orbit node:role:add beast database
orbit node:role:add beast metrics --converge
```
