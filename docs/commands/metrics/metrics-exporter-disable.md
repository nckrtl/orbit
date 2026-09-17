---
title: "metrics:exporter:disable"
description: "Exclude one Node from exporter selection."
---

Exclude one Node from exporter selection.

```bash
orbit metrics:exporter:disable <node> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `node` | yes | Node ID or name. |

The command answers `metrics.exporter_node_inactive` for a Node that is not active and `node.role_conflict` for the Metrics Node itself. A change in the selected exporters stops and replaces the Prometheus container; Grafana keeps running.
