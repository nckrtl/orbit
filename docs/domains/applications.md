---
title: "Applications"
description: "How a Project becomes an Instance on a Node: create or adopt a development checkout, provision its endpoint, clone to production, move, and remove."
covers:
  - apps/gateway/app/Actions/Instances/{CreateInstanceAction,CopyInstanceDependenciesAction,CloneInstanceDatabaseAction,RegisterInstanceAction,RenameInstanceAction,ListInstancesAction,ShowInstanceAction}.php
  - apps/gateway/app/Domain/Instances/{DatabaseClone,DependencyCopy}/**
  - apps/gateway/app/Domain/Instances/{InstanceState,InstanceSourceLayout,InstanceDestinationGuard,ComposerSourceClassifier,Development*}.php
  - apps/gateway/app/Domain/Instances/Registration/**
  - apps/gateway/app/Infrastructure/Instances/{NativeDevelopmentInstanceProvisioner,RemoteDevelopmentInstanceSourceLifecycle,RemoteDevelopmentInstanceConfigurator,RemoteRegistrationSourceManager,RemoteInstanceDestinationGuard,RemoteInstanceSqliteCloner,RemoteInstanceDependencyCopier}.php
  - apps/gateway/app/{Http/Controllers/Api/InstancesController.php,Http/Requests/Instances/**,Data/Instances/**,Models/Instance.php}
  - apps/cli/app/Commands/Instances/{CreateInstanceCommand,RegisterInstanceCommand,RenameInstanceCommand,ListInstancesCommand,ShowInstanceCommand,InstanceOutput}.php
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
| Record a renamed branch or move its domain | `instance:rename` | [Record a renamed branch](#record-a-renamed-branch) |
| Move to another Node | `instance:transfer` | [Instance transfer](/reference/instance-transfer) |
| Remove | `instance:destroy` | [Instance removal](/reference/instance-removal) |

## Create a development Instance

Select an active Linux Node with an active `app-dev` role. The name `default` is reserved for the Project's default development source.

```bash
orbit instance:create <project> <node> default
orbit instance:create <project> <node> feature-one --branch=release --app-domains='{"web":"feature.example.test"}'
```

The Gateway clones the repository to `<apps-root>/<project-slug>/<name>`. It reads the repository with the Project's [source access](/reference/projects#source-access). A `gh_cli` Project needs no GitHub login on the Node. The [apps root](/reference/node-settings) comes from the Node settings. The name decides the path and the generated domain. The branch is a separate choice.

| Input | Selected branch |
| --- | --- |
| `default`, no `--branch` | The Project `default_branch`. |
| Another name, no `--branch` | The matching origin branch, otherwise the existing local branch, otherwise a new branch with that name from the fetched `default_branch` commit. |
| Any name, `--branch=<branch>` | The requested origin branch, otherwise the existing local branch, otherwise a new branch from the fetched `default_branch` commit. The branch need not equal the Instance name. |

For example, `instance:create <project> <node> t3-1a2b3c4d --branch=t3code/1a2b3c4d` creates that branch when it is missing. An origin branch keeps its existing selection behavior. An existing local branch is not reset to the default commit. A missing fetched `default_branch` when it is needed returns `instance.branch_resolution_failed`; Orbit selects no fallback branch. The implicit `default` selection must resolve the Project default branch. Invalid branch names return `validation.failed` before source changes.

The response returns `selected_branch` and `branch_override`. `branch_override` holds the `--branch` value, also when it equals the default branch. It is null when the Instance inherits its branch. The Instance also records the starting commit.

Creation moves through recorded states: `reserved`, `checkout_prepared`, `source_resolved`, and `active`. A failure before activation removes the new Instance and its owned checkout, Route and projections, runtime, staging paths, and database copies. Setup has not run, so cleanup runs no teardown and never cascades into another Instance. The response keeps the original failure code. Once cleanup completes, another create is a fresh request and can use a different branch.

A fresh reservation records a unique source preparation ID before remote work. Preparation refuses an existing destination, creates the directory exclusively, and records the ID with its device and inode in Git metadata after cloning.

Cleanup and retry require that receipt when a new reservation's directory exists. A lost successful preparation response can be recovered from the receipt. An interruption before the receipt is written retains the unconfirmed directory and reports incomplete cleanup; retry and forced removal neither adopt nor delete it. Legacy reserved rows without preparation evidence cannot adopt existing source on retry. Matching origin and account ownership alone do not prove that an attempt owns a checkout.

An interruption or incomplete cleanup can leave a pre-activation Instance. An identical retry resumes at the first unfinished state. The retry must name the same Project, Node, app override map, domain map and branch override. A retry that changes one of them returns `instance.placement_conflict`. When cleanup cannot finish, the original error includes `details.cleanup = "incomplete"`, the Instance identity, and a recovery command. [Pre-activation removal](/reference/instance-removal#pre-activation-removal) accepts these states without weakening the source ownership guards.

After activation, you can commit and move `HEAD`. The starting commit stays as it is. The recorded branch changes only through [recording a renamed branch](#record-a-renamed-branch), or when a Project default-branch update switches a `default` Instance without `branch_override`. Keep the recorded branch checked out. [Removal](/reference/instance-removal#checks-before-removal) refuses a checkout on another branch with `instance.source_branch_mismatch`, also with `--force`. [Cloning](/reference/instance-cloning#candidate-rules) refuses such a candidate with `instance.clone_candidate_branch_invalid`.


The Gateway refuses these requests before it changes anything:

| Code | Cause |
| --- | --- |
| `project.source_defaults_incomplete` | The Project has no valid default branch or app list. |
| `instance.node_inactive` | The Node is not an active Linux Node. |
| `instance.node_not_app_dev` | The Node has no active `app-dev` role. |
| `instance.node_excluded` | The Project [excludes](/reference/development-node-exclusions) the Node. |
| `instance.path_taken` | The name is not `default`, and another managed Instance uses the path. |
| `instance.default_path_occupied` | The name is `default`, and its path is used by a managed Instance or holds an unmanaged directory. |
| `instance.candidate_required` | The Node has the active `app-prod` role. A repeat for an existing production Instance is refused the same way. Use [`instance:clone`](/reference/instance-cloning). |
| `instance.placement_unavailable` | The owning Node does not have exactly one active `app-dev` or `app-prod` role. |

### Dependency copy

New Instances start from the successful development release of `default` on the same Node. Orbit creates a linked worktree at that release's commit. The Instances API exposes `seed_path` and `seed_commit` on `default`, so external callers can create their own linked worktrees at the same commit before registration.

The Project's [setup steps](/reference/instance-setup) own dependency copying. They receive `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` and can reflink all their dependency and cache folders, including nested monorepo projects. Copy only disposable folders that do not hold Instance-specific configuration, absolute links or runtime state. Do not copy `.env`, logs, databases, `public/hot` or configuration caches. A linked worktree shares Git history, never the seed's working files.

Use `cp -a --reflink=auto` for an ordinary-copy fallback on filesystems without block cloning, or `--reflink=always` when a Project requires shared blocks. When no release exists, Orbit creates an independent clone; the seed variables are empty and setup installs from lock files. Project setup steps replace the automatic copy of root `vendor` and `node_modules`.

### Database clone

When the Project has an Instance named `default` with a database attached under prefix `DB`, every other new development Instance gets its own copy of that database. The copy has the same kind as the source. Orbit makes it after the source is ready and before the setup steps, so a setup step such as a migration runs against the copy.

| Source | Copy |
| --- | --- |
| MySQL on a [Database server](/reference/database-servers) | A database named `<project>_<instance>` on the same server, owned by the Instance's user and filled with `mysqldump --single-transaction` piped into `mysql` inside the server's container, plus the test database `<project>_<instance>_test`. |
| SQLite file inside the `default` checkout | A copy at the same relative path in the new checkout, also on another Node. Tests use `:memory:`. |

On the same Node, Orbit holds SQLite's write lock while it takes a reflink of the SQLite database file and its `-wal` file. It first checks that the filesystem can clone the file, so no writer waits when it cannot. Orbit copies a snapshot made with SQLite's backup API instead in three cases: the filesystem has no block cloning, a writer holds the lock for two seconds, or the source is on another Node.

Orbit records the copy as a [database the Instance owns](/reference/database-connections#owned-databases) with the connection slug `<project>-<instance>`, attaches it under prefix `DB`, and synchronizes `.env` and `.env.testing`.

When the Instance has no stored configuration yet and its [application directory](/reference/projects#application-directory) has a `.env`, Orbit first [imports](/reference/environment-variables#import) that file, so synchronization keeps its other keys. Without a `.env`, synchronization writes only the stored keys.

The clone returns these codes.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.database_clone_unsupported` | 422 | The source is not MySQL on a Database server or a SQLite file inside the `default` checkout. Nothing changes. |
| `instance.database_clone_failed` | 502 | The copy failed. Orbit drops the partial copy and removes the Instance as a failed setup does. |

No teardown step runs after a failed copy, because no setup step ran yet. When the removal cannot finish, the error has `cleanup: incomplete` and names the `instance:destroy` command that finishes it.

Orbit records each finished step of the copy on its connection. When a create stops before the copy finished, an identical `instance:create` finishes the copy and then runs the setup steps. It copies the data again unless the earlier copy finished, so it never keeps a partial copy.

The copy holds the full data of the `default` Instance, including personal data. A Project without a `default` Instance, or whose `default` Instance has no `DB` attachment, gets no copy.

## Record a renamed branch

Rename the Git branch in the checkout first, then tell Orbit what is already checked out:

```bash
git branch -m t3code/login-redirect
orbit instance:rename <instance> --branch=t3code/login-redirect
orbit instance:rename <instance> --branch=t3code/login-redirect --app=web --domain=login-redirect.orbit-website.test
```

The API is `POST /api/v1/instances/{instance}/rename` with optional `branch`, `domain` and app selector `app`; branch or domain is required. MCP `instance-rename` takes `instance_id` and those same fields. App is prohibited without a domain; domain changes require an app selector unless the Project has one app. All forms return the same Instance representation as `instance:show`. Only an active development checkout is eligible, not a linked worktree or production Instance. The Instance ID, name, path, layout, starting commit, and placement stay the same.

For a supplied branch, Orbit reads the checkout locally on its Node. Symbolic `HEAD` must already name that exact branch, or the request returns `instance.branch_not_checked_out` without changing anything. Orbit checks source identity but never contacts origin, changes Git refs, switches the checkout, or renames the branch itself. Dirty or unpublished source is allowed for this recording operation.

A changed branch becomes both `selected_branch` and `branch_override`. This pins even a `default` Instance to the explicit selection. Supplying the already recorded branch is a no-op that preserves the existing override. Domain-only rename leaves both branch fields alone. Removal, cloning, and Doctor then check the newly recorded branch; normal removal still requires clean, published source.

A supplied domain moves only the selected app's authoritative single-target Project Route through [Route replacement](/reference/routes#change-an-instance-route-domain), including a generated Route. Laravel's stored `APP_URL`, `.env`, and cached config follow the new URL. All supplied fields and the domain's availability are checked before mutation. A combined request with a wrong branch or occupied domain changes neither record. The branch record changes only after any requested domain convergence succeeds.

An identical retry after a completed rename returns the Instance without creating an additional Route, including when the caller lost the success response. Incomplete Route replacements follow [Route recovery](/reference/routes#resume-or-refuse-a-change). If a full rollback before cutover deleted the failed replacement, an identical retry can reserve a fresh replacement; the changed branch is still recorded only after domain convergence succeeds.

Another lifecycle owner returns `instance.lifecycle_busy`, including contention from the environment owner. A different app, domain or branch presence/value during replacement recovery returns `route.domain_change_conflict`, as defined by the [rename retry identity](/reference/routes#change-an-instance-route-domain). This includes requesting the original domain before or after cutover: the retained replacement owns its destination, so the original domain is not a no-op. That refusal changes neither the recorded branch nor stored or application environment values.

Infrastructure failure can leave Route recovery work before or after cutover, so inspect the Route and repeat the same request. [instance:rename](/cli/instance#orbit-instancerename) lists the inputs, output, and refusal codes. [Development branch reconciliation](#development-branch-reconciliation) explains the reasons and alternatives.

## Register an existing checkout

Run registration on the `app-dev` Node that holds the source. The Node that sends the request is the Node that receives the Instance.

```bash
cd ~/src/acme
orbit instance:register
orbit instance:register --path=/srv/src/acme --include-worktrees --yes --json
```

Registration transfers ownership of the source to Orbit. Orbit moves the source into its managed path, and `instance:destroy` later deletes it. There is no unregister command.

The CLI reads the stored `remote.origin.url` of the checkout. It sends no request for a directory outside Git or for an unsafe origin. A safe origin is `https://` without a user or password, `ssh://` without a password, or `git@host:path`. Any other scheme, a query, a fragment, whitespace, or a control character is unsafe.

The Gateway finds the Project by [repository identity](/reference/projects#repository-identity), or uses `--project`. Registration never creates a Project or infers a nested web root. It inherits the Project app list unless `--app-overrides=JSON` supplies a path/web-root map. `--app-domains=JSON` supplies explicit domains by app name; omitted serving names generate domains. Registration and creation use the same map validation and retry identity. When no Project owns the repository, the Gateway returns `instance.project_missing` and changes nothing. Create the Project with [`project:create`](/cli/project#orbit-projectcreate) first.

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

Before source preparation, the Gateway assigns every app a [Vite port](/reference/assigned-vite-ports) and reserves a Route for every [serving app](/reference/projects#project-types). Null web root can serve the app directory itself; only a package with path `.` and null web root is non-serving. `--app-domains=JSON` supplies explicit domains by app name; omitted names generate domains from the Cluster or Node TLD. Non-serving packages and task workspaces whose recorded mode is unrouted get no serving Routes. [Routes](/reference/routes#select-a-domain-and-scope) owns the map and generated-name rules.

After the source is ready, the Gateway continues in this order:

1. It inspects each serving app's source and records its [PHP version](/reference/php-runtime#select-the-php-version) and Laravel flag in its own source profile.
2. For each Laravel app with a Route, it sets that app's `APP_URL` to its own Route URL. See [Laravel application URL](#laravel-application-url).
3. It publishes each app's Route, certificates, Caddy site, firewall rules and private DNS records.
4. It marks the Instance active only after every required app Route is active.
5. It runs the Project [setup steps](/reference/instance-setup).

An app without a Route skips URL and serving preparation; an unrouted task workspace also skips source classification. Orbit never scans sibling directories to choose a monorepo application. An identical retry resumes each app's unfinished step. After source classification, retry re-inspects that same app: changed PHP version or Laravel evidence returns `app-dev.source_evidence_changed`. [Instance runtime output](/reference/projects#instance-app-runtime-output) fixes the app-keyed profile and endpoint representation.

A failed setup step during `instance:create` runs the teardown steps and removes the new Instance. See [Run setup](/reference/instance-setup#run-setup).

An identical `instance:create` for an active Instance returns it unchanged and runs no setup. An Instance can stay active with a failed setup: after `instance:setup` or `instance:register --setup` fails, or when Orbit could not confirm the failed step or finish the rollback. Then `instance:create` returns `instance.setup_step_failed` until `instance:setup` succeeds, unless the [database clone](#database-clone) did not finish: then `instance:create` finishes it and runs setup.

Orbit does not recover missing source profiles on older Instances. [Projects: One public name without compatibility](/reference/projects#one-public-name-without-compatibility) explains the no-legacy-support rule.

## Laravel application URL

For a routed source, Orbit inspects the [application directory](/reference/projects#application-directory) selected by the app's effective path. A nested Laravel app uses its own `composer.json` and `artisan`, not repository-root files. Registration does not discover apps for you.

For app types other than `laravel-package`, Orbit treats a source as Laravel when it has a regular `artisan` file and a `composer.json` that declares `laravel/framework` exactly once, in `require` or `require-dev`. A source with a `composer.json` and neither marker is plain PHP. A source without `composer.json` has no PHP. A `laravel-package` app is exempt from this application-marker validation: it needs no `artisan`, and a `laravel/framework` declaration alone is valid.

| Source | Code |
| --- | --- |
| For a non-package app: `artisan` without the declaration, the declaration without `artisan`, the declaration in both sections, or a symlinked `artisan` | `app-dev.laravel_source_invalid` |
| `composer.json` is a symlink, is not a regular file, or is not owned by the Node's managed user; or `artisan` exists without `composer.json` | `app-dev.source_metadata_unsafe` |
| `composer.json` is invalid JSON, or no supported PHP version meets its constraint | `app-dev.php_version_unsupported` |

Detection reads files only. It runs no Composer, Artisan, or application code, and it installs no dependencies.

### Symfony applications

A `symfony-app` Project checks `bin/console` instead of `artisan`. Its source is valid when it has a regular `bin/console` file and a `composer.json` that declares `symfony/framework-bundle` in `require`. A `composer.json` with neither marker is plain PHP. `bin/console` without the declaration, the declaration without `bin/console`, or a symlinked `bin/console` returns `app-dev.symfony_source_invalid`. `bin/console` without `composer.json` returns `app-dev.source_metadata_unsafe`.

A Symfony source is never Laravel. Orbit does not write `APP_URL` for it and does not force `APP_URL` in its stored environment. Set the values the application needs with [`env:update`](/reference/environment-variables).

For a Laravel source, Orbit writes `APP_URL=https://<route-domain>`:

- When `.env` exists, Orbit replaces the one `APP_URL` line or adds it. Every other byte stays the same.
- When `.env` is missing, Orbit creates it from `.env.example`, or empty, and adds `APP_URL`.
- When `bootstrap/cache/config.php` exists, Orbit replaces its one cached `url` value.

A symlinked file, two `APP_URL` lines, or an unclear cached value stops provisioning with `app-dev.laravel_url_configuration_failed`. After activation, the [stored environment](/reference/environment-variables) owns `APP_URL`.

## Production Instances

`instance:create` refuses an `app-prod` Node with `instance.candidate_required`, including a repeat for a production Instance that already exists. A production Instance always starts as a [clone](/reference/instance-cloning) of a development or production Instance. Its first [deployment](/reference/deployments) creates its first release.

A Project can have one production Instance per `app-prod` Node. Each production Instance has its own Unix user, home, and [PHP-FPM service](/reference/php-runtime#production-runtime).

## Set the web root

An Instance inherits each Project app's path and web root. Configure the list with `project:update --apps=JSON`, or set the development Instance's map with `instance:update --app-overrides=JSON`. [Projects](/reference/projects#application-directory) owns path validation and [override update recovery](/reference/projects#instance-override-update-lifecycle). Serving web roots are relative to app paths; production uses its sole app inside the selected release. The removed `root` field has no supported flag or alias.

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

### Development branch reconciliation

An agent can start on a temporary branch such as `t3code/1a2b3c4d`, rename it locally after its first message, then record `t3code/login-redirect` and a readable URL. Branch names are independent of Instance identity: they can contain `/`, but Instance names decide managed paths and generated domains. Requiring branch and Instance names to match was rejected. Selecting a different existing branch as a fallback was rejected too, because it silently gives the agent the wrong source. Orbit creates only a genuinely missing requested branch from the fetched default commit.

Branch recording is explicit and checks the checkout without changing it. Inferring a rename in Doctor or removal would mutate intent and hide a checkout switch. Running `git branch -m` inside Orbit would repeat the caller's operation and introduce races. Renaming the Instance or moving its directory would disrupt agents and Processes without helping branch reconciliation. Force still cannot waive a resolved source's branch identity check: it permits discarding dirty or unpublished work, not deleting a checkout with a different identity. Recording an unpublished branch does not make its commits published for normal removal.

Failed creation should release its name, path and domain, not leave every attempt for manual deletion. Cleanup therefore removes confirmed attempt-owned resources while retaining recovery state when it cannot finish. Origin and account ownership are insufficient evidence: an unregistered same-origin checkout may contain another caller's work. The preparation receipt binds a reservation to the directory it created, so retries and force cannot claim an existing or replacement checkout. An interruption before that receipt leaves source intact rather than guessing ownership.

An Instance-owned domain change uses the existing Route replacement path rather than another Route endpoint or an in-place domain edit. That path already owns Caddy, certificates, DNS, Laravel URL synchronization and retries. A generated Route keeps its provenance and generation basis; converting it to explicit provenance would erase how it originated and change later slug and TLD recomputation. Its supplied readable domain can therefore change again when those inputs change.

Infrastructure cannot share a database transaction with source records, so Orbit validates first and records a changed branch only after requested domain convergence succeeds. Promising atomic rollback after cutover was rejected: recovery before cutover restores the old domain, while recovery after cutover completes the change.

### Active does not mean healthy

An Instance is active once Orbit prepared its source, runtime, Route, and Laravel URL. A new application can lack dependencies, an application key, or a database, and it can return errors. You need the endpoint to finish that setup. So Orbit does not wait for a healthy response. A health gate, a separate activation command, and setup commands inferred from the framework were rejected.

### Orbit owns the Laravel URL

Laravel uses `APP_URL` to build links outside a request. When Orbit changes a domain and leaves `APP_URL` alone, links break. So Orbit derives `APP_URL` from the Route in development and production. Reading an existing `APP_URL` as the source of the domain was rejected: the Route decides the endpoint.

### Copy dependencies, not the checkout

A Project knows its own dependency and cache folders. Copying only the root missed the dependency trees of nested projects. Project setup steps can copy the matching folders from a known release and then install incrementally. Copying a whole checkout is rejected because it brings Instance-specific configuration and runtime state. A ZFS dataset clone per Instance is rejected because it needs per-Instance datasets and privileges; ordinary reflinks work within the existing storage layout.

### One application model

The Instance is the only runnable application model. Orbit keeps no conversion tooling and no compatibility path for other models. Two models would double every lifecycle rule and every check.
