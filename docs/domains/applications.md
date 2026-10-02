---
title: "Applications"
description: "How a Project becomes an Instance on a Node: create or adopt a development checkout, provision its endpoint, clone to production, move, and remove."
covers:
  - apps/gateway/app/Actions/Instances/{CreateInstanceAction,CopyInstanceDependenciesAction,CloneInstanceDatabaseAction,RegisterInstanceAction,ListInstancesAction,ShowInstanceAction}.php
  - apps/gateway/app/Domain/Instances/{DatabaseClone,DependencyCopy}/**
  - apps/gateway/app/Domain/Instances/{InstanceState,InstanceSourceLayout,InstanceDestinationGuard,ComposerSourceClassifier,Development*}.php
  - apps/gateway/app/Domain/Instances/Registration/**
  - apps/gateway/app/Infrastructure/Instances/{NativeDevelopmentInstanceProvisioner,RemoteDevelopmentInstanceSourceLifecycle,RemoteDevelopmentInstanceConfigurator,RemoteRegistrationSourceManager,RemoteInstanceDestinationGuard,RemoteInstanceSqliteCloner,RemoteInstanceDependencyCopier}.php
  - apps/gateway/app/{Http/Controllers/Api/InstancesController.php,Http/Requests/Instances/**,Data/Instances/**,Models/Instance.php}
  - apps/cli/app/Commands/Instances/{CreateInstanceCommand,RegisterInstanceCommand,ListInstancesCommand,ShowInstanceCommand,InstanceOutput}.php
  - apps/cli/app/Services/Git/**
---

# Applications

A Project records one Git repository and the defaults for running it. An Instance is one copy of that Project on one Node. An Instance on an `app-dev` Node owns a checkout or a linked worktree. An Instance on an `app-prod` Node owns a production home with releases. The Project [type](/reference/projects#project-types) decides whether an Instance gets a Route and serves PHP.

This page follows an Instance from creation to removal. Each step links to the page that owns its details.

| Step | Command | Details |
| --- | --- | --- |
| Record the repository | `project:create` | [Projects](/reference/projects) |
| Create a development checkout | `instance:create` | [Create a development Instance](#create-a-development-instance) |
| Adopt an existing checkout | `instance:register` | [Register an existing checkout](#register-an-existing-checkout) |
| Run application commands | `instance:setup` | [Instance setup and teardown](/reference/instance-setup) |
| Create a production copy | `instance:clone` | [Instance cloning](/reference/instance-cloning) |
| Deploy production code | `instance:deploy` | [Production release layout](/reference/deployments) |
| Move to another Node | `instance:transfer` | [Instance transfer](/reference/instance-transfer) |
| Remove | `instance:destroy` | [Instance removal](/reference/instance-removal) |

## Create a development Instance

Select an active Linux Node with an active `app-dev` role. The name `default` is reserved for the Project's default development source.

```bash
orbit instance:create <project> <node> default
orbit instance:create <project> <node> feature-one --branch=release --domain=feature.example.test
```

The Gateway clones the repository to `<apps-root>/<project-slug>/<name>`. It reads the repository with the Project's [source access](/reference/projects#source-access). A `gh_cli` Project needs no GitHub login on the Node. The [apps root](/reference/node-settings) comes from the Node settings. The name decides the path and the generated domain. The branch is a separate choice.

| Input | Selected branch |
| --- | --- |
| `default`, no `--branch` | The Project `default_branch`. |
| Another name, no `--branch` | The remote branch with that name. If it is missing, a new branch with that name from the `default_branch` commit. |
| `--branch=<branch>` | The existing remote branch. If it is missing and it equals a name other than `default`, a new branch from the `default_branch` commit. |

A missing branch in any other case returns `instance.branch_resolution_failed`. Orbit selects no fallback branch.

The response returns `selected_branch` and `branch_override`. `branch_override` holds the `--branch` value, also when it equals the default branch. It is null when the Instance inherits its branch. The Instance also records the starting commit.

Creation moves through recorded states: `reserved`, `checkout_prepared`, `source_resolved`, and `active`. An identical retry resumes at the first unfinished state. The retry must name the same Project, Node, root, and branch override. A retry that changes one of them returns `instance.placement_conflict`.

If creation fails before `active`, the Instance stays in `reserved`, `checkout_prepared`, or `source_resolved` and records the failure in `failed_step` and `error_code`. You can retry creation or [remove the failed Instance](/reference/instance-removal#failed-creation) with `instance:destroy`. Removal deletes any partial checkout, deletes the reserved Route if one exists, releases the reserved Vite port, and deletes the Instance row. Orbit still refuses removal while creation is in progress; a non-active state without failure evidence is not enough.

After activation, you can commit and move `HEAD`. The recorded branch and starting commit stay as they are. One exception: when the Project default branch changes, Orbit switches a `default` Instance without `branch_override` and records the new branch. Keep the recorded branch checked out. [Removal](/reference/instance-removal#checks-before-removal) refuses a checkout on another branch with `instance.source_branch_mismatch`, also with `--force`. [Cloning](/reference/instance-cloning#candidate-rules) refuses such a candidate with `instance.clone_candidate_branch_invalid`.

The Gateway refuses these requests before it changes anything:

| Code | Cause |
| --- | --- |
| `project.source_defaults_incomplete` | The Project has no valid default branch or root. |
| `instance.node_inactive` | The Node is not an active Linux Node. |
| `instance.node_not_app_dev` | The Node has no active `app-dev` role. |
| `instance.node_excluded` | The Project [excludes](/reference/development-node-exclusions) the Node. |
| `instance.path_taken` | The name is not `default`, and another managed Instance uses the path. |
| `instance.default_path_occupied` | The name is `default`, and its path is used by a managed Instance or holds an unmanaged directory. |
| `instance.candidate_required` | The Node has the active `app-prod` role. A repeat for an existing production Instance is refused the same way. Use [`instance:clone`](/reference/instance-cloning). |
| `instance.placement_unavailable` | The owning Node does not have exactly one active `app-dev` or `app-prod` role. |

### Dependency copy

Installing dependencies is the slowest part of a new checkout. When the Project has an active Instance named `default` on the same Node, every other new development Instance gets a copy of that Instance's `vendor` and `node_modules` directories. Orbit makes the copy after the source is ready and before the database clone and the setup steps.

Orbit copies with `cp -a --reflink=auto`. On a filesystem with block cloning, such as XFS, btrfs, or OpenZFS 2.2 or later with block cloning enabled, the copy shares the data blocks of the `default` Instance. It takes seconds and uses almost no extra disk space. A file gets its own blocks only when one of the two Instances changes it, so neither Instance sees the other's changes. On a filesystem without block cloning, such as ext4, `cp` makes a normal copy instead.

Orbit copies only these two directories. The rest of the checkout comes from the clone, so the new Instance never gets the `.env`, caches, logs, or runtime files of the `default` Instance. Symlinks inside the directories stay symlinks. Composer and npm write relative links, so a link to a package inside the repository points into the new checkout.

Orbit skips a directory in these cases:

- The `default` Instance does not have it, for example after the [dependency prune](/reference/app-dev-runtime-hibernation#dependency-prune).
- It is a symlink in the `default` Instance.
- The new checkout already has it, for example because the repository commits `vendor`.

Orbit copies each directory to a staging path next to the checkout, `.orbit-copy.<instance>.<directory>`, and then renames it into place. So a directory in the new checkout is complete or absent. During the copy, Orbit holds the Process admission lock of the `default` Instance. The dependency prune and its restore wait for that lock for up to 30 seconds. Then they report `process.operation_busy`: the hibernation sweep tries again on its next pass, and waking the `default` Instance succeeds once the copy ends. The copy may take 120 seconds on the Node, and then `timeout` stops it.

A failed or stopped copy removes its staging path, and creation continues without the missing directories. The Gateway log gets a warning with the code `instance.dependency_copy_failed`, both Instance IDs, the exit code, and the end of the error output.

The [setup steps](/reference/instance-setup) still run. `composer install` and `npm install` compare the copied directories with the lock files of the new branch and change only what differs. A setup step that deletes the directory first, such as `npm ci`, discards the copy and installs everything again.

[Task workspaces](/reference/tasks#shared-instance) get the same copy.

### Database clone

When the Project has an Instance named `default` with a database attached under prefix `DB`, every other new development Instance gets its own copy of that database. The copy has the same kind as the source. Orbit makes it after the source is ready and before the setup steps, so a setup step such as a migration runs against the copy.

| Source | Copy |
| --- | --- |
| MySQL on a [Database server](/reference/database-servers) | A database named `<project>_<instance>` on the same server, owned by the Instance's user and filled with `mysqldump --single-transaction` piped into `mysql` inside the server's container, plus the test database `<project>_<instance>_test`. |
| SQLite file inside the `default` checkout | A copy at the same relative path in the new checkout, also on another Node. Tests use `:memory:`. |

On the same Node, Orbit holds SQLite's write lock while it takes a reflink of the SQLite database file and its `-wal` file. It first checks that the filesystem can clone the file, so no writer waits when it cannot. Orbit copies a snapshot made with SQLite's backup API instead in three cases: the filesystem has no block cloning, a writer holds the lock for two seconds, or the source is on another Node.

Orbit records the copy as a [database the Instance owns](/reference/database-connections#owned-databases) with the connection slug `<project>-<instance>`, attaches it under prefix `DB`, and synchronizes `.env` and `.env.testing`.

When the Instance has no stored configuration yet and its checkout has a `.env`, Orbit first [imports](/reference/environment-variables#import) that file, so synchronization keeps its other keys. Without a `.env`, synchronization writes only the stored keys.

The clone returns these codes.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.database_clone_unsupported` | 422 | The source is not MySQL on a Database server or a SQLite file inside the `default` checkout. Nothing changes. |
| `instance.database_clone_failed` | 502 | The copy failed. Orbit drops the partial copy and removes the Instance as a failed setup does. |

No teardown step runs after a failed copy, because no setup step ran yet. When the removal cannot finish, the error has `cleanup: incomplete` and names the `instance:destroy` command that finishes it.

Orbit records each finished step of the copy on its connection. When a create stops before the copy finished, an identical `instance:create` finishes the copy and then runs the setup steps. It copies the data again unless the earlier copy finished, so it never keeps a partial copy.

The copy holds the full data of the `default` Instance, including personal data. A Project without a `default` Instance, or whose `default` Instance has no `DB` attachment, gets no copy.

## Register an existing checkout

Run registration on the `app-dev` Node that holds the source. The Node that sends the request is the Node that receives the Instance.

```bash
cd ~/src/acme
orbit instance:register
orbit instance:register --path=/srv/src/acme --include-worktrees --yes --json
```

Registration transfers ownership of the source to Orbit. Orbit moves the source into its managed path, and `instance:destroy` later deletes it. There is no unregister command.

The CLI reads the stored `remote.origin.url` of the checkout. It sends no request for a directory outside Git or for an unsafe origin. A safe origin is `https://` without a user or password, `ssh://` without a password, or `git@host:path`. Any other scheme, a query, a fragment, whitespace, or a control character is unsafe. The Gateway finds the Project by [repository identity](/reference/projects#repository-identity), or uses `--project`. Registration never creates a Project. When no Project owns the repository, the Gateway returns `instance.project_missing` and changes nothing. Create the Project with [`project:create`](/cli/project#orbit-projectcreate) first.

The Gateway then inspects the source on the caller's Node. It trusts none of the facts the CLI sends.

| Source | Instance name |
| --- | --- |
| An independent checkout whose directory name is the Project slug and whose branch is the `default_branch` | `default` |
| Any other checkout or linked worktree | `--name`, or the directory name |

For a source that qualifies as `default`, a `--name` other than `default` returns `instance.identity_conflict`. A name must be a lowercase DNS label of at most 63 characters, or registration returns `instance.name_invalid`.

Orbit records the source layout as `checkout` or `worktree`. It moves the complete source to `<apps-root>/<project-slug>/<name>`. `HEAD`, the branch or detached state, the index, dirty and untracked files, and all refs stay as they are. A source that is already at that path is adopted in place: Orbit verifies it without moving, copying, or repairing its Git links.

Source verification covers this source's `HEAD`, branch, index, working tree, and local Git configuration. It does not include other branches, tags, remote-tracking refs, or agent checkpoint refs in the shared repository. Those refs can change while registration runs or between attempts. A change to the source itself before adoption or during a move still refuses registration. An identical retry resumes when the source itself is unchanged. After adoption, the managed destination is authoritative and retries check its Git identity rather than the pre-adoption content digest.

By default, registration adopts only the requested source. When Orbit moves a checkout that has linked worktrees, it repairs their Git links so they keep working. `--include-worktrees` adopts the checkout and every linked worktree in one step. The Gateway checks the complete set before it moves anything. If one check fails, nothing moves. A move to another filesystem copies and verifies the source before Orbit deletes the original. An identical retry resumes an interrupted move. The Gateway finishes that move during registration. It does not stop and ask you to change the source yourself.

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

For an Instance with a Route, a retry after step 1 inspects the source again. If the PHP version or the Laravel flag changed, the retry returns `app-dev.source_evidence_changed`. For an Instance without a Route, a retry records the new profile.

A failed setup step during `instance:create` runs the teardown steps and removes the new Instance. See [Run setup](/reference/instance-setup#run-setup).

An identical `instance:create` for an active Instance returns it unchanged and runs no setup. An Instance can stay active with a failed setup: after `instance:setup` or `instance:register --setup` fails, or when Orbit could not confirm the failed step or finish the rollback. Then `instance:create` returns `instance.setup_step_failed` until `instance:setup` succeeds, unless the [database clone](#database-clone) did not finish: then `instance:create` finishes it and runs setup.

Orbit does not recover missing source profiles on older Instances. [Projects: One public name without compatibility](/reference/projects#one-public-name-without-compatibility) explains the no-legacy-support rule.

## Laravel application URL

For Project types other than `laravel-package`, Orbit treats a source as Laravel when it has a regular `artisan` file and a `composer.json` that declares `laravel/framework` exactly once, in `require` or `require-dev`. A source with a `composer.json` and neither marker is plain PHP. A source without `composer.json` has no PHP. A `laravel-package` Project is exempt from this application-marker validation: it needs no `artisan`, and a `laravel/framework` declaration alone is valid.

| Source | Code |
| --- | --- |
| For a non-package Project: `artisan` without the declaration, the declaration without `artisan`, the declaration in both sections, or a symlinked `artisan` | `app-dev.laravel_source_invalid` |
| `composer.json` is a symlink, is not a regular file, or is not owned by the Node's managed user; or `artisan` exists without `composer.json` | `app-dev.source_metadata_unsafe` |
| `composer.json` is invalid JSON, or no supported PHP version meets its constraint | `app-dev.php_version_unsupported` |

Detection reads files only. It runs no Composer, Artisan, or application code, and it installs no dependencies.

For a Laravel source, Orbit writes `APP_URL=https://<route-domain>`:

- When `.env` exists, Orbit replaces the one `APP_URL` line or adds it. Every other byte stays the same.
- When `.env` is missing, Orbit creates it from `.env.example`, or empty, and adds `APP_URL`.
- When `bootstrap/cache/config.php` exists, Orbit replaces its one cached `url` value.

A symlinked file, two `APP_URL` lines, or an unclear cached value stops provisioning with `app-dev.laravel_url_configuration_failed`. After activation, the [stored environment](/reference/environment-variables) owns `APP_URL`.

## Production Instances

`instance:create` refuses an `app-prod` Node with `instance.candidate_required`, including a repeat for a production Instance that already exists. A production Instance always starts as a [clone](/reference/instance-cloning) of a development or production Instance. Its first [deployment](/reference/deployments) creates its first release.

A Project can have one production Instance per `app-prod` Node. Each production Instance has its own Unix user, home, and [PHP-FPM service](/reference/php-runtime#production-runtime).

## Set the web root

An Instance inherits the Project root. `--root` stores a relative override for one Instance. The root is a relative path of at most 255 bytes. Each segment uses only `A-Z`, `a-z`, `0-9`, `.`, `_`, and `-`, and is not `.` or `..`. A leading or trailing `/` or an empty segment is refused. A package Project also accepts `.`, the repository root. On a production Instance, the root resolves inside the selected release, through `<home>/current`.

## Input boundary

`instance:create` and `instance:destroy` accept no repository, command, or shell input. The Project owns the repository. Registration accepts source facts only, and the Gateway verifies each one. It never accepts a Node: the caller is the Node.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Registration takes ownership

A registered source enters the same lifecycle as a created checkout: one managed path, one removal command, one set of safety checks. Registration that only observes an external worktree was rejected. It needed a separate unregister command, could not move or delete the source, and left Orbit serving paths it did not control. Adopting only linked worktrees was rejected too, because an independent checkout is equally useful source.

### The default Instance keeps its name

The default development source keeps the name `default`, its path, and its Route when the Project's default branch changes. An Instance named after its branch would move and get a new domain on every branch rename. A second public name for the default branch, such as `main_branch`, was rejected because two names for one setting make the contract unclear.

### An explicit branch stays explicit

Orbit records `--branch` even when it equals the default branch. Comparing values later cannot tell a deliberate choice from an inherited one. So when the Project default branch changes, Orbit switches only the development Instance named `default`, and only when it has no override. Using the Instance name as the only way to pick a branch was rejected, because a release branch would then need a new identity.

### Active does not mean healthy

An Instance is active once Orbit prepared its source, runtime, Route, and Laravel URL. A new application can lack dependencies, an application key, or a database, and it can return errors. You need the endpoint to finish that setup. So Orbit does not wait for a healthy response. A health gate, a separate activation command, and setup commands inferred from the framework were rejected.

### Orbit owns the Laravel URL

Laravel uses `APP_URL` to build links outside a request. When Orbit changes a domain and leaves `APP_URL` alone, links break. So Orbit derives `APP_URL` from the Route in development and production. Reading an existing `APP_URL` as the source of the domain was rejected: the Route decides the endpoint.

### Copy dependencies, not the checkout

A new Instance needs the slow part of the `default` Instance, its installed dependencies, and nothing that names the `default` Instance. A copy of the whole checkout would bring its `.env`, configuration cache, `public/hot`, logs, and absolute links. Each of those would need a rewrite before the copy is safe to run. The clone brings only tracked files, and the dependency directories hold relative paths, so nothing needs a rewrite. The setup steps run after the copy, so every Instance has one creation path, and the copy only makes the installs fast.

An opt-in flag was rejected: the copy is always correct and always faster. A ZFS dataset clone per Instance was rejected: it works only on ZFS, needs a dataset and permissions per Instance, and saves only seconds over a reflink.

A reflink of a SQLite file that the `default` Instance is writing could pair a database file and a WAL file from different moments. The write lock makes the pair consistent. Readers continue, and writers wait only while Orbit takes the reflink.

### One application model

The Instance is the only runnable application model. Orbit keeps no conversion tooling and no compatibility path for other models. Two models would double every lifecycle rule and every check.
