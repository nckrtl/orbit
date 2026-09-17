---
title: "tool:manager:list"
description: "List the managers a Node supports and their status."
---

List every manager supported for a Node with a nullable manager ID and its lifecycle status.

```bash
orbit tool:manager:list --node=ID [--json]
```

| Option | Meaning |
| --- | --- |
| `--node=ID` | Numeric target Node ID. |

| Status | Meaning |
| --- | --- |
| `uninstalled` | Orbit supports the manager on the Node, but it has no persisted state yet. |
| `provisioning` | Orbit is installing or verifying the manager. |
| `active` | The manager is available for Tool operations. |
| `failed` | Provisioning failed, and the next install retries it. |
