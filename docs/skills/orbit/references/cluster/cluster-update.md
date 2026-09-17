---
title: "cluster:update"
description: "Change the name, TLD, or state."
---

# cluster:update

Change the name, TLD, or state of a Cluster. At least one option is required.

```bash
orbit cluster:update <cluster> [--name=NAME] [--tld=TLD] [--state=STATE]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `cluster` | yes | Numeric Cluster ID. |

| Option | Meaning |
| --- | --- |
| `--name=NAME` | New Cluster name. |
| `--tld=TLD` | New development TLD. An empty value, `--tld=`, unsets it. |
| `--state=STATE` | `inactive` or `active`. |

```bash
orbit cluster:update 3 --state=active
orbit cluster:update 3 --tld=
```

Activating a Cluster that has a TLD, or setting a TLD on an active Cluster, makes the Gateway check that one active Router exists, that no non-member Node owns the proposed TLD, and that every affected Route keeps a valid domain, scope, and target. Generated domains then use the Cluster TLD even when member Nodes have their own TLDs. The Gateway then republishes DNS and Router projections before it stores the change. A failure leaves the previous state authoritative. A name change alone touches no traffic.

| Error code | Meaning |
| --- | --- |
| `cluster.update_required` | No option was given. |
| `cluster.state_invalid` | The state is not `inactive` or `active`. |
| `cluster.router_required` | The Cluster would be active with a TLD but has no active Router. |
| `cluster.tld_conflict` | A Node outside the Cluster already owns the proposed TLD. |
| `cluster.router_busy` | Another Router transition still owns this Cluster; retry after it finishes. |
| `route.reconciliation_required` | An active Route depends on the change and cannot be reconciled by this operation. |
