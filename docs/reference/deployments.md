---
title: "Production release layout"
description: "How a production App instance stores deploy steps, separates releases from persistent files, deploys a branch, and rolls back retained code."
---

# Production release layout

This page tells an operator how a production App instance stores named deploy steps and changes its deployment branch. It describes how that App instance separates replaceable code from persistent environment configuration and optional SQLite data. It covers deployment of the recorded branch and rollback of retained code. Cloning produces each production App instance, and the first deployment produces the release layout. [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit) owns the production release and serving-layout boundary. [ADR 0073](/decisions/0073-store-deploy-steps-as-named-appinstance-records) owns deploy-step records and the branch update.

Removing a deploy step requires consent. The prompt names the App instance and step and defaults to No; `--yes` confirms without prompting. JSON and noninteractive calls require `--yes`. Read commands show complete tables with explicit empty results; create and update commands show the recorded step details.

## Read the production home

The Gateway prepares each new production home with these paths before it publishes serving state.

| Path | Purpose |
| --- | --- |
| `releases/` | Contains retained, replaceable code releases owned by the production App instance. |
| `.env` | Holds the persistent environment file outside every release. |
| `database.sqlite` | Holds the optional persistent SQLite database when the operating agent configures or supplies one. Layout preparation does not create this file. |
| `current` | Selects one release through a symbolic link. It is absent until Orbit selects code. |

Each prepared release contains a `.env` symbolic link that resolves to the production home's `.env` file. Production creation stages initial source under `releases/` and leaves `current` absent. The explicit first deployment selects code later. Staged source does not become serving state merely because it exists.

Orbit keeps `database.sqlite` at the production-home path. The operating agent must configure the application to use that path. Orbit provides no database-path environment placeholder and does not infer or rewrite a stored literal database value. The [App instance cloning reference](/reference/appinstance-cloning) describes optional SQLite seeding into this path.

## Configure deploy steps

The Gateway stores each deploy step as a named record on the production App instance. The Gateway does not start a deployment when it stores, lists, or removes a step.

Each record uses these fields.

| Field | Type | Contract |
| --- | --- | --- |
| `name` | string | Unique within the App instance. 1 through 63 lowercase letters, digits, or hyphens. A name starts and ends with a letter or digit. |
| `phase` | string | `before_activation` or `after_activation`. Create defaults to `before_activation`. |
| `command` | string | Nonempty UTF-8 command of at most 16 KiB with no NUL byte. The operating agent owns this command. |
| `timeout_seconds` | integer | Timeout from 1 through 900 seconds. Create defaults to 300 seconds. |

The Gateway places an unplaced step at the end of its phase. Placement names one existing step in the same phase with `before` or `after`. The two placement fields are exclusive. A production App instance may own at most 32 steps. The sum of timeouts, including defaulted values, cannot exceed 3,600 seconds. The Gateway enforces those limits on every create, update, and destroy.

The Gateway refuses a duplicate name, a placement that names an unknown step or a step in another phase, a thirty-third step, a timeout over 900 seconds, or a total over 3,600 seconds, and it stores no change. The Gateway uses the App instance's current Node-access authorization for every deploy-step request and refuses a non-production App instance. Authorized reads return commands, but the Gateway keeps command text out of Activity records, validation errors, and generic diagnostics.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deploy-steps` | Returns the step records in phase and placement order. An App instance with no steps returns an empty array. |
| `POST /api/v1/instances/{instance}/deploy-steps` | Creates one step. The body requires `name` and `command` and accepts `phase`, `timeout_seconds`, and exclusive `before` or `after`. |
| `PATCH /api/v1/instances/{instance}/deploy-steps/{step}` | Updates the named step. The body may change `command`, `phase`, `timeout_seconds`, and exclusive `before` or `after`. |
| `DELETE /api/v1/instances/{instance}/deploy-steps/{step}` | Destroys the named step. Remaining steps keep their relative order. |

The `{step}` path segment is the step's unique name.

## Change the deployment branch

The Gateway stores the deployment branch on the production App instance. The Gateway does not change deploy steps or start a deployment when it stores a branch.

The CLI's human result shows the accepted deployment branch alongside the selected source branch. The JSON App instance response keeps `selected_branch` as the source branch; it does not expose the stored deployment setting.

| Request | Result |
| --- | --- |
| `PATCH /api/v1/instances/{instance}` | Accepts `branch`, a Git branch name accepted by Orbit's branch validator. A later App default change does not replace this instance-owned value. |

The Gateway refuses a development App instance with a bounded conflict before it stores a branch. The same Node-access authorization as deploy-step mutations applies.

## Use the deployment API

An authorized client starts a deployment or code rollback synchronously and can inspect the retained releases before or after an interrupted request.

