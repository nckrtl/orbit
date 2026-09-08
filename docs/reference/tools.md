# Tools

This reference is for operators who manage packages on Nodes and need to understand Tool Manager availability, first-use provisioning, retries, removal, and Doctor results.

## Choose a Tool Manager

Orbit exposes its code-owned Tool Managers on active Linux Nodes that the Gateway manages over Secure Shell (SSH). A Node role can require a manager during role convergence, but the role does not own the manager or its Tools. Roleless operator clients remain outside Tool management under [ADR 0012](../decisions/0012-ubuntu-24-04-roleless-operator-clients.md).

List every manager supported for a Node before choosing one.

```bash
orbit tool:manager:list --node=<node-id>
```

The command reports a nullable manager ID and one of these lifecycle states.

| Status | Meaning |
| --- | --- |
| `uninstalled` | Orbit supports the manager on the Node, but the manager has no persisted state and its software is not available yet. |
| `provisioning` | Orbit is installing or verifying the manager and its protected prerequisites. |
| `active` | The manager is available for Tool operations. |
| `failed` | Manager provisioning or verification failed and the next install can retry it. |

An `uninstalled` manager has no database ID. Persisted manager rows use their database ID and retain bounded failure fields when provisioning fails.

The supported managers have these scopes.

| Manager | Package scope | Availability |
| --- | --- | --- |
| `apt` | The Node's Advanced Package Tool package database | Materialized with the managed Node baseline |
| `vp` | Orbit's shared Vite+ global package scope on the Node | Materialized on first use or when a role requires it |
| `composer` | Orbit's shared Composer global package scope on the Node | Materialized on first use or when a role requires it |

[ADR 0001](../decisions/0001-tool-management.md) defines Tool ownership and caller input. [ADR 0042](../decisions/0042-provision-tool-managers-on-demand.md) defines manager availability and role independence.

## Install a Tool

Install one manager-native package by naming its manager and target Node.

```bash
orbit tool:install <package> --manager=<manager> --node=<node-id>
```

When the selected manager is `uninstalled` or `failed`, the Gateway first provisions or retries that manager in its protected scope. A successful provisioning records the manager as `active` before the Gateway changes Tool intent. A failed provisioning returns `tool.manager_provision_failed`, keeps the bounded failure on the manager, and does not create a Tool row. Repeating the same install command retries the manager from live Node state.

The Gateway rejects Tool mutations with `tool.node_unmanaged` when the Node is a roleless operator client or is otherwise outside Gateway-owned SSH management. Manager installation is independent of the Node's assigned infrastructure roles.

Orbit does not install every registered manager during Node provisioning. A materialized manager remains active after its final Tool is removed, and Orbit exposes no manager-removal command.

## Remove a Tool

Run the removal command with the Tool ID that Orbit returned when it created the managed package intent.

```bash
orbit tool:remove <tool-id>
```

The Gateway probes the package before removal. When the package is installed, removal proceeds when accepted under [ADR 0001](../decisions/0001-tool-management.md)'s Tool-removal contract, and the Gateway probes the package again after manager removal. A successful removal deletes the Tool row.

APT removes the package without purging its configuration files. Dpkg can therefore retain the package record, configuration files, and last package version after the executable files are gone. The Gateway treats that removed package state as absence and deletes the Tool row. [ADR 0001](../decisions/0001-tool-management.md) defines the package-ownership and exact-removal boundary.

The Gateway returns bounded outcomes for each removal result.

| Condition | Result | Tool row |
| --- | --- | --- |
| The package is already absent, including an APT package with retained configuration files | Removal succeeds without another manager removal | Deleted |
| The accepted Tool removal succeeds and the second probe reports absence | Removal succeeds | Deleted |
| The installed-version probe fails or returns unsafe output | `tool.version_probe_failed` | Retained as a retryable failure |
| The manager removal fails or the package remains installed | `tool.remove_failed` | Retained as a retryable failure |

[ADR 0001](../decisions/0001-tool-management.md) governs removal-plan eligibility and package-set limits.

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
