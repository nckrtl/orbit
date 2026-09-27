---
title: "Production release layout"
description: "How a production Instance stores deploy steps, separates releases from persistent files, deploys a branch, and rolls back retained code."
covers:
  - apps/gateway/app/Domain/AppInstances/Deployment/**
  - apps/gateway/app/Actions/AppInstances/{Deploy,Rollback}AppInstanceAction.php
  - apps/gateway/app/Actions/AppInstances/{AppInstanceDeploymentConfigResolver,UpdateAppInstanceAction,ListAppInstanceReleasesAction,ListAppInstanceDeploymentsAction}.php
  - apps/gateway/app/Actions/AppInstances/*AppInstanceDeployStep*Action.php
  - apps/gateway/app/Infrastructure/AppInstances/RemoteProductionDeployment.php
  - apps/gateway/app/Http/Streaming/**
  - apps/gateway/app/Http/Controllers/Api/{AppInstanceDeploymentsController,AppInstanceDeployStepsController,AppInstanceReleasesController,AppInstanceRollbacksController}.php
  - apps/gateway/app/Models/{AppInstanceDeployment,AppInstanceDeployStep}.php
---

# Production release layout

An Instance on an `app-prod` Node serves code from a release. The Gateway deploys the Instance's branch into a fresh release, runs the Instance's deploy steps, and switches the `current` link atomically. Environment configuration and an optional SQLite database live outside the releases, so they survive each deployment. [Cloning](/reference/appinstance-cloning) creates every production Instance, and its first deployment selects the first release. [`instance`](/cli/instance#orbit-instancedeploy) lists the commands.

The `app-prod` role decides this layout. `APP_ENV` does not; see [Laravel mode](/reference/environment-variables#laravel-mode).

## The production home

Each production Instance has a home, `/home/<production-user>`, with these paths.

| Path | Purpose |
| --- | --- |
| `releases/<name>/` | One retained release: a Git checkout of the deployed branch. |
| `.env` | The environment file. Each release holds a `.env` link to it. |
| `database.sqlite` | An optional SQLite database. Orbit keeps the path but does not create the file. |
| `current` | A link to the selected release. It is absent until the first deployment. |

A clone leaves the home prepared, with no `current` link. The application must point its SQLite configuration at `<home>/database.sqlite` itself. Orbit has no placeholder for that path. [Cloning](/reference/appinstance-cloning) describes how to seed the file.

The web root is the Instance root, or else the Project root, inside `current`. A root such as `public` serves `<home>/current/public`. Caddy resolves the `current` link before it passes a script path to PHP-FPM, so a request after a switch loads its PHP files from the new release.

## Deploy steps

A deploy step is a named command that runs during a deployment. Each production Instance stores its own steps. Storing a step does not start a deployment.

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

A deployment captures the Instance's current branch and recorded steps when it starts. The Gateway fetches the latest configured remote branch into a fresh release; a deployment accepts no commit selector, and a failed fetch leaves the selected release unchanged. A branch that moves later does not change the release being prepared.

The Gateway runs these phases in order:

1. **Source preparation.** The Gateway creates a fresh release as the production user and checks out the fetched branch.
2. **Environment sync.** The Gateway writes the stored environment to `<home>/.env` on the Instance's owning Node.
3. **Before activation.** The Gateway runs each `before_activation` step in phase and placement order from the fresh release, as the Instance's Unix user through a fixed non-interactive shell. It transports each command as protected script content.
4. **Activation.** After every pre-activation step succeeds, the Gateway atomically replaces `current` with a link to the fresh release.
5. **PHP refresh.** For a PHP Instance, the Gateway refreshes its dedicated runtime cache and waits for confirmed completion. An Instance without PHP skips this operation.
6. **After activation.** The Gateway runs each `after_activation` step in phase and placement order.

The Instance's placement owns its environment, so synchronization does not require a Route. When a stored value contains `{{app_instance.domain}}`, the Gateway needs an authoritative Route domain; otherwise synchronization fails with `env.reference_unavailable`. A route-less Instance without that domain reference can deploy normally. See [environment synchronization](/reference/environment-variables#synchronize).

Provisioning and cloning never start a deployment. Orbit adds no command of its own: no migration, cache clear, dependency install, asset build, health check, or restart. An empty step list runs no application command. A request during the switch resolves to a complete old or new release. The switch and PHP cache refresh do not make a compatible application unavailable, but an application command can change its availability, and Orbit retains that command's effect.

Each application command emits standard output and standard error as events while it runs. An event carries bytes from one stream and at most 16 KiB; the Gateway splits larger process reads into ordered events without changing their bytes. The final command result retains the latest 64 KiB from each stream and marks a stream `truncated: true` when older bytes were discarded.

The Gateway records one history row for each deployment and rollback, including the branch, commit, status, failed boundary, selected release, caller, and phase/output events. It retains the most recent 50 rows per Instance, with at most 128 KiB of events per row. It does not snapshot the earlier step configuration.

Each step uses its recorded timeout. Timeout or cancellation terminates that step's process group and stops later steps; Orbit never resumes or automatically replays an interrupted command. The operation deadline is the accepted sum of step timeouts plus up to 900 seconds for release, environment, activation, and runtime work, and never exceeds the request's 570-second deadline. A stopped run reports `deployment.deadline_exceeded` when a deadline expires.

## Failures

The failed boundary decides which release stays selected. The deployment result identifies that boundary and the release selected when the invocation ends.

| Failed boundary | Selected release |
| --- | --- |
| Source preparation, environment sync, or a `before_activation` step | The earlier release, or none before the first deployment. |
| Activation | The release that `current` selects when the Gateway reads it back from the Node. When that read fails, the release selected before the deployment. |
| PHP refresh or an `after_activation` step | The new release. |

Orbit does not undo the effects of a step, environment sync, or data changes. You decide how to recover.

## Roll back

A code rollback selects one retained release beneath the Instance's `releases/` directory through `current`. The Gateway verifies release ownership and that the web root stays inside the release, then switches `current` atomically and refreshes PHP-FPM. An Instance without PHP skips the cache refresh. A rollback fetches no Git data, synchronizes no environment, runs no deployment step, changes no database file, and infers no application recovery command. You own any data or application recovery needed after the switch.

## History

The Gateway records one row for each deployment and each rollback. It writes the row when the run starts and completes it when the run ends. It keeps the last 50 rows for each Instance.

Each row holds `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by` (the calling Node's name), and `events`. `events` holds the phase and output events of the run, with at most 128 KiB of output.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployments` | The Instance's rows, newest first, without `events`. |
| `GET /api/v1/deployments/{deployment}` | One row with `events`. |

## One operation at a time

The command exit status identifies whether the streamed operation completed successfully.

| Stream outcome | Exit status |
| --- | --- |
| The final event is a succeeded result. | Zero. |
| The final event is a failed result. | Nonzero. The command identifies the failed boundary and the selected release when the result includes one. |
| The stream is malformed, truncated, or ends without a result. | Nonzero. The command never infers success from earlier events. |
| The operator presses Ctrl-C. | Nonzero. The CLI closes its HTTP connection at once and does not submit another deployment or rollback request. |

A connected invocation ends with exactly one `result` event. An execution failure after admission produces a failed result in the stream; the Gateway does not try to send a second HTTP error response. An unavailable deployment configuration, including a development Instance, is such a failed result. Application output is flushed while its command is still running, and Caddy uses a 1 millisecond flush interval so it can still cancel the FastCGI request after a client disconnects. Gateway request and proxy limits cover the accepted deployment deadline.

Ctrl-C closes the connection at once, including during a silent step with no output yet: the CLI does not wait for that step to finish first. Human output marks the current step failed and shows a red "Operation interrupted." footer; JSON mode ends the stream with no result line.

Before each phase event, the Gateway uses a bounded 250 millisecond probe that flushes one JSON-safe whitespace byte every 10 milliseconds. The whitespace and event form one valid NDJSON line, and every byte counts toward the 32 KiB line limit.

The Gateway detects a client disconnect when it writes an output event or performs a phase probe. A silent active command can therefore continue until it produces output, exits, or times out and the Gateway attempts the next stream write. The bounded probe gives HTTP/1.1 and HTTP/2 disconnects time to propagate through Caddy and PHP FastCGI Process Manager (PHP-FPM), but one write does not guarantee immediate detection of every downstream close.

Once the disconnect is detected, cancellation stops the next protected boundary from starting, and the Gateway waits for bounded process cleanup. It sends no success result, replays no event, and does not roll code back automatically. The client can use the releases request to inspect the current selection before deciding whether to retry or request a rollback.

Deployment and rollback require access to the Instance's Node. Their Activity records contain only the request and target identifiers and the terminal status, selected release, failed step, and error code. They never contain application output or configured command text. Generic errors follow the same redaction boundary.

## Exclude competing mutations

Deployment and code rollback share one operation owner for the same production Instance. That owner also covers deploy-step create, update, and destroy, and the branch update. It further covers Instance removal, environment import, stored environment updates, environment synchronization, and Route domain changes. A competing request waits within the bounded operation deadline or receives a busy refusal before it can mutate that instance. An interrupted deployment releases the owner only after it has stopped its active application command.

## Produce the release layout

Cloning is the only way the Gateway creates a production Instance. The clone result is a prepared home with no selected `current` release. The first explicit deployment fetches the configured branch, synchronizes stored environment values, runs recorded deploy steps, and selects that release. See [Instance cloning](/reference/appinstance-cloning).

## Project updates

A Project update does not deploy, change `deployment_branch`, replace production source, or select a different release. Production Instances keep their recorded initial branch, starting commit, checkout path, production home, and deployment ownership. When the Project web root changes, production continues to resolve that root inside the already selected `current` release.

## Resolve the serving path

The web root is the Instance root override or its Project root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Inspect release placement with Doctor

[Doctor](/cli/doctor) checks each production Instance against its home. It reports a missing or wrongly owned home, a broken `current` link, a selected release outside `releases/`, and a web root that leaves the release. A home without `current` is healthy before the first deployment. Doctor accepts an older release after a rollback, and a release whose branch has moved on.

## Retained content

Orbit never deletes an old release by itself. [Instance removal](/reference/appinstance-removal) removes `current` and the serving setup, and keeps `releases/`, `.env`, `database.sqlite`, and the local PHP-FPM tuning for recovery.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Orbit owns releases, you own the steps

Release preparation, the switch, and the PHP-FPM refresh are the same for every application, so Orbit does them. The commands before and after the switch differ for each application, so each Instance stores its own. Orbit does not guess steps from the framework.

### Deploy a branch, not a commit

The operation is "deploy what the branch holds now". So a deployment takes no commit. Two deployments of one branch can produce different code.

### Rollback selects code only

Older code can need a data recovery that only the application knows. So a rollback switches code and nothing else. Coupling it to a database rollback is a rejected alternative.

### Steps as named records

Each step has its own create, update, and destroy, so you can change one step without resending the others. A single replace-all document and a repeatable step flag are rejected alternatives. A command with spaces, colons, or quotes breaks a flag separator.

### Clone is the only way in

Every production Instance comes from a clone, and its first deployment builds the release layout. Orbit does not convert a production home that serves code from its checkout.
