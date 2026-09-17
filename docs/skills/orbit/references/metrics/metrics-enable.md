---
title: "metrics:enable"
description: "Enable Metrics on one Node."
---

# metrics:enable

Enable the Metrics role on one active Node.

```bash
orbit metrics:enable [node] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `node` | in non-interactive calls | Node ID or name. An interactive terminal lists the eligible active Nodes and prompts when it is omitted; a `--json` or non-interactive call fails with `metrics.node_required`. |

```bash
orbit metrics:enable beast
```

The Gateway accepts the role on a Node that already carries another role and answers `node.role_conflict` to a second enable while an assignment exists on any Node. Retry a failed convergence with `orbit node:role:add <node> metrics --converge`; there is no separate Metrics convergence command.

After enabling, Prometheus binds `127.0.0.1:9090` on the Metrics Node, Grafana binds the Node's WireGuard address on port 3000, and two UFW rules owned by the role admit only the Gateway's WireGuard address to Grafana.
