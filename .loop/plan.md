# Feature plan

Plan format: 1
Issue: ORB-220
Flow: discovery
Review verdict: PASS

## Outcome

An authorized Gateway client receives bounded deployment or rollback progress and application output as a synchronous NDJSON stream, can distinguish completion from interruption, and can inspect the current retained release state.

## Code boundaries

In:
- `apps/gateway/routes/api.php`, new deployment Form Requests under `apps/gateway/app/Http/Requests/AppInstances/`, and a thin deployment controller under `apps/gateway/app/Http/Controllers/Api/`: add synchronous deploy and rollback POST routes plus a releases GET route; accept only an empty JSON object for deploy and one validated `release` member for rollback; apply `ServingNode::InstanceOwning` authorization before stream admission; return the ordinary JSON error envelope for validation, binding, and access refusals before sending an NDJSON header or body.
- Deployment stream types and serialization under `apps/gateway/app/Domain/AppInstances/Deployment/**`, `apps/gateway/app/Data/AppInstances/**`, and the deployment controller: emit phase, output, and terminal result events with one request ID and a monotonic sequence; include `step_name` only for a named pre-activation or post-activation step and omit it from every other phase event; base64-encode arbitrary output bytes; preserve the existing 16,384-byte decoded output chunk limit; keep every encoded line at or below 32 KiB; flush each line during execution; and emit exactly one final result for a connected invocation, including post-admission failures without a second HTTP envelope.
- Existing `DeployAppInstanceAction`, `RollbackAppInstanceAction`, `DeploymentRequest`, and their domain tests: report source preparation, environment synchronization, each named pre-activation and post-activation step, activation, PHP refresh, and rollback phase boundaries through the invocation-local event sink while preserving the established ordering, selected-release outcomes, failure mapping, deadline, and lack of automatic retry or rollback.
- The HTTP stream cancellation boundary and `apps/gateway/app/Http/Middleware/RecordCommandActivity.php`: turn a client disconnect into the existing `DeploymentCancellation` signal, keep response sending and Activity completion attached to the active streamed invocation, wait for existing bounded local and remote process-group cleanup, suppress a terminal success after disconnect, and record only bounded request, target, terminal status, selected-release, failed-step, and error-code data without application output, command text, paths, or raw exceptions.
- `apps/gateway/app/Domain/AppInstances/Deployment/ProductionDeployment.php`, `apps/gateway/app/Infrastructure/AppInstances/RemoteProductionDeployment.php`, a narrow list action and response data under `apps/gateway/app/Actions/AppInstances/` and `apps/gateway/app/Data/AppInstances/`, and focused tests: inspect the production release layout through its existing ownership, repository, name, and effective-root guards; return only present retained release names and the nullable current selection; expose no run, output, or deployment-history state.
- `apps/gateway/app/Infrastructure/Gateway/GatewayCaddyConfigRenderer.php`, `GatewayFpmConfigRenderer.php`, any bounded Gateway timeout configuration they share, and `apps/gateway/tests/Feature/Infrastructure/Gateway/NativeGatewayWebConvergerTest.php`: disable response buffering for the deployment stream and allow Caddy and PHP-FPM to keep one admitted request alive for the accepted sum of step timeouts plus the 900-second infrastructure budget without weakening ordinary request validation or transport limits.
- `apps/gateway/tests/Feature/Api/AppInstanceDeploymentsTest.php`, `apps/gateway/tests/Feature/Api/DeploymentStreamTest.php`, `apps/gateway/tests/Feature/Api/CommandActivityTest.php`, and focused existing deployment action, native deployment, and Gateway web-convergence tests: cover the pre-admission JSON boundary, Node access, exact NDJSON schema and limits, ordering and flush timing, terminal behavior, execution failures, disconnect cleanup, release inspection, Activity redaction, and proxy/runtime budget.

