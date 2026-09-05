# Apps

An App is Orbit's stable record for one application. It owns the canonical [repository identity](../concepts.md#repository-identity), one supported repository access URL, a default branch, and a relative web root that later AppInstances inherit as source defaults. [ADR 0026](../decisions/0026-identify-each-app-by-one-repository.md) defines repository ownership.

## Create an App

Use `app:new` with a slug and an HTTPS or SSH Git origin:

```bash
orbit app:new acme https://github.com/acme/site.git
```

The CLI sends `public` as the root unless `--root` supplies another normalized
relative path. It asks the Gateway to resolve the repository's symbolic default
branch when `--main-branch` is omitted. The Gateway performs that lookup once
and stores the result; a later change to the remote default does not rewrite the
App.

Both source defaults can be explicit:

```bash
orbit app:new acme https://github.com/acme/site.git \
  --main-branch=stable \
  --root=web/public
```

The Gateway verifies that an explicit main branch exists in the repository.
Repository access failures, missing explicit branches, and an unavailable or
malformed remote default return `app.default_branch_unavailable` without
including repository diagnostics or credentials.

The Gateway API requires `repository_url` and `root`, accepts an optional
`main_branch`, and returns all three values. The PHP SDK's
`CreateAppRequest` carries the same fields. SDK App responses and the
`app:list` and `app:show` commands expose the stored repository, main branch,
and root.

## Keep one repository owner

The Gateway derives repository identity from the repository host and path, independent of the supported SSH or HTTPS access form and an optional terminal `.git`. It stores this identity separately from the selected access URL.

Creating another App for an owned identity or updating an App to an identity owned by another App fails with `app.repository_identity_conflict`. The Gateway changes neither App. An update that selects an equivalent supported access URL keeps the App ID and repository identity and returns the selected URL.

Repository validation and failure details do not expose embedded credentials or unredacted Git output. A checkout-origin lookup uses the canonical identity and therefore resolves no more than one App across equivalent access forms.

During an upgrade, the Gateway checks every existing App before it makes repository identity unique. If it finds a duplicate identity, it reports the conflicting App IDs, changes no App, and refuses the migration until an operator resolves the conflict.

## Retry creation safely

`app:new` is an idempotent creation command. Repeating it with the same name, slug, repository access URL, main branch, root, and defaults returns the existing App. An omitted branch is not resolved again during that retry.

A same-slug retry that changes any creation value fails with `app.identity_conflict` and does not mutate the App. Use the separate App update operation for a deliberate slug or source-default change; creation never performs update reconciliation. See [ADR 0016](../decisions/0016-reconcile-app-identity-and-source-default-updates.md) for that boundary.

## Legacy Apps

Apps created before source defaults were required can have a null main branch
or root. API, SDK, and CLI JSON responses report those nulls unchanged. Reading
or retrying creation does not infer values for a legacy App.
