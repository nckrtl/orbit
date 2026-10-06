---
title: "Projects"
description: "How a Project records one repository, its named apps, source access, and Instance defaults, and how create, update, and removal work."
covers:
  - "apps/gateway/app/{Actions/Instances/{UpdateInstanceAppOverridesAction,AdmitInstanceAppMutationAction,ReserveInstanceAppProjectionsAction,RunInstanceAppProjectionAction}.php,Domain/Instances/Apps/**,Infrastructure/Instances/NativeAppProjectionRuntime.php,{Actions,Domain,Infrastructure}/Projects/**}"
  - "apps/gateway/app/Domain/SourceControl/{GitRepositoryIdentity,GitRepositoryOrigin,ProjectRoot,RelativeWebRoot,RepositoryDefaultBranchResolver}.php"
  - "apps/gateway/app/Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver.php"
  - "apps/gateway/app/{Http/{Controllers/Api/ProjectsController.php,Requests/Projects/**},Data/Projects/**}"
  - "apps/gateway/app/Models/{Project,ProjectUpdate,InstanceAppUpdate,InstanceAppProjection,InstanceAppProjectionStep}.php"
  - "apps/gateway/database/migrations/{2026_10_12_{000000_add_named_apps_to_projects,000004_create_app_projection_journals,000005_add_apps_to_project_update_journals},*_{rename_app_domain_to_project_and_instance,add_task_workspace_routing}}.php"
  - "apps/cli/app/Commands/Projects/**"
---

# Projects

A Project records one Git repository, its named apps, and the defaults for running it. New Instances inherit its default branch and apps. Each app's type decides its runtime capabilities. The API path is `/api/v1/projects`, and the CLI family is [`project`](/cli/project).

## Fields

A Project stores these fields. API responses, the SDK, and CLI JSON use the same names.

