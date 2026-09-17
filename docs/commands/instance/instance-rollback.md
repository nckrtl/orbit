---
title: "instance:rollback"
description: "Select one retained release."
---

Select one retained release through `current`. Rollback does not fetch Git, synchronize environment values, run deploy steps, or change database files.

```bash
orbit instance:rollback <instance> --release=NAME [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |

| Option | Meaning |
| --- | --- |
| `--release=NAME` | Retained release name from `instance:release:list`. |

The command streams the same `phase`, `output`, and `result` events as `instance:deploy`, with the `rollback` phase, and uses the same exit status table.

```bash
orbit instance:release:list 15
orbit instance:rollback 15 --release=20260914T101500Z
```

<Warning>
Orbit selects the older code only. You own any data or application recovery that the switch needs.
</Warning>

## Use it when

Use this command when the user wants the previous code live again after a bad deployment. Tell the user that Orbit switches code only: migrations and data changes from the newer release stay in place.

## Check

The last line of the stream must be a `result` event with `status: succeeded`. Confirm the selection with [`instance:release:list`](instance-release-list.md).
