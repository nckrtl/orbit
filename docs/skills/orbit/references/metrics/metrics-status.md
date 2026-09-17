---
title: "metrics:status"
description: "Show the assignment, container health, and exporter state."
---

# metrics:status

Show whether Metrics is enabled, the Grafana URL, the assignment with its Node and lifecycle status, any failed step and error code, and one row per exporter with its desired and actual state, selection reason, and degraded reason.

```bash
orbit metrics:status [--json]
```
