---
title: "Projects"
description: "How a Project records one repository, its named apps, source access, and Instance defaults, and how create, update, and removal work."
covers:
  - "apps/gateway/app/{Actions,Domain,Infrastructure}/Projects/**"
  - "apps/gateway/app/Domain/SourceControl/{GitRepositoryIdentity,GitRepositoryOrigin,ProjectRoot,RelativeWebRoot,RepositoryDefaultBranchResolver}.php"
  - "apps/gateway/app/Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver.php"
  - "apps/gateway/app/{Http/{Controllers/Api/ProjectsController.php,Requests/Projects/**},Data/Projects/**}"
  - "apps/gateway/app/Models/{Project,ProjectUpdate}.php"
  - "apps/gateway/database/migrations/{2026_10_21_{000000_add_named_apps_to_projects,000004_add_app_to_schedules,000005_remove_project_and_instance_roots},*_{rename_app_domain_to_project_and_instance,add_task_workspace_routing}}.php"
  - "apps/cli/app/Commands/Projects/**"
  - "apps/cli/app/Support/NamedAppOptions.php"
---

# Projects

A Project records one Git repository, its named apps, and the defaults for running it. New Instances inherit its default branch and apps. Each app's type decides its runtime capabilities. The API path is `/api/v1/projects`, and the CLI family is [`project`](/cli/project).

## Project Documents

[Project Documents](/reference/project-documents) are a native folder tree of notes and versioned attachments owned by the Project, independent of its Instances and Git branches. Orbit keeps their metadata in the Gateway database and their bodies in a dedicated private UpCloud bucket. The shared contract covers editing, upload/download, archive, permanent removal, and recovery across API, CLI, SDK, MCP, and web.

## Fields

A Project stores these fields. API responses, the SDK, and CLI JSON use the same names.

