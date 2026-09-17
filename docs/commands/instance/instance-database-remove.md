---
title: "instance:database:remove"
description: "Remove a Database connection and clear those keys."
---

Remove a Database connection from an App instance and clear the prefixed stored keys. Other stored keys stay in place.

```bash
orbit instance:database:remove <slug> --instance=SELECTOR [--prefix=PREFIX] [--force] [--json]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--instance=SELECTOR` | required | Positive App instance ID or exact Route domain. |
| `--prefix=PREFIX` | `DB` | Prefix of the attachment to remove. |
| `--force` | off | Confirm removal without prompting. Interactive confirmation defaults to No, and a JSON or non-interactive call without `--force` returns `database.confirmation_required`. Interactive decline, Ctrl-C, and EOF cancel with `input.cancelled`. |

```bash
orbit instance:database:remove app --instance=12 --force
```

An unknown attachment returns `database.attachment_missing`.

## After a refusal

| Error code | What to do |
| --- | --- |
| `database.confirmation_required` | Get consent, then add `--force`. |
| `database.attachment_missing` | No attachment has that slug and prefix. Check the prefix with the user. |
| `input.cancelled` | The user declined. Stop. |
