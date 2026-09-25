---
title: "Projects"
description: "How a Project records one repository, its type, its default branch, and the root path that Instances inherit."
---

# Projects

A Project stores one repository, access URL, `type`, default branch, and normalized root path. New Instances inherit these source defaults. For Laravel apps the root is a web root; for package Projects it may be `.` to name the repository root. [ADR 0105](/decisions/0105-name-applications-as-project-and-instance) names the record. [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type) owns type. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines default identity. [ADR 0026](/decisions/0026-identify-each-app-by-one-repository) defines repository ownership.

The canonical HTTP surface is `/api/v1/projects` and the canonical CLI family is `project:*`. `/api/v1/apps` remains a dual-read and dual-write compatibility path for the same records so older CLI binaries and `app-*` MCP tools keep working.

CLI JSON and SDK array output name the Project collection `projects` and the Instance collection `instances`. Instance registration also returns its collection as `instances`. These outputs do not emit the former `apps` or `app_instances` collection keys. Gateway list responses keep their standard `data` envelope. Stored table names and foreign keys are unchanged.


Project removal requires default-No interactive confirmation or explicit `--yes`. JSON and piped calls never imply consent. Inputs remain explicit; human requests show progress and preserve request IDs.

## Create a Project

Use `project:create` with a slug, a type, and an HTTPS or SSH Git origin:

```bash
orbit project:create acme laravel-app https://github.com/acme/site.git
```

`type` is required on the canonical surface. Allowed values are `monorepo`, `laravel-app`, `laravel-package`, and `node-package`. Compatibility `POST /api/v1/apps` callers that omit `type` receive `laravel-app`.

The command-line interface (CLI) uses `.` as the root for `laravel-package` and `node-package`, and `public` for other types, unless you set `--root`. Laravel apps use their relative web root. The API and SDK always require `root`. Without `--default-branch`, the Gateway reads and saves the repository's default branch once. A later remote change does not update the Project.

Both source defaults can be explicit:

```bash
orbit project:create acme laravel-app https://github.com/acme/site.git \
  --default-branch=stable \
  --root=web/public
```

The Gateway verifies that an explicit default branch exists in the repository. Repository access failures, missing explicit branches, and an unavailable or malformed remote default return `app.default_branch_unavailable` without including repository diagnostics or credentials. A private `github.com` repository needs the Gateway's [GitHub App](/reference/github-app) installed on the account that owns it.

The public Project contract uses these source fields.

| Field or option | Result |
| --- | --- |
| `type` | Required on `project:create`. Closed enum `monorepo`, `laravel-app`, `laravel-package`, or `node-package`. |
| `repository_url` | Required repository access URL in the Gateway API and PHP SDK. |
| `default_branch` | Optional Gateway API and PHP SDK input; returned by every Project response. |
| `--default-branch` | Optional CLI input for `project:create`. |
| `root` and `--root` | Required API and SDK field. A normalized repository-relative path; `.` is allowed for package types and means the repository root. |

SDK Project responses and the `project:list` and `project:show` commands expose the stored type, repository, default branch, and root. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Keep one repository owner

The Git host and path identify a repository. Equivalent SSH and HTTPS URLs match, with or without a trailing `.git`. The Gateway stores this identity separately from the access URL. Creating a second Project for the same repository returns `app.repository_identity_conflict` and changes nothing.

Repository validation and failure details do not expose embedded credentials or unredacted Git output. A checkout-origin lookup uses the canonical identity and therefore resolves no more than one Project across equivalent access forms.

During an upgrade, the Gateway checks every existing Project before it makes repository identity unique. If it finds a duplicate identity, it reports the conflicting Project IDs, changes no Project, and refuses the migration until an operator resolves the conflict.

## Resolve a Project during registration

Registration finds the Project from the checkout's verified Git origin, the `remote.origin.url` value stored in the checkout without `insteadOf` rewrites. It matches the repository identity across URL formats. Conflicting Project or source details stop registration before any changes.

When no Project owns the repository, the interactive CLI shows the safe repository origin and every inferred value, asks only for unresolved values and confirmation, and then asks the Gateway to create the Project before its Instance. The CLI refuses a credential-bearing or otherwise unsafe origin locally without displaying it or sending a request.

Ownership transfer requires a default-No confirmation naming the source, or explicit `--yes`. Non-interactive registration, including every `--json` call, requires `--yes` and refuses when a required value remains unresolved. These refusals send no mutation request. If Project creation succeeds and later registration fails, the valid Project remains available for an identical retry.

Registration can infer these Project values from unambiguous source evidence.

