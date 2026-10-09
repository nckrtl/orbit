---
title: "Instance releases"
description: "How development defaults and production Instances build releases, run deploy steps, and select current code."
covers:
  - apps/gateway/app/Domain/{Instances/Deployment/**,Instances/ProductionWebRootManager.php,Projects/*DeployStep*.php}
  - apps/gateway/app/Actions/Instances/{DeployInstanceAction,DeployDefaultInstanceAction,RollbackInstanceAction,InstanceDeploymentConfigResolver,UpdateInstanceAction,ListInstanceReleasesAction,ListInstanceDeploymentsAction,*InstanceDeployStep*Action}.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteProductionDeployment,RemoteDevelopmentDeployment,DevelopmentReleaseProgram,ProductionApplicationPaths,ProductionWebRootProgram,RemoteProductionWebRootManager}.php
  - apps/gateway/app/Console/Commands/DeployDevelopmentDefaultsCommand.php
  - apps/gateway/app/Http/Streaming/**
  - apps/gateway/app/Http/{Controllers/Api/{InstanceDeploymentsController,InstanceDeployStepsController,InstanceReleasesController,InstanceRollbacksController,ProjectDevelopmentDeployStepsController},Requests/Projects/*ProjectDevelopmentDeployStepRequest}.php
  - apps/gateway/app/Models/{InstanceDeployment,InstanceDeployStep,ProjectDevelopmentDeployStep}.php
  - packages/php-sdk/src/{Requests,Responses}/Projects/*DevelopmentDeployStep*.php
---

# Instance releases

An Instance on an `app-prod` Node serves code from a release. The Gateway deploys the Instance's branch into a fresh release, runs the Instance's deploy steps, and switches the `current` link atomically. Environment configuration and an optional SQLite database live outside the releases, so they survive each deployment. [Cloning](/reference/instance-cloning) creates every production Instance, and its first deployment selects the first release. [`instance`](/cli/instance#orbit-instancedeploy) lists the commands.

The `app-prod` role decides this layout. `APP_ENV` does not; see [Laravel mode](/reference/environment-variables#laravel-mode).

## The production home

Each production Instance has a home, `/home/<production-user>`, with these paths.

| Path | Purpose |
| --- | --- |
| `releases/<name>/` | One retained release: a Git checkout of the deployed branch. |
| `.env` | The durable environment file. Each release's application directory holds a `.env` link to it. |
| `env/<directory>/.env` | The durable environment file of another application directory that a [Route with a web root](/reference/routes#web-roots-on-production) serves. Each release links that directory's `.env` to it. |
| `database.sqlite` | An optional SQLite database. Orbit keeps the path but does not create the file. |
| `current` | A link to the selected release. It is absent until the first deployment. |

A clone leaves the home prepared, with no `current` link. The application must point its SQLite configuration at `<home>/database.sqlite` itself. Orbit has no placeholder for that path. [Cloning](/reference/instance-cloning) describes how to seed the file.

The Gateway lists retained releases as the production user from a directory that user can access. A private SSH home does not prevent reading the first clone's empty selection. Failed scans and invalid release receipts still stop the operation.

The web root is the Instance root, or else the Project root, inside `current`. A root such as `public` serves `<home>/current/public`. A nested root such as `apps/site/public` serves `<home>/current/apps/site/public`; its [application directory](/reference/projects#application-directory) is `<home>/current/apps/site`. The release's `apps/site/.env` links to `<home>/.env`, with a relative target calculated from that depth (in this example, `../../../../.env`). Root `public` keeps the release-root `.env` link with target `../../.env`. Caddy resolves the `current` link before it passes a script path to PHP-FPM, so a request after a switch loads its PHP files from the new release.

With root `server/web/public`, both the initial clone and later releases link `server/web/.env` with target `../../../../.env`. Orbit does not create a second link at the release root. Release selection, retained-release listing, rollback validation, and [Doctor](/cli/doctor) check the link in that same application directory. Source classification reads that directory's `composer.json` and `artisan`, while ownership and Git identity checks still cover the whole release.

A [Route with a web root](/reference/routes#web-roots-on-production) adds a web root in `current`, such as `apps/docs/public`. Source preparation links the release's `apps/docs/.env` to `<home>/env/apps/docs/.env`, with target `../../../../env/apps/docs/.env`, before the deploy steps run. Activation, for a deployment and for a rollback, checks that each such web root exists without a link, adds a missing `.env` link, and grants Caddy access before it switches `current`. Only active Routes count. An Instance without such a Route sends the same commands as before, byte for byte.

A new release without such a directory fails at source preparation. Like any other preparation failure, it keeps the earlier selection and leaves the release directory. A rollback to an older release without the directory fails at activation and keeps the earlier selection.

## Development defaults

A `default` Instance on an `app-dev` Node is a development release seed. Other development Instances remain ordinary working checkouts. `orbit instance:deploy ID`, the existing deployment API (`POST /api/v1/instances/{instance}/deploy` with `{}`), and MCP's Instance deploy operation can deploy a default by hand. The Project's default branch supplies the target commit; production branch overrides do not apply.

The Gateway checks development defaults every minute with `orbit:deploy-development-defaults`. The GitHub App currently has no push webhook, so this schedule is the push fallback. It fetches the default branch inside the Instance operation lock and skips an unchanged commit. Pushes that arrive during a deployment are picked up on the next tick; intermediate commits coalesce to the newest fetched commit. Only one deployment runs per Instance, including manual requests.

An unchanged tick creates no deployment history row. Failed deployments are recorded and retried on a later tick.

A deployment records that the default's route projection is pending before it converges the route, and clears that mark when the converge succeeds. A layout migration also marks the projection pending. A tick reconverges a visitable default's `current` route projection only while the mark is set, so a crash or a failed converge is repaired on the next tick. An unchanged tick with a completed projection does not take the [projection lock](/reference/routes#coordinate-publication), because a full converge rebuilds the Node's PHP-FPM pools, Caddy, and DNS and blocks every other Route operation while it runs.

The existing checkout directory is the release home. Its `.git` repository stays at the same path. `releases/<name>` holds detached linked worktrees, and `current` selects the live one. The first deployment migrates a plain checkout by reflinking its files into an initial release before selecting that release. The original repository and checkout files stay in place: existing task bridges and registered `t3code-<hex>` worktrees keep their Git links. Do not move or delete this repository.

Orbit writes ownership receipts before creating layout directories, registering releases, or staging `current`. A retry can complete those interrupted operations after validating the receipts and Git registration. It refuses foreign or ambiguous paths instead of adopting or deleting them.

A visitable default serves its Project or Instance web root through `current` after migration; a default without a Route is still deployed. Before switching a visitable default, Orbit validates the candidate's web root and grants Caddy access, so a request does not wait for permissions to catch up with the new link. The shared Git directory stays private even when it is outside the selected worktree; its access changes use the same recovery snapshot.

A candidate starts at the current release's commit. Orbit reflinks its files with `cp --reflink=always`, excluding worktree metadata, then resets tracked code to the newest default-branch commit. This carries dependency and cache folders at any depth without framework-specific folder names. Internal absolute symlinks become relative links in the copy; links outside the release are refused. Releases must share a filesystem that supports reflinks; Orbit refuses a copy fallback. No candidate writes into the live release. Project development deploy steps run in list order in the candidate directory.

Every required step must pass before Orbit atomically renames the new `current` symlink into place. A failed required step keeps the last good selection and is reported. Each best-effort step runs with a reflink snapshot of the candidate. Snapshotting rebases internal absolute symlinks, including links created by earlier required steps. On failure, Orbit restores that snapshot before continuing, so partially written caches do not replace the previous caches. The new release still switches after all required steps pass.

Orbit reports the failed step's exit status and names it in a warning. The output and stored events keep that warning even when the overall deployment succeeds or command output reached its storage limit.

Before it builds a candidate and after a deployment, Orbit prunes managed releases to at most 3 per home. It keeps `current` and every release that another Instance on that Node records as its seed. It keeps the previous selection only while those leave room under the limit, and removes every other release. A read-only folder in a release, such as a cache, does not stop its removal.

The seed fields on an Instance are a durable lease: asynchronous setup and interrupted retries can still read that immutable release after later deployments. The lease lasts until the consuming Instance is removed. Pruning never removes a leased release, so leases alone can keep more than 3; the Gateway then logs a warning with the count. Pruning reads these leases under the same Node source lock used for seed selection and validates every retained release marker.

An interrupted `git worktree add` or `git worktree remove` can leave a broken release folder. Either its `.git` points to its missing administrative directory under the stable repository's `.git/worktrees/`, or it has no `.git` at all. A folder without `.git` counts as broken only when Git has no registration for it, or a registration that names exactly that folder. Release listing skips a broken release, and the Gateway logs a warning naming it.

Pruning removes a broken release that is not `current`, the previous selection, or a leased seed, when its ownership receipt proves that Orbit created it. It also removes a registration left behind. A broken folder without a receipt stays for operator inspection, with the same warning, and healthy releases continue through listing and deployment. Initialization removes a folder without `.git` whose creation intent it recovers. Listing still requires a valid `current`. Pruning also requires a valid previous selection and every leased seed before inspecting unused releases. Invalid ownership receipts, symlinks, and foreign or ambiguous Git metadata still fail validation.

A failed candidate is removed without changing `current`. Cleanup never prunes the stable repository or other linked worktrees.

Environment files and caches in development releases are copies, not links back into another Instance. When Orbit builds a candidate, it copies `.env` and any `.env.testing` from the default's stable home into the same relative directory of the candidate. It does this at the checkout root, in the application directory, and in each directory that a [Route with a web root](/reference/routes#serve-several-web-roots) serves. So explicit environment synchronization and a Route's `APP_URL` reach the next deployment without a write into the live seed. Production environment and rollback rules below remain separate.

Orbit skips a directory that is missing from the stable home or from the new commit. A stable directory that leaves the checkout through a link fails the preparation. The stable home keeps the commit of its migration. Orbit writes a Route's `APP_URL` only into a stable directory that holds `artisan`, so an application that a later commit adds gets no `APP_URL` from Orbit.

## Deploy steps

All development and production deploy steps run at the release's repository root, not at a nested application's directory. For root `apps/site/public`, an Artisan step must say `cd apps/site && php artisan migrate --force`. Changing the web root does not change the scope of a repository-owned command.

A deploy step is a named command that runs during a deployment. Each production Instance stores its own steps. A Project stores a separate ordered development deploy list, shared by its development deployments and independent of setup and teardown. Storing a step does not start a deployment.

### Development deploy steps

Use `project:dev-deploy-step:list`, `project:dev-deploy-step:create`, `project:dev-deploy-step:update`, and `project:dev-deploy-step:destroy`, with `--project=ID`. The API collection is `/api/v1/projects/{project}/dev-deploy-steps`; GET lists, POST creates, and PATCH or DELETE on `/{name}` changes or removes one step. MCP exposes the same operations.

Each step has `name`, `command`, `timeout_seconds`, and `required`. Names use lowercase letters, digits, and hyphens, with a letter or digit at each end, and are at most 63 characters. Commands are nonempty UTF-8 strings of at most 16 KiB without NUL bytes. Timeouts are 1–900 seconds, default 300. `required` is a JSON boolean, default `true`; the CLI accepts `--required=true` or `--required=false`. PATCH accepts any of command, timeout, required, or placement; omitted fields stay unchanged.

A new step appends unless `before` or `after` names another step in this Project's development deploy list. The fields are exclusive. Updates without placement keep their position. Names cannot be renamed. Each list permits at most 32 steps and 3,600 seconds of total timeout. Unknown names, duplicate names, invalid fields, unknown placement, self-placement, and limit violations are refused without changing the list. Node access follows the Project's owning Node. Activity records never include commands.

Required steps must pass before a development deployment switches `current`. A failed best-effort step (`required: false`) is reported but does not prevent the switch; the release retains caches reflinked from the previous release. Use required steps for installs, builds, and migrations, and best-effort steps for cache warm-up.

### Production deploy steps

Production steps belong to an Instance and run before or after activation.

| Field | Contract |
| --- | --- |
| `name` | Unique within the Instance. 1 through 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. |
| `phase` | `before_activation` (the default) or `after_activation`. |
| `command` | Non-empty UTF-8 of at most 16 KiB, without a NUL byte. |
| `timeout_seconds` | 1 through 900. The default is 300. |

A new step goes to the end of its phase. `before` or `after` places it next to another step of the same phase. The two fields are exclusive. An Instance has at most 32 steps, and their timeouts add up to at most 3,600 seconds. The Gateway refuses a change that breaks a rule and stores nothing.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deploy-steps` | The steps in phase and placement order. |
| `POST /api/v1/instances/{instance}/deploy-steps` | Creates one step. Requires `name` and `command`. Accepts `phase`, `timeout_seconds`, and `before` or `after`. |
| `PATCH /api/v1/instances/{instance}/deploy-steps/{name}` | Changes `command`, `phase`, `timeout_seconds`, or the placement. |
| `DELETE /api/v1/instances/{instance}/deploy-steps/{name}` | Removes the step. The others keep their order. |

The branch lives on the Instance. `PATCH /api/v1/instances/{instance}` with `branch` sets it. Without one, a deployment uses the Instance's selected branch. A Project default change does not change it.

Deploy-step and branch changes need an access grant to the Instance's Node. The Gateway refuses them for an Instance that is not a production Instance with `deployment_config.unavailable` (409). Activity and errors never contain a step command.

## Use the deployment API

A deployment and a rollback run synchronously and stream their progress.

| Request | Body | Result |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | `{}` | Deploys the Instance's branch. |
| `POST /api/v1/instances/{instance}/rollback` | `{"release": "<name>"}` | Selects one retained release. |
| `GET /api/v1/instances/{instance}/releases` | none | `releases`, the retained release names, and `selected_release`, which can be null. |

The Gateway validates the request and the access grant before it opens the stream. A refusal there uses the normal JSON error envelope. After that, the response is `application/x-ndjson`. Each line is one event of at most 32 KiB with `type`, an increasing `sequence`, and the `request_id`.

| Event type | Fields |
| --- | --- |
| `phase` | `phase` is `source_preparation`, `environment_sync`, `before_activation`, `activation`, `php_refresh`, `after_activation`, or `rollback`. `step_name` names a deploy step. |
| `output` | `stream` is `stdout` or `stderr`. `data_base64` holds at most 16 KiB of output. |
| `result` | `status` is `succeeded` or `failed`, with `failed_step`, `error_code`, and `selected_release`, each nullable. This is always the last line. |

`failed_step` names the boundary that failed: `preparation`, `environment`, `before_activation`, `activation`, `cache_refresh`, `after_activation`, `rollback_selection`, or `operation`. An error after the stream opens, such as a non-production Instance, ends the stream with a failed `result` and no second HTTP error.

The Gateway flushes output while a command runs. Before each phase event, it writes one whitespace byte every 10 milliseconds for 250 milliseconds. That lets it notice a closed connection through Caddy and PHP-FPM. It notices a disconnect only when it writes, so a silent command can run until it writes, exits, or times out. Then the Gateway starts no further step and sends no result. It never replays events or rolls back by itself.

## Deploy

A deployment reads the branch and the steps once, when it starts. Then it runs these phases in order.

1. **Source preparation.** The Gateway clones the repository into a new release as the production user and checks out the latest commit of the branch. A branch that moves later does not change this release.
2. **Environment sync.** The Gateway writes the stored configuration into `<home>/.env`, as [synchronization](/reference/environment-variables#synchronize) does. This needs exactly one Route on the Instance. An Instance without a Route fails here with `env.owner_unavailable`.
3. **Before activation.** The Gateway runs each `before_activation` step in order, from the new release, as the production user, with a non-interactive shell.
4. **Activation.** The Gateway replaces `current` atomically with a link to the new release.
5. **PHP refresh.** The Gateway reconciles and confirms the dedicated FPM runtime, then resets that service's OPcache and waits for completion.
6. **After activation.** The Gateway runs each `after_activation` step in order.
7. **Cleanup.** The Gateway removes old releases, as [Retained releases](#retained-releases) describes. A failed cleanup is a warning in the output, not a failed deployment.

Orbit skips this phase for an Instance that does not serve PHP. A changed resolved release restarts the dedicated service so its workers enter the new application directory.

Orbit runs no migration, Laravel cache clear, dependency install, asset build, or application health check of its own. With no steps, a deployment runs no application command. The `current` switch is atomic, but the dedicated FPM restart is not zero-downtime: requests can fail briefly while its socket and workers are unavailable, and in-flight requests can be interrupted. Other Instances keep their own PHP services. A confirmed no-op reconciliation does not restart FPM.

Each step has its own timeout. A timeout stops the step's process group and every later step. The whole deployment has a deadline of the step timeouts plus 900 seconds. It never exceeds the request's 570-second command deadline. When a deadline stops the run, the error code is `deployment.deadline_exceeded`.

## Failures

The failed boundary decides which release stays selected.

| Failed boundary | Selected release |
| --- | --- |
| Source preparation, environment sync, or a `before_activation` step | The earlier release, or none before the first deployment. |
| Activation | The release that `current` selects when the Gateway reads it back from the Node. When that read fails, the release selected before the deployment. |
| PHP refresh or an `after_activation` step | The new release. |

Orbit never undoes the effects of a step, of the environment sync, or of data changes. You decide how to recover.

## Roll back

A rollback selects one retained release through `current`. The Gateway checks that the release is inside `releases/` and that the web root stays inside it. It then switches `current`, reconciles the dedicated FPM runtime, and resets its cache, as a deployment does. A changed resolved release restarts that service with the same brief serving interruption. A rollback fetches nothing, writes no environment, runs no step, and changes no database file.

## History

The Gateway records one row for each deployment and each rollback. It writes the row when the run starts and completes it when the run ends. It keeps the last 50 rows for each Instance.

Each row holds `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by` (the calling Node's name, or `schedule` for an automatic development deployment), and `events`. `events` holds the phase and output events of the run, with at most 128 KiB of output.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployments` | The Instance's rows, newest first, without `events`. |
| `GET /api/v1/deployments/{deployment}` | One row with `events`. |

## One operation at a time

Deployment, rollback, deploy-step changes, and branch changes share the Instance's operation lock with environment operations, Instance removal, and Route changes. A competing request waits or receives a busy error. An interrupted deployment releases the lock after its running command stops.

## Inspect release placement with Doctor

[Doctor](/cli/doctor) checks each production Instance against its home. It reports a missing or wrongly owned home, a broken `current` link, a selected release outside `releases/`, and a web root that leaves the release. It checks the `.env` link of the default application directory only. A home without `current` is healthy before the first deployment. Doctor accepts an older release after a rollback, and a release whose branch has moved on.

## Retained releases

A successful production deployment keeps at most 3 releases in the home: the new selection, the selection before it, and the newest other release. `initial` counts as the oldest, and later releases sort by the creation time in their names. It removes the rest, and the output names each one. So [`instance:rollback`](/cli/instance#orbit-instancerollback) can return to the previous selection and one other release. A rollback removes nothing. A failed deployment removes nothing either; the next successful one does.

A deployment does not [restart Processes](/cli/process#orbit-processrestart), so a running Process, such as a queue worker, can still work in an older release. Cleanup keeps every release that is the working directory, root, or executable of a running process of the production user, also beyond the limit. The output names each such release; restart its Process to free it. When cleanup cannot read where a live process works, it removes nothing and reports a failed cleanup.

Cleanup removes only folders that pass the release checks of the listing. It first renames a release to `releases/.pruned-<name>`, so the release leaves the list at once, and then deletes that folder. The release's own files go with it, such as old logs in its `storage/`. A folder that a stopped cleanup left is deleted by the next one. A partial folder that fails the release checks stays.

[Instance removal](/reference/instance-removal) removes `current` and the serving setup, and keeps `releases/`, `.env`, `env/`, `database.sqlite`, and the local PHP-FPM tuning for recovery.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A default keeps one stable Git repository

Moving a plain checkout's repository into a release would break the administrative paths of its existing linked worktrees. Development defaults therefore leave `.git` in place and register detached release worktrees against it. A layout marker binds the release home to the Instance and repository. Each managed release has its own marker; pruning refuses an unmarked worktree even when it shares the same repository.

Reflinking the release tree carries dependencies and caches at any depth, without a framework-specific folder catalog. A clone followed by fresh installs is a rejected alternative because it discards the seed's useful state. Falling back to byte copies is also rejected: it hides a filesystem prerequisite and turns a quick incremental deployment into a full dependency copy.

### Best-effort steps cannot publish partial caches

Orbit does not know which files a Project's cache command writes. It snapshots the candidate before each best-effort step and restores it on failure. Ignoring only the exit code is a rejected alternative because a command can fail after corrupting a cache. A separate remote receipt confirms restoration, even when command output was truncated. Failure to restore is an infrastructure failure and blocks activation.

### Orbit owns releases, you own the steps

Release preparation, the switch, and the PHP-FPM refresh are the same for every application, so Orbit does them. The commands before and after the switch differ for each application, so each Instance stores its own. Orbit does not guess steps from the framework.

### Deploy a branch, not a commit

The operation is "deploy what the branch holds now". So a deployment takes no commit. Two deployments of one branch can produce different code.

### Three releases per home

Each release holds a full checkout with its dependencies, so the count decides the disk use. Three keep the current code, the way back, and one more step back. The previous selection outranks newer releases that never went live, because it is the release a rollback needs first. For development, a leased seed outranks everything else, because another Instance still reads it; the limit gives way and the Gateway warns. For production, a release that a running process uses outranks the limit for the same reason: deleting it would pull the code, the dependencies, and the configuration cache out from under that process.

When pruning fails closed on a broken development release, every later deployment of that home fails too. So Orbit removes a broken release when its ownership receipt proves that Orbit created it. Without that proof, a folder in `releases/` may be someone's work, so Orbit skips it and warns.

### Rollback selects code only

Older code can need a data recovery that only the application knows. So a rollback switches code and nothing else. Coupling it to a database rollback is a rejected alternative.

### Steps as named records

Each step has its own create, update, and destroy, so you can change one step without resending the others. A single replace-all document and a repeatable step flag are rejected alternatives. A command with spaces, colons, or quotes breaks a flag separator.

### Clone is the only way in

Every production Instance comes from a clone, and its first deployment builds the release layout. Orbit does not convert a production home that serves code from its checkout.

The production home exists before the first release is selected. Deployment and runtime consumers must not treat that pre-release home as a selected release.