Out:
- Asynchronous jobs stay unchanged; deployment and rollback run in the admitted HTTP request and add no queue, background worker, Agent, polling resource, or job status API.
- Reconnect and replay stay unchanged; a disconnected client receives no cursor, resume token, stored event buffer, automatic retry, or automatic code rollback.
- Persisted logs and deployment history stay unchanged; no deployment-run row, output store, event store, historical configuration snapshot, or new database migration is added.
- `packages/php-sdk/` stays unchanged; the issue adds no SDK request, response, event decoder, or release-inspection method.
- `apps/cli/` stays unchanged; the issue adds no CLI deploy, rollback, stream renderer, or release-list command.
- `apps/e2e/`, `bin/e2e-*`, and `.loop/proof/` stay unchanged; the existing discovery topology and commands supply observations without harness changes, proof fixtures, isolated proof, or proof instrumentation.
- Deployment execution policy stays unchanged: Orbit still does not infer application commands, retry a command, undo persistent effects, roll back data, or remove retained releases.

## Documentation

### Documentation audit

Scope: ORB-220; the `apps/gateway`, Gateway, and AppInstance documentation context, plus the production deployment reference required by the `docs` label.

Fixed:
- `docs/reference/deployments.md`: the page described deployment execution but omitted its HTTP boundary -> it now states the deploy, rollback, and release-inspection requests; NDJSON fields and limits, including omission of `step_name` outside named pre-activation and post-activation steps; terminal-result behavior; disconnect cancellation; no replay or automatic rollback; authorization; sanitized Activity; and the Gateway buffering and execution-budget boundary.

Audited without changes:
- `docs/architecture.md` already assigns release preparation and activation to Orbit, application command selection to the operating agent, and machine changes to the Gateway.
- `docs/concepts.md` already defines Gateway and AppInstance consistently with the authenticated target and deployment placement.
- `docs/domains/applications.md` already routes deployment details to the production release reference and keeps explicit deployment separate from provisioning and conversion.
- `docs/reference/gateway-trust.md` already requires authenticated WireGuard peers and retains Node-access authorization as a separate per-action check.
- `docs/reference/environment-variables.md` already owns protected environment synchronization and its serialization with AppInstance mutations.
- `docs/reference/php-runtime.md` already owns bounded, verified, AppInstance-specific cache refresh without a fallback service reload.
- `docs/reference/appinstance-removal.md` already agrees that interruption preserves inspectable retained production content and does not recreate a removed selection.
- `docs/generated/context.json` was rebuilt by `composer docs-build` and had no byte change.

Reported:
- none.

