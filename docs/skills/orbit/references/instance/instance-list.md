---
title: "instance:list"
description: "List App instances."
---

# instance:list

List App instances with their App, Node, status, Route domain, and nullable `removal` progress.

```bash
orbit instance:list [--json]
```

## Use it when

Use this command to find an App instance ID for another command, or to see which Node runs an App.

## Check

Select the App instance by App, Node, and Route domain, and ask the user when more than one matches. A non-null `removal` means an interrupted removal that [`instance:destroy`](instance-destroy.md) resumes. `migration_required: true` means the App instance needs [`instance:register`](instance-register.md).
