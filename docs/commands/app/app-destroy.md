---
title: "app:destroy"
description: "Remove an App."
---

Remove an App. The Gateway refuses the request while the App still owns a Route, so remove its App instances first. Removing an App also deletes its process and Schedule definitions.

```bash
orbit app:destroy <app> [--yes] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `app` | yes | Numeric App ID. |

Removal asks for confirmation with No selected. Supply `--yes` for automation, including `--json`. The CLI resolves the App before asking; declining, Ctrl-C or EOF returns `input.cancelled` without sending removal. A valid noninteractive call without `--yes` returns `input.confirmation_required`.
