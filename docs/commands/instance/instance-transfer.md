---
title: "instance:transfer"
description: "Move a development App instance to another Node."
---

Move an active development App instance to another app-dev Node in an active Cluster. Both Nodes must belong to active Clusters. The operation stops source processes, copies the source into an independent checkout, moves or replaces its Route, and deletes the old placement. See [App instance transfer](/reference/appinstance-transfer) for preflight, retained content, and recovery.

```bash
orbit instance:transfer <instance> <node> [--name=NAME] [--sqlite-source-path=PATH] [--force] [--json]
```

Use these options to select the destination and confirm downtime.

| Option | Meaning |
| --- | --- |
| `--name=NAME` | Optional destination name. |
| `--sqlite-source-path=PATH` | Optional SQLite database to copy from the source. |
| `--force` | Confirm downtime and deletion of the old placement. |
| `--json` | Return machine-readable output without prompts. Requires `--force` for consent. |

Without `--force`, an interactive call asks for default-No consent naming the source, destination and effect. JSON and noninteractive calls without `--force` return `instance.confirmation_required` before mutation.

Production, standalone, and same-Node transfers are refused.

## Use it when

Use this command to move a development App instance to another Node in an active Cluster. The App is down during the move, and the old placement is deleted. Tell the user both before you ask for consent.

## Check

1. Run `orbit instance:show <instance> --json` and confirm the new Node and an active status.
2. Request the Route domain.

## After a refusal

| Error code | What to do |
| --- | --- |
| `instance.confirmation_required` | The call had `--json` without `--force`. Get consent, then add `--force`. |
