---
title: "env:sync"
description: "Replace the workload `.env` from the complete stored configuration."
---

Replace the workload `.env` from the complete stored configuration. Stored configuration is the only input, so local-only keys and local edits disappear; import them first when they must remain.

```bash
orbit env:sync --instance=SELECTOR [--json]
```

| Option | Meaning |
| --- | --- |
| `--instance=SELECTOR` | Positive App instance ID or exact Route domain. |

The Gateway resolves the placeholders from the App instance's sole Route and environment, checks the destination directory, owner, and capacity before it decrypts a value, and then atomically replaces `.env` as the runtime user with mode `0600`. A matching protected file reports `changed: false`.

```bash
orbit env:sync --instance=12
```

<Note>
Synchronization changes only the file. It does not refresh a framework cache or restart a Process, so run those application steps afterwards when running code must see the new values.
</Note>

An unconfirmed write returns `env.sync_unconfirmed`; repeat the command to recheck the file. Import, update, sync, removal, deployment, and Route transitions share one operation owner per App instance, so a competitor waits or returns `env.operation_busy`.
