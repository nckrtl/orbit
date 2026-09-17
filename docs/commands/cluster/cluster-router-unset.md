---
title: "cluster:router:unset"
description: "Clear the Router from an inactive Cluster."
---

Clear the Router from a Cluster that is inactive or has no TLD.

```bash
orbit cluster:router:unset <cluster> [--force]
```

| Option | Meaning |
| --- | --- |
| `--force` | Skip the confirmation prompt. Required in non-interactive and `--json` calls. |

<Warning>
The Gateway refuses to clear the Router while the Cluster owns a Route (`cluster.routes_require_router`) or while the Cluster is active with a TLD (`cluster.active_router_required`). Deactivate the Cluster or remove its TLD first.
</Warning>
