---
title: "process:list"
description: "List the Processes of one App instance or Node, or the definitions of one App."
---

# process:list

List the Process records of one App instance or Node with their desired and observed states and `keep_alive`, or the definitions of one App without their commands.

```bash
orbit process:list (--instance=ID | --node=ID-or-name | --app=APP) [--json]
```

```bash
orbit process:list --instance=12
orbit process:list --node=beast
orbit process:list --app=1
```
