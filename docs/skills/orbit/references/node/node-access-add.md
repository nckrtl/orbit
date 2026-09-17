---
title: "node:access:add"
description: "Allow one Node to run commands on another Node."
---

# node:access:add

Allow one Node to run Gateway commands on another Node. Access edges authorize Orbit commands and Gateway API actions; they do not change private network reachability, which WireGuard membership already grants.

```bash
orbit node:access:add <consumer> <serving> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `consumer` | yes | Numeric ID of the Node that sends commands. |
| `serving` | yes | Numeric ID of the Node that receives them. |

```bash
orbit node:access:add 3 7
```
