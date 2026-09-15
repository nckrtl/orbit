---
title: "Apps"
description: "How an App records one repository, its default branch, and the web root that App instances inherit."
---

# Apps

An App stores one application's Git repository, access URL, default branch, and relative web root. New App instances inherit these source defaults. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines default identity; [ADR 0026](/decisions/0026-identify-each-app-by-one-repository) defines repository ownership.

App removal requires default-No interactive confirmation or explicit `--yes`. JSON and piped calls never imply consent. Inputs remain explicit; human requests show progress and preserve request IDs.

## Create an App

Use `app:create` with a slug and an HTTPS or SSH Git origin:

```bash
orbit app:create acme https://github.com/acme/site.git
```

The command-line interface (CLI) uses `public` as the web root unless you set `--root`. Without `--default-branch`, the Gateway reads and saves the repository's default branch once. A later remote change does not update the App.

Both source defaults can be explicit:

```bash
orbit app:create acme https://github.com/acme/site.git \
  --default-branch=stable \
  --root=web/public
```

The Gateway verifies that an explicit default branch exists in the repository. Repository access failures, missing explicit branches, and an unavailable or malformed remote default return `app.default_branch_unavailable` without including repository diagnostics or credentials.

The public App contract uses these source fields.

| Field or option | Result |
| --- | --- |
| `repository_url` | Required repository access URL in the Gateway API and PHP SDK. |
| `default_branch` | Optional Gateway API and PHP SDK input; returned by every App response. |
| `--default-branch` | Optional CLI input for `app:create`. |
| `root` and `--root` | Required API and SDK field and the CLI's normalized relative web-root input. |

SDK App responses and the `app:list` and `app:show` commands expose the stored repository, default branch, and root. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Keep one repository owner

The Git host and path identify a repository. Equivalent SSH and HTTPS URLs match, with or without a trailing `.git`. The Gateway stores this identity separately from the access URL. Creating a second App for the same repository returns `app.repository_identity_conflict` and changes nothing.

Repository validation and failure details do not expose embedded credentials or unredacted Git output. A checkout-origin lookup uses the canonical identity and therefore resolves no more than one App across equivalent access forms.

During an upgrade, the Gateway checks every existing App before it makes repository identity unique. If it finds a duplicate identity, it reports the conflicting App IDs, changes no App, and refuses the migration until an operator resolves the conflict.

## Resolve an App during registration

Registration finds the App from the checkout's verified Git origin. It matches the repository identity across URL formats. Conflicting App or source details stop registration before any changes.

When no App owns the repository, the interactive CLI shows the safe repository origin and every inferred value, asks only for unresolved values and confirmation, and then asks the Gateway to create the App before its App instance. The CLI refuses a credential-bearing or otherwise unsafe origin locally without displaying it or sending a request.

Ownership transfer requires a default-No confirmation naming the source, or explicit `--yes`. Non-interactive registration, including every `--json` call, requires `--yes` and refuses when a required value remains unresolved. These refusals send no mutation request. If App creation succeeds and later registration fails, the valid App remains available for an identical retry.

Registration can infer these App values from unambiguous source evidence.

| App value | Verified source evidence |
| --- | --- |
| Slug | Repository name |
| `default_branch` | Remote symbolic default branch |
| Root | `public` for an unambiguous Laravel checkout |

Valid explicit values fill only unresolved or optional values. They do not override a conflicting repository identity or verified source fact.

## Retry creation safely

Repeating `app:create` with the same name, slug, repository access URL, default branch, root, and defaults returns the existing App. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `app.identity_conflict` and does not mutate the App. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Update an App

Use `app:update` when an existing App must change its slug, repository access URL, default branch, or relative web root. The Gateway API accepts `PATCH /api/v1/apps/{app}` with those same fields. The PHP SDK sends `UpdateAppRequest`. Omitted fields stay unchanged. [ADR 0016](/decisions/0016-reconcile-app-identity-and-source-default-updates) owns the reconciliation lifecycle. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit app:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
```

| Field or option | Result |
| --- | --- |
| `slug` and `--slug` | Reconcile generated development Route domains and Laravel application URLs before the new slug is published. Existing checkout paths, production users, and homes stay as recorded. |
| `repository_url` and `--repository` | Store the selected HTTPS or SSH access URL. Equivalent forms keep the same canonical repository identity. |
| `default_branch` and `--default-branch` | Store the new App default and switch every development `default` App instance that inherits it. An explicit `branch_override` stays unchanged even when it matched the old default. |
| `root` and `--root` | Change the inherited web root of every App instance without its own override. Production resolves the new root inside the active release. |

The Gateway treats the supplied fields as one operation. It inventories affected App instances and Routes, preflights every Orbit-owned checkout and generated domain, prepares reversible mutations, then publishes. A confirmed failure before publication rolls back origins, prepared Routes, stored Laravel `APP_URL` values, and runtime projections. The previous App record stays authoritative. An identical retry resumes the recorded state from its last verified evidence. A conflicting update while one update is incomplete returns `app.update_in_progress`.

When the repository access URL changes, the Gateway updates `origin` once for each Orbit-owned development checkout. Linked worktrees use that common repository and are not mutated directly. The Gateway refuses the update before mutation when a worktree's common repository is not owned by an Orbit checkout, when the canonical identity belongs to another App (`app.repository_identity_conflict`), or when any affected source fails preflight (`app.repository_preflight_failed` or `app.repository_unowned_common`). Production Git source, deployment branch, starting commit, and release layout do not change. App updates never start a deployment.

When `default_branch` cannot switch on an inheriting `default` source, the Gateway refuses before publication (`app.source_switch_failed`). The App instance name, managed path, and Route identity stay unchanged.

When the slug or web root changes, the Gateway reconciles generated Routes, runtime projections, and Laravel canonical URLs before publication. An application HTTP error does not block completion after Orbit-owned writes succeed. [Applications](/domains/applications#reconcile-an-app-update) describes source ownership and Laravel URL ownership during these updates.

## Incomplete source defaults

An App whose source defaults are incomplete can have a null default branch or root. API, SDK, and CLI JSON responses report those nulls unchanged. Reading or retrying creation does not infer missing values.