| Request | Input and response |
| --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | Accepts only an empty JSON object and returns deployment events as `application/x-ndjson`. |
| `POST /api/v1/instances/{instance}/rollback` | Accepts only `release`, the retained release name to select, and returns rollback events as `application/x-ndjson`. |
| `GET /api/v1/instances/{instance}/releases` | Returns the present retained release names as `releases` and the nullable current selection as `selected_release`. It returns no deployment history. |

## Use the PHP SDK

The PHP software development kit (SDK) exposes typed create, list, update, and destroy operations for deploy steps on these same routes. It also exposes an App instance update for the branch, deploy, rollback, and retained-release list. Deploy-step, branch, and retained-release operations keep the ordinary JSON request, envelope, error, and response transport. A step create omits `timeout_seconds` when the caller does not supply it. A deployment sends an empty JSON object. A rollback sends only `release`. List and show reads remain bodyless.

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
| `orbit instance:update INSTANCE --branch=BRANCH` | Changes the deployment branch without changing steps. Add `--json` to return the App instance and its `request_id`. |
| `orbit instance:show INSTANCE` | Shows the App instance and prints its deploy steps in phase and placement order. Add `--json` to include those steps in the App instance object. |
| `orbit instance:deploy INSTANCE` | Starts an explicit deployment and renders phase, output, and result events as they arrive. Add `--json` to write those same events as newline-delimited JSON (NDJSON), including a failed `result`. |
| `orbit instance:rollback INSTANCE --release=NAME` | Selects one retained release and renders rollback events as they arrive. Add `--json` to write those same events as NDJSON, including a failed `result`. |
| `orbit instance:release:list INSTANCE` | Lists retained release names, the current selection, and the `request_id`. Add `--json` to return those values as one JSON object. |

Human deploy and rollback output shows a progress tree. The phases the Gateway always sends appear up front; named deploy steps and the PHP cache refresh phase reveal only when their phase starts. Glyphs and color show waiting, running, success, failure, and not-reached states, and active indicators alternate while work is in progress. Standard output and standard error from application commands appear labeled and escaped, and print above the tree without redrawing it, while their step runs, so application output cannot become terminal control input.

On failure the tree marks the last reached step as failed, with the error code shown under it, even when the Gateway's failed boundary has no step of its own (an activation or cache-refresh failure during a rollback, for example). Every later step shows as not reached, and the footer turns red. The final lines add the failed boundary, the error code, the selected release when available, and the request ID.

Add `--json` to deploy or rollback to write newline-delimited JSON (NDJSON) without prompts, progress decoration, or other prose. The CLI writes each validated event as one compact line using the event fields in the table above. An `output` line keeps `data_base64`, so arbitrary application bytes remain valid JSON.

The JSON contract for these commands is that event stream. The CLI writes the terminal `result` event when `status` is `succeeded` and when `status` is `failed`. A failed `result` keeps `type`, `sequence`, `request_id`, `status`, `failed_step`, `error_code`, and `selected_release`. The CLI does not rewrite that event as `{"error":{"code","message","request_id"}}`. A development App instance that cannot supply deployment configuration therefore ends `--json` with a failed `result` whose `error_code` is `deployment_config.unavailable`.

The CLI uses the shared safe JSON error envelope as one line, and preserves the request ID when available, only for these outcomes.

| Outcome | JSON document |
| --- | --- |
| The stream ends with a `result` event | The validated NDJSON events, including a failed `result`. |
| The Gateway or CLI refuses the command before the stream opens | One object with `error.code`, `error.message`, and `error.request_id`. |
| The stream is malformed, truncated, or ends without a result | The validated events already written, then one error-envelope line. |

`instance:deploy-step:create`, `instance:deploy-step:list`, `instance:deploy-step:update`, `instance:deploy-step:destroy`, `instance:update`, `instance:show`, and `instance:release:list` write one JSON object and use that error envelope on failure.

The command exit status identifies whether the streamed operation completed successfully.

| Stream outcome | Exit status |
| --- | --- |
| The final event is a succeeded result. | Zero. |
| The final event is a failed result. | Nonzero. The command identifies the failed boundary and the selected release when the result includes one. |
| The stream is malformed, truncated, or ends without a result. | Nonzero. The command never infers success from earlier events. |
| The operator presses Ctrl-C. | Nonzero. The CLI closes its HTTP connection at once and does not submit another deployment or rollback request. |

A connected invocation ends with exactly one `result` event. An execution failure after admission produces a failed result in the stream; the Gateway does not try to send a second HTTP error response. An unavailable deployment configuration, including a development App instance, is such a failed result. Application output is flushed while its command is still running, and Caddy uses a 1 millisecond flush interval so it can still cancel the FastCGI request after a client disconnects. Gateway request and proxy limits cover the accepted deployment deadline.

