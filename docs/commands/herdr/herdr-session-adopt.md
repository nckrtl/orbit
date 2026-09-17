---
title: "herdr:session:adopt"
description: "Adopt an existing Herdr session for observation without owning its lifecycle."
---

Adopt a Herdr session that already runs on a Node outside Orbit, so that `herdr:observe` can issue grants for it. Orbit records the session and publishes its private observer, and does not create, restart, or stop the server.

```bash
orbit herdr:session:adopt <session> --node=NODE [--user=USER] [--publish-observer] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `session` | yes | Existing named Herdr session on the Node. |

| Option | Meaning |
| --- | --- |
| `--node=NODE` | Node ID or registered name. |
| `--user=USER` | Unix user that owns the Herdr session and socket. Defaults to the Node's managed runtime user. |
| `--publish-observer` | Publish the private receive-only observer during adoption. |

```bash
orbit herdr:session:adopt commander-tasks --node=beast --publish-observer
```

Use [`herdr:session:create`](herdr-session-create.md) for a session that Orbit runs as a Node Process. An adopted session is not restarted by [`herdr:session:restart`](herdr-session-restart.md).
