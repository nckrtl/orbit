---
title: "Instance releases"
description: "How production Instances build releases and select current code, and how development defaults deploy in their checkout."
covers:
  - apps/gateway/app/Domain/{Instances/Deployment/**,Instances/ProductionWebRootManager.php,Projects/*DeployStep*.php}
  - apps/gateway/app/Actions/Instances/{DeployInstanceAction,DeployDefaultInstanceAction,StartInstanceDeployAction,StartInstanceRollbackAction,RunAutomaticDeploysAction,RollbackInstanceAction,RestartInstanceProcessesAction,InstanceDeploymentConfigResolver,UpdateInstanceAction,ListInstanceReleasesAction,ListInstanceDeploymentsAction,*InstanceDeployStep*Action}.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteProductionDeployment,RemoteDevelopmentDeployment,DevelopmentCheckoutProgram,ProductionApplicationPaths,ProductionWebRootProgram,RemoteProductionWebRootManager}.php
  - apps/gateway/app/Console/Commands/AutoDeployCommand.php
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

A clone leaves the home prepared, with no `current` link. When a clone seeds SQLite, Orbit registers an owned SQLite connection, attaches it under `DB`, and syncs `DB_DATABASE=<home>/database.sqlite` into the production `.env`. Later releases use the same database. [Cloning](/reference/instance-cloning) describes how to seed the file.

The Gateway lists retained releases as the production user from a directory that user can access. A private SSH home does not prevent reading the first clone's empty selection. Failed scans and invalid release receipts still stop the operation.

The web root is the Instance root, or else the Project root, inside `current`. A root such as `public` serves `<home>/current/public`. A nested root such as `apps/site/public` serves `<home>/current/apps/site/public`; its [application directory](/reference/projects#application-directory) is `<home>/current/apps/site`. The release's `apps/site/.env` links to `<home>/.env`, with a relative target calculated from that depth (in this example, `../../../../.env`). Root `public` keeps the release-root `.env` link with target `../../.env`. Caddy resolves the `current` link before it passes a script path to PHP-FPM, so a request after a switch loads its PHP files from the new release.

With root `server/web/public`, both the initial clone and later releases link `server/web/.env` with target `../../../../.env`. Orbit does not create a second link at the release root. Release selection, retained-release listing, rollback validation, and [Doctor](/cli/doctor) check the link in that same application directory. Source classification reads that directory's `composer.json` and `artisan`, while ownership and Git identity checks still cover the whole release.

A [Route with a web root](/reference/routes#web-roots-on-production) adds a web root in `current`, such as `apps/docs/public`. Source preparation links the release's `apps/docs/.env` to `<home>/env/apps/docs/.env`, with target `../../../../env/apps/docs/.env`, before the deploy steps run. Activation, for a deployment and for a rollback, checks each such web root and its links, adds a missing `.env` link, and grants Caddy access before it switches `current`. Only active Routes count. An Instance without such a Route uses only its Instance web root.

A new release without such a directory fails at source preparation. Like any other preparation failure, it keeps the earlier selection and leaves the release directory. A rollback to an older release without the directory fails at activation and keeps the earlier selection.

## Shared paths

Production Instances keep shared directories in the Instance home. Before any `before_activation` step, Orbit links each declared path from the new release to `<home>/<path>`. A first deployment creates the persistent directory. If it is empty, Orbit seeds it from the directory in the new release. Later deployments keep its contents and discard the release copy. These operations run as the Instance user. Deploy steps must preserve these links.

Laravel app Projects default to `storage`, relative to the application directory. For root `apps/site/public`, the default is `apps/site/storage`, linked to `<home>/apps/site/storage`. Other Project types default to no shared paths.

Use `orbit instance:update ID --shared-paths='["storage","var/uploads"]'` to replace the paths, `--shared-paths='[]'` to disable sharing, or `--shared-paths=null` to restore the default.

Set `shared_paths` with `PATCH /api/v1/instances/{instance}`, for example `{"shared_paths":["storage","var/uploads"]}`. The list replaces the default. `[]` disables shared paths; `null` restores the Project-type default. Instance responses include the effective `shared_paths`. This setting requires access to the Instance's Node and the same operation lock as deployments. It applies to the next deployment and does not move files in the current release.

Paths are relative to the repository root. Use at most 32 distinct, non-overlapping directory paths, each at most 255 bytes. Components use letters, digits, dots, underscores, and hyphens. Absolute paths, `.` and `..` components, hidden top-level paths, and Orbit's `releases`, `current`, `env`, and `database.sqlite` paths are refused. Source and destination ancestors must not be symlinks. Shared paths cannot replace a web root or its ancestors.

### Public storage

When the application's `storage` directory is shared and its web root is `public`, preparation creates `public/storage -> ../storage/app/public` if the link is absent. Do not add an Artisan `storage:link` step for this layout. An existing link is accepted only when it resolves to that application's `storage/app/public`, either inside the release or at the matching persistent path in the Instance home. All other web-root symlinks, broken links, and symlinks within the public storage directory are refused during preparation, activation, and release inspection.

Activation grants Caddy read and directory access to `storage/app/public` and passage through its parents. It does not grant read access to private uploads or logs. Directories in the public storage tree receive default ACLs so new uploads inherit access.

### Replace a hand-made link-storage step

If the Instance already uses `<home>/storage`, leave that directory and its files in place. Confirm that the effective shared paths include `storage`, then remove the `link-storage` step with `DELETE /api/v1/instances/{instance}/deploy-steps/link-storage` before the next deployment. Orbit reuses the existing directory and creates the public storage link. For a nested application, use its matching persistent path.

If files still live only in the selected release, copy them into the matching persistent directory before deploying. Use an application maintenance window when writes can occur during the copy. Automatic seeding uses the new release's source files, not the selected release's runtime data. Orbit does not change existing production files when this setting is saved.

Prune removes the links with their releases and never follows them into the shared directories. A rollback selects older code and leaves shared data unchanged. A release created before shared paths were enabled keeps its original layout; deploy again to create a release with managed links.

## Development defaults

A `default` Instance on an `app-dev` Node is a plain Git checkout that Orbit keeps at the newest commit of the Project's default branch. Other development Instances on the Node start from it. `orbit instance:deploy ID`, the deployment API (`POST /api/v1/instances/{instance}/deploy` with `{}`), and the MCP tool `instance-deploy` can deploy a default by hand. Each starts an [operation](#deploy-through-the-api). The Project's default branch supplies the target commit; production branch overrides do not apply.

The [automatic deployment tick](#automatic-deployments) deploys a default each time its default branch has a new commit. Only one deployment runs per Instance, including manual requests.

A deployment runs in the checkout itself, in two phases.

**Source preparation.** Orbit fetches the default branch and checks out the fetched commit on that branch. The fetch runs as the managed user against the Project repository URL; see [credentialed Git](/reference/instance-setup#credentialed-git). Untracked and ignored files stay, so dependencies, caches, `.env` files, and databases carry over.

The exception is a directory that the old commit has and the new commit removes, such as a package. Git would leave that directory's untracked and ignored files, for example its `node_modules`. Orbit deletes them before the checkout, so a [seed](#seeds) copy finds only directories that the commit has.

- When tracked files have uncommitted changes, Orbit refuses with `deployment.checkout_dirty`. The output lists those files, and the checkout stays unchanged.
- When the local default branch has commits that the fetched commit does not contain, Orbit refuses with `deployment.branch_diverged` and changes nothing. A checkout would drop those commits.
- When the checkout would overwrite an untracked file, Git refuses it.

**Steps.** The Project's [development deploy steps](#development-deploy-steps) run in list order in the checkout. They report the `before_activation` phase. There is no activation, because the checkout is what the Instance serves.

When every required step passes, Orbit records the commit as the default's `seed_commit`. When a required step fails, the later steps do not run and the deployment fails.

- The checkout stays at the new commit with the files the steps left. Orbit does not roll it back.
- The seed keeps the last commit that deployed. So the automatic deployment tick deploys the commit again after a backoff and reruns the steps, at most three times.

A failed best-effort step (`required: false`) gets a warning that names it and its exit status, and the deployment continues. The output and stored events keep that warning, even when the deployment succeeds or the output reached its storage limit.

A step and Orbit's checkout take the lock that [setup and teardown steps](/reference/instance-setup) hold on the checkout: a `flock` on the checkout directory. While a setup or teardown step runs there, the deployment fails with `instance.lifecycle_busy` and the tick retries after a backoff. A step's processes do not inherit the lock, so a process that a step leaves running cannot hold up the next step. Steps must not change tracked files: the next deployment would refuse the checkout as dirty.

Explicit environment synchronization writes the checkout's `.env` files, so the application sees new values at once. A Route's web root serves files from the checkout, and a deployment does not change the Route.

A deployment records that the default's Route projection is pending before it converges the Route, and clears that mark when the converge succeeds. A tick reconverges a visitable default's Route only while the mark is set, so a crash or a failed converge is repaired on the next tick. An unchanged tick with a completed projection does not take the [projection lock](/reference/routes#coordinate-publication), because a full converge rebuilds the Node's PHP-FPM pools, Caddy, and DNS and blocks every other Route operation while it runs.

### Seeds

A new development Instance on the same Node starts from the default: Orbit records the default's checkout as its `seed_path` and the default's `seed_commit`, and creates a linked worktree of the default's repository at that commit. [Setup steps](/reference/instance-setup#run-setup) receive the two values as `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` and copy dependency folders from the checkout. The checkout can change while they copy, because a deployment can run at the same time. A copied folder is a quick start, not a matching set: setup must still run its locked installs. A default's own setup gets no seed.

## Deploy steps

Development deploy steps run at the checkout's repository root, and production deploy steps at the release's repository root, not at a nested application's directory. For root `apps/site/public`, an Artisan step must say `cd apps/site && php artisan migrate --force`. Changing the web root does not change the scope of a repository-owned command.

A deploy step is a named command that runs during a deployment. Each production Instance stores its own steps. A Project stores a separate ordered development deploy list, shared by its development deployments and independent of setup and teardown. Storing a step does not start a deployment.

### Development deploy steps

Use `project:dev-deploy-step:list`, `project:dev-deploy-step:create`, `project:dev-deploy-step:update`, and `project:dev-deploy-step:destroy`, with `--project=ID`. The API collection is `/api/v1/projects/{project}/dev-deploy-steps`; GET lists, POST creates, and PATCH or DELETE on `/{name}` changes or removes one step. MCP exposes the same operations.

Each step has `name`, `command`, `timeout_seconds`, and `required`. Names use lowercase letters, digits, and hyphens, with a letter or digit at each end, and are at most 63 characters. Commands are nonempty UTF-8 strings of at most 16 KiB without NUL bytes. Timeouts are 1–900 seconds, default 300. `required` is a JSON boolean, default `true`; the CLI accepts `--required=true` or `--required=false`. PATCH accepts any of command, timeout, required, or placement; omitted fields stay unchanged.

A new step appends unless `before` or `after` names another step in this Project's development deploy list. The fields are exclusive. Updates without placement keep their position. Names cannot be renamed. Each list permits at most 32 steps and 3,600 seconds of total timeout. Unknown names, duplicate names, invalid fields, unknown placement, self-placement, and limit violations are refused without changing the list. Node access follows the Project's owning Node. Activity records never include commands.

Required steps must pass before Orbit records the commit as deployed. A failed best-effort step (`required: false`) is reported but does not fail the deployment, and the files it wrote stay. Use required steps for installs, builds, and migrations, and best-effort steps for cache warm-up.

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

### Vite store prerequisite

Before a JavaScript step (`npm-build`, or a command using `vp`, Node, npm/npx, pnpm, Yarn, Bun, Vite, or Corepack), Orbit prepares the [Project environment in the vp store](/reference/tools#project-environments-in-the-vite-store) when the Project declares `vite-plus`. The prerequisite runs before the application command, as the store owner. It records desired versions before installing missing content and verifies the result before allowing the step to start. It does not run package install scripts as the store owner.

The default JavaScript deploy runs `vp install --frozen-lockfile` followed by `vp run build`, through `vp env exec` with recorded exact Node and package-manager versions as the Instance user. That execution reads the shared store but never writes it. A data migration replaces stored legacy default steps containing hard-coded Node and npm paths; runtime execution does not rewrite commands. Customized commands keep their flags, shell operators, and additional build scripts, including SSR builds.

A missing runtime or package manager that cannot be installed fails with `deployment.vp_store_failed`, before the build command runs. Invalid or incompatible Project requirements fail with `deployment.vp_requirements_invalid`. The current production release stays selected. Fix connectivity, store ownership, or Project requirements and retry the deploy; no manual runtime installation is required. Doctor reports recorded store drift without repairing it.

## Deploy through the API

A deployment and a rollback each run as an [operation](/reference/operations) on the Gateway queue.

| Request | Body | Result |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | `{}` | `202` with an `instance.deploy` operation. Follow it with `GET /api/v1/operations/{operation}`. |
| `POST /api/v1/instances/{instance}/rollback` | `{"release": "<name>"}` | `202` with an `instance.rollback` operation that selects one retained release. Follow it with `GET /api/v1/operations/{operation}`. |
| `GET /api/v1/instances/{instance}/releases` | none | `releases`, the retained release names, and `selected_release`, which can be null. A development default has none. |

### The deploy operation

The Gateway validates the request and the access grant, checks that the Instance can be deployed, and answers `202`. A refusal uses the normal JSON error envelope: `deployment_config.unavailable` (409) for an Instance that is neither a production Instance nor an active development default, `operation.target_busy` (409) while another operation holds the Instance, and `operation.queue_unavailable` (503) without the Gateway queue.

The operation's steps follow the phases below. Each name is a phase, or a phase and a deploy step's name:

| Step | Resumable | Failed boundary |
| --- | --- | --- |
| `source_preparation` | yes | `preparation` |
| `environment_sync` | yes | `environment` |
| `before_activation.<step>` | no | `before_activation` |
| `activation` | yes | `activation` |
| `php_refresh` | yes | `cache_refresh` |
| `process_restart` | yes | `after_activation` |
| `after_activation.<step>` | no | `after_activation` |
| `cleanup` | yes | none: a failed cleanup is a warning |

An in-flight deploy whose stored plan predates `process_restart` finishes that original plan after a Gateway upgrade. It does not restart Processes automatically. New deploy operations include the restart phase. Other changes to a stored plan still fail with `operation.plan_changed`.

A development default has `source_preparation` and one `before_activation.<step>` for each [development deploy step](#development-deploy-steps). `php_refresh` appears only for an Instance that serves PHP.

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`. A failed operation names the step in `failed_step` and the deployment's error code in `error_code`, such as `deployment.step_failed`. Read the full log of the run from its [history row](#history).

A lost worker resumes a resumable step and fails any other step with `operation.worker_lost`, as [Recover a lost worker](/reference/operations#recover-a-lost-worker) describes. The failed boundary decides the selected release, as for any failure.

### The rollback operation

The Gateway validates the request and the access grant, checks that the Instance is a production Instance that retains the release, and answers `202`. The operation's `input` is `{ release }`. A refusal uses the normal JSON error envelope and changes nothing:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `rollback.release_not_retained` | 422 | The Instance does not retain the release. [`instance:release:list`](/cli/instance#orbit-instancereleaselist) lists the releases it retains. A queued deploy stays queued |
| `deployment_config.unavailable` | 409 | The Instance is not a production Instance |
| `operation.target_busy` | 409 | Another operation holds the Instance |
| `operation.queue_unavailable` | 503 | The Gateway has no queue |

The `rollback` step checks the release again, because a deploy's cleanup can remove it after the request. Then the operation fails at `rollback` with `rollback.release_invalid`. A rollback cancels a deploy that no worker has started, as [One operation at a time](#one-operation-at-a-time) describes.

| Step | Resumable | Failed boundary |
| --- | --- | --- |
| `rollback` | yes | `rollback_selection` |
| `activation` | yes | `activation` |
| `php_refresh` | yes | `cache_refresh` |
| `process_restart` | yes | `after_activation` |

The `rollback` step reads the current selection and finds the retained release. It changes nothing. `php_refresh` appears only for an Instance that serves PHP.

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`, as for a deploy. `release` is the retained release, and `commit` is its commit. A failed operation names the step in `failed_step` and the error code in `error_code`, such as `rollback.release_invalid`.

A rollback ignores a cancel request once its `activation` step succeeded: it still refreshes PHP and restarts Processes. Before that, a cancel stops it before its next step, and the history row ends `failed` with `deployment.cancelled`.

## Deploy

A deployment reads the branch and the steps once, when it starts. Then it runs these phases in order.

1. **Source preparation.** The Gateway clones the branch into a new release as the production user, then prepares its shared paths and public storage link.
2. **Environment sync.** The Gateway writes the stored configuration into `<home>/.env`, as [synchronization](/reference/environment-variables#synchronize) does. This needs exactly one Route on the Instance. An Instance without a Route fails here with `env.owner_unavailable`.
3. **Before activation.** The Gateway runs each `before_activation` step in order, from the new release, as the production user, with a non-interactive shell.
4. **Activation.** The Gateway replaces `current` atomically with a link to the new release.
5. **PHP refresh.** The Gateway reconciles and confirms the dedicated FPM runtime, then resets that service's OPcache and waits for completion.
6. **Process restart.** The Gateway restarts active Instance Processes that are desired `running` and have `restart_on_deploy: true` (the default). Stopped Processes stay stopped.
7. **After activation.** The Gateway runs each `after_activation` step in order.
8. **Cleanup.** The Gateway removes old releases, as [Retained releases](#retained-releases) describes. A failed cleanup is a warning in the output, not a failed deployment.

Each Process restart uses the normal Process restart action and reports success or failure in deployment events. A failure stops the deployment before after-activation steps and cleanup. The new release stays selected.

Deployment environment synchronization only writes files. It does not run the explicit env-sync runtime refresh or restart Processes before activation. Explicit [production env-sync](/reference/environment-variables#synchronize) refreshes PHP and uses the same Process restart action afterward, reporting restarted Process IDs in its response.

Orbit skips PHP refresh for an Instance that does not serve PHP. A changed resolved release restarts the dedicated service so its workers enter the new application directory.

To opt out, set `restart_on_deploy: false` in the complete Process create or update specification, or pass `--no-restart-on-deploy` to [`process:create` or `process:update`](/cli/process). This setting applies to production deployments, rollbacks, and a successful explicit production env-sync runtime refresh. For Horizon managed as an Instance Process, keep the default and remove a hand-made `php artisan horizon:terminate` after-activation step with `instance:deploy-step:destroy`. Orbit now restarts that Process before after-activation steps. The normal runtime stop behavior applies.

Orbit runs no migration, Laravel cache clear, dependency install, asset build, or application health check of its own. With no deploy steps, Orbit runs only its runtime operations. The `current` switch is atomic, but the dedicated FPM restart is not zero-downtime: requests can fail briefly while its socket and workers are unavailable, and in-flight requests can be interrupted. Other Instances keep their own PHP services. A confirmed no-op reconciliation does not restart FPM.

Each step has its own timeout. A timeout stops the step's process group and every later step. The whole deployment has a deadline of the step timeouts plus 900 seconds, at most 1,600 seconds, the [deadline of an operation](/reference/operations#how-an-operation-runs). A rollback has a deadline of 900 seconds. When a deadline stops the run, the error code is `deployment.deadline_exceeded`.

## Failures

The failed boundary decides which release stays selected.

| Failed boundary | Selected release |
| --- | --- |
| Source preparation, environment sync, or a `before_activation` step | The earlier release, or none before the first deployment. |
| Activation | The release that `current` selects when the Gateway reads it back from the Node. When that read fails, the release selected before the deployment. |
| PHP refresh, a Process restart, or an `after_activation` step | The new release. |

Orbit never undoes the effects of a step, of the environment sync, or of data changes. You decide how to recover.

Before it prepares a release, the Gateway checks that the production home records the Project's `repository_url` in its release layout marker and in the origin of the selected release. When that check fails, the Gateway reads the recorded URLs to name the cause.

| Error code | Cause | Recovery |
| --- | --- | --- |
| `deployment.repository_rebind_required` | The home records another URL for the same repository, for example the SSH form after the Project moved to HTTPS. | Run `orbit project:update <project> --repository=<url>` with the Project's current URL. It [re-binds the home](/reference/projects#production-instances). |
| `deployment.repository_mismatch` | The home records another repository. | None in place. The home belongs to another repository. |
| `deployment.selection_invalid` | Another selection check failed. | Inspect the home with [Doctor](#inspect-release-placement-with-doctor). |

Rollback and the release list report the same two repository codes.

## Roll back

A rollback selects one retained release through `current`. The Gateway checks that the release is inside `releases/` and that the web root stays inside it. It then switches `current`, reconciles the dedicated FPM runtime, and resets its cache, as a deployment does. A changed resolved release restarts that service with the same brief serving interruption.

After PHP refresh, a rollback restarts the same active, opted-in Instance Processes as a deployment. The `process_restart` step and its output record the results. A restart failure fails the rollback at `process_restart`, with the failed boundary `after_activation`, and leaves the rollback release selected. A rollback fetches nothing, writes no environment, runs no deploy command, and changes no database file.

## Automatic deployments

The Gateway runs `orbit:auto-deploy` every minute, a job on the [Gateway queue](/reference/gateway-queue#scheduled-work). The command `orbit:deploy-development-defaults` is its old name and still works. One tick serves every [development default](#development-defaults) and every production Instance with `auto_deploy` on.

For each Instance, the tick reads the branch head on the Gateway with `git ls-remote` and the Project's [read credential](/reference/instance-setup#credentialed-git). It keeps the GitHub App installation that covers the repository for up to 60 minutes. It keeps the read tokens for up to 50 minutes, encrypted with the application key. So a quiet minute costs one `ls-remote` and no GitHub API call. A failed read drops the cached installation and tokens.

- When the Instance already runs the commit the tick would deploy, the tick starts nothing and writes no history row.
- Otherwise it starts an [`instance.deploy` operation](#the-deploy-operation) pinned to that commit, with `triggered_by` `orbit:auto-deploy`. No Node name contains a colon, so the marker cannot be a caller.
- The deployment proves that the branch contains the commit and checks out exactly that commit. It fails with `deployment.commit_not_on_branch` otherwise.
- Pushes that arrive meanwhile wait for the next tick. That tick reads the newest head, so intermediate commits coalesce.
- While another operation holds the Instance, the tick skips it and reports `busy`. Its own operation that has not ended yet is `deploying`.

Operations need the [Gateway queue](/reference/gateway-queue#without-a-queue). Without it, the tick fails with `operation.queue_unavailable` for each Instance that has a commit to deploy, and changes nothing. A head the Gateway cannot read fails the tick for that Instance with `source.head_unavailable`. The other Instances still deploy.

### Automatic development defaults

A default deploys its default branch's head. Its deployed commit is its `seed_commit`. A failed commit retries as the [failure rules](#failures-of-automatic-deployments) describe; for a default every failure is retryable. A new commit deploys at once.

### Production Instances

A production Instance deploys automatically after you turn it on:

```bash
orbit instance:update <instance> --auto-deploy=true
```

The tick starts from the commit of the Instance's newest succeeded deployment or rollback. Until a first deployment succeeds, it reports `not_deployed`; deploy the Instance by hand once. It reads the head of the deployment branch: the `--branch` that `instance:update` set, or the Instance's branch.

When the Project has a `merge_check`, the tick deploys the newest commit that passed it. It finds that commit the way [automatic Gateway releases](/reference/github-app#find-the-newest-green-commit) do. The commit is on the branch's first-parent history, and a GitHub Actions check run of that name passed on exactly that commit. It strictly descends from the deployed commit and is not blocked. It never deploys a red or pending commit. When the head is red, it can deploy an older green commit behind it. A commit deploys within about 2 minutes after its check passes.

| Result | Meaning |
| --- | --- |
| `up_to_date` | The Instance runs the head. |
| `started` | The tick started a deployment of the commit it names. |
| `deploying` | The tick's deployment has not ended yet. |
| `gate_pending` | The head's check has not finished, and no older commit is green. The tick reads the check again each minute. |
| `gate_failed` | The head's check failed, and no older commit is green. The tick reads it again after 5 minutes, so a re-run that passes deploys. |
| `no_green_commit` | No commit may deploy: the head is blocked, or it does not descend from the deployed commit, for example after a force push. |
| `commit_failed` | The head is blocked, and no gate applies: a development default, or a Project without a `merge_check`. |
| `busy` | Another operation holds the Instance. |
| `backing_off` | The commit failed, and its retry is not due yet. |
| `paused` | A manual deployment or a rollback paused automatic deployment. |
| `not_deployed` | No deployment of the Instance has succeeded yet. |
| `failed` | The tick could not read the head or the check, or could not start the deployment. `error_code` names why. |

The green-commit walk reads the head's own check run too, so one walk answers both questions. The tick walks again only when the head changed, while the head's check has not finished, or 5 minutes after it failed. A `no_green_commit` answer stays until the head, the deployed commit, or the blocked commits change.

Without a `merge_check`, the tick deploys every new head. `instance:update` accepts that and warns about it. A `merge_check` is read through the [GitHub App](/reference/github-app). A Project that reads through the GitHub CLI, or is not on `github.com`, cannot gate: `instance:update --auto-deploy=true` refuses it with `instance.auto_deploy_gate_unreadable` while it has a `merge_check`.

#### Failures of automatic deployments

A failure before any of the Project's own steps ran, at `operation`, `preparation`, or `environment`, is transient. The tick deploys the commit again after 10 minutes, then after 20 minutes. After three failed attempts, the commit is blocked.

These failures are lasting, and the commit is blocked at once:

- A cancelled deployment.
- A commit that is not on the branch.
- A home that records another repository.
- A home that records another URL for the same repository.
- An Instance that cannot be deployed.
- A failure in a deploy step or later.

A blocked commit does not deploy automatically again. A new commit deploys at once. Failures before the newest manual deployment do not count.

#### Pause

A manual deployment or a rollback after `auto_deploy` was last set pauses the Instance, whether it succeeded or not, also while it still runs. A rollback that waits for a worker has no history row yet. Its operation holds the Instance, so the tick reports `busy`, starts nothing, and does not count the commit as stalled. The Instance stays paused until you set `--auto-deploy=true` again; a new commit does not resume it.

Resuming continues from the commit that is live then. The commit a rollback moved away from is blocked. The tick reads it from the deployment history, so it counts also when you resume or deploy by hand before a tick saw the rollback. The tick also keeps it in `auto_deploy_state.blocked`, so it stays blocked after its rows leave the history.

A blocked commit stays blocked. It never deploys automatically again, until a newer commit lands on the branch: that commit deploys, and the blocked one is behind it.

A rollback that came after the tick queued the deployment wins while no worker has started the deployment: the rollback cancels it, as [One operation at a time](#one-operation-at-a-time) describes. Once a worker has started it, the rollback is busy.

A rollback or manual deployment can also end between the tick's read of the history and the start of its deployment. So the deployment checks for a pause again under the Instance lock. A pause found there ends the operation with `deployment.paused` and deploys nothing. A first attempt writes no history row. An attempt that a worker resumes after a lost worker ends its row with `deployment.paused`, which does not count as a failure of the commit.

**Visibility.** Each automatic deployment has its operation and its [history row](#history) with `triggered_by` `orbit:auto-deploy`. `instance:show` shows the last result, its commit, the head, and a pause. When a result other than `up_to_date`, `deploying`, or `gate_pending` starts, the tick writes one Activity entry, `auto_deploy.<result>`, with the command `orbit:auto-deploy`. A [release alert](/reference/gateway-recovery#release-alerts) with the target `instance:<id>` reports a failed automatic deployment once per commit, a commit or a pending head that waited 30 minutes once per commit, and a pause.

**One worker.** Development defaults, production Instances, and manual deployments share the one `operations` worker, as [One worker for every operation](/reference/operations#one-worker-for-every-operation) describes. A tick's deployment waits in the queue while another deployment runs. Instances behind one Route deploy one after another, not together.

## History

The Gateway records one row for each deployment and each rollback. It writes the row when a worker first starts the deploy or rollback operation, and completes it when the run ends. It keeps the last 50 rows for each Instance.

Each row holds `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by` (the calling Node's name, or `orbit:auto-deploy` for an [automatic deployment](#automatic-deployments)), and `events`. `events` holds the phase and output events of the run, with at most 128 KiB of output. A deployment saves them after each step, so a resumed deployment keeps the events up to the last step that ended. The `cleanup` phase marks release cleanup. A development deployment records `commit` and leaves `release` and `selected_release` null.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployments` | The Instance's rows, newest first, without `events`. |
| `GET /api/v1/deployments/{deployment}` | One row with `events`. |

## One operation at a time

Deployment, rollback, deploy-step changes, and branch changes share the Instance's operation lock with environment operations, Instance removal, and Route changes. A competing request waits or receives a busy error. A deployment or a rollback holds the lock while its job runs; an interrupted job releases it after its running command stops.

A deploy or rollback operation also holds the Instance from its start request to its end. A second start answers `operation.target_busy` with the first operation's ID, also while the first one waits in the queue.

A rollback is the one exception. It wins over a deploy of the Instance that no worker has started, manual or automatic:

- The deploy ends `cancelled`.
- The deploy's `error_code` is `operation.superseded`, and its `cancel_requested` is true.
- The rollback takes the Instance.

A deploy that a worker started keeps the Instance, also while it waits to resume after a lost worker. The rollback then answers `operation.target_busy` with the deploy's ID. Cancel the deploy with [`operation:cancel`](/cli/operation), or wait for it to end. Then start the rollback again.

The Gateway cancels the deploy and creates the rollback in one database transaction. When a worker claims the deploy first, the deploy runs, and the rollback is busy. One operation holds the Instance in every case. When the Gateway then cannot queue the rollback's job, the rollback fails with `operation.dispatch_failed`, and the deploy stays cancelled; start the rollback again.

## Inspect release placement with Doctor

[Doctor](/cli/doctor) checks each production Instance against its home. It reports a missing or wrongly owned home, a broken `current` link, a selected release outside `releases/`, and a web root that leaves the release. It checks the `.env` link of the default application directory only. A home without `current` is healthy before the first deployment. Doctor accepts an older release after a rollback, and a release whose branch has moved on.

## Retained releases

When preparing a candidate, Orbit keeps the selected release's Node and package-manager references, even when its build fails. Retained releases keep those references for rollback too. Release cleanup drops references only for releases it takes out of the retained set; a release kept for running processes keeps its runtimes.

A successful production deployment keeps at most 3 releases in the home: the new selection, the selection before it, and the newest other release. `initial` counts as the oldest, and later releases sort by the creation time in their names. It removes the rest, and the output names each one. So [`instance:rollback`](/cli/instance#orbit-instancerollback) can return to the previous selection and one other release. A rollback removes nothing. A failed deployment removes nothing either; the next successful one does.

Releases that only stopped Processes used can be removed once they fall outside the retention limit. If a Process opts out, uses a fixed old-release path, or leaves child processes running, it can still use an older release. Cleanup keeps every release that is the working directory, root, or executable of a running process of the production user, also beyond the limit. The output names each such release; stop or restart its remaining processes to free it. When cleanup cannot read where a live process works, it removes nothing and reports a failed cleanup.

Cleanup removes only folders that pass the release checks of the listing. It first renames a release to `releases/.pruned-<name>`, so the release leaves the list at once, and then deletes that folder. Cleanup deletes the release's own files and shared-path links. It does not follow those links or delete persistent shared directories in the Instance home. Files in unshared directories go with the release. A folder that a stopped cleanup left is deleted by the next one. A partial folder that fails the release checks stays.

[Instance removal](/reference/instance-removal) removes `current` and the serving setup, and keeps `releases/`, `.env`, `env/`, `database.sqlite`, and the local PHP-FPM tuning for recovery.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A development default is a plain checkout

A default serves development, so it updates in place like any working checkout: fetch, check out, and run the steps. Releases, a `current` link, snapshots, and pruning are what make a production switch atomic and reversible. A development default needs neither, and that machinery cost disk space, broke when a release lost its Git link, and served a stale checkout to Processes and Schedules that ran outside the release. Release layouts stay where something is served to users: the Gateway's own releases and production homes.

A failed step leaves the new commit checked out instead of rolling back. A rollback would need the old dependencies too, and the automatic deployment tick reruns the steps, so a passing retry repairs the checkout. Refusing uncommitted tracked changes keeps someone's edit from being discarded; untracked files carry the dependencies, so the checkout keeps them.

Seeds name the checkout, not a frozen copy. Setup always runs locked installs after it copies, so a copy taken during a deployment only costs install time. Leases that kept frozen releases alive for other Instances are a rejected alternative: they grew without bound and blocked cleanup.

### Orbit owns releases, you own the steps

Release preparation, the switch, and the PHP-FPM refresh are the same for every application, so Orbit does them. The commands before and after the switch differ for each application, so each Instance stores its own. Orbit does not guess steps from the framework.

### Deploy a branch, not a commit

A manual deployment means "deploy what the branch holds now". So it takes no commit, and two manual deployments of one branch can produce different code. An automatic deployment is the exception: it deploys the commit that passed the gate, so it pins that commit, and the branch must contain it. Without the pin, a newer, unchecked head could deploy in place of the checked commit.

### Poll, do not wait for a webhook

The Gateway reads the branch head every minute instead of receiving a GitHub push webhook. A webhook would need a public endpoint and a webhook secret on a Gateway that otherwise answers only WireGuard peers. The tick reuses the paths that development defaults and automatic Gateway releases already use. A quiet minute costs one `git ls-remote`, and a pending check costs a few GitHub API reads, well within the App's rate limit. The price is up to one minute of delay.

Conditional requests with an ETag were rejected: a quiet minute already makes no GitHub API call, and the cache keeps token requests rare.

### Pending checks need no deadline

The green-commit walk skips a pending commit and walks back to the newest green commit that descends from the deployed one. So no deployment waits for a check to finish, and a deadline for pending checks was rejected. The stall alert after 30 minutes reports a commit that does not deploy.

### Only you resume after a pause

A manual deployment or a rollback is a person's decision about what runs. A merge is not a decision to undo it. So a new commit does not resume automatic deployment; only setting `auto_deploy` again does.

### One operations worker for every deployment

Automatic deployments and rollbacks run as operations on the one `operations` worker, like manual deployments. One worker keeps one deployment at a time in the fleet and lets each Instance's lock and a lost worker work the same way for every deployment. A second worker for automatic deployments was rejected: it would let two deployments run at once and would need its own lock and recovery. A deployment or a rollback of one Instance can wait for another Instance's deployment, and Instances behind one Route deploy in turn.

### Three releases per home

Each release holds a full checkout with its dependencies, so the count decides the disk use. Three keep the current code, the way back, and one more step back. The previous selection outranks newer releases that never went live, because it is the release a rollback needs first.

A release that a running process uses outranks the limit: deleting it would pull the code, the dependencies, and the configuration cache out from under that process.

### Rollback selects code only

Older code can need a data recovery that only the application knows. So a rollback switches code and nothing else. Coupling it to a database rollback is a rejected alternative.

### A rollback wins over a queued deploy

A rollback undoes a bad release, often while users see errors. A deploy that no worker started has changed nothing yet, so the rollback cancels it instead of waiting behind it. A deploy that runs has already changed the Instance. A rollback that stopped it would leave it at a step boundary, half done. So the rollback refuses, and a person decides whether to cancel the deploy or let it end. The rule is the same for manual and automatic deploys.

### Steps as named records

Each step has its own create, update, and destroy, so you can change one step without resending the others. A single replace-all document and a repeatable step flag are rejected alternatives. A command with spaces, colons, or quotes breaks a flag separator.

### Clone is the only way in

Every production Instance comes from a clone, and its first deployment builds the release layout. Orbit does not convert a production home that serves code from its checkout.

The production home exists before the first release is selected. Deployment and runtime consumers must not treat that pre-release home as a selected release.
