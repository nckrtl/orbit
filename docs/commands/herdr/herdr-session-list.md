---
title: "herdr:session:list"
description: "List named Herdr sessions on one Node."
---

List the named Herdr sessions on one Node with their identity and health.

```bash
orbit herdr:session:list --node=NODE [--json]
```

| Option | Meaning |
| --- | --- |
| `--node=NODE` | Node ID or registered name. |

Each session reports `node`, `session`, `user`, `process_id`, `observer_url`, `status`, `herdr_version`, `protocol`, and three distinct health values for the Process, the listener, and the Herdr session identity.
