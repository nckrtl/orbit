---
title: "herdr:session:destroy"
description: "Destroy one session, its Process, and its private observer."
---

# herdr:session:destroy

Destroy one named session, its Node Process, and its private observer.

```bash
orbit herdr:session:destroy <session> --node=NODE [--accept-termination] [--json]
```

| Option | Meaning |
| --- | --- |
| `--node=NODE` | Node ID or registered name. |
| `--accept-termination` | Accept termination of live panes. |

```bash
orbit herdr:session:destroy commander-tasks --node=beast --accept-termination
```

> **Warning:** The Gateway inspects live panes first and refuses with `herdr.session_in_use` while any pane is live, unless you pass `--accept-termination`. Terminated panes and their terminal output are gone; Orbit persists no terminal output.
