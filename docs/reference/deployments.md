# Production release layout

This page tells an operator how a production AppInstance separates replaceable code from persistent environment configuration and optional SQLite data, deploys its configured branch, selects retained code during rollback, and converts an existing flat production home. [ADR 0046](../decisions/0046-own-production-release-deployment-in-orbit.md) owns the production release and serving-layout boundary.

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

## Configure deployments

The Gateway stores one complete deployment configuration for each production AppInstance. Reading or replacing this configuration does not start a deployment.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployment-config` | Returns the configured `branch` and ordered `steps`. An existing production AppInstance with no stored steps returns an empty array and keeps its recorded branch. |
| `PUT /api/v1/instances/{instance}/deployment-config` | Atomically replaces the complete `branch` and `steps` configuration. The request does not fetch source, run a command, or change `current`. |

The complete request and response use these fields.

| Field | Type | Contract |
| --- | --- | --- |
| `branch` | string | Required Git branch name accepted by Orbit's branch validator. A later App default change does not replace this instance-owned value. |
| `steps` | array | Required ordered list with at most 32 entries. An empty list is valid. |
| `steps[].name` | string | Required unique name of 1 through 63 lowercase letters, digits, or hyphens. A name starts and ends with a letter or digit. |
| `steps[].phase` | string | Required `before_activation` or `after_activation`. |
| `steps[].command` | string | Required nonempty UTF-8 command of at most 16 KiB with no NUL byte. The operating agent owns this command. |
| `steps[].timeout_seconds` | integer | Optional timeout from 1 through 900 seconds. The default is 300 seconds. |

The array preserves the order of steps within each phase. The sum of configured timeouts, including defaulted values, cannot exceed 3,600 seconds.

The Gateway rejects malformed JSON, duplicate or unknown members, duplicate step names, wrong types, and values outside these limits before it changes either field. Both endpoints use the AppInstance's current Node-access authorization and refuse a non-production AppInstance. Authorized reads return commands, but the Gateway keeps command text out of Activity records, validation errors, and generic diagnostics.

## Use the deployment API

An authorized client starts a deployment or code rollback synchronously and can inspect the retained releases before or after an interrupted request.

| Request | Input and response |
| --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | Accepts only an empty JSON object and returns deployment events as `application/x-ndjson`. |
| `POST /api/v1/instances/{instance}/rollback` | Accepts only `release`, the retained release name to select, and returns rollback events as `application/x-ndjson`. |
| `GET /api/v1/instances/{instance}/releases` | Returns the present retained release names as `releases` and the nullable current selection as `selected_release`. It returns no deployment history. |

Request validation and Node-access authorization finish before a deployment stream opens. A refusal uses the ordinary JSON error envelope. After admission, each newline-delimited JSON (NDJSON) line is one event with a maximum encoded size of 32 KiB. Every event contains `type`, a monotonically increasing `sequence`, and the request's `request_id`.

| Event type | Fields |
| --- | --- |
| `phase` | `phase` identifies `source_preparation`, `environment_sync`, `before_activation`, `activation`, `php_refresh`, `after_activation`, or `rollback`. `step_name` is present only for a named `before_activation` or `after_activation` step. Other phase events omit it. |
| `output` | `stream` is `stdout` or `stderr`. `data_base64` carries at most 16 KiB of decoded bytes so arbitrary application output remains valid NDJSON. |
| `result` | `status` is `succeeded` or `failed`. `failed_step`, `error_code`, and `selected_release` are nullable. This event is the final line. |

A connected invocation ends with exactly one `result` event. An execution failure after admission produces a failed result in the stream; the Gateway does not try to send a second HTTP error response. Application output is flushed while its command is still running. Gateway request and proxy limits cover the accepted deployment deadline without response buffering.

When the client disconnects, the Gateway signals cancellation to the active invocation and waits for bounded process cleanup. It sends no success result, replays no event, and does not roll code back automatically. The client can use the releases request to inspect the current selection before deciding whether to retry or request a rollback.

Deployment and rollback require access to the AppInstance's Node. Their Activity records contain only the request and target identifiers and the terminal status, selected release, failed step, and error code. They never contain application output or configured command text. Generic errors follow the same redaction boundary.

## Deploy the configured branch

An explicit deployment captures the AppInstance's current branch and step configuration for the complete invocation. The Gateway creates a fresh release, fetches the latest configured remote branch into it, and keeps that checkout even if the remote branch advances while the deployment runs. A deployment accepts no commit selector. A failed fetch leaves the selected release unchanged.

The Gateway synchronizes stored environment values before it runs an application command. It then runs every `before_activation` step in configured order from the fresh release as the AppInstance's Unix user through a fixed non-interactive shell. Orbit transports the command as protected script content and does not add inferred setup, migration, cache, health, maintenance, dependency, or asset commands. An empty step list runs no application commands. Provisioning and cloning never start a deployment.

After every pre-activation step succeeds, the Gateway atomically replaces `current` with a link to the fresh release. A PHP AppInstance then refreshes its dedicated runtime cache and waits for confirmed completion before the Gateway runs the `after_activation` steps in order. A non-PHP AppInstance skips the cache operation.

Each request that overlaps activation resolves to a complete old or new release. The `current` replacement and PHP cache refresh do not cause a missing-root or unavailable-service response for a compatible application. An application command can still change application availability, and Orbit retains that command's effect.

## Read output and failures

Each application command emits its standard output and standard error as events while the deployment invocation runs. One event carries bytes from exactly one stream and contains at most 16 KiB, or 16,384 bytes. The Gateway splits a larger process read into ordered events without changing its bytes. Event delivery continues until the command exits, times out, or is cancelled.

The final command result retains the latest 64 KiB, or 65,536 bytes, from standard output and the latest 64 KiB from standard error. Output at the exact limit is complete. When either stream exceeds its limit, the result discards that stream's older bytes and reports `truncated: true`. Orbit keeps events and the final result only for the invocation. It creates no deployment-run row, output history, or earlier step-configuration snapshot.

Each step uses its configured timeout. Timeout or cancellation terminates the process group owned by that step and stops later steps. Orbit never resumes or automatically replays an interrupted command. The complete operation deadline is the accepted sum of step timeouts plus no more than 900 seconds for release, environment, activation, and runtime work.

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

Deployment and code rollback share one operation owner with deployment-layout conversion, AppInstance removal, environment import, stored environment updates, environment synchronization, and Route hostname changes for the same production AppInstance. A competing request waits within the bounded operation deadline or receives a busy refusal before it can mutate that instance. An interrupted deployment releases the owner only after it has stopped its active application command.

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

An unsupported tuning directive, unsafe destination, changed retained file, or changed serving association stops conversion with a bounded conflict. A refusal before the first recorded effect leaves the old workload unchanged. Repeating a completed request only validates and returns the completed layout; it does not replace the retained release or discard later local edits.

The operating agent must quiesce all application access and checkpoint or close SQLite before relocation so no `-wal`, `-shm`, or `-journal` sidecar remains. Orbit refuses active owned Processes, an observed open database, or one of those source or destination sidecars, but it does not infer maintenance mode, process shutdown, queue handling, schema migration, or another application command.

## Resolve the serving path

The effective web root is the AppInstance root override or its App root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Retain production content

AppInstance removal clears the owned `current` serving link and its Caddy, certificate, Route, and runtime projections. It retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf` for operator recovery.

Orbit does not remove old releases automatically. Orbit owns explicit release preparation, activation, conversion, and code rollback, while the operating agent owns configured application steps and recovery decisions. Converting an existing flat production home and executing a deployment remain separate operations.
