---
title: "node:role:remove"
description: "Remove one role assignment and its dependent state."
---

# node:role:remove

Remove one role assignment from a Node. Removing the last role restores the public SSH recovery rule before the Gateway deletes the assignment.

```bash
orbit node:role:remove <node> <role> [--force] [--purge-data] [--offline] [--json]
```

| Option | Meaning |
| --- | --- |
| `--force` | Confirm destructive role removal and dependent cleanup. |
| `--purge-data` | Request supported role-owned data cleanup. For `metrics`, this deletes the Prometheus and Grafana volumes and stored credentials. |
| `--offline` | Remove the role from a Node the Gateway cannot reach. |

```bash
orbit node:role:remove beast database --force
```

A role that still hosts a Route target, such as `app-dev` or `app-prod` with active App instances, is refused until those App instances are removed.