| Field | Meaning |
| --- | --- |
| `slug` | Unique name, at most 63 characters. It names the directory of each new checkout and the generated domains. |
| `name` | Display name. It defaults to the slug. |
| `code` | Unique code of three uppercase letters. See [Project codes](#project-codes). |
| `apps` | Non-empty list of named applications. Each entry has `name`, `path`, `web_root`, and `type`. See [Application directory](#application-directory). |
| `repository_url` | HTTPS or SSH Git URL that Orbit uses to fetch. |
| `source_access` | `github_app` or `gh_cli`. How Orbit reads a private `github.com` repository. See [Source access](#source-access). |
| `default_branch` | Branch of the `default` Instance and the base for new branches. |
| `task_check` | Optional command that task baselines and handoffs run. It defaults to null for every type. See [Project check](/reference/tasks#project-check). |
| `task_workspace_routed` | Boolean, default true. Whether newly created task workspaces get a Route. See [Task workspace routing](#task-workspace-routing). |

## Application directory

Each app stores its application directory explicitly as `path`, relative to the repository. Its `web_root` is relative to that directory. App `site` with path `apps/site` and web root `public` serves `apps/site/public`; app `web` with path `.` and web root `public` serves `public`. Orbit never searches the repository to choose an app.

| App field | Contract |
| --- | --- |
| `name` | Required lowercase ASCII DNS label, 1 through 63 characters, letters or digits at both ends and hyphens internally. Unique within the Project. Uppercase is invalid; `default` is an ordinary app name. |
| `path` | Required canonical repository-relative directory, including `.` for the repository top level. |
| `web_root` | Required member: a canonical non-dot directory relative to `path`, or null. Null serving semantics depend on type and path, as described below. |
| `type` | Required `laravel-app`, `monorepo`, `laravel-package`, or `node-package`. See [Project types](#project-types). |

Null web root serves the application directory itself for `laravel-app`, `monorepo`, and package apps with a non-dot path. Only a package with path `.` and null web root is non-serving.

Each path is 1 through 255 bytes, with segments containing only ASCII letters, digits, dots, underscores and hyphens. The composed serving path is at most 255 bytes. Paths accept `/` separators only, with no absolute prefix, drive prefix, backslash, NUL, empty segment, trailing slash, `.` segment other than the entire application path, or `..` segment. Resolution must stay inside the checkout or release, including after resolving symlinks. Configuration validation checks the lexical form; projection checks containment before making runtime changes.

App paths must be distinct within the effective app list; nested directories are allowed but two apps cannot own the same manifests or `.env` path. Names and paths are case-sensitive. An existing app's name identifies it; replacing the name is removal plus addition. Responses sort apps by name; creation retries compare lists by name, not order.

An Instance inherits every Project app. `app_overrides` is an object keyed by existing app name. Each value has exactly `path` and `web_root`, both required; it overrides both for that Instance. Omitted names inherit. An update replaces the whole override map; `{}` clears it. It cannot add apps or override names or types. Instance responses expose the stored map and an effective `apps` list with the same four fields as the Project. Effective path uniqueness and type rules apply after overrides. Registration adopts the whole checkout and inherits all apps unless the caller sends this map; it never discovers nested apps.

The application directory contains `composer.json`, `artisan`, development [environment files](/reference/environment-variables#where-the-file-lives), and Laravel [logs](/reference/instance-logs#know-which-file-the-gateway-reads). Source inspection, PHP-FPM, default systemd Instance Processes, and Instance Schedules use the selected app's directory. Production resolves its single app through `current`. Setup, teardown, deploy steps, and task-check commands still run from the repository root; a nested Artisan step must change directory explicitly. Git identity, transfers and source selection cover the whole repository.

### Instance app runtime output

Project `apps` and Instance effective `apps` contain only the four configuration fields. Runtime state is a separate Instance `app_runtime` object keyed by every effective app name. API list/show and mutation responses, CLI JSON and MCP use the same keys. Each value has exactly `route`, `domain`, `url`, `source_profile`, `vite_port`, `agentation_port`, `agentation_url`, `annotator_port`, and `annotator_url`. It includes null members rather than omitting them.

```json
{
  "apps": [
    {"name":"admin","path":"apps/admin","web_root":"public","type":"laravel-app"},
    {"name":"web","path":"apps/site","web_root":"public","type":"laravel-app"}
  ],
  "app_overrides": {},
  "app_runtime": {
    "admin": {
      "route": null, "domain": null, "url": null,
      "source_profile": null,
      "vite_port": 5173, "agentation_port": null, "agentation_url": null,
      "annotator_port": null, "annotator_url": null
    },
    "web": {
      "route": null, "domain": null, "url": null,
      "source_profile": {"php_version":"8.5","laravel":true},
      "vite_port": 5174, "agentation_port": null, "agentation_url": null,
      "annotator_port": null, "annotator_url": null
    }
  }
}
```

This example selects app fields from an Instance during preparation, not an active serving Instance. `route` is null without an authoritative association; otherwise it is the full [Route record](/reference/routes#route-record), including its immutable `app`. `domain` equals that Route's domain and `url` equals `https://<domain>`; both are null without it. During replacement they follow the authoritative side of cutover, never a pending first Route. A classified `source_profile` has exactly nullable string `php_version` and boolean `laravel`; null means inspection has not completed or serving inspection was skipped.

Ports are null or integers from 1024 through 65535. Agentation and annotator URLs are their service bases, `https://<domain>/__orbit/agentation` and `https://<domain>/__orbit/annotator`; each is null without both its own port and authoritative Route. Production port members are null. Object keys sort by app name. Runtime state cannot be supplied as configuration. The PHP SDK has `$apps`, `$appOverrides` and `$appRuntime` on `InstanceResponse`; `$appRuntime` is a name-keyed map of `InstanceAppRuntimeResponse` DTOs with `$route`, `$domain`, `$url`, `$sourceProfile`, `$vitePort`, `$agentationPort`, `$agentationUrl`, `$annotatorPort` and `$annotatorUrl`. `toArray()` emits the JSON keys above; `$sourceProfile` is null or an `InstanceAppSourceProfileResponse` DTO with `$phpVersion` and `$laravel`.

The removed top-level Instance `route`, `domain`, `url`, `vite_port`, `agentation_port`, `annotator_port`, `annotator_url`, `selected_php_version`, `source_is_laravel` and any scalar source-profile output have no aliases or primary-app fallback, even for one app. Stored Route/target association, source classification, port assignments and pending reservation or withdrawal state migrate to app `web` without resetting classification. Ports without collisions stay unchanged; [port migration](/reference/assigned-vite-ports#migrate-port-reservations) defines collision preflight, journaled reallocation and retained-reservation refusal. Missing classification remains null. Migration retires the Instance scalar columns only after these app-keyed records exist. Instance-wide branch, checkout, placement, hibernation, release and lifecycle fields remain unchanged. Human output renders an Apps subtree rather than one domain or port column.

### Shared app-projection ownership

Project app-list changes and Instance override changes share per-Instance projection plans, admission guards and remote step receipts. `ProjectUpdate` owns the Project request, its complete affected Instance set and its `reserved`, `preflighted`, `prepared`, `publishing`, `cleaning_up` and terminal phases. `InstanceAppUpdate` owns one Instance's normalized override request, prior map, candidate map and publication/recovery phase. Neither shared adapter publishes public configuration. `InstanceAppProjection` belongs to exactly one of these parent operations and one affected Instance; it records immutable before/candidate effective apps, profiles and resource fingerprints. Each Instance has at most one incomplete owner for app mutations, including after its executing process exits.

Reservation uses the existing Instance operation locks and their existing ordering and reentrancy, followed by the existing projection and Node service locks where needed. A Project operation takes every affected Instance lock before any preflight and reserves the entire Instance set durably in one transaction or reserves none. No remote work runs in that transaction. Releasing an OS lock does not abandon a journal. A retry takes the same locks and resumes the recorded owner; a new request cannot steal it.

Instance operation locks come before Process admission locks and runtime leases. Node runtime migration also takes the full Instance set first, then keeps its existing Process-admission-before-source-lock order. Nested calls remain reentrant; migration cannot hold Process admission while waiting for an Instance operation owner. [ADR 0196](/decisions/0196-derive-application-directory-from-web-root#delivery-and-planned-path-inventory) fixes the admission entrypoints and planned files.

Contention between app mutations returns existing `instance.lifecycle_busy` (409). Within the same unfinished override owner, a different normalized map returns `instance.app_update_in_progress`; within the same Project owner, a different request returns `project.update_in_progress`. Equivalent maps ignore key order and app lists compare by name. Unrelated lock callers retain their existing errors, including environment and Process lock errors. Read-only Schedule logs and inspection remain available under either persisted app owner, with their existing lifecycle and placement checks. More specific source, Route, environment and runtime errors survive; only unspecified override projection failures use `instance.app_update_failed`. Failed restoration retains ownership and accepts only the identical request.

### Candidate rendering and public configuration

Preparation commits a projection phase before requesting remote work. Internal serving readers select the before or candidate app/profile view from that committed journal, through the existing whole-Node Caddy build and shared-version FPM paths. Every independent Node rebuild sees the same phase. Public API, CLI, SDK, MCP, web and ordinary configuration readers continue to use published Project apps, Instance overrides and runtime profiles until the parent's publication boundary.

Candidate Process and Schedule resolvers receive explicit internal targets and immutable resource fingerprints, not a temporarily changed public row. Route ID, domain, provenance and port identities are carried explicitly for retained apps; app-list additions/removals prepare or withdraw generated Routes through their existing owners.

No temporary public-row staging, transaction-held remote build, in-memory renderer override, second Caddy publisher or unmanaged Node fragment is allowed. Before publication, restoration first commits the old-side rendering intent and reconciles current desired Node state, including unrelated sites and pools. It never installs a stale whole-Node snapshot. Recovery restores private files only from their recorded owned artifacts. After publication, verification and cleanup continue forward.

### Protected step and receipt contract

Each plan freezes its parent kind/ID, Instance and Node placement, normalized request digest, old/candidate app configuration and profiles, selected release identity, app Route/domain/provenance/port identities, and affected Process/Schedule IDs and specification fingerprints. It records desired Process state separately from observed running/stopped/sleeping state, and records Schedule timer enabled and active state separately. Changing a frozen resource is a conflict, not permission to replace the plan on retry.

| Record | Required evidence |
| --- | --- |
| Parent journal | Stable operation ID, normalized request and prior configuration, phase, publication boundary and completion result. Project ownership includes the complete affected Instance set. |
| Per-Instance projection | Exactly one parent kind/ID, Instance/Node identity, immutable plan and digest, old/candidate profiles and resource fingerprints, committed render side and recovery direction. |
| Step intent | Stable step ID/sequence, owner/Instance/app/resource identity, plan digest, action/targets, receipt identity, recovery action and status. Commit before every remote mutation. |
| Protected remote receipt | Same owner/Instance/app/step/digest, target identities, before-state snapshot references and protection metadata, created-vs-existing artifacts, verified result and completion marker. Snapshot bytes stay protected, not in plaintext journal fields. |
| Step acknowledgment | Verified receipt/result fingerprint, completed phase or retained conflict/failure evidence. An absent acknowledgment is not proof that remote work failed. |

Receipt creation has a stable retry identity of its own. Snapshot/receipt creation, stop, write, reload, state restoration and cleanup each require a committed intent. After interruption or a lost response, recovery inspects that receipt and actual owned artifacts before retrying; it never captures an already modified file as its original snapshot. Missing, damaged or foreign evidence stops recovery without guessing or deleting foreign files. A committed intent without acknowledgment remains uncertain even when no error was recorded. New preparation is refused until recovery verifies that step's protected evidence or checkpointed restoration finishes. Restore and cleanup are checkpointed steps too.

Shared receipts authorize remote preparation, restoration and cleanup only; the parent alone installs public maps/profiles. Completed retries verify completion and return the current resource without restarting runtimes. Environment bytes appear only in encrypted control-plane storage or protected remote snapshots/transport, never plaintext journal fields, command arguments, logs or public output. [Environment](/reference/environment-variables#app-path-preparation-and-protected-receipts) owns file checks and protection details.

### Instance override update lifecycle

`PATCH /api/v1/instances/{instance}` accepts `app_overrides`; CLI `instance:update --app-overrides=JSON`, SDK `UpdateInstanceRequest($appOverrides: ...)` and MCP `instance-update` send that map. It cannot be combined with production `deployment_branch`. Omission is unchanged; `{}` clears all overrides. Equivalent maps ignore key order. This separate mutation owns the Instance operation lock from reservation through cleanup, sharing it with deploy, transfer, removal, rename, environment, Processes, Schedules and dependency operations. A competing owner returns existing `instance.lifecycle_busy` (409). An update to the Project's app list also acquires the affected Instance locks before preflight; neither operation may change an effective app beneath the other.

The `InstanceAppUpdate` journal records the requested map and prior map durably for the Instance. Its shared projections record prior/candidate runtime profiles, effective paths, protected snapshot references and step intents before remote mutation. It preflights every affected app's canonical containment, distinct paths, source classification, web root, permissions and available runtime. It validates every existing Process, preset and Schedule against the new app path. Domains, Route IDs, provenance and ports are unchanged.

An override update cannot change a retained app between serving and non-serving in either direction (only a package with path `.` and null web root is non-serving); it returns `app.serving_state_change_unsupported` (409) before remote work. This includes clearing an override when inheritance would change the app's serving state. Initial Instance creation or registration may supply a serving package override because provisioning reserves and publishes its required Route before activation. Migration also preserves an existing serving package override; it is not a new override mutation.

Production override mutations return `app.production_path_update_unsupported` (409); migrated overrides remain readable and are inherited by single-app cloning, but production paths change only through source preparation in a separate feature.

For a path change, encrypted configuration stays keyed to the same app. A source environment file must match the rendered stored configuration before preflight can continue; import and synchronize local edits first. A different source file or an unrelated destination `.env`/`.env.testing` returns `instance.app_environment_conflict` (409). A destination file matching the same app's rendered configuration is acceptable and is snapshotted. Orbit stages the configured `.env` and any managed `.env.testing` in the new app directory with mode `0600`. Empty unconfigured apps with no files remain file-free. It never imports a sibling's file, follows an unsafe link, or deletes a file at the old app path.

On a development `default`, preflight and staging cover both the stable home's environment path and the selected release's new app path; runtime projection continues through `current`. A candidate or selected release never links back to another app's live seed.

Preparation projects Caddy access, source profile and PHP version, FPM pool/socket working directory and cached APP_URL for each affected serving app. It rewrites derived Process environment paths, preset runtime files and default working directories, while retaining explicit Process directories. It rewrites Schedule scripts and service working directories while preserving timer state. It stops affected running Processes during preparation and restores their recorded running/stopped state against the new path before publication; it never starts sleeping Processes or cold dependencies. Unchanged apps retain their files, pools, Processes and Schedules. Repository commands and Git paths do not move.

Publication is one database transaction that installs the override map and corresponding app profiles after every prepared projection succeeds. Before this transaction, public output shows the old map and profiles even when committed internal rendering selects candidate paths. The transaction also records the journal's published boundary; a lost response cannot make recovery choose rollback after the new map became visible.

Prepublication failure restores snapshotted files, runtimes and desired states, removes only owned newly staged files, and records rollback completion. A failed rollback stays recoverable and accepts only the identical request. After publication, recovery goes forward: it verifies the new projection and cleans only operation-owned staging files, without restoring old app paths. It leaves old-path environment files in place with their existing protections. No unrelated file is removed.

A journal checkpoint precedes each mutating step. A crash resumes from that journal and verifies remote results rather than assuming an unfinished write failed. An identical retry resumes rollback before publication or forward cleanup after publication; a completed retry, including a lost success response, returns the current Instance without restarting runtimes. A different map during an incomplete update returns `instance.app_update_in_progress` (409). An infrastructure failure without a more specific existing code returns `instance.app_update_failed` (409), retaining the failed step for inspection and retry. A confirmed unchanged map is a no-op. The web editor uses this same lifecycle, not a database-only override save.

### Convert removed fields

The migration removes the Project and Instance `root` fields, `--root` flag and SDK `$root`, with no alias. Requests that send these removed settings fail validation. It moves the former Project `type` into the app. Every existing Project gets one app named `web`. Convert each removed web-root value independently; do not derive overrides from the converted Project.

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
| Instance path overrides | `app_overrides` on create, register and update; responses also return effective `apps` | `--app-overrides=JSON` on those mutations | `$appOverrides` on corresponding Instance requests/responses; `$apps` on responses |
| Instance Process or Schedule app | `app` on create and in responses; optional list filter | `--app=NAME` on create/list | `$app` on create requests and response DTOs; optional list request parameter |
| Project Process or Schedule definition app | Top-level `app` alongside `name`, `environments`, `spec` on create/replace and responses | `--app=NAME` on definition create/update/list | `$app` on definition requests and response DTOs |
| App Route | `app` on create and in responses; optional list filter | `route:create INSTANCE DOMAIN --app=NAME`, `route:list --app=NAME` | `$app` on `CreateRouteRequest`, `RouteResponse` and list request |
| App environment, logs, dependencies and source inspection | `app` selector; query on reads, JSON body on mutations | `--app=NAME` | `$app` on corresponding requests and response app identity |

`POST /api/v1/projects` requires `apps`; there is no implicit app list in API, SDK, MCP or CLI. `PATCH` with `apps` replaces the complete list; omission leaves it unchanged. Unknown or duplicate fields and wrong JSON types return HTTP 422 `validation.failed`. CLI `--apps` and `--app-overrides` are JSON, not comma-separated shorthand, files or repeatable flags. Invalid JSON fails locally with `project.apps_invalid` or `instance.app_overrides_invalid`. `project:create` takes `SLUG REPOSITORY`, not a Project type positional argument. No surface retains a Project-level type selector.

For example, `orbit project:create drift git@github.com:acme/drift.git --apps='[{"name":"web","path":"apps/site","web_root":"public","type":"laravel-app"},{"name":"admin","path":"apps/admin","web_root":"public","type":"laravel-app"}]'` creates two apps in one repository. SDK create/update `$apps` inputs are lists of four-field arrays (`name` and `path` strings, nullable string `web_root`, string `type`), not shorthand tuples. Project and Instance response `$apps` properties are lists of `ProjectAppResponse` DTOs with `$name`, `$path`, `$webRoot` and `$type`; `toArray()` emits the same four-field JSON. `$appOverrides` inputs and response properties are name-keyed maps of arrays with string `path` and nullable string `web_root`. There is no single-app shorthand or scalar fallback.

An app selector is an exact name, not an app ID or path. Omission resolves the sole app and stores its name, including a sole non-serving package. Several apps require an explicit selector; there is no primary app. A Route-domain selector identifies its app and Instance; an explicit `app` must agree with it. A pooled domain remains ambiguous for operations that require one Instance. Node-owned Processes and Schedules reject `app`. A null selector is not omission and fails `validation.failed`.

Resource-ID start/stop/run/log/destroy operations use the resource's stored app and accept no new selector. Project slug change, transfer and removal process all affected apps. Instance domain rename affects only the selected app; it never changes the Instance name or sibling domains.

Generated MCP schemas expose `apps`, `app_overrides` and `app` on the same operations as API requests. The web app's Project create/edit form has a repeatable app editor with name, path, web root and type; it sends the entire list. Instance detail lists every app with its effective paths and Route and edits the override map. Process/Schedule and definition forms have an app picker required for multi-app Projects; single-app forms send the sole name. Environment/log/dependency panels select an app. Route forms choose an app.

Only packages with path `.` and null web root show as non-serving rather than a broken link. Other null web roots serve the app directory itself.

The web app displays the same validation codes, never chooses the first app, and uses no fallback to removed fields.

### App-list update safety and limits

Adding or changing an app preflights all affected development Instances and their effective paths, domain conflicts and runtime projections before publishing the list.

An edit to the app list cannot change a retained name between serving and non-serving, in either direction, at the Project or effective Instance level. Serving state follows the app type and effective path as well as its web root; null alone does not mean non-serving. It returns `app.serving_state_change_unsupported` (409), even when an override masks the declared change or no Instance exists. Add a new app name with the intended serving state instead; remove the old app only after satisfying app-in-use checks.

Existing overrides remain for unchanged names and win over Project paths. Removing an app is refused while a Process, Schedule, Project definition, explicit Route, tracking configuration or environment configuration refers to it, or an Instance has an override for it. Generated Routes and pools can be removed by the app-list update itself.

A rename is removal plus addition, not an implicit reference rewrite. Removing the last app is invalid. `ProjectUpdate` stores requested/prior app lists and normalized identity, reserves every affected Instance through the shared ownership contract, and prepares additions, removals and path/type changes through the complete environment, serving and worker adapters. Publication installs the app list and corresponding profiles at the existing `publishing` boundary. Before `publishing`, failure restores old app configuration, Routes and runtimes; once `publishing` starts, retry continues forward under the existing Project update lifecycle. The parent's phase, not a missing step acknowledgment, selects recovery direction. Scalar updates that omit `apps` retain the existing lifecycle.

Multi-app production releases are not supported in this group. A Project with production Instances cannot add a second app.

Production creation or cloning of a multi-app Project is refused before remote work. Existing production pools accept only the same Project and app on every target and reassignment. Database attachment commands for Instances require one app; adding a second app to a Project with attached Instance databases is refused. Several development apps can still store their own database keys independently. [Analytics](/reference/analytics#single-app-boundary) remains single-app only; a second app is refused while any Instance has tracking hosts. Disable tracking before that app-list edit.

An app-list change that changes an existing production Instance's effective path, web root or type returns `app.production_path_update_unsupported`; unrelated development overrides remain editable. Slug changes retain the existing production source/release boundary and do not start a deployment.

## Development deploy steps

A Project owns an ordered [development deploy list](/reference/deployments#development-deploy-steps), separate from setup and teardown and from production's per-Instance deploy steps. Use [`project:dev-deploy-step`](/cli/project#orbit-projectdev-deploy-steplist) to list, create, update, or remove a step. Each step stores a name, command, timeout, and `required` boolean, which defaults to true. These operations change configuration only; they never start a deployment.

## Project types

The type belongs to each named app. Every Instance inherits that type; path overrides do not change it. The Project's repository-wide `task_check` defaults to null regardless of its apps.

| App type | Web root | Route | PHP-FPM |
| --- | --- | --- | --- |
| `laravel-app` | Non-dot relative directory, or null to serve the app directory | Exactly one per active routed Instance/app pair | Yes; requires Laravel source in the app path |
| `monorepo` | Non-dot relative directory, or null to serve the app directory | Exactly one per active routed Instance/app pair | Only when its app path classifies as Laravel source |
| `laravel-package` | Null or a non-dot relative directory | Unless path is `.` and web root is null | No |
| `node-package` | Null or a non-dot relative directory | Unless path is `.` and web root is null | No |

`.` is allowed as an application path, never as a serving web root. Non-serving packages need no `artisan` file. Unrouted task workspaces inherit apps but skip Route and serving-runtime preparation.

## Create a Project

Create a Project before you create or register its first Instance.

```bash
orbit project:create acme git@github.com:acme/site.git --apps='[{"name":"web","path":".","web_root":"public","type":"laravel-app"}]'
orbit project:create leden git@github.com:acme/leden.git --source-access=gh_cli --apps='[{"name":"web","path":"apps/site","web_root":"public","type":"laravel-app"}]'
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

The Gateway derives a repository identity from the host and path of the URL. Equivalent SSH and HTTPS URLs, with or without `.git`, have the same identity. A second Project for the same repository returns `project.repository_identity_conflict`. Registration uses this identity to find the Project of a checkout.

## Registration needs a Project

[`instance:register`](/domains/applications#register-an-existing-checkout) adopts a checkout only for an existing Project. It finds the Project by repository identity, or uses `--project`. When no Project owns the repository, it fails with `instance.project_missing` and changes nothing. Create the Project with `project:create` first. Registration inherits its apps unless an explicit Instance override map is sent; it does not search the checkout for nested apps.

SDK Project responses and the `project:list` and `project:show` commands expose the stored apps, repository, source access, default branch, task check, and `task_workspace_routed`. The task check is an ordinary setting, like setup steps, so activity records it as sent. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Retry creation safely

Repeating `project:create` with the same name, slug, apps, repository access URL, source access, default branch, any sent task check, and any sent `task_workspace_routed` value returns the existing Project. An omitted source access means `github_app`. Omitting `task_workspace_routed` keeps the stored value. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `project.identity_conflict` and does not mutate the Project. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Project codes

Each Project has a unique code of three uppercase letters. The Gateway derives it from the slug unless `POST /api/v1/projects` sends `code`. The code stays the same when the slug changes. Task cards use it as a label.

Change the code in the web app, or send `PATCH /api/v1/projects/{project}` with only `code`. A code sent with other fields returns `project.code_update_separate`. A code that is not three uppercase letters returns `project.invalid_code`. A code in use returns `project.code_conflict`. When every three-letter code is taken, creation returns `project.codes_exhausted`.

## Update a Project

Use `project:update` when an existing Project must change its apps, slug, repository access URL, source access, default branch, task check, or task workspace routing. The Gateway API accepts `PATCH /api/v1/projects/{project}` with those same fields, including `task_workspace_routed`. The PHP SDK sends `UpdateProjectRequest` to that path. Omitted fields stay unchanged; send `task_check: null` to clear the task check. The CLI and the MCP `project-update` tool accept the same fields. The [Update lifecycle](#update-lifecycle) defines source reconciliation. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
orbit project:update 14 --source-access=gh_cli --default-branch=main
```

`project:update` and `PATCH /api/v1/projects/{project}` change the fields you send and leave the rest. Creation never updates a Project.

| Field | Effect |
| --- | --- |
| `apps` and `--apps` | Replaces the complete app list through preflight and runtime reconciliation. Omission leaves it unchanged. |
| `slug` and `--slug` | Projects every Instance before publication, with no partial projection. Checkout paths, production users, and homes stay unchanged. Generated Routes use the new slug; explicit domains do not. |
| `repository_url` and `--repository` | Runs `git remote set-url origin` in each development checkout. Equivalent HTTPS and SSH URLs share an identity. See [Repository changes](#repository-changes). |
| `source_access` and `--source-access` | Applies at once and touches no checkout. See [Change source access](#change-source-access). |
| `default_branch` and `--default-branch` | Must exist on the remote. Switches every development `default` Instance without a `branch_override`. Explicit overrides stay unchanged. |
| `task_check` and `--task-check` | Sets the command that task baselines and handoffs run. Send null or `--clear-task-check` to run no check. |
| `task_workspace_routed` and `--task-workspace-routed=true\|false` | Sets routing for future task workspaces. Existing workspaces keep their recorded mode and Routes. |

An app type or path change must keep every effective Instance app valid. A serving Route requires a serving app with a contained composed document root (null serves the app directory); otherwise the update returns `route.target_web_root_unsupported`.

### Change source access

The Gateway first resolves the remote default branch with the new `source_access` value. When that read fails, nothing changes. A `default_branch` or `repository_url` sent in the same request is checked with the new value.

### Task workspace routing

`POST /api/v1/projects` and `PATCH /api/v1/projects/{project}` accept `task_workspace_routed` as a JSON boolean. Null, strings, and numbers fail with HTTP 422 `validation.failed`, with details for that field. Creation defaults to true; an omitted update leaves it unchanged. An explicit value participates in creation's identity check; omitting it on an identical retry preserves the existing value. API list and show responses, the SDK, CLI JSON, and MCP expose the stored boolean. CLI human detail output labels it `Task workspace routed`.

The create and update commands accept `--task-workspace-routed=true` or `--task-workspace-routed=false`. An invalid CLI value returns `project.task_workspace_routed_invalid` before a request. The setting controls task provisioning only. It does not change ordinary Instances or bypass app-path, Route, and app-type validation. A settings-only update does not reconcile existing sources or Routes.

The migration seeds false for existing Projects with slug `orbit` and true for other existing Projects to preserve their previous creation behavior. This is a one-time migration of the legacy policy; the engine never consults the slug. It also records the mode of existing task workspaces from their actual provisioned state, so Doctor does not reinterpret them after a settings change. Renaming a Project does not change the setting.

### Update lifecycle

An unfinished [Instance rename](/reference/routes) owns its Route and URL until it completes. Project reconciliation checks that owner under the shared Instance locks and returns `instance.lifecycle_busy` before changing the Project or projecting a new slug. Retry the matching rename first, including after its domain has converged but its completion transaction failed.

The Gateway applies `slug`, `repository_url`, `default_branch`, and `apps` as one recorded operation:

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

The Gateway returns these codes for Project requests. Named-app requests add the following exhaustive set; existing source, runtime, Route and environment failures keep their existing codes and include app identity for app-scoped failures. API errors use the stated HTTP status. CLI semantic validation and MCP failures preserve the same code; malformed JSON types or unknown members at the API use existing `validation.failed` (422).

| New code | HTTP | Cause |
| --- | --- | --- |
| `project.apps_invalid` | 422 | Empty app list, missing app member, invalid name/type/path/web root, or an invalid composed document root. Also local CLI JSON-list parse failure. |
| `project.app_name_conflict` | 422 | Duplicate app name in the submitted list. |
| `project.app_path_conflict` | 422 | Two effective apps share an application path, including after Instance overrides. |
| `project.app_in_use` | 409 | Removing an app would leave an override, Process, Schedule, definition, explicit Route, tracking configuration or stored environment reference. |
| `instance.app_overrides_invalid` | 422 | Invalid override-map shape, unknown app name, or invalid override paths. Also local CLI map parse failure. |
| `app.required` | 422 | An app-scoped operation omitted the selector on a multi-app Project. |
| `app.not_found` | 422 | The selected app does not belong to the owning Project. |
| `app.selector_conflict` | 422 | A Route-domain selector and explicit app name disagree. |
| `app.selector_unsupported` | 422 | A Node-owned Process or Schedule was given an app. |
| `instance.app_environment_conflict` | 409 | Override preflight found unsynchronized source environment files or unrelated destination environment files. |
| `instance.app_update_in_progress` | 409 | Another override map is recorded by an incomplete update. |
| `instance.app_update_failed` | 409 | Override projection failed without a more specific existing code; identical retry resumes recovery. |
| `app.projection_receipt_conflict` | 409 | Protected projection evidence is missing, damaged or foreign. Ownership remains reserved; recovery does not recapture snapshots or guess at prior state. |
| `app.projection_incomplete` | 409 | Completion was attempted before the recorded steps or required restoration were acknowledged. Finish recovery before releasing ownership. |
| `app.projection_recovery_required` | 409 | A failed step or restoration is recorded. Recover that evidence before preparing new steps. |
| `app.production_path_update_unsupported` | 409 | An override update targets production, or an app-list change changes an existing production app's effective path, web root or type. |
| `app.serving_state_change_unsupported` | 409 | A retained app's declared or effective serving state would change between serving and non-serving through an app-list or override edit, including override clearing. |
| `app.port_migration_conflict` | 409 | Port migration found conflicting retained transfer/withdrawal reservations; complete the named owning operations before retrying migration. |
| `app.analytics_multi_app_unsupported` | 409 | An analytics read/enable targets a multi-app Project, or adding another app would affect an Instance with tracking hosts. |
| `app.production_multi_app_unsupported` | 409 | Adding another app with production Instances present, or preparing production from a multi-app Project. |
| `app.database_multi_app_unsupported` | 409 | Adding another app while Instance database attachments exist, or using an Instance database attachment operation on a multi-app Project. |

No new Doctor issue codes are added. The `app` field distinguishes existing app-scoped findings. App lookup and validation finish before remote changes; app-list preflight and publication retain the recorded update and retry rules.

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

Creation stays idempotent: a changed value is a conflict, never an update. Changing a slug, URL, branch, or app list touches checkouts, Routes, and runtimes on several Nodes, so it runs as one recorded operation. A plain database update would leave checkouts, domains, and document roots out of step.

### Recovery goes forward after publication

Before `publishing`, the old values are still in effect, so a rollback is safe. After that point, clients can already see the new domain. Rolling back would change a public name twice. So a failure after publication is retried forward.

### App type decides capabilities

Instances share the Project's app list and app types. Per-Instance type or PHP-FPM flags were rejected because they could classify the same app differently. A package with no web root has no Route or idle PHP-FPM master. An app with a web root has its own Route, not a Route chosen from repository order.

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
