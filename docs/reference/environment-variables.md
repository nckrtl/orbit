---
title: "Instance environment variables"
description: "How the Gateway imports, stores, updates, and synchronizes an Instance .env file without exposing its values."
covers:
  - apps/gateway/app/Domain/Instances/Environment/**
  - apps/gateway/app/Actions/*/{Import,Update,Synchronize,Refresh}*Environment*Action.php
  - apps/gateway/app/Http/Controllers/Api/InstanceEnvironment*Controller.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteInstanceEnvironmentAccess,RemoteInstanceEnvironmentRuntimeRefresher,NativeInstanceEnvironmentOperationLock}.php
  - apps/gateway/app/Models/{Instance,InstanceEnvironmentValue}.php
  - apps/gateway/database/migrations/{2026_09_30_*,*rename_app_domain_to_project_and_instance}.php
  - apps/cli/app/Commands/Environment/**
  - packages/php-sdk/src/{Requests,Responses}/Environment/**
---

# Instance environment variables

The Gateway owns the environment configuration of every Instance. It stores each key and value encrypted, and it writes the Instance's `.env` file only when you synchronize. A value never appears in a response, an Activity entry, an error, or a log. [`env`](/cli/env) lists the commands.

`ORBIT_DOCUMENT_CLEANUP_RUNTIME` selects the private local directory for isolated Project Document cleanup-gate fixtures when `APP_ENV=testing`; other environments ignore this override. Its default is `/run/orbit/project-documents/`. Never point it into `ORBIT_HOME`, a checkout, a web directory, or a backup. Installed Gateway services use the default runtime directory. This setting is not an Instance environment value or deletion authorization. See [the restore-time cleanup gate](/reference/project-documents#restore-time-cleanup-gate).

`ORBIT_TASK_VMS_ENABLED`, `ORBIT_TASK_VMS_DEV_CLUSTER_ID`, `ORBIT_TASK_VMS_WIREGUARD_RANGE`, `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN`, `ORBIT_TASK_VMS_PI_ARTIFACT_PATH`, `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256`, `ORBIT_TASK_VMS_PI_MODELS`, `ORBIT_TASK_VMS_MEDIUM_PROJECTS`, and `ORBIT_TASK_VMS_PARK_AFTER_MINUTES` configure [task VMs](/reference/compute-drivers#configure-task-vms) in the Gateway's own environment. They are not Instance keys. Task VMs are off by default, the reserved WireGuard range defaults to `10.44.0.128/25`, a waiting VM parks after 30 minutes, and the other values are unset. `ORBIT_TASK_VMS_PI_MODELS` and `ORBIT_TASK_VMS_MEDIUM_PROJECTS` are JSON lists. The Gateway validates these values once, when it first uses them.

Incus hosts are configured through [Node `settings.task_vms`](/reference/node-settings#configure-a-task-vm-host), not an environment list or the `app-dev` role. `ORBIT_TASK_VMS_INCUS_HOSTS` is read only by the [deployment migration](/reference/node-settings#migrate-the-environment-host-list). Keep its current JSON list until migration copies it into the existing host Nodes, verify their settings, and then remove the variable.

`ORBIT_TASK_VMS_UPCLOUD_ENABLED`, `ORBIT_TASK_VMS_UPCLOUD_PROJECTS`, `ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE`, `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS`, `ORBIT_TASK_VMS_UPCLOUD_ZONE`, `ORBIT_TASK_VMS_UPCLOUD_PLAN`, `ORBIT_TASK_VMS_UPCLOUD_DISK_GB`, and `ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE` configure the [UpCloud overflow](/reference/compute-drivers#configure-upcloud) of task VMs in the Gateway's own environment. They are not Instance keys. The overflow is off by default and unconfigured while the token file is unset. With the token file set, the Gateway sweeps UpCloud and queues a template build when release handoff or the per-minute reconcile finds no verified template for a new recipe identity, also with the switch off. A failed attempt waits for a new identity or an explicit `task-vms:build-recipe --retry`. The token stays in its protected file on the Gateway. The Gateway ignores the old `ORBIT_UPCLOUD_*` keys; remove them from its environment.

`ORBIT_CHECK_PROCESSES`, `ORBIT_CHECK_SCOPE`, and `ORBIT_CHECK_BASE` configure the repository's [local checks](/reference/implementation-loop#parallel-processes) in the shell that runs them. They are not Instance keys. `ORBIT_CHECK_PROCESSES` sets the number of processes that Pest, PHPStan, Rector, and Pint start. The default is the machine's core count, up to 4. `bin/test` still reads the older `ORBIT_TEST_PROCESSES` when it is unset. The candidate gate sets the other two so that Pint and Rector [check the changed files](/reference/implementation-loop#changed-file-quality-checks). `bin/quality-scope` checks every file when `CI` or `GITHUB_ACTIONS` is set.

The Gateway's own environment is separate from an Instance's stored configuration. Set `ORBIT_TASKS_IMPLEMENTER_EFFORT` and `ORBIT_TASKS_REVIEWER_EFFORT` in the Gateway's `.env`, not the task workspace's `.env`. See [Tasks configuration](/reference/tasks#configuration) for their defaults and when changes apply.

`APP_VERSION` in the Gateway's own `.env` sets the version that `gateway:status` reports. When it is unset or empty, the Gateway reports the commit in its release's `REVISION` file, or `dev` outside a release. Leave it unset in the [release layout](/reference/gateway-recovery#release-layout), so every release reports its own commit. `.env.example` does not set it, and [adoption](/reference/gateway-recovery#adopt-the-release-layout) comments it out of the shared env file. It is not an Instance key.

`ORBIT_GATEWAY_CHECKOUT` is the Gateway application directory. It defaults to `/home/orbit/orbit/apps/gateway`, which is a link to the current release in the release layout.

`ORBIT_GATEWAY_RELEASE_MIN_FREE_MB` is the free space, in MiB, that [prepare](/reference/gateway-recovery#prepare-a-release) keeps in the releases directory. It defaults to `1024`. It is not an Instance key. `ORBIT_GATEWAY_RELEASES_KEEP` is the most releases [deploy](/reference/gateway-recovery#deploy-a-release) keeps, the current and the previous one included. It defaults to `3`, and a value below `2` counts as `2`. `ORBIT_GATEWAY_RELEASE_SNAPSHOTS_KEEP` is how many pre-migration [database snapshots](/reference/gateway-recovery#migrations-and-the-snapshot) the Gateway keeps, also `5` by default. `ORBIT_GATEWAY_RELEASE_SCHEDULER_DRAIN_SECONDS` is how long the [runtime handoff](/reference/gateway-recovery#runtime-handoff) lets the old scheduler finish its running commands, `600` by default. `ORBIT_GATEWAY_RELEASE_TICK_CONFIRMATION_SECONDS` is how long a verified release has for its own scheduler to run `tasks:tick` before its [tick confirmation](/reference/gateway-recovery#post-release-tick-confirmation) is missed and alerts, `180` by default and at least `60`.

`ORBIT_GATEWAY_VERIFY_ORIGIN` is the origin deploy uses for `/up` and Gateway status. It defaults to `https://gateway.orbit`. It is not an Instance key.

`ORBIT_GATEWAY_QUEUE_CONNECTION` is the slug of the Redis [Database connection](/reference/database-connections) of the [Gateway queue](/reference/gateway-queue), such as `gateway-queue`. Empty, the default, keeps the scheduler running its commands itself and the Gateway creates no `horizon` Process. `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` is the Redis database index of the queue and Horizon, `1` by default. Neither is an Instance key.

`ORBIT_GATEWAY_RELEASE_SMOKE_TIMEOUT` and `ORBIT_GATEWAY_RELEASE_SMOKE_PROJECT` configure the [smoke step](/reference/gateway-recovery#smoke) of a Gateway release. The timeout is the limit `bin/gateway-smoke` gets, from 1 to 600 seconds, default `90`. The Project, by ID or slug, turns on the document write check; unset, no release writes a document. They are not Instance keys.

`ORBIT_GATEWAY_RELEASE_BRANCH` and `ORBIT_GATEWAY_RELEASE_CHECK` name the branch and the check run that [automatic releases](/reference/gateway-recovery#automatic-releases) follow. They default to `main` and `Required checks`. The repository is the `origin` of the shared release repository. They are settings in the Gateway's own environment, not Instance keys.

`ORBIT_OPSBOT_WEBHOOK_URL` and `ORBIT_OPSBOT_WEBHOOK_SECRET` are settings in the Gateway's own environment. They are not Instance keys. See [Tasks: OpsBot direction webhook](/reference/tasks#opsbot-direction-webhook). The Gateway never returns the secret. Setting Instance keys with those names does not configure the Gateway.

`ORBIT_CLI_RELEASE_REPOSITORY` is a setting in the Gateway's own environment, not an Instance key. It names the repository whose CLI releases make up the [desired fleet state](/reference/self-update#how-the-gateway-resolves-it). The default is `https://github.com/nckrtl/orbit`. On the machine that runs `orbit self-update`, `ORBIT_SELF_UPDATE_RELEASES` replaces the release download location, `https://github.com/nckrtl/orbit/releases/download`, for a mirror you trust. The Gateway never sets it.

`ORBIT_FLEET_ROLLOUT` and `ORBIT_FLEET_ROLLOUT_ORDER` are settings in the Gateway's own environment, not Instance keys. They [turn the fleet rollout on](/reference/gateway-recovery#turn-it-on) and name the Nodes it visits first. The rollout is off by default. `ORBIT_CLI_RELEASE_STATIC_MANIFEST` is for tests only: it replaces Git history and GitHub with a local CLI release manifest on a disposable topology. Never set it on a real Gateway.

`ORBIT_RELEASE_ALERT_WEBHOOK_URL` and `ORBIT_RELEASE_ALERT_WEBHOOK_SECRET` are settings in the Gateway's own environment too, not Instance keys. See [Release alerts](/reference/gateway-recovery#webhook). The Gateway never returns the secret.

Set `ORBIT_TASKS_PROVISIONING_FAILURE_THRESHOLD` in the Gateway's `.env` to choose how many consecutive workspace provisioning failures request failure assistance (default `3`, minimum `1`). A successful start resets the count. See [Claim and provision](/reference/tasks#claim-and-provision) for the cause-carrying reason and logging.

Reviewer trust is not an Instance environment setting. The Gateway operator sets `ORBIT_TASKS_GITHUB_REVIEWERS` in the Gateway's `.env`. A task workspace's `.env` or branch cannot grant [GitHub feedback authority](/reference/tasks#trusted-github-feedback).

The authors whose pull requests Orbit reviews and merges are Gateway configuration too. The operator sets `ORBIT_TASKS_PULL_REQUEST_AUTHORS` in the Gateway's `.env`, in the same format. A pull request cannot name its own author as trusted. See [Incoming pull requests](/reference/tasks#incoming-pull-requests).

The logins requested as reviewers on a published task pull request are also Gateway configuration. The operator sets `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` in the Gateway's `.env`. Unset or empty requests no one. See [Tasks configuration](/reference/tasks#configuration).

The Incus harness also reads its own environment. `ORBIT_E2E_INCUS_MEMORY` overrides the memory limit for every guest it creates, for example `2GiB`. When unset, the harness uses the [per-Node defaults](/reference/incus-topologies#capacity). It does not read this setting from an Instance's `.env`.

`ORBIT_GATEWAY_URL` and `ORBIT_CA_PATH` are web development proxy inputs, not Instance settings. A [topology web session](/reference/web-app#run-against-a-topology) pins the URL and trusted CA to its selected topology. Inherited endpoint overrides, including settings in the caller's environment or web development files, must not redirect Gateway, realtime, or metrics traffic to the live fleet. Missing required topology configuration fails startup instead of reading a live profile or another user's credentials.

`ORBIT_DOCS_ROOT` overrides the Docs tooling's documentation directory, normally repository-root `docs/`. `bin/docs-merge-check` sets it to the temporary checkout's `docs/` directory so all lint rules inspect the selected tree. This local tooling setting is not an Instance environment value. See [the contributor checks](/contributor-guide#checks-that-need-no-network).

## Operations

The import and update endpoints accept either a positive numeric Instance ID or an exact Route domain in `{instance}`. A selector that matches no Instance returns HTTP 404. A Route domain that has multiple Instance targets returns HTTP 409 with `env.target_ambiguous`. The Instance's recorded placement owns its environment: the owning Node is the Instance's `node_id`, not a Route. An Instance without a Route can still have its environment synchronized. Changing the Project's [task workspace routing setting](/reference/projects#task-workspace-routing) does not change an existing Instance's environment target.

| Operation | Request | Body | Effect |
| --- | --- | --- | --- |
| Import | `POST /api/v1/instances/{instance}/environment/import` | `{}` or `{"replace": true}` | Reads the Instance's `.env` into stored configuration. |
| Update | `PUT /api/v1/instances/{instance}/environment/{key}` | `{"value": "..."}` | Adds or replaces one stored value. |
| Synchronize | `POST /api/v1/instances/{instance}/environment/sync` | `{}` | Replaces the Instance's `.env` with the complete stored configuration. |

Import and update change stored configuration only. The `.env` file on the Node stays the same until you synchronize. The Gateway refuses unknown, duplicate, or wrongly typed members with 422 before it changes anything.

A success returns `instance_id`, `operation`, `changed`, and `key_count`, the total number of stored keys. `changed` is `false` when the stored value or the file already matched.

The Instance must be active and have complete placement. The Node must have exactly one active `app-dev` or `app-prod` role; without one, the Gateway returns `instance.placement_unavailable` (409).

Import, update, and synchronization use the Instance's recorded owning Node. A Route is not required for synchronization unless a stored value refers to `{{instance.domain}}`; then the Gateway needs an authoritative Route and returns `env.reference_unavailable` (409) if it cannot resolve one. A Route in an incomplete transition also blocks synchronization. The caller needs an access grant to the Instance's Node. Import and synchronize also need an active Node. Update does not contact the Node, so it works while the Node is unreachable.

## Where the file lives

The Instance's recorded [vp runtime and package-manager environment](/reference/tools#project-environments-in-the-vite-store) is separate from its environment variables. The nullable `vp_environment` record holds store versions and prerequisite status, not `.env` keys or values. Preparing or adopting those tools does not import, synchronize, or rewrite the Instance's `.env` file.

The Gateway derives the file location and the user from the Instance's placement. The caller cannot choose the Node, the user, the directory, or the file name.

| Placement | File | User |
| --- | --- | --- |
| `app-dev` | `.env` in the Instance's application directory within the checkout | The Node's managed user |
| `app-prod` | `.env` in the production home | The Instance's production user |

[Monorepo dependency discovery](/reference/instance-dependencies#monorepo-sub-projects) reads separate manifests and lockfiles under `apps/` and `packages/`. It does not import, store, or synchronize those sub-projects' `.env` files. The Instance environment keeps its existing application-directory scope.

For Laravel, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, development reads and writes `<checkout>/apps/site/.env`, and `.env.testing` lives beside it. A development default uses the same paths in its checkout, which it serves directly without a `current` link.

On `app-prod`, every release links `.env` in its application directory to the production home's file. With root `apps/site/public`, `<home>/releases/<name>/apps/site/.env` links to `<home>/.env`; no release-root `.env` link is needed. Application data uses separate [shared directories](/reference/deployments#shared-paths); changing those paths does not change the environment file. See [Production release layout](/reference/deployments).

## Import

Import reads the Instance's `.env`. It never reads `.env.example`. Before it reads the contents, the Gateway checks SSH access, the user, the path, the file type, the owner, and the size. A missing, unsafe, or oversized file stops the import.

The importer accepts blank lines, comments, quoted and escaped values, multiline quoted values, and expansion of keys from the same file. It does not read the Gateway's own environment. A duplicate key, an unresolved expansion, invalid syntax, or a file over 1 MiB refuses the whole import. Orbit stores keys and values only, without comments or formatting.

Without `replace`, a file key that is already stored returns `env.import_conflict` (409), and nothing is stored. With `replace`, matching keys take the file value, new keys are added, and stored keys that the file lacks stay.

For a Laravel Instance, import stores `APP_URL` as `https://{{instance.domain}}`, so the URL follows the Route. It keeps a non-empty `APP_KEY` as the file has it. When the file contains `APP_KEY` with an empty value, import reuses the Instance's non-empty stored key, or generates a cryptographically random 32-byte key with the `base64:` prefix if no usable stored key exists. This also applies to the existing-file import during Instance creation. A missing `APP_KEY` stays missing; other values stay as the file has them. Non-Laravel imports do not generate keys.

When a Route's domain changes, Orbit updates APP_URL in that application's `.env` and Laravel cached configuration, not in an unrelated file at the repository root. A [Route with a web root](/reference/routes#serve-several-web-roots) writes `APP_URL` into the `.env` of the directory it serves; the stored Instance environment belongs to the Instance's own Route. On production, that file is `<home>/env/<directory>/.env`, which each release links to.

## Update

Update stores one string. `""`, `"false"`, and `"0"` are distinct strings. For a Laravel Instance, `APP_URL` accepts only `https://{{instance.domain}}`.

## Limits

The Gateway checks the complete result before it stores any part of an import or update. A failure returns `env.configuration_invalid` (422). The details name the key, the failed rule, and a leftover placeholder token, but never a value.

| Item | Limit |
| --- | --- |
| Key | 1 to 255 characters. It starts with `A-Z`, `a-z`, or `_`, and can then also hold `0-9`. |
| Value | Valid UTF-8, at most 65,536 bytes, without a NUL byte. |
| Keys | At most 1,024 for each Instance. |
| File | At most 1 MiB after rendering. |
| Placeholders | `{{instance.domain}}` and `{{instance.environment}}`, alone or inside a longer value. Any other `{{...}}` fails. |

## Synchronize

Synchronization takes one snapshot of the Instance, any authoritative Route, and the stored configuration. It resolves `{{instance.domain}}` to the Route's domain when one is present and resolves `{{instance.environment}}` to `development` on `app-dev` or `production` on `app-prod`. Only a stored domain placeholder requires a Route. A leftover `{{` or `}}` after rendering returns `env.reference_unavailable` (409) before the file changes.

Before it decrypts a value, the Gateway checks SSH access, the user, the path, the directory's write permission, the file type and owner, read-only storage, and free space. A failed check returns an error and leaves `.env` as it is.

The Gateway renders every stored key in sorted order, as a quoted value. It writes the result as a candidate file with mode `0600`, owned by the runtime user, and renames it over `.env`. A matching file with mode `0600` stays in place and returns `changed: false`. Stored configuration is the only input. Edits to stored keys disappear.

Before it writes, the Gateway reads the file's key names. A key that stored configuration lacks returns `env.sync_would_drop_keys` (409), and the file stays as it is. The details list the key names, comma-separated, never a value. Orbit owns, and so may remove, only the keys its last synchronization wrote and the keys a detach removed since. To keep the other keys, store them with `env:update`, or import the file with `replace` when its other keys are already stored. Otherwise remove them from the file. A Route transition on a production Instance skips this check, because deploys already own that file.

When the Gateway cannot confirm the write, it returns `env.sync_unconfirmed` (the file may have changed). Repeat the request: it checks the file again and either accepts the matching file or writes it.

For an Instance that owns its `DB` database, synchronization also writes `.env.testing` with the same checks and mode. The `DB_*` keys of that connection point to the [test database](/reference/database-connections#test-databases):

- A missing file is created from the same values as `.env`, with `APP_ENV=testing` and those `DB_*` keys.
- In an existing untracked file, Orbit sets only those `DB_*` keys and removes the other `DB_*` keys of the connection. Every other line stays.
- A file that Git tracks in the checkout stays unchanged. Orbit records the test database name in the `testing` property of the `env:sync` activity.

Laravel loads `.env.testing` instead of `.env` when `APP_ENV` is `testing`. So a new file holds every key of `.env`, such as `APP_KEY`.

When Git cannot report whether it tracks the file, for example because of a dubious-ownership error or a damaged repository, synchronization returns `env.testing_tracking_unknown` and leaves `.env.testing` unchanged. A checkout outside a Git repository counts as untracked. Orbit never deletes `.env.testing`. The file stays after the Instance stops owning its `DB` database.

Development synchronization changes only `.env` and `.env.testing`. On production, when `.env` changes and a PHP version is selected, Orbit checks `<home>/current`. Orbit resolves the application directory inside that release from the Instance root, falling back to the Project root, and removes a trailing `public` component. For example, `apps/site/public` selects `<home>/current/apps/site`.

If that application directory has `bootstrap/cache/config.php`, Orbit runs `php<version> artisan config:cache` there as the Instance user. It then resets FPM's opcache through the same runtime refresh used by deploys. An uncached release stays uncached. Changes only to `.env.testing` do not trigger a refresh. Deploys and Route-domain transitions use file-only synchronization; only the explicit env-sync API/CLI path runs this refresh. Unchanged files do not trigger a refresh unless an earlier refresh is pending.

No current release or no selected PHP version produces an explicit skipped result.

The production sync response adds `runtime_refresh`: `config_cache_and_opcache_refreshed`, `opcache_refreshed_config_uncached`, `skipped_unchanged`, `skipped_no_current_release`, or `skipped_no_php_version`. Development responses keep their existing shape. Config-cache failures return `env.config_cache_refresh_failed` (502) and do not reset opcache. Runtime reset failures return `env.php_runtime_refresh_failed` (502). Both errors say the file changed; neither exposes application output or environment values.

Config is built at a temporary `APP_CONFIG_CACHE` path and verified by booting Artisan against that cache before renaming it over the old cache. Laravel loads the cached array using the application's own bootstrap and autoload context, including custom Composer vendor directories and shared repository autoloaders. Valid cached enums and class instances need no special handling. A build failure keeps the old cache. Fix the cause and repeat env-sync; the Gateway remembers pending refreshes and retries them even when `.env` is unchanged.

It records the pending refresh in the Instance database row as soon as the production `.env` replacement is confirmed, before key tracking or `.env.testing` synchronization can fail, and clears it only after PHP refresh and eligible Process restarts succeed. Pending refreshes survive application cache clears, including Gateway recovery. The database migration copies existing cache markers into Instance rows.

After refresh, Orbit uses the deploy Process restart action to restart running Instance Processes opted into `restart_on_deploy`. The response includes `restarted_process_ids`. Restart failures return `env.process_restart_failed` (502), including IDs already restarted, without exposing application output. An unexpected refresh failure returns `env.runtime_refresh_failed` (502).

Only successful explicit production runtime refresh restarts eligible Instance Processes. Development synchronization, deploy and Route file synchronization, and skipped or failed runtime refresh do not restart them through env-sync. Opted-out and failed Processes are excluded. Check `process:list` after `env.process_restart_failed`, recover failed Processes with `process:restart <process-id>`, then retry env-sync to finish any remaining eligible restarts. Repeating env-sync can succeed while those Processes remain failed. Restart unmanaged workers separately when they retain configuration in memory.

The Gateway takes one consistent snapshot of the Instance owner, any authoritative Route, and complete stored configuration. It resolves `{{instance.domain}}` from the Route when available and `{{instance.environment}}` to the default Laravel mode for the Instance's Node role (`development` on app-dev or `production` on app-prod). A Route is required only when a stored value uses the domain placeholder; a missing Route or unavailable reference then stops synchronization before replacement. An incomplete Route transition also stops synchronization. The generated dotenv file has stable key order and preserves literal whitespace, newlines, quotes, dollar signs, backslashes, empty strings, and stored application keys.

Import, update, synchronize, deploy, removal, and Route changes on one Instance share one operation lock. A competing request waits or returns `env.operation_busy`.

## Laravel mode

`APP_ENV` and `APP_DEBUG` are ordinary stored keys. The Node role, not these keys, decides the release layout, the Unix user, and the PHP-FPM pool. So a change to `APP_ENV` never moves an Instance between layouts. When `APP_ENV` is absent or is the environment placeholder, Orbit reads it as `development` on `app-dev` and `production` on `app-prod`.

A [clone](/reference/instance-cloning) onto `app-prod` copies the candidate's stored configuration except `DB_*` keys and then sets `APP_ENV=production` and `APP_DEBUG=false`. For a `symfony-app` it sets `APP_ENV=prod` and `APP_DEBUG=0`, Symfony's production mode. You can change both afterwards. A transfer keeps every stored key.

When the clone seeds a SQLite snapshot, it registers an owned connection on the target Node, attaches it under `DB`, and synchronizes `DB_CONNECTION=sqlite` and `DB_DATABASE=<production_home>/database.sqlite` into the production `.env`. This provisioning step uses the pending clone's placement and Route checks; public environment operations still require an active Instance. An identical retry reuses the connection and attachment. Without a SQLite snapshot, the clone has no candidate `DB_*` values. Other application settings, such as `QUEUE_CONNECTION`, copy unchanged.

## Other writers

Other operations also change stored keys, and never the file itself:

- [`instance:database:add` and `instance:database:remove`](/reference/database-connections#add-a-connection-on-an-instance) write or clear the keys of one database prefix.
- Creating an `agentation-mcp` Process stores `AGENTATION_URL` as `https://{{instance.domain}}/__orbit/agentation`. See [Agentation](/reference/agentation).
- Creating an `annotator` Process stores `ANNOTATOR_URL` as `https://{{instance.domain}}/__orbit/annotator/annotations`.

Removing the annotator Process deletes that stored key. Synchronization renders the current Route domain. Without a Route, the domain placeholder returns `env.reference_unavailable`.

The annotator also projects the concrete `ANNOTATOR_URL` and `ORBIT_ANNOTATOR_PORT` into every systemd Process of the Instance. These derived values override a stale `.env` value or a caller-supplied environment map. Process creation and removal rewrite the existing units of sibling Processes without changing their observed runtime state. Sleeping workers are not started, and cold dependencies are not restored. A running sibling reads the new values on its next start or restart. After removal, units unset both keys, even before the next environment synchronization. This runtime projection does not write `.env`; see [Annotator Process](/reference/agentation#annotator-process).

A production [deployment](/reference/deployments) synchronizes the stored configuration before it runs any deploy step. A development default deploys in its checkout, so synchronization writes the files it serves and a deployment does not synchronize.

## Synchronize during a domain change

A development [Instance rename](/cli/instance#orbit-instancerename) updates Laravel `.env` and cached `app.url` through Route convergence, and records the stored APP_URL only after that operation succeeds. If a failed replacement remains, requesting the original domain is not a rollback or a no-op: it returns `route.domain_change_conflict` without another branch or environment mutation. Retry the pending destination to finish the replacement.

A domain change of a production Route rewrites the production `.env` inside the Route operation. It uses the same checks and writer as synchronization. It resolves `{{instance.domain}}` against the new Route, although the public operations refuse an Instance while its Route changes. Stored values stay the same, so a placeholder `APP_URL` changes and a literal `APP_KEY` does not.

When the change fails before the cutover, recovery renders the stored configuration against the old Route and restores `.env` before it removes the new Route. After the cutover, a retry checks the new file and does not revert it. See [Routes](/reference/routes).

## Project update boundary

A Project slug change updates the Laravel `APP_URL` that the Route domain owns. For a development Instance with stored configuration, the Gateway synchronizes `.env` against the replacement Route before the cutover. An Instance without stored configuration skips that synchronization, because there is nothing to render; the update evidence records `environment: skipped` with the reason `env.configuration_missing`. Route convergence then writes the new `APP_URL` into the `.env` and cached configuration of the [application directory](/reference/projects#application-directory) in both cases. For a monorepo Project with root `server/web/public`, that is `server/web/.env`, never a file at the repository root. Run `env:import` to start storing the configuration.

When a step fails before the new slug is published, the Gateway restores the stored configuration, renders the file against the old Route again, and withdraws the replacement Route. After publication, a retry continues forward. See [Update lifecycle](/reference/projects#update-lifecycle).

## Inspect the projection with Doctor

[Doctor](/cli/doctor) renders the stored configuration against the Instance's Route and placement and compares it with the `.env` file. It reports a missing, unsafe, or different production file as an `instance` finding. It shows no key or value, and it does not inspect a framework cache.

## Storage and recovery

The Gateway encrypts every stored value, placeholders included, with its own application key before it writes the row. Restoring stored configuration needs that key.

Laravel import creates an application's `APP_KEY` only when the source value is empty and no non-empty stored key exists. When development provisioning creates a missing Laravel `.env`, it writes the non-empty stored key, or generates one for that file only when neither the store nor `.env.example` has a key. It does not store the generated key. Import the file to keep it in stored configuration. See [Laravel application URL](/domains/applications#laravel-application-url). Synchronization never generates, rotates, or deletes a stored application key. An import without `replace` still refuses conflicting keys, including `APP_KEY`.

## Errors

Environment operations return these codes in the Orbit error envelope. None of them contains a value.

| Code | HTTP | Cause |
| --- | --- | --- |
| `env.target_ambiguous` | 409 | The domain reaches more than one Instance. |
| `env.owner_unavailable` | 409 | The Instance is not active or not fully placed, or it lacks exactly one healthy Route. |
| `instance.placement_unavailable` | 409 | The owning Node does not have exactly one active `app-dev` or `app-prod` role. |
| `env.import_conflict` | 409 | Import without `replace` found a key that is already stored. |
| `env.import_source_missing` | 404 | The recorded source `.env` file does not exist; fix or restore the file before importing. |
| `env.configuration_invalid` | 422 | A key, value, placeholder, count, size, or Laravel `APP_URL` rule failed. |
| `env.reference_unavailable` | 409 | A placeholder is left over after rendering. |
| `env.operation_busy` | 409 | Another operation holds the Instance's lock. |
| `env.sync_unconfirmed` | 409 | The Gateway cannot confirm the write. Retry the request. |
| `env.config_cache_refresh_failed` | 502 | The file changed, but config cache preparation failed or was unconfirmed. FPM opcache was not reset. |
| `env.php_runtime_refresh_failed` | 502 | The file changed and config cache preparation completed, but FPM opcache refresh failed. |
| `env.process_restart_failed` | 502 | PHP refresh succeeded, but an opted-in Instance Process could not restart; the error reports IDs already restarted. Recover failed Processes with `process:restart <process-id>` before repeating env-sync. |
| `env.runtime_refresh_failed` | 502 | Orbit could not record or confirm the runtime refresh. No application output is returned. Retry env-sync. |
| `env.sync_would_drop_keys` | 409 | The file has keys that stored configuration lacks and Orbit never wrote. Store or import them, or remove them from the file. |
| `env.testing_tracking_unknown` | 409 | Git cannot report whether the checkout tracks `.env.testing`. `.env` is written; `.env.testing` stays unchanged. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The Gateway holds the configuration

A `.env` file on a Node holds values for one placement, such as its domain and paths. Another placement cannot rebuild it from the Gateway. So the Gateway stores the configuration, and synchronization writes it for the destination. Treating the Node's file as the source is a rejected alternative.

### Empty Laravel keys are initialized before synchronization

A fresh Laravel checkout often has an empty `APP_KEY`. Storing that empty value would make synchronization erase a key generated only on the Node, and the application could fail after deployment or cloning. Import therefore initializes an empty key in stored configuration before synchronization. It reuses an existing stored key to avoid invalidating sessions or encrypted data on repeated imports. It does not run application code or use the Gateway's own encryption key. Applications with a non-default cipher must supply their own compatible key.

### Every value is encrypted

Orbit does not classify values as secret or not. One missed classification would leave a credential in plain text. So the Gateway encrypts every value.

### Synchronization never merges local edits

A write that pulls edits from the Node would replace stored intent with changes that nobody requested. So synchronization only writes, and import is the explicit way to read a file.

### Synchronization runs no framework

Development synchronization must work before dependencies are installed and before the application runs, so it only writes files. Production FPM disables opcache timestamp validation. Writing `.env` alone cannot make a cached Laravel configuration take effect there. A changed production sync therefore rebuilds an existing config cache before resetting opcache. It skips runtime work before the first release or PHP selection, and never creates a config cache where none existed. After refresh, running Instance Processes opted into `restart_on_deploy` restart so workers also see the new environment.

### The Node role decides isolation

An operator may set `APP_ENV` to `local` or `staging` on a production Instance. If `APP_ENV` decided the layout or the Unix user, that edit would turn off release layout or user isolation. So the Node role decides them, and `APP_ENV` stays an application setting.

The Instance environment comes from its Node role.
