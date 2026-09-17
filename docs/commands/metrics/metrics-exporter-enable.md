---
title: "metrics:exporter:enable"
description: "Select one Node as an exporter target."
---

Store an explicit exporter preference for one Node.

```bash
orbit metrics:exporter:enable <node> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `node` | yes | Node ID or name. |

The Gateway selects exporters only on active Nodes with a managed WireGuard address and Gateway-owned SSH management. Without a stored preference, a Node with an active or provisioning role is selected and a roleless Node is excluded; the Metrics Node is always selected.

| Preference | Node state | Result |
| --- | --- | --- |
| absent | carries an active or provisioning role | selected |
| absent | carries no role | excluded |
| enabled | eligible Node with or without a role | selected |
| disabled | eligible Node except the Metrics Node | excluded |
| any value | ineligible record | excluded |

The command answers `metrics.exporter_node_inactive` for a Node that is not active and `metrics.exporter_node_ineligible` for a Node outside Gateway-owned SSH management, before it saves the preference or starts remote work. A selected Node runs the exporter bound to its WireGuard address on port 9100, admitted only from the Metrics Node.
