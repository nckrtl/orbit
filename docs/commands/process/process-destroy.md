---
title: "process:destroy"
description: "Remove one Process and its artifacts, or one definition."
---

Stop and remove the exact owned runtime artifacts of one Process, then delete its record. With `--app`, destroy one App process definition by name.

```bash
orbit process:destroy <process> [--app=APP] [--yes] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `process` | yes | Numeric Process ID, or the definition name with `--app`. |

| Option | Meaning |
| --- | --- |
| `--app=APP` | Numeric App ID. Selects a definition instead of an installed Process. |
| `--yes` | Confirm destruction without prompting. Interactive confirmation defaults to No, and a JSON or non-interactive call without `--yes` returns `input.confirmation_required`. Interactive decline, Ctrl-C, and EOF cancel with `input.cancelled`. |

```bash
orbit process:destroy 41 --yes
orbit process:destroy queue --app=1 --yes
```

<Warning>
Systemd units are named `orbit-process-{id}-{name}.service` and containers `orbit-process-{id}-{name}`; the Gateway checks that exact owner marker before it stops or removes anything and never adopts or deletes a colliding unit or container.
</Warning>

Destroying a definition leaves every existing App instance copy in place.
