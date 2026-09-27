---
title: "Projects"
description: "How a Project records one repository, its type, and the source defaults that Instances inherit, and how create, update, and removal work."
covers:
  - apps/gateway/app/{Actions,Domain,Infrastructure}/Apps/**
  - apps/gateway/app/Domain/Projects/{ProjectType,ProjectCode}.php
  - apps/gateway/app/Domain/SourceControl/{GitRepositoryIdentity,GitRepositoryOrigin,ProjectRoot,RelativeWebRoot,RepositoryDefaultBranchResolver}.php
  - apps/gateway/app/Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver.php
  - apps/gateway/app/Http/{Controllers/Api/AppsController.php,Requests/Apps/**}
  - apps/gateway/app/Data/Apps/**
  - apps/gateway/app/Models/{App,AppUpdate}.php
  - apps/cli/app/Commands/Apps/**
---

# Projects

A Project records one Git repository and the defaults for running it. New Instances inherit its default branch and root. Its type decides what those Instances can do. The API path is `/api/v1/projects`, and the CLI family is [`project`](/cli/project).

## Fields

A Project stores these fields. API responses, the SDK, and CLI JSON use the same names.

| Field | Meaning |
| --- | --- |
| `slug` | Unique name, at most 63 characters. It names the directory of each new checkout and the generated domains. |
| `name` | Display name. It defaults to the slug. |
| `code` | Unique code of three uppercase letters. See [Project codes](#project-codes). |
| `type` | `monorepo`, `laravel-app`, `laravel-package`, or `node-package`. See [Project types](#project-types). |
| `repository_url` | HTTPS or SSH Git URL that Orbit uses to fetch. |
| `default_branch` | Branch of the `default` Instance and the base for new branches. |
| `root` | Repository-relative path that Instances inherit. |
| `task_check` | Command that task baselines and handoffs run. See [Project check](/reference/tasks#project-check). |

## Project types

The type belongs to the Project, so every Instance of one repository behaves the same way.

| Type | Route | PHP-FPM | Root `.` allowed | Default `task_check` |
| --- | --- | --- | --- | --- |
| `laravel-app` | Exactly one per active Instance | Yes | No | `composer check` |
| `monorepo` | Only an explicit Route | Only with a Route to a Laravel source | No | none |
| `laravel-package` | Only an explicit Route | No | Yes | `composer check` |
| `node-package` | Only an explicit Route | No | Yes | none |

`.` means the repository root. A Route cannot target an Instance whose root is `.`. Set a relative web root first.

## Create a Project

Create a Project before you create or register its first Instance.

```bash
orbit project:create acme laravel-app git@github.com:acme/site.git
orbit project:create acme laravel-app git@github.com:acme/site.git --default-branch=stable --root=web/public
```

The CLI root defaults to `.` for package types and `public` for other types. The API and SDK require `root`. Without `--default-branch`, the Gateway reads the remote default branch once and stores it. A later change on the remote does not update the Project. An explicit branch must exist on the remote. A private `github.com` repository needs the [GitHub App](/reference/github-app) on its owner account.

Creation is idempotent. A retry with the same values returns the existing Project. A retry that omits the default branch does not read the remote again. A retry with any different value, including another URL for the same repository, returns `app.identity_conflict` and changes nothing.

## Repository identity

The Gateway derives a repository identity from the host and path of the URL. Equivalent SSH and HTTPS URLs, with or without `.git`, have the same identity. A second Project for the same repository returns `app.repository_identity_conflict`. Registration uses this identity to find the Project of a checkout.

## Create a Project during registration

When [`instance:register`](/domains/applications#register-an-existing-checkout) finds no Project for the repository, the CLI infers these values and asks for the rest:

| Value | Source |
| --- | --- |
| Slug | The repository name. |
| `default_branch` | The checkout's `origin/HEAD`. |
| Root | `public` when the checkout has `composer.json`, `artisan`, and a `public` directory. |

A value you pass fills an unresolved value. It cannot override the repository identity. When the Project is created but registration then fails, the Project stays for an identical retry.

## Project codes

Each Project has a unique code of three uppercase letters. The Gateway derives it from the slug unless `POST /api/v1/projects` sends `code`. The code stays the same when the name or slug changes. Task cards use it as a label.

Change the code in the web app, or send `PATCH /api/v1/projects/{project}` with only `code`. A code sent with other fields returns `app.code_update_separate`. A code in use returns `app.code_conflict`.

## Update a Project

Update a Project when its type, slug, repository, default branch, root, or task check changes.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
```

`project:update` and `PATCH /api/v1/projects/{project}` change the fields you send and leave the rest. Creation never updates a Project.

| Field | Effect |
| --- | --- |
| `type` | Applies at once. A change to `laravel-app` is refused with `project.type_requires_route` while an active Instance has no Route. A change away from `laravel-app` keeps existing Routes. |
| `slug` | Replaces each generated Route with one for the new slug. Explicit domains do not change. Checkout paths, production users, and homes keep their recorded values. |
| `repository_url` | Changes `origin` in each development checkout. Linked worktrees share that repository and need no change. |
| `default_branch` | Must exist on the remote. Switches every development `default` Instance without a `branch_override`. Its name, path, and Route stay the same. |
| `root` | Changes the effective root of every Instance without its own root, and reprojects its runtime. |
| `task_check` | Applies at once. Send null or `--clear-task-check` to run no check. |

A type change must keep a valid root. When the stored root is `.` and the new type does not allow it, validation fails on `root`. Send a web root with the type change. A type or root change that leaves a Route target with root `.` returns `route.target_web_root_unsupported`.

### Update lifecycle

The Gateway applies `slug`, `repository_url`, `default_branch`, and `root` as one recorded operation:

| Status | Work |
| --- | --- |
| `reserved` | Records the request and the previous values. |
| `preflighted` | Checks every affected checkout, worktree, and generated domain. |
| `prepared` | Switches branches, changes origins, and creates replacement Routes. The old values stay in effect. |
| `publishing` | Stores the new Project values. Replaces each generated Route, then updates each Instance's Laravel URL, stored environment, and runtime. |
| `cleaning_up` | Checks that no production Instance changed. |
| `complete` | Done. |

A failure before `publishing` rolls back: Orbit restores origins, branches, and Routes and ends in `rolled_back`. A rollback that fails stays `rolling_back`, and an identical retry continues it. A failure after `publishing` starts stays in place, and an identical retry continues forward. A different update while one is incomplete returns `app.update_in_progress`.

In the `publishing` step, a failure to update one Instance's Laravel URL, environment, or runtime does not fail the update. Run [Doctor](/cli/doctor) after a slug change to find an Instance that needs attention.

Every origin check reads the `remote.origin.url` stored in the checkout. It ignores `insteadOf` rewrites on the Node. The update never changes production source, the deployment branch, or releases, and it never starts a deployment.

## Remove a Project

`project:destroy` removes a Project that has no Instances and no Routes. It deletes the Project's process and Schedule definitions, setup and teardown steps, and Node exclusions.

## Errors

The Gateway returns these codes for Project requests.

| Code | Cause |
| --- | --- |
| `app.identity_conflict` | A create retry differs from the stored Project. |
| `app.repository_identity_conflict` | Another Project owns the repository. |
| `app.default_branch_unavailable` | The Gateway cannot read the remote, or the branch is missing. The message holds no Git output or credentials. |
| `app.slug_conflict` | Another Project has the slug. |
| `app.update_required` | The update sends no field. |
| `app.update_in_progress` | Another update of this Project is incomplete. |
| `app.repository_preflight_failed` | A checkout is missing, has another origin, or cannot reach the new URL. |
| `app.repository_unowned_common` | A worktree uses a repository that no Orbit checkout owns. |
| `app.source_switch_failed` | A `default` checkout cannot switch to the new default branch. |
| `app.production_ownership_changed` | A production Instance changed during the update. |
| `app.has_app_instances` | Removal found Instances. |
| `app.has_routes` | Removal found Routes. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One repository, one Project

Registration must find exactly one Project from a checkout's origin. Comparing URLs as strings was rejected, because an SSH and an HTTPS URL would allow two Projects for one repository. Letting Projects share a repository and taking the first match was rejected, because the result would depend on database order.

### Updates are separate from creation

Creation stays idempotent: a changed value is a conflict, never an update. A slug, URL, branch, or root change touches checkouts, Routes, and runtimes on several Nodes, so it runs as one recorded operation. A plain database update would leave checkouts, domains, and document roots out of step.

### Recovery goes forward after publication

Before `publishing`, the old values are still in effect, so a rollback is safe. After that point, clients can already see the new domain. Rolling back would change a public name twice. So a failure after publication is retried forward.

### Type decides capabilities

Instances of one repository share one serving contract. Per-Instance route or PHP-FPM flags were rejected. A Laravel package or a monorepo must not publish a domain or keep an idle PHP-FPM master, so only `laravel-app` gets a Route by default.

### Project and Instance

"App" also names Laravel applications, desktop builds, and Node roles such as `app-dev`. So the repository record is a Project, and one copy on a Node is an Instance. Stored table names keep `apps` and `app_instances`.
