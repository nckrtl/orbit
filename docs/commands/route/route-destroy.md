---
title: "route:destroy"
description: "Remove a Route."
---

Remove an untargeted private App Route or a custom proxy Route and its managed traffic configuration. The Gateway completes projection cleanup before deleting the record. See [Route removal](/reference/routes#remove-an-untargeted-private-route) for refusal and retry rules. A custom proxy Route has no App instance target, so destroy cleans that hostname without touching App Routes.

```bash
orbit route:destroy <route> [--yes]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `route` | yes | Numeric Route ID. |

<Warning>
The Gateway refuses removal with `route.target_conflict` while an active App instance depends on the Route. Remove the App instance with `instance:destroy` instead; that removal clears the target and deletes a final-target Route as part of its own lifecycle.
</Warning>

Route removal and target clearing ask for confirmation with No selected. Supply `--yes` for automation, including `--json`. The CLI resolves the Route before asking; declining, Ctrl-C or EOF returns `input.cancelled` without sending the mutation. A valid noninteractive call without `--yes` returns `input.confirmation_required`. Existing targeting and removal restrictions still apply.
