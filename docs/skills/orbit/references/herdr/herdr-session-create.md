---
title: "herdr:session:create"
description: "Create or ensure one named Herdr session on a managed Node."
---

# herdr:session:create

Create one named Herdr session, or return it unchanged when a compatible session already runs.

```bash
orbit herdr:session:create <session> --node=NODE --user=USER [--publish-observer] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `session` | yes | Named Herdr session. |

| Option | Meaning |
| --- | --- |
| `--node=NODE` | Node ID or registered name. |
| `--user=USER` | Unix user that owns the Herdr server and socket. It must match the Node's managed runtime user; a mismatch fails with `herdr.user_mismatch` before the Gateway creates a Process. |
| `--publish-observer` | Publish the private receive-only observer so grants can be issued. |

```bash
orbit herdr:session:create commander-tasks --node=beast --user=orbit --publish-observer
```

The Gateway composes one Node-targeted systemd Process for the headless server and stores the session identity. Observer publication requires a successful session snapshot with Herdr observe protocol 22; a different protocol fails with `herdr.observer_unsupported`, and a publication failure records listener health as `herdr.observer_failed` while the session keeps running.

Repeating an identical create returns the same session without restarting it. A changed user, Process specification, or observer listen address for the same Node and name fails with `herdr.session_conflict` and leaves the running server in place.