Verification: `composer docs-build`, `composer docs-lint`, and `git diff --check` passed; documentation commits `2d7e22614d92265fa535702d6574135708cd176e` and `8dd3a8844e4d608cadd9efcd3bbc62bbc2e51cd7`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Deploy accepts only an empty JSON object, rollback accepts only `release`, and invalid input or denied Node access returns the ordinary JSON error envelope before a stream opens. | Routes, deployment Form Requests, controller admission, model binding, and `ServingNode::InstanceOwning` authorization. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/AppInstanceDeploymentsTest.php` covers empty, missing, malformed, duplicate, unknown, and wrong-type members plus missing instance and denied caller cases, asserting JSON rather than NDJSON on every refusal. |
| 2. An admitted request returns `application/x-ndjson`; every line has `type`, increasing `sequence`, and one `request_id`; phase, output, and result events contain only their specified fields and values. | Typed deployment progress events, line serializer, request correlation, response headers, and deployment stream controller. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/DeploymentStreamTest.php` parses every line and asserts the closed event shapes, all phase values, `step_name` presence for named pre-activation and post-activation steps, `step_name` omission from every other phase event, stdout and stderr tags, base64 payloads, and terminal selected-release fields. |
| 3. Output events decode to at most 16 KiB, complete lines stay at most 32 KiB, all execution phases are visible, and output reaches the client before the command exits. | Existing process chunking, phase emission in both deployment actions, NDJSON encoding, response flushing, Gateway Caddy buffering, and PHP-FPM request budget. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/DeploymentStreamTest.php tests/Unit/Infrastructure/Processes/NativeProcessRunnerTest.php tests/Feature/Infrastructure/Gateway/NativeGatewayWebConvergerTest.php`; discovery action `deployment-http-stream` observes delayed interleaved output and every phase through the real Gateway before process exit, decodes each chunk, measures each line, and inspects the live Caddy and PHP-FPM limits. |
| 4. One result event ends a connected invocation; post-admission execution failure becomes a failed result, and a disconnected invocation makes no success claim and gains no automatic replay. | Stream lifecycle, terminal-result serializer, deployment result mapping, disconnect state, and response exception containment. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/DeploymentStreamTest.php` asserts one final result on success and failure, no bytes after it, no nested HTTP envelope, no successful terminal event after simulated disconnect, and one action invocation only. |
| 5. Disconnect cancellation reaches the active command, bounded cleanup finishes without automatic code rollback, and release inspection returns only present retained release names and the current selection with no history. | HTTP cancellation source, existing deployment cancellation/process-group cleanup, release-list action and guarded native inspection, and releases response data. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/AppInstanceDeploymentsTest.php tests/Feature/Api/DeploymentStreamTest.php tests/Feature/Infrastructure/AppInstances/ProductionDeploymentTest.php tests/Unit/Infrastructure/Processes/NativeProcessRunnerTest.php`; discovery action `deployment-http-disconnect` closes the client during a command with a child process, observes bounded cleanup and unchanged automatic selection behavior, then reads the retained names and current selection. |
| 6. Only a caller with access to the target Node receives output; Activity and generic errors retain bounded identifiers and outcomes but no output or command text; Gateway transport limits cover the bounded action. | Pre-stream Node access, stream-aware `RecordCommandActivity`, target resolution, sanitization, result projection, and Gateway Caddy/PHP-FPM configuration. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/AppInstanceDeploymentsTest.php tests/Feature/Api/CommandActivityTest.php tests/Feature/Infrastructure/Gateway/NativeGatewayWebConvergerTest.php`; discovery action `deployment-http-stream` compares an authorized stream with a denied JSON response and inspects the resulting bounded Activity and live transport configuration. |
| 7. Maintained documentation states stream fields, limits, terminal behavior, disconnects, and current-release inspection, and generated context is current. | Documentation section above. | `composer docs-build && composer docs-lint`; `git diff --exit-code 8dd3a8844e4d608cadd9efcd3bbc62bbc2e51cd7 -- docs/generated/context.json` when implementation needs no later documentation correction. |
| 8. Focused Gateway acceptance checks, the Gateway project check, and the exact clean-candidate repository gate pass. | Changed Gateway code, tests, documentation, and repository quality boundaries. | Run the focused commands above, then `cd apps/gateway && composer check`; on the committed clean candidate the retained Builder runs root `composer check`, which runs all five project checks and TIA suites and records the exact-head `result.json`. |

## Incus observations

Incus: required; flow: discovery. After independent preflight approval, acquire the standard ORB-220 discovery topology and use the existing `bin/e2e-topology shell`, `exec`, `sync`, and `verify` commands for `deployment-http-stream` and `deployment-http-disconnect`.

For `deployment-http-stream`, configure one production AppInstance with delayed, interleaved stdout and stderr, an output value above 16 KiB, and a controlled post-admission failure. Call the real Gateway with an authorized peer and an access-denied peer. Record response headers, line arrival times before process exit, every sequence and request ID, phase and optional step name, decoded output sizes and bytes, encoded line sizes, the single success or failure result, current selection, sanitized Activity, and live Caddy and PHP-FPM stream and deadline settings.

For `deployment-http-disconnect`, start a step that owns a child process, close the HTTP client after output arrives, and record bounded termination of the complete process group, absence of a success result or automatic replay, unchanged automatic code-selection behavior, the retained release names and current selection returned by the inspection endpoint, and final topology verification.

