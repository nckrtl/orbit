---
title: "instance:destroy"
description: "Remove an App instance and its owned Processes, Schedules, and Route."
---

Remove an App instance. The Gateway clears its Route target, removes every owned Process and Schedule, and deletes the record.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |

| Option | Meaning |
| --- | --- |
| `--yes` | Confirm removal without prompting. Required for JSON and noninteractive calls. |
| `--force` | Delete dirty or unpublished development source after identity checks pass, and accept a checkout together with its registered linked worktrees. |

Interactive removal defaults to No. `--force` does not supply consent.

Normal removal refuses dirty source, a `HEAD` that no origin branch or tag contains, and a checkout with registered linked worktrees. Forced removal waives only those three refusals. It never waives the source path, ownership, layout, origin, branch, or worktree inventory checks, which return `instance.source_path_mismatch`, `instance.source_ownership_mismatch`, `instance.source_layout_mismatch`, `instance.source_origin_mismatch`, `instance.source_branch_mismatch`, or `instance.source_worktrees_mismatch`.

```bash
orbit instance:destroy 12
orbit instance:destroy 12 --force
```

<Warning>
Production removal retains `releases/`, `.env`, an existing `database.sqlite`, and local PHP-FPM tuning for recovery. Development removal deletes the checkout. Repeating the same command resumes an interrupted removal from its first unfinished step.
</Warning>

## Use it when

Use this command only when the user asks to remove an App instance. Name the App instance, its Node, and its domain when you ask for consent.

## Check

Run `orbit instance:list --json`. The App instance is gone. A non-null `removal` means the removal stopped part way; repeat the command to resume it.

## After a refusal

A refusal for dirty source, an unpushed `HEAD`, or linked worktrees names work that would be lost. Tell the user what it is, and add `--force` only when they accept the loss. A source mismatch code means the source on the Node does not match the record; `--force` does not waive it. Report the code to the user and stop.