| Field | Meaning |
| --- | --- |
| `slug` | Unique name, at most 63 characters. It names the directory of each new checkout and the generated domains. |
| `name` | Display name. It defaults to the slug. |
| `code` | Unique code of three uppercase letters. See [Project codes](#project-codes). |
| `type` | The Project's type. See [Project types](#project-types). A single-app Project's app keeps this type. |
| `apps` | Non-empty list of named applications. Each entry has `name`, `path`, `web_root`, and `type`. See [Application directory](#application-directory). |
| `repository_url` | HTTPS or SSH Git URL that Orbit uses to fetch. |
| `source_access` | `github_app` or `gh_cli`. How Orbit reads a private `github.com` repository. See [Source access](#source-access). |
| `default_branch` | Branch of the `default` Instance and the base for new branches. |
| `task_check` | Optional command that task baselines and handoffs run. It defaults to null for every type. See [Project check](/reference/tasks#project-check). |
| `task_workspace_routed` | Boolean, default true. Whether newly created task workspaces get a Route. See [Task workspace routing](#task-workspace-routing). |
| `review_and_merge` | Boolean, default false. Whether Orbit reviews every push of the Project's tasks, reviews incoming pull requests, and merges reviewed green heads. See [Review and merge](/reference/tasks#review-and-merge). |
| `merge_check` | The check run that must pass on a head before Orbit merges it, such as `Required checks`. Null by default. The flow needs it. |

## Application directory

Each app stores its application directory explicitly as `path`, relative to the repository. Its `web_root` is relative to that directory. App `site` with path `apps/site` and web root `public` serves `apps/site/public`; app `web` with path `.` and web root `public` serves `public`. Orbit never searches the repository to choose an app.

| App field | Contract |
| --- | --- |
| `name` | Required lowercase ASCII DNS label, 1 through 63 characters, letters or digits at both ends and hyphens internally. Unique within the Project. Uppercase is invalid; `default` is an ordinary app name. |
| `path` | Required canonical repository-relative directory, including `.` for the repository top level. |
| `web_root` | Required member: a canonical non-dot directory relative to `path`, or null. Null serving semantics depend on type and path, as described below. |
| `type` | Required `laravel-app`, `symfony-app`, `monorepo`, `laravel-package`, or `node-package`. See [Project types](#project-types). |

Null web root serves the application directory itself for `laravel-app`, `symfony-app`, `monorepo`, and package apps with a non-dot path. Only a package with path `.` and null web root is non-serving.

Each path is 1 through 255 bytes, with segments containing only ASCII letters, digits, dots, underscores and hyphens. The composed serving path is at most 255 bytes. Paths accept `/` separators only, with no absolute prefix, drive prefix, backslash, NUL, empty segment, trailing slash, `.` segment other than the entire application path, or `..` segment. Resolution must stay inside the checkout or release, including after resolving symlinks. Configuration validation checks the lexical form; provisioning checks containment before making runtime changes.

App paths must be distinct within the effective app list; nested directories are allowed but two apps cannot own the same manifests or `.env` path. Names and paths are case-sensitive. An existing app's name identifies it; replacing the name is removal plus addition. Responses sort apps by name; creation retries compare lists by name, not order.

An Instance inherits every Project app. `app_overrides` is an object keyed by existing app name. Each value has exactly `path` and `web_root`, both required; it overrides both for that Instance. Omitted names inherit, and `{}` inherits every app. You set the map when you create or register the Instance; it cannot add apps or override names or types. Effective path uniqueness and type rules apply after overrides. Registration adopts the whole checkout and inherits all apps unless the caller sends this map; it never discovers nested apps.

The application directory contains `composer.json`, `artisan`, development [environment files](/reference/environment-variables#where-the-file-lives), and Laravel [logs](/reference/instance-logs#know-which-file-the-gateway-reads). Source inspection, PHP-FPM, default systemd Instance Processes, and Instance Schedules use the selected app's directory. Production resolves its single app through `current`. Setup, teardown, deploy steps, and task-check commands still run from the repository root; a nested Artisan step must change directory explicitly. [Setup and teardown](/reference/instance-setup#run-setup) and [task checks](/reference/tasks#project-check) export `VP_HOME` to the Node's resolved Vite+ store, including for project-local `vp`. Git identity, transfers and source selection cover the whole repository.

### Instance app output

Instance responses expose the stored `app_overrides` map and an effective `apps` list with the same four fields as the Project. The CLI shows them as an Apps field.

```json
{
  "apps": [
    {"name":"docs","path":"apps/docs","web_root":"public","type":"laravel-app"},
    {"name":"web","path":"apps/site","web_root":"public","type":"laravel-app"}
  ],
  "app_overrides": {}
}
```

The Instance `route`, `domain`, `url`, and port fields describe the sole app of a single-app Project. They are null when the Project has several apps. Each serving app has its own Route; a [generated domain](/reference/routes#select-a-domain-and-scope) starts with the app name, such as `docs.main.drift.test`.

### Change apps

A Project's apps and an Instance's overrides change only while the Project has no Instances. An Instance serves its apps from Routes, PHP-FPM pools, environment files, Processes, and Schedules. Orbit does not move those runtimes in place.

To change the apps of a Project with Instances, remove its Instances, send the new `apps` list, and recreate the Instances. `PATCH /api/v1/projects/{project}` with `apps` returns `project.apps_locked_by_instances` (409) while any Instance of the Project exists, including one being created or removed. To change an Instance's overrides, remove the Instance and create or register it again with the new map.

A Project without Instances replaces its complete app list in one write. Removing an app that a Process definition, Schedule definition, or Route still names returns `project.app_in_use` (409) with the app in `details.app`. Remove that reference first. Removing the last app is invalid.

### Convert removed fields

The migration removes the Project and Instance `root` fields, `--root` flag and SDK `$root`, with no alias. Requests that send `root` fail with `validation.failed` and a message that names `root`. It copies the Project `type` into the app. Every existing Project gets one app named `web`. Convert each removed web-root value independently; do not derive overrides from the converted Project.

| Removed value | App path | App web root | Explanation |
| --- | --- | --- | --- |
| `public` | `.` | `public` | A root-level application. |
| `apps/site/public` | `apps/site` | `public` | Strip only the final path segment. |
| `apps/site/web` | `apps/site/web` | null | Keep the whole application and document-root directory. |
| `web` | `web` | null | Serve the application directory itself. |
| `.` on `laravel-package` or `node-package` | `.` | null | A non-serving package, not a Laravel application. No Route or FPM pool is invented. |

For example, a Project's removed value `public` becomes `apps: [{"name":"web","path":".","web_root":"public","type":"laravel-app"}]`. An Instance whose removed override is `apps/site/public` becomes `app_overrides: {"web":{"path":"apps/site","web_root":"public"}}`. An absent removed override becomes `{}`; an explicit override equal to the Project value is still preserved. A package override with a removed root other than `.` remains serving (including a non-dot path with null web root), with the package type and no PHP-FPM. Existing explicit Route domains stay the same; [generated domains change to include `web`](/reference/routes#select-a-domain-and-scope).

The migration associates existing environment values, Instance Processes, Schedules, Project definitions and app Routes with `web`. Node-owned Processes and Schedules keep null `app`. Existing single-app production environments, release links, dedicated FPM identities and pool associations keep their physical layout. Conversion is resumable and uses the existing Route replacement lifecycle for generated-domain cutover. Development Instances with a serving app acquire a generated Route if none exists, except task workspaces whose recorded mode is unrouted. Existing source-only production Instances keep their recorded unrouted mode; migration does not invent a public domain. There is no request compatibility period.

Only a trailing `public` segment is stripped during conversion. All other supported non-dot roots retain their full directory as the app path and use null web root to serve that directory. Neither application directories nor document roots move. Production apps with null web root retain the release-root `<release>/.env` link with target `../../.env` to `<production-home>/.env`, even with a nested app path. Trailing-public apps retain their app-directory link and its original relative target depth. Migration performs no runtime or environment relocation.

### Named-app interfaces

All interfaces use the same names. API JSON, CLI JSON and MCP use snake_case. PHP SDK properties and constructor parameters use camelCase for multiword names.

| Purpose | API / MCP | CLI | PHP SDK |
| --- | --- | --- | --- |
| Project app list | `apps` on create, update, list and show | `--apps=JSON` on `project:create` and `project:update` | `$apps` on `CreateProjectRequest`, `UpdateProjectRequest` and `ProjectResponse` |
| Instance path overrides | `app_overrides` on create and register; responses also return effective `apps` | `--app-overrides=JSON` on `instance:create` and `instance:register` | `$appOverrides` on `CreateInstanceRequest`, `RegisterInstanceRequest` and `InstanceResponse`; `$apps` on `InstanceResponse` |
| Instance Process or Schedule app | `app` on create and in responses | `--app=NAME` on `process:create` and `schedule:create` | `$app` on `CreateProcessRequest`, `CreateScheduleRequest`, `ProcessResponse` and `ScheduleResponse` |
| Project Process or Schedule definition app | Top-level `app` alongside `name`, `environments`, `spec` on create/replace and responses | `--app=NAME` with `--project` on `process:create`, `process:update`, `schedule:create` and `schedule:update` | `app` in the definition JSON; `$app` on `ProjectRuntimeDefinitionResponse` |

`POST /api/v1/projects` requires `apps`; there is no implicit app list in API, SDK, MCP or CLI. `PATCH` with `apps` replaces the complete list; omission leaves it unchanged. Unknown or duplicate fields and wrong JSON types return HTTP 422 `validation.failed`. CLI `--apps` and `--app-overrides` are JSON, not comma-separated shorthand, files or repeatable flags. Invalid JSON fails locally with `project.apps_invalid` or `instance.app_overrides_invalid`.

For example, `orbit project:create drift monorepo git@github.com:acme/drift.git --apps='[{"name":"web","path":"apps/site","web_root":"public","type":"laravel-app"},{"name":"docs","path":"apps/docs","web_root":"public","type":"laravel-app"}]'` creates two apps in one repository. SDK create/update `$apps` inputs are lists of four-field arrays (`name` and `path` strings, nullable string `web_root`, string `type`), not shorthand tuples. Project and Instance response `$apps` properties are lists of `ProjectAppResponse` DTOs with `$name`, `$path`, `$webRoot` and `$type`; `toArray()` emits the same four-field JSON. `$appOverrides` inputs and response properties are name-keyed maps of arrays with string `path` and nullable string `web_root`. There is no single-app shorthand or scalar fallback.

An app selector is an exact name, not an app ID or path. Omission resolves the sole app and stores its name, including a sole non-serving package. Several apps require an explicit selector; there is no primary app. A missing selector returns `app.required`, and an unknown name returns `app.not_found`. Node-owned Processes and Schedules reject `app` with `app.selector_unsupported`. Resource-ID start, stop, run, log and destroy operations use the resource's stored app and accept no selector.

Environment, log and dependency operations, production Instances, and analytics have no app selector yet. They work on single-app Projects. On a Project with several apps, an operation that needs one app returns `app.required`.

Generated MCP schemas expose `apps`, `app_overrides` and `app` on the same operations as API requests. The [web app](/reference/web-app) lists a Project's apps. It edits them while the Project has no Instances and shows them read-only, with the reason, while it has Instances.

Only packages with path `.` and null web root show as non-serving rather than a broken link. Other null web roots serve the app directory itself.

## Setup and teardown steps

A Project owns ordered [setup and teardown lists](/reference/instance-setup) for its development Instances. Each named command runs on the Instance's Node from the repository root. A `default` Instance with the development release layout runs them in its active release. When Orbit cannot read that release, setup returns `instance.active_release_unavailable` and teardown runs in the checkout. Production Instances run neither list.

When a command is missing or not executable on that Node (exit 127 or 126), Orbit returns `instance.setup_step_unavailable` or `instance.teardown_step_unavailable` with the step name and `outcome: missing`. The message names the step, Node, and exit code and says the command was not found or is not executable. Other command failures still return `instance.setup_step_failed` or `instance.teardown_step_failed`. See [Instance setup and teardown](/reference/instance-setup#failure-codes) for retry and removal behavior.

## Development deploy steps

A Project owns an ordered [development deploy list](/reference/deployments#development-deploy-steps), separate from setup and teardown and from production's per-Instance deploy steps. Use [`project:dev-deploy-step`](/cli/project#orbit-projectdev-deploy-steplist) to list, create, update, or remove a step. Each step stores a name, command, timeout, and `required` boolean, which defaults to true. These operations change configuration only; they never start a deployment.

## Project types

Each named app has a type, and that type decides the app's runtime. Every Instance inherits the app types; path overrides do not change them. The Project also keeps its own `type`. When the type of a Project with one app changes, that app takes the new type. The Project's `task_check` covers the whole repository. It defaults to null, whatever apps the Project has.

| App type | Web root | Route | PHP-FPM |
| --- | --- | --- | --- |
| `laravel-app` | Non-dot relative directory, or null to serve the app directory | Exactly one per active routed Instance/app pair | Yes; requires Laravel source in the app path |
| `symfony-app` | Non-dot relative directory, or null to serve the app directory | Exactly one per active routed Instance/app pair | Yes; requires Symfony source in the app path |
| `monorepo` | Non-dot relative directory, or null to serve the app directory | Exactly one per active routed Instance/app pair | Only when its app path classifies as Laravel source |
| `laravel-package` | Null or a non-dot relative directory | Unless path is `.` and web root is null | No |
| `node-package` | Null or a non-dot relative directory | Unless path is `.` and web root is null | No |

`.` is allowed as an application path, never as a serving web root. Non-serving packages need no `artisan` file. Unrouted task workspaces inherit apps but skip Route and serving-runtime preparation.

A `symfony-app` serves like a `laravel-app`: one Route, a PHP-FPM pool, and the PHP version from `composer.json`. Orbit runs no Laravel step for it. It writes no `APP_URL` and patches no Laravel configuration cache. See [Symfony applications](/domains/applications#symfony-applications).

## Create a Project

Create a Project before you create or register its first Instance.

```bash
orbit project:create acme laravel-app git@github.com:acme/site.git --apps='[{"name":"web","path":".","web_root":"public","type":"laravel-app"}]'
orbit project:create leden laravel-app git@github.com:acme/leden.git --source-access=gh_cli --apps='[{"name":"web","path":"apps/site","web_root":"public","type":"laravel-app"}]'
```

Every interface requires the app list. Each entry must include its type, path and web root, including explicit null for a non-serving package. Without `--default-branch`, the Gateway reads the remote default branch once and stores it. A later change on the remote does not update the Project. An explicit branch must exist on the remote. The Gateway reads the remote with the Project's [source access](#source-access).

Creation is idempotent. A retry with the same values returns the existing Project. A retry that omits the default branch does not read the remote again. A retry with any different value, including another URL for the same repository, returns `project.identity_conflict` and changes nothing.

## Source access

`source_access` decides how Orbit reads a private `github.com` repository. It applies to every read: default-branch checks on create and update, branch resolution on `instance:create`, and every clone and fetch on a Node.

| Value | Orbit reads with |
| --- | --- |
| `github_app` | The Gateway's [GitHub App](/reference/github-app#how-orbit-reads-a-repository), when an installation covers the repository. Otherwise without a credential. This is the default. |
| `gh_cli` | The [GitHub CLI login](/reference/github-app#read-through-the-github-cli) of the Gateway's `orbit` user. Only for `github.com` repository URLs. |

A public repository and a repository on another host work with `github_app`. A `gh_cli` Project cannot start [tasks](/reference/tasks), because tasks publish through the App.

## Repository identity

The Gateway derives a repository identity from the host and path of the URL. Equivalent SSH and HTTPS URLs, with or without `.git`, have the same identity. A second Project for the same repository returns `project.repository_identity_conflict`. Registration uses this identity to find the Project of a checkout. [Doctor](/cli/doctor#what-each-family-checks) uses it to compare a checkout's origin with the Project, so an SSH origin of an HTTPS Project is not `project.repository_origin_mismatch`.

## Registration needs a Project

[`instance:register`](/domains/applications#register-an-existing-checkout) adopts a checkout only for an existing Project. It finds the Project by repository identity, or uses `--project`. When no Project owns the repository, it fails with `instance.project_missing` and changes nothing. Create the Project with `project:create` first. Registration inherits its apps unless an explicit Instance override map is sent; it does not search the checkout for nested apps.

SDK Project responses and the `project:list` and `project:show` commands expose the stored apps, repository, source access, default branch, task check, `task_workspace_routed`, `review_and_merge`, and `merge_check`. The task check is an ordinary setting, like setup steps, so activity records it as sent. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Retry creation safely

Repeating `project:create` with the same name, slug, apps, repository access URL, source access, default branch, any sent task check, and any sent `task_workspace_routed` value returns the existing Project. An omitted source access means `github_app`. Omitting `task_workspace_routed` keeps the stored value. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `project.identity_conflict` and does not mutate the Project. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Project codes

Each Project has a unique code of three uppercase letters. The Gateway derives it from the slug unless `POST /api/v1/projects` sends `code`. The code stays the same when the slug changes. Task cards use it as a label.

Change the code in the web app, or send `PATCH /api/v1/projects/{project}` with only `code`. A code sent with other fields returns `project.code_update_separate`. A code that is not three uppercase letters returns `project.invalid_code`. A code in use returns `project.code_conflict`. When every three-letter code is taken, creation returns `project.codes_exhausted`.

## Update a Project

Use `project:update` when an existing Project must change its type, apps, slug, repository access URL, source access, default branch, task check, task workspace routing, or review and merge. The Gateway API accepts `PATCH /api/v1/projects/{project}` with those same fields, including `task_workspace_routed`. The PHP SDK sends `UpdateProjectRequest` to that path. Omitted fields stay unchanged; send `task_check: null` to clear the task check. The CLI and the MCP `project-update` tool accept the same fields. The [Update lifecycle](#update-lifecycle) defines source reconciliation. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
orbit project:update 14 --source-access=gh_cli --default-branch=main
```

`project:update` and `PATCH /api/v1/projects/{project}` change the fields you send and leave the rest. Creation never updates a Project.

| Field | Effect |
| --- | --- |
| `apps` and `--apps` | Replaces the complete app list while the Project has no Instances. See [Change apps](#change-apps). Omission leaves it unchanged. |
| `slug` and `--slug` | Projects every Instance before publication, with no partial projection. Checkout paths, production users, and homes stay unchanged. Generated Routes use the new slug; explicit domains do not. |
| `repository_url` and `--repository` | Runs `git remote set-url origin` in each development checkout. Equivalent HTTPS and SSH URLs share an identity. See [Repository changes](#repository-changes). |
| `source_access` and `--source-access` | Applies at once and touches no checkout. See [Change source access](#change-source-access). |
| `default_branch` and `--default-branch` | Must exist on the remote. Switches every development `default` Instance without a `branch_override`. Explicit overrides stay unchanged. |
| `task_check` and `--task-check` | Sets the command that task baselines and handoffs run. Send null or `--clear-task-check` to run no check. |
| `task_workspace_routed` and `--task-workspace-routed=true\|false` | Sets routing for future task workspaces. Existing workspaces keep their recorded mode and Routes. |
| `review_and_merge` and `--review-and-merge=true\|false` | Switches the [review-and-merge flow](/reference/tasks#review-and-merge). It applies at the next tick, to open tasks too. |
| `merge_check` and `--merge-check=NAME` | Names the check that must pass before Orbit merges. Send null or `--clear-merge-check` to clear it. |

### Change source access

The Gateway first resolves the remote default branch with the new `source_access` value. When that read fails, nothing changes. A `default_branch` or `repository_url` sent in the same request is checked with the new value.

### Task workspace routing

`POST /api/v1/projects` and `PATCH /api/v1/projects/{project}` accept `task_workspace_routed` as a JSON boolean. Null, strings, and numbers fail with HTTP 422 `validation.failed`, with details for that field. Creation defaults to true; an omitted update leaves it unchanged. An explicit value participates in creation's identity check; omitting it on an identical retry preserves the existing value. API list and show responses, the SDK, CLI JSON, and MCP expose the stored boolean. CLI human detail output labels it `Task workspace routed`.

The create and update commands accept `--task-workspace-routed=true` or `--task-workspace-routed=false`. An invalid CLI value returns `project.task_workspace_routed_invalid` before a request. The setting controls task provisioning only. It does not change ordinary Instances or bypass app-path, Route, and app-type validation. A settings-only update does not reconcile existing sources or Routes.

The migration seeds false for existing Projects with slug `orbit` and true for other existing Projects to preserve their previous creation behavior. This is a one-time migration of the legacy policy; the engine never consults the slug. It also records the mode of existing task workspaces from their actual provisioned state, so Doctor does not reinterpret them after a settings change. Renaming a Project does not change the setting.

### Review and merge

`PATCH /api/v1/projects/{project}` accepts `review_and_merge` as a JSON boolean and `merge_check` as a string of at most 255 characters, or null. The flow is on only while `review_and_merge` is true, `merge_check` is set, `source_access` is `github_app`, and `task_compute` is `shared`. A request that would leave the switch on without one of these fails with HTTP 422 `validation.failed` on `merge_check` or `review_and_merge`, and changes nothing. CLI human detail output labels the fields `Review and merge` and `Merge check`. An invalid CLI value returns `project.review_and_merge_invalid`, `project.merge_check_conflict`, or `project.merge_check_invalid` before a request.

```bash
orbit project:update 46 --review-and-merge=true --merge-check="Required checks"
```

[Tasks: Review and merge](/reference/tasks#review-and-merge) describes what the switch changes.

### Update lifecycle

An unfinished [Instance rename](/reference/routes) owns its Route and URL until it completes. Project reconciliation checks that owner under the shared Instance locks and returns `instance.lifecycle_busy` before changing the Project or projecting a new slug. Retry the matching rename first, including after its domain has converged but its completion transaction failed.

The Gateway applies `slug`, `repository_url`, and `default_branch` as one recorded operation:

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

Branch switch and rollback checkout commands run as the managed user without a credential environment, so the managed user owns the files they write. A filter can only come from `.git/config`, which the worker cannot write.

Run [Doctor](/cli/doctor) to inspect any projection that needs attention.

### Repository changes

A repository change touches only `origin`. Local branches, the checked-out commit, and the recorded starting commit stay the same. Orbit never pushes. Linked worktrees share the checkout's repository and need no change.

Every origin check reads the `remote.origin.url` stored in the checkout. It ignores `insteadOf` rewrites on the Node. The update never changes production source, the deployment branch, or releases, and it never starts a deployment.

## Remove a Project

`project:destroy` removes a Project that has no Instances and no Routes. It deletes the Project's process and Schedule definitions, [task definitions](/reference/tasks#task-definitions), setup and teardown steps, Node exclusions, and update records.

A Project with tasks cannot be removed. The Gateway refuses the request with HTTP 409 and `project.has_task_groups`.

## Errors

The Gateway returns these codes for Project requests. Named-app requests add the following codes; existing source, runtime, Route and environment failures keep their existing codes. API errors use the stated HTTP status. CLI semantic validation and MCP failures preserve the same code; malformed JSON types or unknown members at the API use existing `validation.failed` (422).

| New code | HTTP | Cause |
| --- | --- | --- |
| `project.apps_invalid` | 422 | Empty app list, missing app member, invalid name/type/path/web root, or an invalid composed document root. Also local CLI JSON-list parse failure. |
| `project.app_name_conflict` | 422 | Duplicate app name in the submitted list. |
| `project.app_path_conflict` | 422 | Two effective apps share an application path, including after Instance overrides. |
| `project.apps_locked_by_instances` | 409 | An apps update while the Project has Instances. Remove the Instances, change the apps, then recreate the Instances. |
| `project.app_in_use` | 409 | Removing an app that a Process definition, Schedule definition or Route still names. |
| `instance.app_overrides_invalid` | 422 | Invalid override-map shape, unknown app name, or invalid override paths. Also local CLI map parse failure. |
| `app.required` | 422 | An app-scoped operation omitted the selector on a multi-app Project. |
| `app.not_found` | 422 | The selected app does not belong to the owning Project. |
| `app.selector_unsupported` | 422 | A Node-owned Process or Schedule was given an app. |
| `app.port_migration_conflict` | 409 | Port migration found conflicting retained transfer/withdrawal reservations; complete the named owning operations before retrying migration. |

No new Doctor issue codes are added. The `app` field distinguishes existing app-scoped findings. App lookup and validation finish before remote changes.

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

Creation stays idempotent: a changed value is a conflict, never an update. Changing a slug, URL, or branch touches checkouts, Routes, and runtimes on several Nodes, so it runs as one recorded operation. A plain database update would leave checkouts, domains, and document roots out of step.

### Recovery goes forward after publication

Before `publishing`, the old values are still in effect, so a rollback is safe. After that point, clients can already see the new domain. Rolling back would change a public name twice. So a failure after publication is retried forward.

### App type decides capabilities

Instances share the Project's app list and app types. Per-Instance type or PHP-FPM flags were rejected because they could classify the same app differently. A package with no web root has no Route or idle PHP-FPM master. An app with a web root has its own Route, not a Route chosen from repository order.

### Apps are explicit

A repository can hold several applications, and a Laravel application can sit below the checkout's top level. The application directory owns `artisan`, `composer.json`, `.env`, and logs; the checkout does not. So each app records its `path` and `web_root`, and every runtime path comes from them. Searching the repository for `artisan` or `public` directories was rejected, because the result would depend on what happens to be in the checkout. Deriving the application directory from one web-root setting was rejected, because it cannot describe several apps and it hides which directory owns `.env`.

Repository commands stay at the repository root. Setup, teardown, deploy steps, task checks, and Git operations belong to the repository, not to one app, so a nested Artisan step changes directory itself.

### One Route and one pool per app

Every serving app gets its own Route, PHP-FPM pool, environment file, and `APP_URL`. Generated domains start with the app name, also for a single app named `web`, so a second app never renames the first. Choosing a primary app was rejected: list order or a default name would decide which app owns the plain domain, and adding an app could then move it.

### Apps change only without Instances

An Instance turns its apps into Routes, certificates, PHP-FPM pools, environment files, Processes, and Schedules on its Node. Moving those in place needs a journaled preparation, publication, and rollback across every affected runtime and Node. That machinery was rejected as more code and risk than the change is worth: apps change rarely, and removing and recreating Instances already converges every runtime through tested lifecycles. So the Gateway refuses an apps change while the Project has Instances, and an Instance keeps the override map it was created with.

### A setting routes task workspaces

A new task workspace is visitable only when the Project's `task_workspace_routed` setting says so. Choosing that from the slug `orbit` was rejected, because the engine would then know one repository. [Task workspace routing](#task-workspace-routing) records the one-time migration of that old result, and that a later change does not reroute a workspace that already exists.

### One public name without compatibility

Project is the only public name for the repository record. Orbit has one operator, who does not value legacy support, so compatibility paths, aliases, inert endpoints, and conversion windows are removed by default without waiting for fleet migration or another confirmation. A second name adds code, tests, documentation, and ambiguity without protecting a supported user population.

The supported surface is `/api/v1/projects`, `project:*`, the Project MCP tools, and Instance fields `project_id` and `project`. The former `/api/v1/apps` routes, their nested Process and Schedule definition routes, and generated `app-*` MCP tools are removed. Project creation requires an explicit `apps` list with a type on every entry; there is no path-specific default for an old endpoint. CLI Project selection uses `--project`; `--app` selects a named application within that Project, never the Project itself. [Dependency scan and update](/reference/instance-dependencies) select one Instance by its Route domain with `--project`.

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

### Task compute

`task_compute` selects `shared` or `vm` for future task group claims. It defaults to `shared` while sandbox rollout is in progress. The first reservation records the selected mode on the group. That mode stays fixed through retries, review, and resume, even if the Project setting changes. Existing groups that have already started keep `shared`. A VM group waits with a visible reason when its sandbox is unavailable and never uses a shared workspace as a fallback. Enable `vm` only after the corresponding lane has passed its disposable proof.

Set the mode with `orbit project:create ... --task-compute=shared|vm` or
`orbit project:update <id> --task-compute=shared|vm`. `project:show` shows the
Project setting. `tasks:show` shows the group's pinned mode and its capacity wait
reason. Omitting the option on update preserves the setting.
