---
title: "cluster:node:add"
description: "Attach a Node."
---

# cluster:node:add

Attach an active Node to a Cluster.

```bash
orbit cluster:node:add <cluster> <node>
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `cluster` | yes | Numeric Cluster ID. |
| `node` | yes | Numeric Node ID. |

```bash
orbit cluster:node:add 3 7
```

Attaching to an inactive Cluster records membership and leaves Node routing and generated domains unchanged. Attaching to an active Cluster without a TLD uses Cluster scope and keeps generated domains on the Node TLD. Attaching to an active Cluster with a TLD moves generated Route domains into that Cluster namespace and Cluster scope before membership becomes authoritative. `node:add --cluster=ID` attaches a Node during provisioning with the same guards.

| Error code | Meaning |
| --- | --- |
| `cluster.node_inactive` | The Node is not active. |
| `cluster.membership_conflict` | The Node already belongs to another Cluster. |
| `cluster.lan_ip_conflict` | The Node's LAN address is already assigned in the Cluster. |
