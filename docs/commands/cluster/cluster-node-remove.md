---
title: "cluster:node:remove"
description: "Detach a Node."
---

Detach a Node from a Cluster. Routes on that Node return to Node scope before membership ends. Generated domains fall back to the Node TLD.

```bash
orbit cluster:node:remove <cluster> <node> [--force]
```

| Option | Meaning |
| --- | --- |
| `--force` | Skip the confirmation prompt. Required in non-interactive and `--json` calls. |

```bash
orbit cluster:node:remove 3 7 --force
```

| Error code | Meaning |
| --- | --- |
| `cluster.membership_missing` | The Node does not belong to this Cluster. |
| `cluster.router_detach_forbidden` | The Node is the Cluster Router; clear the Router first. |
| `cluster.ingress_detach_forbidden` | The Node carries the `ingress` role; remove that role first. |
| `cluster.tld_conflict` | The Node shares the active Cluster TLD and cannot leave while that TLD is active. |
| `cluster.confirmation_required` | The call was non-interactive without `--force`. |
