---
title: "cluster:destroy"
description: "Remove an empty Cluster."
---

Remove an empty Cluster.

```bash
orbit cluster:destroy <cluster> [--force]
```

| Option | Meaning |
| --- | --- |
| `--force` | Skip the confirmation prompt. Required in non-interactive and `--json` calls. |

```bash
orbit cluster:destroy 3 --force
```

The Gateway refuses a Cluster that still has member Nodes (`cluster.not_empty`) or still scopes Routes (`cluster.has_routes`).
