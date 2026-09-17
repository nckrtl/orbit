---
title: "app:show"
description: "Show one App with its stored repository, default branch, and root."
---

Show one App. Null `default_branch` or root values mean the source defaults are incomplete, and new App instance creation fails with `app.source_defaults_incomplete` until they exist.

```bash
orbit app:show <app> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `app` | yes | Numeric App ID. |
