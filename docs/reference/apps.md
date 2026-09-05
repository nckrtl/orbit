# Apps

This page tells an operator how an App stores the repository URL, default branch, and relative web root that later AppInstances inherit as source defaults. [ADR 0025](../decisions/0025-stabilize-the-default-appinstance-identity.md) defines its stable default-source identity.

## Create an App

Use `app:new` with a slug and an HTTPS or SSH Git origin:

```bash
orbit app:new acme https://github.com/acme/site.git
```

The CLI sends `public` as the root unless `--root` supplies another normalized relative path. It asks the Gateway to resolve the repository's symbolic default branch when `--default-branch` is omitted. The Gateway performs that lookup once and stores the result; a later change to the remote default does not rewrite the App.

Both source defaults can be explicit:

```bash
orbit app:new acme https://github.com/acme/site.git \
  --default-branch=stable \
  --root=web/public
```

The Gateway verifies that an explicit default branch exists in the repository. Repository access failures, missing explicit branches, and an unavailable or malformed remote default return `app.default_branch_unavailable` without including repository diagnostics or credentials.

The public App contract uses these source fields.

| Field or option | Result |
| --- | --- |
| `repository_url` | Required repository origin in the Gateway API and PHP SDK. |
| `default_branch` | Optional Gateway API and PHP SDK input; returned by every App response. |
| `--default-branch` | Optional CLI input for `app:new`. |
| `root` and `--root` | Required API and SDK field and the CLI's normalized relative web-root input. |

SDK App responses and the `app:list` and `app:show` commands expose the stored repository, default branch, and root. The API, SDK, CLI, activity, Doctor, and validation contracts do not expose a second compatibility name for `default_branch`.

## Retry creation safely

`app:new` is an idempotent creation command. Repeating it with the same name, slug, repository, default branch, root, and defaults returns the existing App. An omitted branch is not resolved again during that retry.

A retry that changes any creation value fails with `app.identity_conflict` and
does not mutate the App. Use the separate App update operation for a deliberate
slug or source-default change; creation never performs update reconciliation.
See [ADR 0016](../decisions/0016-reconcile-app-identity-and-source-default-updates.md)
for that boundary.

## Incomplete source defaults

An App whose source defaults are incomplete can have a null default branch or root. API, SDK, and CLI JSON responses report those nulls unchanged. Reading or retrying creation does not infer missing values.
