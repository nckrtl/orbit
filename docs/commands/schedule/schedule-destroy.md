---
title: "schedule:destroy"
description: "Remove one Schedule or one definition."
---

Remove one Schedule through the Gateway, or one definition by name.

```bash
orbit schedule:destroy <schedule> [--app=APP] [--yes] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `schedule` | yes | Schedule UUID, or the definition name with `--app`. |

| Option | Meaning |
| --- | --- |
| `--app=APP` | Numeric App ID. Selects a definition. |
| `--yes` | Confirm destruction without prompting. Interactive confirmation defaults to No, and a JSON or non-interactive call without `--yes` returns `input.confirmation_required`. Interactive decline, Ctrl-C, and EOF cancel with `input.cancelled`. |

<Warning>
After confirmation the Gateway marks the Schedule `removing`, disables and stops its timer, lets an active command finish, and then removes the exact timer, service, script, and record. A failure leaves the Schedule `removing`, and repeating the command resumes cleanup.
</Warning>
