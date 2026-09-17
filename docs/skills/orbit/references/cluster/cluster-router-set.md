---
title: "cluster:router:set"
description: "Set or replace the Router."
---

# cluster:router:set

Set or replace the Cluster Router. The Router must be an active member Node.

```bash
orbit cluster:router:set <cluster> <node>
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `cluster` | yes | Numeric Cluster ID. |
| `node` | yes | Numeric Node ID of an active member. |

```bash
orbit cluster:router:set 3 7
```

Replacement keeps the current Router active until its replacement is ready, promotes the candidate, and then cleans up the old assignment. Each Cluster has one Router operation owner; a concurrent Router change on the same Cluster waits up to 30 seconds and then receives `cluster.router_busy`. A Node that is not an active member returns `cluster.router_node_invalid`.