Ctrl-C closes the connection at once, including during a silent step with no output yet: the CLI does not wait for that step to finish first. Human output marks the current step failed and shows a red "Operation interrupted." footer; JSON mode ends the stream with no result line.

Before each phase event, the Gateway uses a bounded 250 millisecond probe that flushes one JSON-safe whitespace byte every 10 milliseconds. The whitespace and event form one valid NDJSON line, and every byte counts toward the 32 KiB line limit.

The Gateway detects a client disconnect when it writes an output event or performs a phase probe. A silent active command can therefore continue until it produces output, exits, or times out and the Gateway attempts the next stream write. The bounded probe gives HTTP/1.1 and HTTP/2 disconnects time to propagate through Caddy and PHP FastCGI Process Manager (PHP-FPM), but one write does not guarantee immediate detection of every downstream close.

Once the disconnect is detected, cancellation stops the next protected boundary from starting, and the Gateway waits for bounded process cleanup. It sends no success result, replays no event, and does not roll code back automatically. The client can use the releases request to inspect the current selection before deciding whether to retry or request a rollback.

Deployment and rollback require access to the App instance's Node. Their Activity records contain only the request and target identifiers and the terminal status, selected release, failed step, and error code. They never contain application output or configured command text. Generic errors follow the same redaction boundary.

## Deploy the configured branch

An explicit deployment captures the App instance's current branch and recorded steps for the complete invocation. The Gateway creates a fresh release, fetches the latest configured remote branch into it, and keeps that checkout even if the remote branch advances while the deployment runs. A deployment accepts no commit selector. A failed fetch leaves the selected release unchanged.

The Gateway synchronizes stored environment values before it runs an application command. It then runs every `before_activation` step in phase and placement order from the fresh release as the App instance's Unix user through a fixed non-interactive shell. Orbit transports the command as protected script content and does not add inferred setup, migration, cache, health, maintenance, dependency, or asset commands. An empty step list runs no application commands. Provisioning and cloning never start a deployment.

After every pre-activation step succeeds, the Gateway atomically replaces `current` with a link to the fresh release. A PHP App instance then refreshes its dedicated runtime cache and waits for confirmed completion before the Gateway runs the `after_activation` steps in phase and placement order. An App instance without PHP skips the cache operation.

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

Code rollback accepts one retained release name beneath the App instance's `releases/` directory. The Gateway verifies source ownership and checks that the web root stays inside the release, then atomically selects it through `current`. PHP instances receive the same verified cache refresh as deployment. Instances without PHP skip it.

Code rollback does not fetch Git, synchronize environment values, run deployment steps, change database files, or infer an application recovery command. The operating agent selects the retained release and owns any data or application recovery needed after the switch.

## Exclude competing mutations

Deployment and code rollback share one operation owner for the same production App instance. That owner also covers deploy-step create, update, and destroy, and the branch update. It further covers App instance removal, environment import, stored environment updates, environment synchronization, and Route domain changes. A competing request waits within the bounded operation deadline or receives a busy refusal before it can mutate that instance. An interrupted deployment releases the owner only after it has stopped its active application command.

## Produce the release layout

Cloning is the only way the Gateway creates a production App instance. The clone result is a prepared home with no selected `current` release. The first explicit deployment fetches the configured branch, synchronizes stored environment values, runs recorded deploy steps, and selects that release. See [App instance cloning](/reference/appinstance-cloning).

## App updates

An App update does not deploy, change `deployment_branch`, replace production source, or select a different release. Production App instances keep their recorded initial branch, starting commit, checkout path, production home, and deployment ownership. When the App web root changes, production continues to resolve that root inside the already selected `current` release.

## Resolve the serving path

The web root is the App instance root override or its App root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Inspect release placement with Doctor

Doctor checks each production App instance against its recorded home and release layout without changing the App instance, its files, or its source. It reports bounded findings for a missing or wrongly owned production home, a broken `current` link, a selected release that is missing or resolves outside `releases/`, and an web root that escapes the selected release.

A prepared production home with no `current` link is healthy before its first deployment. Once `current` exists, Doctor requires it to select a retained directory beneath the same production home. These rules apply to standalone and Cluster-scoped App instances because the workload Node owns the release placement in both routing shapes.

Doctor accepts a retained release when the configured branch has advanced since that release was prepared or when an explicit rollback selected older code. It does not fetch the branch head, interpret deployment history, make an application request, or treat an HTTP error as release drift.

## Retain production content

App instance removal clears the owned `current` serving link and its Caddy, certificate, Route, and runtime projections. It retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf` for operator recovery.

Orbit does not remove old releases automatically. Orbit owns explicit release preparation, activation, and code rollback, while the operating agent owns configured application steps and recovery decisions.
