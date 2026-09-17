---
title: "herdr:session:restart"
description: "Restart one named Herdr session."
---

Restart the Herdr server behind one session.

```bash
orbit herdr:session:restart <session> --node=NODE [--handoff] [--json]
```

| Option | Meaning |
| --- | --- |
| `--node=NODE` | Node ID or registered name. |
| `--handoff` | Use Herdr live handoff when the inspected server reports support. The current Herdr command contract reports no supported handoff, so the Gateway restarts the owned Process. |

```bash
orbit herdr:session:restart commander-tasks --node=beast
```

<Note>
Installing or updating the `herdr` Tool changes package files only. It does not restart a running session; run `herdr:session:restart` when the session must pick up a new Herdr version.
</Note>
