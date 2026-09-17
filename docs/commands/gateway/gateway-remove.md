---
title: "gateway:remove"
description: "Remove a profile and its pinned certificate."
---

Remove a profile from the local configuration and delete its pinned certificate file when one exists. The operating-system trust store stays unchanged.

```bash
orbit gateway:remove <name> [--force] [--yes] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Local profile name. |

| Option | Meaning |
| --- | --- |
| `--force` | Remove the active profile and clear the active selection. Without it, the CLI refuses the active profile with `gateway.profile_active`. |
| `--yes` | Confirm removal without a prompt. Required for noninteractive and JSON calls. |

Supply the profile name explicitly. The CLI checks that the profile exists and that active-profile removal has `--force` before asking for consent. The prompt names the profile and the removal of its pinned certificate; it defaults to No. `--force` permits active-profile removal but does not supply consent.

A valid noninteractive or JSON call without `--yes` exits 1 with `input.confirmation_required`. Declining, pressing Ctrl-C, or reaching end of input at the prompt exits 1 with `input.cancelled`. These refusals leave configuration and certificates unchanged. After consent, human output shows the removal operation and its result. JSON returns `profile` with the removed name.

If the profile is removed but its pinned certificate cannot be deleted, the command exits 1 with `gateway.config_invalid`. Its error message states that the profile was removed and certificate cleanup failed. The command does not claim that removal was rolled back.

```bash
orbit gateway:remove staging
orbit gateway:remove default --force --yes
```
