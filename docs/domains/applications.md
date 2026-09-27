---
title: "Applications"
description: "How a Project becomes an Instance on a Node: create or adopt a development checkout, provision its endpoint, clone to production, move, and remove."
covers:
  - apps/gateway/app/Actions/AppInstances/{CreateAppInstanceAction,RegisterAppInstanceAction,ListAppInstancesAction,ShowAppInstanceAction}.php
  - apps/gateway/app/Domain/AppInstances/{AppInstanceState,AppInstanceSourceLayout,AppInstanceDestinationGuard,ComposerSourceClassifier,Development*}.php
  - apps/gateway/app/Domain/AppInstances/Registration/**
  - apps/gateway/app/Infrastructure/AppInstances/{NativeDevelopmentAppInstanceProvisioner,RemoteDevelopmentAppInstanceSourceLifecycle,RemoteDevelopmentAppInstanceConfigurator,RemoteRegistrationSourceManager,RemoteAppInstanceDestinationGuard}.php
  - apps/gateway/app/{Http/Controllers/Api/AppInstancesController.php,Http/Requests/AppInstances/**,Data/AppInstances/**,Models/AppInstance.php}
  - apps/cli/app/Commands/Instances/{CreateInstanceCommand,RegisterInstanceCommand,ListInstancesCommand,ShowInstanceCommand,InstanceOutput}.php
  - apps/cli/app/Services/Git/**
---

# Applications

A Project records one Git repository and the defaults for running it. An Instance is one copy of that Project on one Node. An Instance on an `app-dev` Node owns a checkout or a linked worktree. An Instance on an `app-prod` Node owns a production home with releases. The Project [type](/reference/apps#project-types) decides whether an Instance gets a Route and serves PHP.

This page follows an Instance from creation to removal. Each step links to the page that owns its details.

| Step | Command | Details |
| --- | --- | --- |
| Record the repository | `project:create` | [Projects](/reference/apps) |
| Create a development checkout | `instance:create` | [Create a development Instance](#create-a-development-instance) |
| Adopt an existing checkout | `instance:register` | [Register an existing checkout](#register-an-existing-checkout) |
| Run application commands | `instance:setup` | [Instance setup and teardown](/reference/instance-setup) |
| Create a production copy | `instance:clone` | [Instance cloning](/reference/appinstance-cloning) |
| Deploy production code | `instance:deploy` | [Production release layout](/reference/deployments) |
| Move to another Node | `instance:transfer` | [Instance transfer](/reference/appinstance-transfer) |
| Remove | `instance:destroy` | [Instance removal](/reference/appinstance-removal) |

## Create a development Instance

Select an active Linux Node with an active `app-dev` role. The name `default` is reserved for the Project's default development source.

```bash
orbit instance:create <project> <node> default
orbit instance:create <project> <node> feature-one --branch=release --domain=feature.example.test
```

The Gateway clones the repository to `<apps-root>/<project-slug>/<name>`. The [apps root](/reference/node-settings) comes from the Node settings. The name decides the path and the generated domain. The branch is a separate choice.

| Input | Selected branch |
| --- | --- |
| `default`, no `--branch` | The Project `default_branch`. |
| Another name, no `--branch` | The remote branch with that name. If it is missing, a new branch with that name from the `default_branch` commit. |
| `--branch=<branch>` | The existing remote branch. If it is missing and it equals a name other than `default`, a new branch from the `default_branch` commit. |

A missing branch in any other case returns `instance.branch_resolution_failed`. Orbit selects no fallback branch.

The response returns `selected_branch` and `branch_override`. `branch_override` holds the `--branch` value, also when it equals the default branch. It is null when the Instance inherits its branch. The Instance also records the starting commit.

Creation moves through recorded states: `reserved`, `checkout_prepared`, `source_resolved`, and `active`. An identical retry resumes at the first unfinished state. The retry must name the same Project, Node, root, and branch override. A retry that changes one of them returns `instance.placement_conflict`. After activation, you can commit, move `HEAD`, or switch branches. The recorded branch and starting commit do not change.

The Gateway refuses these requests before it changes anything:

| Code | Cause |
| --- | --- |
| `app.source_defaults_incomplete` | The Project has no valid default branch or root. |
| `instance.node_inactive` | The Node is not an active Linux Node. |
| `instance.node_not_app_dev` | The Node has no active `app-dev` role. |
| `instance.node_excluded` | The Project [excludes](/reference/development-node-exclusions) the Node. |
| `instance.path_taken` | Another managed Instance uses the path. |
| `instance.candidate_required` | The Node has the `app-prod` role. Use [`instance:clone`](/reference/appinstance-cloning). |

## Register an existing checkout

Run registration on the `app-dev` Node that holds the source. The Node that sends the request is the Node that receives the Instance.

```bash
cd ~/src/acme
orbit instance:register
orbit instance:register --path=/srv/src/acme --include-worktrees --yes --json
```

Registration transfers ownership of the source to Orbit. Orbit moves the source into its managed path, and `instance:destroy` later deletes it. There is no unregister command.

The CLI reads the stored `remote.origin.url` of the checkout. It refuses a directory outside Git and an origin with credentials, and sends no request. The Gateway finds the Project by [repository identity](/reference/apps#repository-identity). When no Project owns the repository, the CLI shows the inferred values and asks the Gateway to create one. The [Projects page](/reference/apps#create-a-project-during-registration) lists those values.

The Gateway then inspects the source on the caller's Node. It trusts none of the facts the CLI sends.

| Source | Instance name |
| --- | --- |
| An independent checkout whose directory name is the Project slug and whose branch is the `default_branch` | `default` |
| Any other checkout or linked worktree | `--name`, or the directory name |

Orbit records the source layout as `checkout` or `worktree`. It moves the complete source to `<apps-root>/<project-slug>/<name>`. `HEAD`, the branch or detached state, the index, dirty and untracked files, and all refs stay as they are. A source that is already at that path stays there.

By default, registration adopts only the requested source. When Orbit moves a checkout that has linked worktrees, it repairs their Git links so they keep working. `--include-worktrees` adopts the checkout and every linked worktree in one step. The Gateway checks the complete set before it moves anything. If one check fails, nothing moves. A move to another filesystem copies and verifies the source before Orbit deletes the original. An identical retry resumes an interrupted move.

Interactive registration asks for default-No consent that names the source. JSON and noninteractive calls need `--yes`. `--setup` runs the Project setup steps after adoption. Plain registration runs no setup.

## Provision the application endpoint

Before it prepares the source, the Gateway assigns the Instance a [Vite port](/reference/assigned-vite-ports) and reserves its Route domain. `--domain` sets an explicit domain. Otherwise the domain is generated from the Cluster or Node TLD, as [Routes](/reference/routes#select-a-domain-and-scope) describes. A `laravel-app` Instance gets one Route. Other Project types get no Route.

After the source is ready, the Gateway continues in this order:

1. It inspects the source once. It records the [PHP version](/reference/php-runtime#select-the-php-version) and whether the source is Laravel. This pair is the source profile.
2. For a Laravel source with a Route, it sets `APP_URL` to the Route URL. See [Laravel application URL](#laravel-application-url).
3. It publishes the Route, certificates, Caddy site, firewall rules, and private DNS records.
4. It marks the Instance and Route active.
5. It runs the Project [setup steps](/reference/instance-setup).

An Instance without a Route skips steps 2 and 3.

A retry after step 1 inspects the source again. If the PHP version or the Laravel flag changed, the retry returns `app-dev.source_evidence_changed`.

An identical `instance:create` for an active Instance returns it unchanged and runs no setup. When setup failed earlier, `instance:create` returns `instance.setup_step_failed` until `instance:setup` succeeds.

## Laravel application URL

Orbit treats a source as Laravel when it has a regular `artisan` file and a `composer.json` that declares `laravel/framework`. A source with a `composer.json` and neither marker is plain PHP. A source without `composer.json` has no PHP. One marker without the other, a symlinked `artisan`, or an `artisan` without `composer.json` stops provisioning with `app-dev.laravel_source_invalid` or `app-dev.source_metadata_unsafe`.

Detection reads files only. It runs no Composer, Artisan, or application code, and it installs no dependencies.

For a Laravel source, Orbit writes `APP_URL=https://<route-domain>`:

- When `.env` exists, Orbit replaces the one `APP_URL` line or adds it. Every other byte stays the same.
- When `.env` is missing, Orbit creates it from `.env.example`, or empty, and adds `APP_URL`.
- When `bootstrap/cache/config.php` exists, Orbit replaces its one cached `url` value.

A symlinked file, two `APP_URL` lines, or an unclear cached value stops provisioning with `app-dev.laravel_url_configuration_failed`. After activation, the [stored environment](/reference/environment-variables) owns `APP_URL`.

## Production Instances

`instance:create` refuses an `app-prod` Node with `instance.candidate_required`. A production Instance always starts as a [clone](/reference/appinstance-cloning) of a development or production Instance. Its first [deployment](/reference/deployments) creates its first release.

A Project can have one production Instance per `app-prod` Node. Each production Instance has its own Unix user, home, and [PHP-FPM service](/reference/php-runtime#production-runtime).

## Set the web root

An Instance inherits the Project root. `--root` stores a relative override for one Instance. Orbit refuses an empty or absolute path and a path with `..`. On a production Instance, the root resolves inside the selected release, through `<home>/current`.

## Input boundary

`instance:create` and `instance:destroy` accept no repository, command, or shell input. The Project owns the repository. Registration accepts source facts only, and the Gateway verifies each one. It never accepts a Node: the caller is the Node.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Registration takes ownership

A registered source enters the same lifecycle as a created checkout: one managed path, one removal command, one set of safety checks. Registration that only observes an external worktree was rejected. It needed a separate unregister command, could not move or delete the source, and left Orbit serving paths it did not control. Adopting only linked worktrees was rejected too, because an independent checkout is equally useful source.

### The default Instance keeps its name

The default development source keeps the name `default`, its path, and its Route when the Project's default branch changes. An Instance named after its branch would move and get a new domain on every branch rename. A second public name for the default branch, such as `main_branch`, was rejected because two names for one setting make the contract unclear.

### An explicit branch stays explicit

Orbit records `--branch` even when it equals the default branch. Comparing values later cannot tell a deliberate choice from an inherited one. So when the Project default branch changes, Orbit switches only the Instances without an override. Using the Instance name as the only way to pick a branch was rejected, because a release branch would then need a new identity.

### Active does not mean healthy

An Instance is active once Orbit prepared its source, runtime, Route, and Laravel URL. A new application can lack dependencies, an application key, or a database, and it can return errors. You need the endpoint to finish that setup. So Orbit does not wait for a healthy response. A health gate, a separate activation command, and setup commands inferred from the framework were rejected.

### Orbit owns the Laravel URL

Laravel uses `APP_URL` to build links outside a request. When Orbit changes a domain and leaves `APP_URL` alone, links break. So Orbit derives `APP_URL` from the Route in development and production. Reading an existing `APP_URL` as the source of the domain was rejected: the Route decides the endpoint.

### One application model

The Instance is the only runnable application model. Orbit keeps no conversion tooling and no compatibility path for other models. Two models would double every lifecycle rule and every check.