Use disposable application output and reversible step fixtures, and record exact setup, commands, identifiers, timestamps, responses, process identities, release names, selection before and after, Activity fields, cleanup, and restoration in `.loop/development.md`. Proof instrumentation and `observed_inputs` are not required. Discovery development only; isolated acceptance proof will not run.

## Implementation order

1. Add failing API tests for deploy, rollback, and release inspection that establish strict JSON inputs, route binding, target Node access, pre-admission JSON errors, NDJSON headers, and the no-history response boundary.
2. Define the minimal phase, output, and result event projections and one NDJSON encoder; bind request ID and sequence at the transport boundary, include `step_name` only for named pre-activation and post-activation steps, omit it from other phase events, base64 output bytes, enforce the 16 KiB decoded and 32 KiB encoded limits, and test exact boundary, closed-schema, ordering, and terminal cases.
3. Extend the existing invocation-local deployment request and deploy and rollback actions to report each required phase and configured step name without changing execution order, failure boundaries, selected-release behavior, deadlines, or command-output retention.
4. Add guarded retained-release enumeration to the existing production deployment contract and native adapter, then expose it through a narrow list action and response data with present names and nullable current selection only.
5. Implement the streamed controller lifecycle: construct disconnect-aware cancellation, flush output during execution, map the action's final result once, contain post-admission failures inside NDJSON, suppress success after disconnect, and never replay an invocation.
6. Make command Activity completion follow streamed response execution and project only bounded deployment outcome fields; update Caddy buffering and Caddy/PHP-FPM timeouts for the maximum accepted deployment budget; preserve ordinary endpoint behavior with focused regression tests.
7. Run the focused API, action, native adapter, process, Activity, and Gateway configuration tests, then `cd apps/gateway && composer check`; after independent plan approval, acquire discovery and record both planned observations before the exact clean-candidate Builder gate.

## Must preserve

- ADR 0046: Orbit owns production release preparation, activation, and explicit code rollback within the AppInstance's recorded home, and deployment remains an explicit request including the first deployment.
- ADR 0046: each deployment uses the configured branch in one fresh release for the complete invocation, without a caller-supplied commit, even if the remote branch advances.
- ADR 0046: persistent environment configuration and optional SQLite data remain outside releases, stored environment configuration synchronizes before application steps, and deployment or rollback never replaces production data.
- ADR 0046: the operating agent owns application command selection and order; Orbit inserts no unconfigured commands and runs configured steps as the AppInstance Unix user with bounded execution time and streamed output.
- ADR 0046: every pre-activation step succeeds before atomic selection, PHP cache refresh completes after selection and before post-activation steps, pre-switch failure leaves the old selection, and post-switch failure retains the new selection.
- ADR 0046: a failed operation reports its boundary without automatic rollback or command retry; explicit rollback only selects retained code and refreshes its runtime cache, while application and data recovery remain operator-owned.
- ADR 0046: Orbit stores no deployment run, deployment history, output log, or historical step snapshot; the HTTP stream and release inspection remain current-invocation and current-runtime views only.
- Existing Gateway invariants: active WireGuard authentication precedes per-action binary Node access; request IDs stay stable; controllers remain thin; application output, configured commands, secrets, argv, paths, and raw exceptions stay out of Activity, generic errors, logs, and debug data.
- Existing process invariants: output order and exact bytes survive chunking; timeout, cancellation, or sink failure terminates the owned local and remote process group before return; later steps do not run; one AppInstance operation owner excludes every existing competing mutation.

## Open questions

none

## Deviations

- Acceptance criterion 8 retains generic wording for all five full no-TIA CI suites. Current repository policy maps that wording to the focused acceptance tests, the changed Gateway project's `composer check`, and the retained Builder's root `composer check` with TIA on the exact clean candidate. The orchestrator should align the issue text with this policy; the acceptance outcome is unchanged and this is not a planning stop.

## Review findings