| Project value | Verified source evidence |
| --- | --- |
| Slug | Repository name |
| `default_branch` | Remote symbolic default branch |
| Root | `public` for an unambiguous Laravel checkout |

Valid explicit values fill only unresolved or optional values. They do not override a conflicting repository identity or verified source fact.

## Retry creation safely

Repeating `project:create` with the same name, slug, type, repository access URL, default branch, root, and defaults returns the existing Project. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `app.identity_conflict` and does not mutate the Project. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Project codes

Each Project has a unique code of three uppercase letters. Existing Projects receive a code during migration; Orbit receives `ORB`. New Projects receive a code at creation, or accept an explicit `code` in `POST /api/v1/projects`. Codes stay unchanged when the Project name or slug changes.

Edit the code in the web Project properties, or send `PATCH /api/v1/projects/{project}` with `{"code":"ORB"}`. Send code changes separately from source settings. Invalid codes return 422; codes already in use return 409. Changing a code updates task card labels without changing task IDs or URLs.

## Update a Project

Use `project:update` when an existing Project must change its type, slug, repository access URL, default branch, relative web root, or task baseline check. The Gateway API accepts `PATCH /api/v1/projects/{project}` and the compatibility path `PATCH /api/v1/apps/{app}` with those same fields. The PHP SDK sends `UpdateAppRequest` to either path. Omitted fields stay unchanged; send `task_baseline_check: null` to clear that setting. The MCP `project-update` tool accepts the same string-or-null field. [ADR 0016](/decisions/0016-reconcile-app-identity-and-source-default-updates) owns the source reconciliation lifecycle. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
```

| Field or option | Result |
| --- | --- |
| `type` and `--type` | Change Project capabilities. A change to `laravel-app` is refused while an active Instance has no Route. A change away from `laravel-app` keeps existing Routes. |
| `slug` and `--slug` | Reconcile generated development Route domains and Laravel application URLs before the new slug is published. Existing checkout paths, production users, and homes stay as recorded. |
| `repository_url` and `--repository` | Store the selected HTTPS or SSH access URL. Equivalent forms keep the same canonical repository identity. |
| `default_branch` and `--default-branch` | Store the new Project default and switch every development `default` Instance that inherits it. An explicit `branch_override` stays unchanged even when it matched the old default. |
| `root` and `--root` | Change the inherited root of every Instance without its own override. Production resolves the new root inside the active release. |
| `task_baseline_check` and `--baseline-check` | Set the Project task baseline command; send null or use `--clear-baseline-check` to clear it. |

A type change must keep a root that the new type allows. When the stored root is `.` and the new type is `laravel-app` or `monorepo`, validation fails on `root` and the message names the type. Send a web root with the type change. A type or root change that leaves a Route target inheriting an unsupported root, such as `.`, returns `route.target_web_root_unsupported`.

The Gateway treats the supplied fields as one operation. It inventories affected Instances and Routes, preflights every Orbit-owned checkout and generated domain, prepares reversible mutations, then publishes. A confirmed failure before publication rolls back origins, prepared Routes, stored Laravel `APP_URL` values, and runtime projections. The previous Project record stays authoritative. An identical retry resumes the recorded state from its last verified evidence. A conflicting update while one update is incomplete returns `app.update_in_progress`.

When the repository access URL changes, the Gateway updates `origin` once for each Orbit-owned development checkout. Linked worktrees use that common repository and are not mutated directly. The Gateway refuses the update before mutation when a worktree's common repository is not owned by an Orbit checkout, when the canonical identity belongs to another Project (`app.repository_identity_conflict`), or when any affected source fails preflight (`app.repository_preflight_failed` or `app.repository_unowned_common`). Production Git source, deployment branch, starting commit, and release layout do not change. Project updates never start a deployment.

Preflight compares the `remote.origin.url` value stored in each checkout with the current repository URL. It ignores `insteadOf` rewrites from the Node's Git configuration.

When `default_branch` cannot switch on an inheriting `default` source, the Gateway refuses before publication (`app.source_switch_failed`). The Instance name, managed path, and Route identity stay unchanged.

When the slug or web root changes, the Gateway reconciles generated Routes, runtime projections, and Laravel canonical URLs before publication. An application HTTP error does not block completion after Orbit-owned writes succeed. [Applications](/domains/applications#reconcile-an-app-update) describes source ownership and Laravel URL ownership during these updates.

## Incomplete source defaults

A Project whose source defaults are incomplete can have a null default branch or root. API, SDK, and CLI JSON responses report those nulls unchanged. Reading or retrying creation does not infer missing values.

Source-profile recovery for an existing production Instance accepts its recorded release checkout after deployment. It preserves the Instance identity and refuses paths outside that Instance’s releases directory.
