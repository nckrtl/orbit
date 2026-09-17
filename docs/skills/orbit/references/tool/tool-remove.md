---
title: "tool:remove"
description: "Remove one Tool and delete its record."
---

# tool:remove

Remove one Tool and delete its record.

```bash
orbit tool:remove <tool> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `tool` | yes | Numeric Tool ID from a successful install or from a failed install that retained the Tool. |

> **Warning:** The command sends the request without a confirmation prompt. `apt` removes the package without purging its configuration files, and the Gateway treats that state as absence. Removal never runs a dependency autoremove and keeps the manager active after the last Tool is gone; Orbit exposes no manager-removal command.

| Condition | Result | Tool row |
| --- | --- | --- |
| The package is already absent | Removal succeeds without another manager removal | Deleted |
| A failed Tool never recorded a version | Removal succeeds without probing the package | Deleted |
| The removal succeeds and the second probe reports absence | Removal succeeds | Deleted |
| The version probe fails on a proven Tool | `tool.version_probe_failed` | Retained for retry |
| The manager removal fails or the package remains | `tool.remove_failed` | Retained for retry |

Retry the same command with the retained Tool ID; the Gateway probes live package state before it plans another mutation. Run `orbit doctor --node=ID --family=tool` to confirm the Node: a retained row for an absent package reports `tool.not_installed`, and a completed removal reports the family healthy.
