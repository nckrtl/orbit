---
title: "Projects"
description: "How a Project records one repository, its type, and the source defaults that Instances inherit, and how create, update, and removal work."
covers:
  - apps/gateway/app/{Actions,Domain,Infrastructure}/Projects/**
  - apps/gateway/app/Domain/Projects/{ProjectType,ProjectCode}.php
  - apps/gateway/app/Domain/SourceControl/{GitRepositoryIdentity,GitRepositoryOrigin,ProjectRoot,RelativeWebRoot,RepositoryDefaultBranchResolver}.php
  - apps/gateway/app/Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver.php
  - apps/gateway/app/Http/{Controllers/Api/ProjectsController.php,Requests/Projects/**}
  - apps/gateway/app/Data/Projects/**
  - apps/gateway/app/Models/{Project,ProjectUpdate}.php
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

`.` means the repository root. A Route cannot target an Instance whose root is `.`. Set a relative web root first. A `laravel-package` Project does not need an `artisan` file.

## Create a Project

Create a Project before you create or register its first Instance.

```bash
orbit project:create acme laravel-app git@github.com:acme/site.git
orbit project:create acme laravel-app git@github.com:acme/site.git --default-branch=stable --root=web/public
```

The CLI root defaults to `.` for package types and `public` for other types. The API and SDK require `root`. Without `--default-branch`, the Gateway reads the remote default branch once and stores it. A later change on the remote does not update the Project. An explicit branch must exist on the remote. A private `github.com` repository needs the [GitHub App](/reference/github-app) on its owner account.

Creation is idempotent. A retry with the same values returns the existing Project. A retry that omits the default branch does not read the remote again. A retry with any different value, including another URL for the same repository, returns `project.identity_conflict` and changes nothing.

## Repository identity

The Gateway derives a repository identity from the host and path of the URL. Equivalent SSH and HTTPS URLs, with or without `.git`, have the same identity. A second Project for the same repository returns `project.repository_identity_conflict`. Registration uses this identity to find the Project of a checkout.

## Create a Project during registration

When [`instance:register`](/domains/applications#register-an-existing-checkout) finds no Project for the repository, the CLI infers these values and asks for the rest:

| Value | Source |
| --- | --- |
| Slug | The repository name. |
| `default_branch` | The checkout's `origin/HEAD`; optional API and SDK input, returned by every Project response. |
| Root | `public` when the checkout has `composer.json`, `artisan`, and a `public` directory. |
| Name | The slug, unless you pass `--project-name`. |
| Type | `monorepo` for the Orbit repository, or for slug `orbit` with a repository path that ends in `/orbit`. `laravel-app` when the root is `public` or ends in `/public`. `laravel-package` otherwise. |
| `type` | Required on `project:create`. Closed enum `monorepo`, `laravel-app`, `laravel-package`, or `node-package`. |
| `repository_url` | Required repository access URL in the Gateway API and PHP SDK. |
| `--default-branch` | Optional CLI input for `project:create`. |
| `root` and `--root` | Required API and SDK field. A normalized repository-relative path; `.` is allowed for package types and means the repository root. |
| `task_check` and `--task-check` | Optional command that task baselines and handoffs run ([Project check](/reference/tasks#project-check)). When omitted, `laravel-app` and `laravel-package` get `composer check`, and `monorepo` and `node-package` get null, which runs no check command. An explicit null also stores no command. |
Registration never picks `node-package` and has no type option. Change the type afterwards with `project:update --type`.

SDK Project responses and the `project:list` and `project:show` commands expose the stored type, repository, default branch, root, and task check. The task check is an ordinary setting, like setup steps, so activity records it as sent. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

A value you pass fills an unresolved value only. It must match what the Gateway verifies:

| Input | Code when it differs |
| --- | --- |
| `--project-slug` for a new Project | `project.slug_conflict`. The slug is always the repository name. |
| `--default-branch` for a new Project | `project.default_branch_conflict`, when the checkout has an `origin/HEAD`. |
| `--root` for a new Project | `project.root_conflict`, when the root was inferred. |
| `--project-slug`, `--project-name`, or `--default-branch` for an existing Project | `project.identity_conflict`. |
| A root that the type does not allow | `project.root_invalid`. |

Valid explicit values fill only unresolved or optional values. They do not override a conflicting repository identity or verified source fact. When the Project is created but registration then fails, the Project stays for an identical retry.

## Retry creation safely

Repeating `project:create` with the same name, slug, type, repository access URL, default branch, root, and any sent task check returns the existing Project. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `project.identity_conflict` and does not mutate the Project. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Project codes

Each Project has a unique code of three uppercase letters. The Gateway derives it from the slug unless `POST /api/v1/projects` sends `code`. The code stays the same when the slug changes. Task cards use it as a label.

Change the code in the web app, or send `PATCH /api/v1/projects/{project}` with only `code`. A code sent with other fields returns `project.code_update_separate`. A code that is not three uppercase letters returns `project.invalid_code`. A code in use returns `project.code_conflict`. When every three-letter code is taken, creation returns `project.codes_exhausted`.

## Update a Project

Use `project:update` when an existing Project must change its type, slug, repository access URL, default branch, relative web root, or task check. The Gateway API accepts `PATCH /api/v1/projects/{project}` with those same fields. The PHP SDK sends `UpdateAppRequest` to that path. Omitted fields stay unchanged; send `task_check: null` to clear the task check. The MCP `project-update` tool accepts the same fields. The [Update lifecycle](#update-lifecycle) defines source reconciliation. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
```

`project:update` and `PATCH /api/v1/projects/{project}` change the fields you send and leave the rest. Creation never updates a Project.

| Field | Effect |
| --- | --- |
| `type` and `--type` | Applies at once. A `laravel-app` needs a Route; changing away keeps existing Routes. |
| `slug` and `--slug` | Projects every Instance before publication, with no partial projection. Checkout paths, production users, and homes stay unchanged. Generated Routes use the new slug; explicit domains do not. |
| `repository_url` and `--repository` | Runs `git remote set-url origin` in each development checkout. Equivalent HTTPS and SSH URLs share an identity. See [Repository changes](#repository-changes). |
| `default_branch` and `--default-branch` | Must exist on the remote. Switches every development `default` Instance without a `branch_override`. Explicit overrides stay unchanged. |
| `root` and `--root` | Changes the effective root of every Instance without its own root. Orbit reprojects the runtime of each such Instance that has a Route. |
| `task_check` and `--task-check` | Sets the command task baseline and handoffs run. Send null or `--clear-task-check` to run no check. |

A type change must keep a valid root. When the stored root is `.` and the new type does not allow it, validation fails on `root`. Send a web root with the type change. A type or root change that leaves a Route target with root `.` returns `route.target_web_root_unsupported`.

### Update lifecycle

The Gateway applies `slug`, `repository_url`, `default_branch`, and `root` as one recorded operation:

| Status | Work |
| --- | --- |
| `reserved` | Records the request and the previous values. |
| `preflighted` | Checks every affected checkout, worktree, and generated domain. |
| `prepared` | Switches branches, changes origins, and creates replacement Routes. The old values stay in effect. |
| `publishing` | Publishes the new Project values after projection succeeds. Replaces generated Routes and updates Instance URLs, environments, and runtimes. |
| `cleaning_up` | Checks that no production Instance changed. |
| `complete` | Done. |

A failure before `publishing` rolls back: Orbit restores origins, branches, and Routes and ends in `rolled_back`. A rollback that fails stays `rolling_back`, and an identical retry continues it. A failure after `publishing` starts stays in place, and an identical retry continues forward. A different update while one is incomplete returns `project.update_in_progress`.

Run [Doctor](/cli/doctor) to inspect any projection that needs attention.

### Repository changes

A repository change touches only `origin`. Local branches, the checked-out commit, and the recorded starting commit stay the same. Orbit never pushes. Linked worktrees share the checkout's repository and need no change.

Every origin check reads the `remote.origin.url` stored in the checkout. It ignores `insteadOf` rewrites on the Node. The update never changes production source, the deployment branch, or releases, and it never starts a deployment.

## Remove a Project

`project:destroy` removes a Project that has no Instances and no Routes. It deletes the Project's process and Schedule definitions, setup and teardown steps, Node exclusions, and update records.

A Project with task groups cannot be removed. The Gateway refuses the request with HTTP 409 and `project.has_task_groups`.

## Errors

The Gateway returns these codes for Project requests. [Create a Project during registration](#create-a-project-during-registration) lists the registration codes.

| Code | Cause |
| --- | --- |
| `project.identity_conflict` | A create retry differs from the stored Project. |
| `project.repository_identity_conflict` | Another Project owns the repository. |
| `project.default_branch_unavailable` | The Gateway cannot read the remote, or the branch is missing. The message holds no Git output or credentials. |
| `project.slug_conflict` | Another Project has the slug. |
| `project.update_required` | The update sends no field. |
| `project.update_in_progress` | Another update of this Project is incomplete. |
| `project.repository_preflight_failed` | A checkout is missing, has another origin, or cannot reach the new URL. |
| `project.repository_origin_failed` | Orbit could not change or restore `origin` in a checkout. |
| `route.domain_conflict` | A new generated domain for the slug belongs to another Route. |
| `project.repository_unowned_common` | A worktree uses a repository that no Orbit checkout owns. |
| `project.source_switch_failed` | A `default` checkout cannot switch to the new default branch. |
| `project.production_ownership_changed` | A production Instance changed during the update. |
| `project.update_failed` | The update failed for a reason without its own code, and Orbit rolled back. |
| `project.has_instances` | Removal found Instances. |
| `project.has_routes` | Removal found Routes. |

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

The repository record is a Project, and one copy on a Node is an Instance. The model, the table, the foreign key, the class, and these docs use those names. `projects` stores the repository record. `instances` stores the running copy. Related Instance rows use `instance_*`, and Project update rows use `project_updates`. The foreign keys are `project_id` and `instance_id`.

Monorepo folders such as `apps/cli` and `apps/gateway`, the Laravel root namespace `App\`, `app/` source folders, `config/app.php`, `APP_*` variables, Node roles `app-dev` and `app-prod`, the GitHub App, the type `laravel-app`, the Route kind `app`, and the node storage setting `apps` are not this domain. An alias or a second name was rejected. [ADR 0181](/decisions/0181-name-the-domain-project-and-instance-everywhere) records the choice.
