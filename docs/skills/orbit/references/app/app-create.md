---
title: "app:create"
description: "Create an App from a slug and a Git repository URL."
---

# app:create

Create an App. The Gateway resolves the remote default branch once when `--default-branch` is omitted and stores the result.

```bash
orbit app:create <slug> <repository> [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Unique App slug. It names the checkout directory under the Node apps root. |
| `repository` | yes | HTTPS or SSH Git repository URL. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--name=NAME` | the slug | Display name. |
| `--default-branch=BRANCH` | the remote default | Stored default branch. The Gateway verifies that an explicit branch exists. |
| `--root=PATH` | `public` | Relative web root that App instances inherit. |

```bash
orbit app:create acme https://github.com/acme/site.git
orbit app:create acme git@github.com:acme/site.git --default-branch=stable --root=web/public
```

Creation is idempotent. Repeating the command with the same values returns the existing App. A retry that changes any value fails with `app.identity_conflict`, and a second App for the same repository fails with `app.repository_identity_conflict`.

A repository access failure or an unavailable remote default returns `app.default_branch_unavailable` without repository diagnostics or credentials.
