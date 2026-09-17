---
title: "route:target:set"
description: "Set the configured target or a complete production target set."
---

# route:target:set

Add or replace the configured App instance target, or send a complete production target set.

```bash
orbit route:target:set <route> <target> [--targets=ID]... [--reassign=ID:ROUTE]... [--remove=ID]...
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `route` | yes | Numeric Route ID. |
| `target` | yes | Numeric App instance ID, or the first ID in a production target set. |

| Option | Meaning |
| --- | --- |
| `--targets=ID` | Additional ordered App instance IDs in the complete production target set. Repeat the option for each extra ID. |
| `--reassign=ID:ROUTE` | Detached App instance ID and destination Route ID. Repeat the option for each reassignment. |
| `--remove=ID` | Detached App instance ID authorized for removal. Repeat the option for each removal. |

```bash
orbit route:target:set 9 12
orbit route:target:set 9 12 --targets=15 --reassign=18:4 --remove=19
```

A single target with no pool options sends `app_instance_id`. Pool options send `targets` plus `dispositions`. The [Routes reference](https://orbit.nckrtl.com/docs/reference/routes.md#change-a-production-target-set) owns refusal, retry, and pool serving rules. Setting the existing single target again returns the unchanged Route. The Gateway returns `route.target_conflict` and preserves both associations when a single-target request would take an App instance from another Route or detach an active App instance from its sole Route.
