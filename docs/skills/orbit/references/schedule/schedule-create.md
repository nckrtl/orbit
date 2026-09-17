---
title: "schedule:create"
description: "Create one Node or App instance Schedule, or record one App definition."
---

# schedule:create

Create one Schedule for a Node or an App instance, or record an App Schedule definition.

```bash
orbit schedule:create <name> (--node=ID | --instance=ID | --app=APP --for=ENV[,ENV]) --calendar=CALENDAR --command=COMMAND [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | 1 through 63 lowercase ASCII letters or digits, with hyphens only between them. Unique within the owner. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=ID` | none | Positive Node ID. |
| `--instance=ID` | none | Positive App instance ID. |
| `--app=APP` | none | Numeric App ID for a definition. |
| `--for=ENV[,ENV]` | none | Definition environments, `development` or `production`. Required with `--app`. |
| `--calendar=CALENDAR` | none | Native systemd calendar expression of at most 255 printable ASCII bytes, such as `daily`, `hourly`, or `Mon..Fri 02:00`. |
| `--command=COMMAND` | none | One non-empty line of at most 4,096 bytes. |
| `--timeout=SECONDS` | `3600` | Execution timeout from 1 through 86,400 seconds. |
| `--no-start` | off | Install an App instance Schedule with its timer disabled and stopped. Not accepted with `--node`. |

```bash
orbit schedule:create daily-report --node=7 --calendar=daily --command='php report.php'
orbit schedule:create hourly-report --instance=12 --calendar=hourly --command='php artisan report:send' --no-start
orbit schedule:create hourly-report --app=1 --for=production --calendar=hourly --command='php artisan report:send' --timeout=600
```

The target Node's `systemd-analyze calendar` accepts or rejects the calendar for a Node or App instance Schedule. A definition stores the calendar without that check, because it has no host Node yet; the check runs when Orbit copies the definition into an App instance.

An identical create returns the same Schedule without changing its desired timer state. A different specification with the same owner and name returns `schedule.retry_conflict`. Orbit changes a specification only when you destroy the Schedule and create its replacement.

> **Note:** The caller command appears only in the protected script that root owns. It never appears in an SSH, `sudo`, `systemctl`, or `journalctl` argument, and it is absent from Activity, Doctor findings, and errors.

A production App instance without a selected release accepts a Schedule installed with `--no-start`, but a run or activation returns `schedule.target_unavailable` until `instance:deploy` selects a release.
