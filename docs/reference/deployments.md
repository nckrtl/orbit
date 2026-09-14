---
title: "Production release layout"
description: "How a production AppInstance stores deploy steps, separates releases from persistent files, deploys a branch, and rolls back retained code."
---

# Production release layout

This page tells an operator how a production AppInstance stores named deploy steps and changes its deployment branch. It describes how that AppInstance separates replaceable code from persistent environment configuration and optional SQLite data. It covers deployment of the recorded branch, rollback of retained code, and conversion of an existing flat production home. [ADR 0046](../decisions/0046-own-production-release-deployment-in-orbit.md) owns the production release and serving-layout boundary. [ADR 0073](../decisions/0073-store-deploy-steps-as-named-appinstance-records.md) owns deploy-step records and the branch update.

## Read the production home

The Gateway prepares each new production home with these paths before it publishes serving state.

| Path | Purpose |
| --- | --- |
| `releases/` | Contains retained, replaceable code releases owned by the production AppInstance. |
| `.env` | Holds the persistent environment file outside every release. |
| `database.sqlite` | Holds the optional persistent SQLite database when the operating agent configures or supplies one. Layout preparation does not create this file. |
| `current` | Selects one release through a symbolic link. It is absent until Orbit selects code. |

Each prepared release contains a `.env` symbolic link that resolves to the production home's `.env` file. Production creation stages initial source under `releases/` and leaves `current` absent. The explicit first deployment selects code later. Staged source does not become serving state merely because it exists.

Orbit keeps `database.sqlite` at the production-home path. The operating agent must configure the application to use that path. Orbit provides no database-path environment placeholder and does not infer or rewrite a stored literal database value. The [AppInstance cloning reference](appinstance-cloning.md) describes optional SQLite seeding into this path.

## Configure deploy steps

The Gateway stores each deploy step as a named record on the production AppInstance. The Gateway does not start a deployment when it stores, lists, or removes a step.

Each record uses these fields.

| Field | Type | Contract |
| --- | --- | --- |
| `name` | string | Unique within the AppInstance. 1 through 63 lowercase letters, digits, or hyphens. A name starts and ends with a letter or digit. |
| `phase` | string | `before_activation` or `after_activation`. Create defaults to `before_activation`. |
| `command` | string | Nonempty UTF-8 command of at most 16 KiB with no NUL byte. The operating agent owns this command. |
| `timeout_seconds` | integer | Timeout from 1 through 900 seconds. Create defaults to 300 seconds. |

The Gateway places an unplaced step at the end of its phase. Placement names one existing step in the same phase with `before` or `after`. The two placement fields are exclusive. A production AppInstance may own at most 32 steps. The sum of timeouts, including defaulted values, cannot exceed 3,600 seconds. The Gateway enforces those limits on every create, update, destroy, and document replacement.

The Gateway refuses a duplicate name, a placement that names an unknown step or a step in another phase, a thirty-third step, a timeout over 900 seconds, or a total over 3,600 seconds, and it stores no change. The Gateway uses the AppInstance's current Node-access authorization for every deploy-step request and refuses a non-production AppInstance. Authorized reads return commands, but the Gateway keeps command text out of Activity records, validation errors, and generic diagnostics.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deploy-steps` | Returns the step records in phase and placement order. An AppInstance with no steps returns an empty array. |
| `POST /api/v1/instances/{instance}/deploy-steps` | Creates one step. The body requires `name` and `command` and accepts `phase`, `timeout_seconds`, and exclusive `before` or `after`. |
| `PATCH /api/v1/instances/{instance}/deploy-steps/{step}` | Updates the named step. The body may change `command`, `phase`, `timeout_seconds`, and exclusive `before` or `after`. |
| `DELETE /api/v1/instances/{instance}/deploy-steps/{step}` | Destroys the named step. Remaining steps keep their relative order. |

The `{step}` path segment is the step's unique name.

## Change the deployment branch

The Gateway stores the deployment branch on the production AppInstance. The Gateway does not change deploy steps or start a deployment when it stores a branch.

| Request | Result |
| --- | --- |
| `PATCH /api/v1/instances/{instance}` | Accepts `branch`, a Git branch name accepted by Orbit's branch validator. A later App default change does not replace this instance-owned value. |

The Gateway refuses a development AppInstance with a bounded conflict before it stores a branch. The same Node-access authorization as deploy-step mutations applies.

## Replace the complete configuration

