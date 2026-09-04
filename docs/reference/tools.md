# Tools

This reference is for operators who remove a Tool and need to know when removal succeeds, how a failed removal retries, and what Doctor reports.

## Remove a Tool

Run the removal command with the Tool ID that Orbit returned when it created the managed package intent.

```bash
orbit tool:remove <tool-id>
```

The Gateway probes the package before removal. When the package is installed, the Gateway accepts only a removal plan for that exact package, runs the manager removal, and probes the package again. A successful removal deletes the Tool row.

APT removes the package without purging its configuration files. Dpkg can therefore retain the package record, configuration files, and last package version after the executable files are gone. The Gateway treats that removed package state as absence and deletes the Tool row. [ADR 0001](../decisions/0001-tool-management.md) defines the package-ownership and exact-removal boundary.

The Gateway returns bounded outcomes for each removal result.

| Condition | Result | Tool row |
| --- | --- | --- |
| The package is already absent, including an APT package with retained configuration files | Removal succeeds without another manager removal | Deleted |
| The exact package removal succeeds and the second probe reports absence | Removal succeeds | Deleted |
| The installed-version probe fails or returns unsafe output | `tool.version_probe_failed` | Retained as a retryable failure |
| The removal plan includes another package | `tool.removal_plan_unsafe` | Retained as a retryable failure |
| The manager removal fails or the package remains installed | `tool.remove_failed` | Retained as a retryable failure |

## Retry a failed removal

Retry the same `tool:remove` command with the retained Tool ID. The Gateway probes live package state before it plans another mutation. When the earlier removal already removed the package but dpkg retained its configuration, the retry deletes the Tool row without requiring a manual command on the Node.

## Check removal with Doctor

Run Doctor for the Tool family when you need to verify the Node after removal.

```bash
orbit doctor --node=<node-id> --family=tool
```

A retained Tool row for an absent package produces bounded `tool.not_installed` drift. After successful removal deletes that row, Doctor reports the Tool family as healthy when no other Tool finding exists. Doctor never includes the raw dpkg status or retained package version in its report. [ADR 0004](../decisions/0004-verify-only-doctor-boundary.md) defines the verify-only and bounded-report boundary.

## Limits

[ADR 0001](../decisions/0001-tool-management.md) governs Tool ownership and removal limits. [ADR 0004](../decisions/0004-verify-only-doctor-boundary.md) governs Doctor inspection and reporting limits.
