---
title: "firewall:list"
description: "List the named rules of one Node."
---

List the named rules recorded for one Node with their action, source, port, protocol, and lifecycle status.

```bash
orbit firewall:list --node=ID
```

| Option | Meaning |
| --- | --- |
| `--node=ID` | Numeric target Node ID. |

Human output uses a read-only table with NAME, ACTION, SOURCE, PORT, PROTOCOL, and STATUS columns. Values wrap inside their cells; below the minimum width, labeled records retain every field. An empty result says `No firewall rules.`.

A rule's `status` is `provisioning`, `active`, `failed`, or `removing`. A failed rule keeps `failed_step` and `error_code` so the same command can be repeated after the cause is fixed. Orbit-owned `orbit:` rules are not part of this list.
