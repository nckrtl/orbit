---
title: "tool:update"
description: "Update one Tool within its constraint."
---

# tool:update

Update one Tool to the manager's current safe candidate.

```bash
orbit tool:update <tool> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `tool` | yes | Numeric Tool ID. |

The result reports `applied` when the package changed, `unchanged` when it is already current, and `blocked_by_constraint` with the stored constraint when the candidate falls outside it. A Homebrew update uses the verified bottle and keeps the installed Tool callable when no newer bottle exists.

Updating the `herdr` formula changes package files only; it does not restart a managed [Herdr session](https://orbit.nckrtl.com/docs/cli/herdr.md).
