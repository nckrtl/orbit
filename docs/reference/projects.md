---
title: "Projects"
description: "How a Project records one repository, its type, its source access, and the source defaults that Instances inherit, and how create, update, and removal work."
covers:
  - "apps/gateway/app/{Actions,Domain,Infrastructure}/Projects/**"
  - "apps/gateway/app/Domain/SourceControl/{GitRepositoryIdentity,GitRepositoryOrigin,ProjectRoot,RelativeWebRoot,RepositoryDefaultBranchResolver}.php"
  - "apps/gateway/app/Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver.php"
  - "apps/gateway/app/{Http/{Controllers/Api/ProjectsController.php,Requests/Projects/**},Data/Projects/**}"
  - "apps/gateway/app/Models/{Project,ProjectUpdate}.php"
  - "apps/cli/app/Commands/Projects/**"
  - "apps/gateway/database/migrations/*_{rename_app_domain_to_project_and_instance,add_task_workspace_routing}.php"
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
| `source_access` | `github_app` or `gh_cli`. How Orbit reads a private `github.com` repository. See [Source access](#source-access). |
| `default_branch` | Branch of the `default` Instance and the base for new branches. |
| `root` | Repository-relative path that Instances inherit. |
| `task_check` | Optional command that task baselines and handoffs run. It defaults to null for every type. See [Project check](/reference/tasks#project-check). |
| `task_workspace_routed` | Boolean, default true. Whether newly created task workspaces get a Route. See [Task workspace routing](#task-workspace-routing). |

## Development deploy steps

A Project owns an ordered [development deploy list](/reference/deployments#development-deploy-steps), separate from setup and teardown and from production's per-Instance deploy steps. Use [`project:dev-deploy-step`](/cli/project#orbit-projectdev-deploy-steplist) to list, create, update, or remove a step. Each step stores a name, command, timeout, and `required` boolean, which defaults to true. These operations change configuration only; they never start a deployment.

## Project types

The type belongs to the Project, so every Instance of one repository behaves the same way.

| Type | Route | PHP-FPM | Root `.` allowed | Default `task_check` |
| --- | --- | --- | --- | --- |
| `laravel-app` | Exactly one per active Instance | Yes | No | none |
| `monorepo` | Only an explicit Route | Only with a Route to a Laravel source | No | none |
| `laravel-package` | Only an explicit Route | No | Yes | none |
| `node-package` | Only an explicit Route | No | Yes | none |

`.` means the repository root. A Route cannot target an Instance whose root is `.`. Set a relative web root first. A `laravel-package` Project does not need an `artisan` file.

## Create a Project

Create a Project before you create or register its first Instance.

```bash
orbit project:create acme laravel-app git@github.com:acme/site.git
orbit project:create acme laravel-app git@github.com:acme/site.git --default-branch=stable --root=web/public
orbit project:create leden laravel-app git@github.com:acme/leden.git --source-access=gh_cli
```

The CLI root defaults to `.` for package types and `public` for other types. The API and SDK require `root`. Without `--default-branch`, the Gateway reads the remote default branch once and stores it. A later change on the remote does not update the Project. An explicit branch must exist on the remote. The Gateway reads the remote with the Project's [source access](#source-access).

Creation is idempotent. A retry with the same values returns the existing Project. A retry that omits the default branch does not read the remote again. A retry with any different value, including another URL for the same repository, returns `project.identity_conflict` and changes nothing.

## Source access

`source_access` decides how Orbit reads a private `github.com` repository. It applies to every read: default-branch checks on create and update, branch resolution on `instance:create`, and every clone and fetch on a Node.

| Value | Orbit reads with |
| --- | --- |
| `github_app` | The Gateway's [GitHub App](/reference/github-app#how-orbit-reads-a-repository), when an installation covers the repository. Otherwise without a credential. This is the default. |
| `gh_cli` | The [GitHub CLI login](/reference/github-app#read-through-the-github-cli) of the Gateway's `orbit` user. Only for `github.com` repository URLs. |

A public repository and a repository on another host work with `github_app`. A `gh_cli` Project cannot start [tasks](/reference/tasks), because tasks publish through the App.

## Repository identity

The Gateway derives a repository identity from the host and path of the URL. Equivalent SSH and HTTPS URLs, with or without `.git`, have the same identity. A second Project for the same repository returns `project.repository_identity_conflict`. Registration uses this identity to find the Project of a checkout.

## Registration needs a Project

[`instance:register`](/domains/applications#register-an-existing-checkout) adopts a checkout only for an existing Project. It finds the Project by repository identity, or uses `--project`. When no Project owns the repository, it fails with `instance.project_missing` and changes nothing. Create the Project with `project:create` first.

SDK Project responses and the `project:list` and `project:show` commands expose the stored type, repository, source access, default branch, root, task check, and `task_workspace_routed`. The task check is an ordinary setting, like setup steps, so activity records it as sent. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Retry creation safely

Repeating `project:create` with the same name, slug, type, repository access URL, source access, default branch, root, any sent task check, and any sent `task_workspace_routed` value returns the existing Project. An omitted source access means `github_app`. Omitting `task_workspace_routed` keeps the stored value. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `project.identity_conflict` and does not mutate the Project. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Project codes

Each Project has a unique code of three uppercase letters. The Gateway derives it from the slug unless `POST /api/v1/projects` sends `code`. The code stays the same when the slug changes. Task cards use it as a label.

Change the code in the web app, or send `PATCH /api/v1/projects/{project}` with only `code`. A code sent with other fields returns `project.code_update_separate`. A code that is not three uppercase letters returns `project.invalid_code`. A code in use returns `project.code_conflict`. When every three-letter code is taken, creation returns `project.codes_exhausted`.

## Update a Project

Use `project:update` when an existing Project must change its type, slug, repository access URL, source access, default branch, relative web root, task check, or task workspace routing. The Gateway API accepts `PATCH /api/v1/projects/{project}` with those same fields, including `task_workspace_routed`. The PHP SDK sends `UpdateProjectRequest` to that path. Omitted fields stay unchanged; send `task_check: null` to clear the task check. The CLI and the MCP `project-update` tool accept the same fields. The [Update lifecycle](#update-lifecycle) defines source reconciliation. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
orbit project:update 14 --source-access=gh_cli --default-branch=main
```

`project:update` and `PATCH /api/v1/projects/{project}` change the fields you send and leave the rest. Creation never updates a Project.

| Field | Effect |
| --- | --- |
| `type` and `--type` | Applies at once. A `laravel-app` needs a Route; changing away keeps existing Routes. |
| `slug` and `--slug` | Projects every Instance before publication, with no partial projection. Checkout paths, production users, and homes stay unchanged. Generated Routes use the new slug; explicit domains do not. |
| `repository_url` and `--repository` | Runs `git remote set-url origin` in each development checkout. Equivalent HTTPS and SSH URLs share an identity. See [Repository changes](#repository-changes). |
| `source_access` and `--source-access` | Applies at once and touches no checkout. See [Change source access](#change-source-access). |
| `default_branch` and `--default-branch` | Must exist on the remote. Switches every development `default` Instance without a `branch_override`. Explicit overrides stay unchanged. |
| `root` and `--root` | Changes the effective root of every Instance without its own root. Orbit reprojects the runtime of each such Instance that has a Route. |
| `task_check` and `--task-check` | Sets the command that task baselines and handoffs run. Send null or `--clear-task-check` to run no check. |
| `task_workspace_routed` and `--task-workspace-routed=true\|false` | Sets routing for future task workspaces. Existing workspaces keep their recorded mode and Routes. |

A type change must keep a valid root. When the stored root is `.` and the new type does not allow it, validation fails on `root`. Send a web root with the type change. A type or root change that leaves a Route target with root `.` returns `route.target_web_root_unsupported`.

### Change source access

The Gateway first resolves the remote default branch with the new `source_access` value. When that read fails, nothing changes. A `default_branch` or `repository_url` sent in the same request is checked with the new value.

### Task workspace routing

`POST /api/v1/projects` and `PATCH /api/v1/projects/{project}` accept `task_workspace_routed` as a JSON boolean. Null, strings, and numbers fail with HTTP 422 `validation.failed`, with details for that field. Creation defaults to true; an omitted update leaves it unchanged. An explicit value participates in creation's identity check; omitting it on an identical retry preserves the existing value. API list and show responses, the SDK, CLI JSON, and MCP expose the stored boolean. CLI human detail output labels it `Task workspace routed`.

The create and update commands accept `--task-workspace-routed=true` or `--task-workspace-routed=false`. An invalid CLI value returns `project.task_workspace_routed_invalid` before a request. The setting controls task provisioning only. It does not change ordinary Instances or bypass root, Route, and Project-type validation. A settings-only update does not reconcile existing sources or Routes.

The migration seeds false for existing Projects with slug `orbit` and true for other existing Projects to preserve their previous creation behavior. This is a one-time migration of the legacy policy; the engine never consults the slug. It also records the mode of existing task workspaces from their actual provisioned state, so Doctor does not reinterpret them after a settings change. Renaming a Project does not change the setting.

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

When updating source, the Gateway passes `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false` to Git during preflight, origin changes, fetches, branch changes, and rollback. The Gateway does not run checkout hooks or a custom filesystem monitor for those operations. The overrides do not change the stored Git configuration.

When a worker is configured, branch switch and rollback checkout commands run as that worker without a credential environment. Any clean, smudge, or process filter they start has the worker's identity, not the managed account's.

Run [Doctor](/cli/doctor) to inspect any projection that needs attention.

### Repository changes

A repository change touches only `origin`. Local branches, the checked-out commit, and the recorded starting commit stay the same. Orbit never pushes. Linked worktrees share the checkout's repository and need no change.

Every origin check reads the `remote.origin.url` stored in the checkout. It ignores `insteadOf` rewrites on the Node. The update never changes production source, the deployment branch, or releases, and it never starts a deployment.

## Remove a Project

`project:destroy` removes a Project that has no Instances and no Routes. It deletes the Project's process and Schedule definitions, [task definitions](/reference/tasks#task-definitions), setup and teardown steps, Node exclusions, and update records.

A Project with tasks cannot be removed. The Gateway refuses the request with HTTP 409 and `project.has_task_groups`.

## Errors

The Gateway returns these codes for Project requests.

| Code | Cause |
| --- | --- |
| `project.identity_conflict` | A create retry differs from the stored Project. |
| `project.repository_identity_conflict` | Another Project owns the repository. |
| `project.default_branch_unavailable` | The Gateway cannot read the remote, or the branch is missing. The message names the App or the GitHub CLI login. It holds no Git output or credentials. |
| `github.cli_unauthenticated` | A `gh_cli` read found no `gh` on the Gateway, or no `github.com` login for the `orbit` user. |
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

### One way to create a Project

`project:create` is the only way to create a Project, so every field, such as source access, is set in one place. Creating a Project during registration was rejected. That second path needed its own options, prompts, and conflict codes, and could not set every field. A private repository that needs the GitHub CLI failed there before the operator could choose the setting.

### Updates are separate from creation

Creation stays idempotent: a changed value is a conflict, never an update. A slug, URL, branch, or root change touches checkouts, Routes, and runtimes on several Nodes, so it runs as one recorded operation. A plain database update would leave checkouts, domains, and document roots out of step.

### Recovery goes forward after publication

Before `publishing`, the old values are still in effect, so a rollback is safe. After that point, clients can already see the new domain. Rolling back would change a public name twice. So a failure after publication is retried forward.

### Type decides capabilities

Instances of one repository share one serving contract. Per-Instance route or PHP-FPM flags were rejected. A Laravel package or a monorepo must not publish a domain or keep an idle PHP-FPM master, so only `laravel-app` gets a Route by default.

### A setting routes task workspaces

A new task workspace is visitable only when the Project's `task_workspace_routed` setting says so. Choosing that from the slug `orbit` was rejected, because the engine would then know one repository. [Task workspace routing](#task-workspace-routing) records the one-time migration of that old result, and that a later change does not reroute a workspace that already exists.

### One public name without compatibility

Project is the only public name for the repository record. Orbit has one operator, who does not value legacy support, so compatibility paths, aliases, inert endpoints, and conversion windows are removed by default without waiting for fleet migration or another confirmation. A second name adds code, tests, documentation, and ambiguity without protecting a supported user population.

The supported surface is `/api/v1/projects`, `project:*`, the Project MCP tools, and Instance fields `project_id` and `project`. The former `/api/v1/apps` routes, their nested Process and Schedule definition routes, and generated `app-*` MCP tools are removed. Project creation requires an explicit `type`; there is no path-specific default for an old endpoint. CLI Project selection uses `--project`, not `--app`. [Dependency scan and update](/reference/instance-dependencies) select one Instance by its Route domain with `--project`.

The same rule applies beyond the repository record: `--wireguard-ip` has no `--wireguard-address` alias, and the [task driver settings](/reference/tasks#drivers) use separate implementer and reviewer environment variables, with no `ORBIT_TASKS_AGENT_DRIVER` fallback. Older clients that depend on removed names must be updated; Orbit provides no compatibility period.

Retaining `/apps` until clients migrate is rejected, because there is no other user's migration to protect. Keeping aliases because they seem cheap or harmless is rejected, because they obscure the supported interface and still need maintenance. A conversion window or an inert endpoint is rejected, because neither provides value to this operator. Public compatibility cleanup and [stored record naming](#project-and-instance) are separate decisions; coupling them would mix interface removal with database and class renaming. Unrelated names such as the GitHub App and Node roles are not compatibility aliases for Project.

### Project and Instance

Project is the repository record. Instance is one running copy of a Project on a Node. Those are the only names for this domain: the model, the table, the foreign key, the class, the API, the CLI, and these docs. Nothing keeps a second name as an alias, a route, a JSON field, a class, a table, or a column.

The model for the repository record is `Project`, and the model for the running copy is `Instance`. Tables are `projects`, `instances`, and `instance_*`. Project update rows are `project_updates`. Foreign keys are `project_id` and `instance_id`. A path parameter that identifies a Project is `{project}`.

Project actions live in `Actions/Projects`. A class is `Project*` or `Instance*` according to whether it belongs to the repository record or the running copy. The Gateway, the CLI, the PHP SDK, and the web app follow that rule. Laravel's root namespace stays `App\`, so those actions are `App\Actions\Projects`.

Environment placeholders are `{{instance.domain}}` and `{{instance.environment}}`. A stored value that contains any other `{{...}}` placeholder is invalid. Synchronization returns `env.reference_unavailable`. A write that fails placeholder validation returns `env.configuration_invalid`. The migration rewrites stored placeholders to these two names. Project has no morph alias. The public subject type is `project`. The Instance morph alias is `instance`. The migration rewrites stored activity class names to `App\Models\Project`.

Realtime types are `project.created`, `project.updated`, and `project.deleted`. Payload classes are `ProjectData`, `InstanceData`, and `InstanceDeploymentData`. Project error codes use the `project.` prefix. `project.has_instances` means removal found Instances. `node.has_instances` means the Node still has Instances. `instance.placement_unavailable` means placement failed. Doctor's repository family is `project`.

Monorepo folders such as `apps/cli` and `apps/gateway`, the Laravel root namespace `App\`, `app/` source folders, `config/app.php`, `APP_*` variables, Node roles `app-dev` and `app-prod`, the GitHub App, the type `laravel-app`, the Route kind `app`, and the node storage setting `apps` are not this domain.

Keeping a stored name that differs from Project or Instance was rejected, because every reader would translate. An alias that accepts another name was rejected, because an alias is a second supported path.

Renaming the monorepo folders, the Laravel root namespace, the `app/` source folders, `config/app.php`, the `APP_*` variables, Node roles, the GitHub App, the type `laravel-app`, the Route kind, or the node storage setting was rejected, because those names are not the Project or Instance record.

Reading a stored placeholder or activity class under a different spelling, as if it were the current name, was rejected: that reading is an alias, and synchronization and activity resolution then fail on rows that use the other spelling. The migration renames tables and columns in place. There is no compatibility view and no dual-write. A caller that sends a removed field, or code that imports a removed class, fails. There is no compatibility period.

A repository check fails when an App-domain name is present in app code, database code, the SDK, the CLI, web sources, or these pages. A monorepo path under `apps/` is not that name. A `covers:` glob names a file that exists, and the check rejects an App-domain name left in that glob.
