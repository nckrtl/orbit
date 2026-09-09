# Apps

An App is Orbit's stable record for one application. It owns the canonical [repository identity](../concepts.md#repository-identity), one supported repository access URL, a `default_branch`, and a relative web root that later AppInstances inherit as source defaults. [ADR 0025](../decisions/0025-stabilize-the-default-appinstance-identity.md) defines the stable default-source identity, and [ADR 0026](../decisions/0026-identify-each-app-by-one-repository.md) defines repository ownership.

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
| `repository_url` | Required repository access URL in the Gateway API and PHP SDK. |
| `default_branch` | Optional Gateway API and PHP SDK input; returned by every App response. |
| `--default-branch` | Optional CLI input for `app:new`. |
| `root` and `--root` | Required API and SDK field and the CLI's normalized relative web-root input. |

SDK App responses and the `app:list` and `app:show` commands expose the stored repository, default branch, and root. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Keep one repository owner

The Gateway derives repository identity from the repository host and path, independent of the supported SSH or HTTPS access form and an optional terminal `.git`. It stores this identity separately from the selected access URL.

Creating another App for an owned identity fails with `app.repository_identity_conflict`. The Gateway creates or changes no App.

Repository validation and failure details do not expose embedded credentials or unredacted Git output. A checkout-origin lookup uses the canonical identity and therefore resolves no more than one App across equivalent access forms.

During an upgrade, the Gateway checks every existing App before it makes repository identity unique. If it finds a duplicate identity, it reports the conflicting App IDs, changes no App, and refuses the migration until an operator resolves the conflict.

## Retry creation safely

`app:new` is an idempotent creation command. Repeating it with the same name, slug, repository access URL, default branch, root, and defaults returns the existing App. An omitted branch is not resolved again during that retry.

A retry that changes any creation value fails with `app.identity_conflict` and does not mutate the App. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL. Orbit exposes no App update operation. [ADR 0016](../decisions/0016-reconcile-app-identity-and-source-default-updates.md) defines the reconciliation boundary for a separate contract.

## Incomplete source defaults

An App whose source defaults are incomplete can have a null default branch or root. API, SDK, and CLI JSON responses report those nulls unchanged. Reading or retrying creation does not infer missing values.
