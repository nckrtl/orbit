# Apps

An App is Orbit's stable record for one application. It owns the repository URL, main branch, and relative web root that later AppInstances inherit as source defaults.

## Create an App

Use `app:new` with a slug and an HTTPS or SSH Git origin:

```bash
orbit app:new acme https://github.com/acme/site.git
```

The CLI sends `public` as the root unless `--root` supplies another normalized relative path. It asks the Gateway to resolve the repository's symbolic default branch when `--main-branch` is omitted. The Gateway performs that lookup once and stores the result; a later change to the remote default does not rewrite the App.

Both source defaults can be explicit:

```bash
orbit app:new acme https://github.com/acme/site.git \
  --main-branch=stable \
  --root=web/public
```

The Gateway verifies that an explicit main branch exists in the repository. Repository access failures, missing explicit branches, and an unavailable or malformed remote default return `app.default_branch_unavailable` without including repository diagnostics or credentials.

The Gateway API requires `repository_url` and `root`, accepts an optional `main_branch`, and returns all three values. The PHP SDK's `CreateAppRequest` carries the same fields. SDK App responses and the `app:list` and `app:show` commands expose the stored repository, main branch, and root.

## Retry creation safely

`app:new` is an idempotent creation command. Repeating it with the same name, slug, repository, main branch, root, and defaults returns the existing App. An omitted branch is not resolved again during that retry.

A retry that changes any creation value fails with `app.identity_conflict` and does not mutate the App. Orbit does not expose an App update operation, and creation never performs update reconciliation. [ADR 0016](../decisions/0016-reconcile-app-identity-and-source-default-updates.md) defines the reconciliation boundary for a separate lifecycle.

## Legacy Apps

Apps created before source defaults were required can have a null main branch or root. API, SDK, and CLI JSON responses report those nulls unchanged. Reading or retrying creation does not infer values for a legacy App.
