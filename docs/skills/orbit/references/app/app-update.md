---
title: "app:update"
description: "Change source defaults and reconcile affected instances."
---

# app:update

Change an App's source defaults. Omitted fields stay unchanged. The Gateway reconciles affected development sources, inherited web roots, and generated domains before publishing the change. It does not start a production deployment. See [Update an App](https://orbit.nckrtl.com/docs/reference/apps.md#update-an-app) for validation, rollback, and retry behavior.

```bash
orbit app:update <app> [--slug=SLUG] [--repository=URL] [--default-branch=BRANCH] [--root=PATH]
```

The command accepts these options.

| Option | Meaning |
| --- | --- |
| `--slug=SLUG` | New App slug. Generated development domains follow it; recorded paths stay unchanged. |
| `--repository=URL` | New Git access URL. The Gateway validates ownership before updating checkout origins. |
| `--default-branch=BRANCH` | New default branch for development sources that inherit it. |
| `--root=PATH` | New relative web root for instances without an override. |