The Gateway also serves one document that reads and atomically replaces the same branch and step records. The Gateway does not start a deployment when it reads or replaces this document.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployment-config` | Returns the recorded `branch` and the step records as ordered `steps`. An existing production AppInstance with no stored steps returns an empty array and keeps its recorded branch. |
| `PUT /api/v1/instances/{instance}/deployment-config` | Atomically replaces the branch and the complete step set from the document. The request does not fetch source, run a command, or change `current`. |

The document uses the same field contracts as the deploy-step records and the branch update. A document write is visible to `instance:deploy-step:list`. A step write is visible to this document. The Gateway rejects malformed JSON, duplicate or unknown members, duplicate step names, wrong types, and values outside the step-count and timeout limits before it changes either field.

## Use the deployment API

An authorized client starts a deployment or code rollback synchronously and can inspect the retained releases before or after an interrupted request.

| Request | Input and response |
| --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | Accepts only an empty JSON object and returns deployment events as `application/x-ndjson`. |
| `POST /api/v1/instances/{instance}/rollback` | Accepts only `release`, the retained release name to select, and returns rollback events as `application/x-ndjson`. |
| `GET /api/v1/instances/{instance}/releases` | Returns the present retained release names as `releases` and the nullable current selection as `selected_release`. It returns no deployment history. |

## Use the PHP SDK

The PHP software development kit (SDK) exposes typed create, list, update, and destroy operations for deploy steps on these same routes. It also exposes an AppInstance update for the branch, document read and replace, deploy, rollback, and retained-release list. Deploy-step, branch, document, and retained-release operations keep the ordinary JSON request, envelope, error, and response transport. A step create or document replacement omits `timeout_seconds` when the caller does not supply it. A deployment sends an empty JSON object. A rollback sends only `release`. List and show reads remain bodyless.

Deploy and rollback return a closeable stream of typed phase, output, and result events. The SDK reads newline-delimited JSON (NDJSON) as the caller advances the stream and handles lines split across arbitrary HTTP chunks. Before it yields an event, it validates the event fields, encoded and decoded limits, continuous sequence, matching request identity, and base64 output encoding. It reports success only when one successful result is the final event, and it rejects malformed or truncated streams without reporting success.

The caller closes the stream when it stops before the terminal result. Closing the stream also closes the HTTP response so the Gateway can observe cancellation. The SDK does not retry the HTTP request or replay stream events. Deploy and rollback keep the connector's TLS verification and redirect policy and use bounded transport timeouts that cover the Gateway's accepted operation deadline.

Request validation and Node-access authorization finish before a deployment stream opens. A refusal at that boundary uses the ordinary JSON error envelope. After admission, each newline-delimited JSON (NDJSON) line is one event with a maximum encoded size of 32 KiB. Every event contains `type`, a monotonically increasing `sequence`, and the request's `request_id`.

| Event type | Fields |
| --- | --- |
| `phase` | `phase` identifies `source_preparation`, `environment_sync`, `before_activation`, `activation`, `php_refresh`, `after_activation`, or `rollback`. `step_name` is present only for a named `before_activation` or `after_activation` step. Other phase events omit it. |
| `output` | `stream` is `stdout` or `stderr`. `data_base64` carries at most 16 KiB of decoded bytes so arbitrary application output remains valid NDJSON. |
| `result` | `status` is `succeeded` or `failed`. `failed_step`, `error_code`, and `selected_release` are nullable. This event is the final line. |

A succeeded result includes `selected_release` and sets `failed_step` and `error_code` to null. A failed result includes `failed_step` and `error_code`; `selected_release` is null when no release remains selected.

## Use deployment commands

The CLI sends each deployment operation through the typed PHP SDK. It does not run an application command, Secure Shell (SSH) command, or deployment step on the operator's machine.

| Command | Result |
| --- | --- |
| `orbit instance:deploy-step:create INSTANCE NAME --command=COMMAND` | Records a step at the end of `before_activation`. Add `--phase`, `--timeout=SECONDS`, and exclusive `--before=NAME` or `--after=NAME` to select the phase, timeout, and placement. Add `--json` to return the stored step and its `request_id`. |
| `orbit instance:deploy-step:list INSTANCE` | Lists the steps in phase and placement order. Add `--json` to return those steps and the `request_id` as one JSON object. |
| `orbit instance:deploy-step:update INSTANCE NAME` | Changes the named step. Add `--command`, `--phase`, `--timeout=SECONDS`, and exclusive `--before=NAME` or `--after=NAME`. Add `--json` to return the stored step and its `request_id`. |
| `orbit instance:deploy-step:destroy INSTANCE NAME` | Removes the named step. Add `--json` to return the destroyed step and its `request_id`. |
| `orbit instance:update INSTANCE --branch=BRANCH` | Changes the deployment branch without changing steps. Add `--json` to return the AppInstance and its `request_id`. |
| `orbit instance:show INSTANCE` | Shows the AppInstance and prints its deploy steps in phase and placement order. Add `--json` to include those steps in the AppInstance object. |
| `orbit instance:deployment-config INSTANCE` | Shows the recorded branch and ordered steps from the same records. Add `--json` to return the same configuration and its `request_id` as one JSON object. |
| `orbit instance:deployment-config INSTANCE --file=PATH` | Reads one complete JSON configuration from `PATH` and replaces the stored branch and steps. Add `--json` to return the stored configuration and its `request_id` as one JSON object. |
| `orbit instance:deploy INSTANCE` | Starts an explicit deployment and renders phase, output, and result events as they arrive. Add `--json` to write those same events as newline-delimited JSON (NDJSON), including a failed `result`. |
| `orbit instance:rollback INSTANCE --release=NAME` | Selects one retained release and renders rollback events as they arrive. Add `--json` to write those same events as NDJSON, including a failed `result`. |
| `orbit instance:release:list INSTANCE` | Lists retained release names, the current selection, and the `request_id`. Add `--json` to return those values as one JSON object. |

The deployment configuration file uses the same `branch` and `steps` fields as the document API. It replaces the complete record set, not one step.

```json
{
    "branch": "main",
    "steps": [
        {
            "name": "migrate",
            "phase": "before_activation",
            "command": "php artisan migrate --force",
            "timeout_seconds": 300
        }
    ]
}
```

Human deploy and rollback output names each phase and named step. It labels standard output and standard error separately and escapes control bytes so application output cannot become terminal control input. Output appears while the step is still running. The final output includes the request ID and the selected release when the Gateway reports one.

Add `--json` to deploy or rollback to write newline-delimited JSON (NDJSON) without prompts, progress decoration, or other prose. The CLI writes each validated event as one compact line using the event fields in the table above. An `output` line keeps `data_base64`, so arbitrary application bytes remain valid JSON.

The JSON contract for these commands is that event stream. The CLI writes the terminal `result` event when `status` is `succeeded` and when `status` is `failed`. A failed `result` keeps `type`, `sequence`, `request_id`, `status`, `failed_step`, `error_code`, and `selected_release`. The CLI does not rewrite that event as `{"error":{"code","message","request_id"}}`. A development AppInstance that cannot supply deployment configuration therefore ends `--json` with a failed `result` whose `error_code` is `deployment_config.unavailable`.

The CLI uses the shared safe JSON error envelope as one line, and preserves the request ID when available, only for these outcomes.

| Outcome | JSON document |
| --- | --- |
| The stream ends with a `result` event | The validated NDJSON events, including a failed `result`. |
| The Gateway or CLI refuses the command before the stream opens | One object with `error.code`, `error.message`, and `error.request_id`. |
| The stream is malformed, truncated, or ends without a result | The validated events already written, then one error-envelope line. |

`instance:deploy-step:create`, `instance:deploy-step:list`, `instance:deploy-step:update`, `instance:deploy-step:destroy`, `instance:update`, `instance:show`, `instance:deployment-config`, `instance:release:list`, and `instance:prepare-deployment` write one JSON object and use that error envelope on failure.

The command exit status identifies whether the streamed operation completed successfully.

| Stream outcome | Exit status |
| --- | --- |
| The final event is a succeeded result. | Zero. |
| The final event is a failed result. | Nonzero. The command identifies the failed boundary and the selected release when the result includes one. |
| The stream is malformed, truncated, or ends without a result. | Nonzero. The command never infers success from earlier events. |
| The operator presses Ctrl-C. | Nonzero. The operating system terminates the CLI and closes its HTTP connection immediately. The CLI does not submit another deployment or rollback request. |

A connected invocation ends with exactly one `result` event. An execution failure after admission produces a failed result in the stream; the Gateway does not try to send a second HTTP error response. An unavailable deployment configuration, including a development AppInstance, is such a failed result. Application output is flushed while its command is still running, and Caddy uses a 1 millisecond flush interval so it can still cancel the FastCGI request after a client disconnects. Gateway request and proxy limits cover the accepted deployment deadline.

Before each phase event, the Gateway uses a bounded 250 millisecond probe that flushes one JSON-safe whitespace byte every 10 milliseconds. The whitespace and event form one valid NDJSON line, and every byte counts toward the 32 KiB line limit.

The Gateway detects a client disconnect when it writes an output event or performs a phase probe. A silent active command can therefore continue until it produces output, exits, or times out and the Gateway attempts the next stream write. The bounded probe gives HTTP/1.1 and HTTP/2 disconnects time to propagate through Caddy and PHP FastCGI Process Manager (PHP-FPM), but one write does not guarantee immediate detection of every downstream close.

Once the disconnect is detected, cancellation stops the next protected boundary from starting, and the Gateway waits for bounded process cleanup. It sends no success result, replays no event, and does not roll code back automatically. The client can use the releases request to inspect the current selection before deciding whether to retry or request a rollback.

Deployment and rollback require access to the AppInstance's Node. Their Activity records contain only the request and target identifiers and the terminal status, selected release, failed step, and error code. They never contain application output or configured command text. Generic errors follow the same redaction boundary.

## Deploy the configured branch

An explicit deployment captures the AppInstance's current branch and recorded steps for the complete invocation. The Gateway creates a fresh release, fetches the latest configured remote branch into it, and keeps that checkout even if the remote branch advances while the deployment runs. A deployment accepts no commit selector. A failed fetch leaves the selected release unchanged.

The Gateway synchronizes stored environment values before it runs an application command. It then runs every `before_activation` step in phase and placement order from the fresh release as the AppInstance's Unix user through a fixed non-interactive shell. Orbit transports the command as protected script content and does not add inferred setup, migration, cache, health, maintenance, dependency, or asset commands. An empty step list runs no application commands. Provisioning and cloning never start a deployment.

After every pre-activation step succeeds, the Gateway atomically replaces `current` with a link to the fresh release. A PHP AppInstance then refreshes its dedicated runtime cache and waits for confirmed completion before the Gateway runs the `after_activation` steps in phase and placement order. A non-PHP AppInstance skips the cache operation.

Each request that overlaps activation resolves to a complete old or new release. The `current` replacement and PHP cache refresh do not cause a missing-root or unavailable-service response for a compatible application. An application command can still change application availability, and Orbit retains that command's effect.

## Read output and failures

Each application command emits its standard output and standard error as events while the deployment invocation runs. One event carries bytes from exactly one stream and contains at most 16 KiB, or 16,384 bytes. The Gateway splits a larger process read into ordered events without changing its bytes. Event delivery continues until the command exits, times out, or is cancelled.

The final command result retains the latest 64 KiB, or 65,536 bytes, from standard output and the latest 64 KiB from standard error. Output at the exact limit is complete. When either stream exceeds its limit, the result discards that stream's older bytes and reports `truncated: true`. Orbit keeps events and the final result only for the invocation. It creates no deployment-run row, output history, or earlier step-configuration snapshot.

Each step uses its recorded timeout. Timeout or cancellation terminates the process group owned by that step and stops later steps. Orbit never resumes or automatically replays an interrupted command. The complete operation deadline is the accepted sum of step timeouts plus no more than 900 seconds for release, environment, activation, and runtime work.

The deployment result identifies the failed boundary and the release selected when the invocation ends. Its code-selection outcome depends on when failure occurs.

| Failed boundary | Selected code |
| --- | --- |
| Release fetch, environment synchronization, or a pre-activation step | The prior `current` target remains selected, or no release remains selected when this is the first deployment. |
| PHP cache refresh or a post-activation step | The fresh release remains selected. |

Orbit does not claim that persistent environment, database, or application effects were undone after either failure. The operating agent owns compatibility and application recovery.

## Roll back retained code

An explicit code rollback accepts one retained release name beneath this AppInstance's `releases/` directory. The Gateway verifies the release's source ownership and effective web-root containment before it atomically selects that release through `current`. A PHP AppInstance then receives the same verified dedicated cache refresh as deployment; a non-PHP AppInstance skips it.

Code rollback does not fetch Git, synchronize environment values, run deployment steps, change database files, or infer an application recovery command. The operating agent selects the retained release and owns any data or application recovery needed after the switch.

## Exclude competing mutations

Deployment and code rollback share one operation owner for the same production AppInstance. That owner also covers deploy-step create, update, and destroy, the branch update, and the configuration document replacement. It further covers deployment-layout conversion, AppInstance removal, environment import, stored environment updates, environment synchronization, and Route hostname changes. A competing request waits within the bounded operation deadline or receives a busy refusal before it can mutate that instance. An interrupted deployment releases the owner only after it has stopped its active application command.

## Convert an existing production home

Use explicit conversion for an existing production AppInstance that still serves code directly from its production home:

```text
orbit instance:prepare-deployment <instance-id>
```

The command sends `POST /api/v1/instances/{instance}/deployment-layout` through the Gateway. Use `--sqlite-source-path=PATH` only when one existing SQLite database must move to the persistent `database.sqlite` destination. The path is an explicit source selection; Orbit does not infer a database from application configuration.

The Gateway completes every preflight check before it moves a file, publishes a runtime, or changes serving state.

| Boundary | Required state |
| --- | --- |
| AppInstance | The record identifies one active production placement with complete source and runtime identity. |
| Source | The production home has a safe owned Git checkout that can move intact into one retained release. |
| Environment | The local `.env` has already been imported into Gateway-owned configuration, and its parsed values agree with the stored values. See [AppInstance environment variables](environment-variables.md#import-an-environment-file). |
| Destinations | `releases/`, `current`, `.env`, the optional `database.sqlite`, and conversion-owned temporary paths have no unsafe type, ownership, link, or content conflict. |
| PHP runtime | Existing local pool tuning can be represented in the dedicated runtime's `local.conf`, and the effective dedicated identity remains the recorded user, home, version, pool, service, socket, and document root. |
| Serving | The recorded Route, Caddy projection, PHP socket, and source path still identify this AppInstance. |
| SQLite | The selected file is safe, no owned application Process is active, no process has the file open, and no SQLite sidecar remains beside the source or destination. |

Every entry below the production home must belong to the production user and group. The document root must contain no symbolic link. For example, a Laravel operator must remove or relocate `public/storage` before conversion. A selected SQLite source must be outside the document root; move it outside the served tree and update the application configuration before conversion.

Conversion moves the existing checkout into one retained release and selects that same content through `current`. It does not fetch, reset, clean, or check out Git. It preserves tracked and ignored files, executable modes, and repository state. It keeps `.env` at the production-home path and links the retained release to it. When SQLite is selected, it moves those exact database bytes to `database.sqlite`; it does not change the schema or rewrite a stored environment value.

For PHP, conversion carries supported local pool tuning into the dedicated runtime's `local.conf`, validates the complete effective identity, and switches only this AppInstance's Caddy upstream to its dedicated socket. It does not restart, reload, or reset another production user's shared or dedicated PHP service.

The Gateway records each conversion boundary before it continues. A retry resumes file movement, persistent-state placement, runtime publication, or Route projection from the recorded state. It rechecks the retained content and serving association before each effect and reports completion only when `current`, the dedicated runtime, and the Route projection agree.

An unsupported tuning directive, unsafe destination, changed retained file, or changed serving association stops conversion with a bounded conflict. A refusal before the first recorded effect leaves the old workload unchanged. Repeating a completed request only validates and returns the completed layout; it does not replace the retained release or discard later local edits. The Gateway answers `deployment_layout.not_convertible` when the AppInstance already uses the release layout without a completed conversion, such as a clone target or a newly provisioned production AppInstance. It gives that answer before it checks the AppInstance's Schedules or Processes, and it changes nothing.

The operating agent must quiesce all application access and checkpoint or close SQLite before relocation so no `-wal`, `-shm`, or `-journal` sidecar remains. Orbit refuses active owned Processes, an observed open database, or one of those source or destination sidecars, but it does not infer maintenance mode, process shutdown, queue handling, schema migration, or another application command.

## Resolve the serving path

The effective web root is the AppInstance root override or its App root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Inspect release placement with Doctor

Doctor checks each production AppInstance against its recorded home and release layout without changing the AppInstance, its files, or its source. It reports bounded findings for a missing or wrongly owned production home, a broken `current` link, a selected release that is missing or resolves outside `releases/`, and an effective web root that escapes the selected release.

A prepared production home with no `current` link is healthy before its first deployment. Once `current` exists, Doctor requires it to select a retained directory beneath the same production home. These rules apply to standalone and Cluster-scoped AppInstances because the workload Node owns the release placement in both routing shapes.

Doctor accepts a retained release when the configured branch has advanced since that release was prepared or when an explicit rollback selected older code. It does not fetch the branch head, interpret deployment history, make an application request, or treat an HTTP error as release drift.

## Retain production content

AppInstance removal clears the owned `current` serving link and its Caddy, certificate, Route, and runtime projections. It retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf` for operator recovery.

Orbit does not remove old releases automatically. Orbit owns explicit release preparation, activation, conversion, and code rollback, while the operating agent owns configured application steps and recovery decisions. Converting an existing flat production home and executing a deployment remain separate operations.
