# ORB-220 development record

Flow: `discovery`

Incus: required. Independent plan review passed for artifact `8c59e1a4eacdc5547e0da660161a905947de6680` before discovery attempt `6df5f1f1c246c16e997b8da8c3d78969` was acquired. The topology remains available for reviewer inspection. Discovery development only; isolated acceptance proof not run.

## Candidate scope

The Gateway now admits synchronous deploy and rollback requests through strict Form Requests and `ServingNode::InstanceOwning` authorization. An admitted request emits bounded NDJSON phase, output, and terminal result events. Output bytes are base64 encoded, one decoded output event is at most 16,384 bytes, one encoded line is at most 32 KiB, and `step_name` is present only for named pre-activation and post-activation steps.

Disconnect state feeds the existing invocation-local cancellation boundary. The Gateway completes bounded local and remote process-group cleanup, records the final deployment projection in Activity, and does not emit a terminal result after the client leaves. Release inspection validates the existing production layout and returns only present retained names and the nullable current selection. Caddy streaming and Gateway PHP-FPM execution limits cover the accepted maximum deployment deadline of 4,500 seconds.

The candidate adds no asynchronous work, replay, stored output, deployment history, migration, automatic retry or rollback, SDK or CLI API, E2E harness change, or proof fixture.

## Discovery fixture

- AppInstance: `2`; owning Node: `app-prod` (`3`); production user: `orbit-app-1`; home: `/home/orbit-app-1`
- Application: the existing Laravel sample; configured branch: `13.x`; effective web root: `public`
- Route: `e2e-prod.orbit`; initial release: `/home/orbit-app-1/releases/initial`
- Stored environment: `APP_ENV=production`
- Stream steps: named `stream-boundary` before activation and named `after-boundary` after activation
- The before step emits stdout and stderr, writes 20,000 stdout bytes, waits three seconds, then emits late stdout. The after step emits stdout and stderr.

The snapshot needed the two current release-layout migrations before this fixture could use the current Gateway schema. The AppInstance was finalized to the active release layout for discovery. Its dedicated PHP 8.5 runtime was converged with the product renderer and convergence script. The snapshot package-source preparation path failed even though PHP 8.5 was already installed, so only that package-source setup was bypassed for this disposable fixture. No repository or harness code was changed for setup.

The live Gateway was converged from the original candidate renderers. Before review correction, the active Caddy JSON contained `flush_interval: -1`, `read_timeout: 4500000000000`, and `write_timeout: 4500000000000` nanoseconds. The installed Gateway pool contained `request_terminate_timeout = 4500s` and `php_admin_value[max_execution_time] = 4500`.

## Incus observations

### `deployment-http-stream`

An app-prod request for AppInstance `2` was refused before streaming with HTTP 403, `application/json`, and `node_access.required`. Caller and serving Node were both Node `3`, which has no self-access edge. No NDJSON response opened.

An authorized app-dev request passed through the real Caddy and PHP-FPM boundary with request ID `572d6b3d-0233-49c9-bdc7-f5493f4c05b4`. It returned HTTP/2 200, `content-type: application/x-ndjson`, `x-accel-buffering: no`, and the same request ID in every event and response header. Sequence values were exactly 1 through 14. Compact arrival observations were:

- `548 ms`: `source_preparation`; line 110 bytes
- `2775 ms`: `environment_sync`; line 108 bytes
- `3041 ms`: named `before_activation` / `stream-boundary`; line 139 bytes
- `3217 ms`: stdout 13 decoded bytes; line 137 bytes
- `3217 ms`: stderr 13 decoded bytes; line 137 bytes
- `3482 ms`: stdout 16,384 decoded bytes; line 21,965 bytes
- `3482 ms`: stdout 3,616 decoded bytes; line 4,941 bytes
- `6515 ms`: late stdout 13 decoded bytes; line 137 bytes
- `6536 ms`: `activation`; line 102 bytes
- `6791 ms`: `php_refresh`; line 104 bytes
- `7006 ms`: named `after_activation` / `after-boundary`; line 138 bytes
- `7168 ms`: stdout 13 decoded bytes; line 138 bytes
- `7168 ms`: stderr 13 decoded bytes; line 138 bytes
- `7190 ms`: one terminal success; line 194 bytes; selected release `20260911113254-cad2478678daa750`

The early application output arrived about 3.3 seconds before late output and about 4.0 seconds before the result. The largest output event was exactly 16,384 decoded bytes, and the largest complete encoded line was 21,965 bytes. Output bytes, streams, phase order, optional step names, sequence, and request ID all parsed successfully.

Activity `1210` completed as `succeeded` after 6,788 ms. It stored only method, path, empty input, source-layout metadata, and the bounded deployment projection: status `succeeded`, selected release `20260911113254-cad2478678daa750`, and null failed-step and error-code fields. It contained no output, base64 payload, configured command, argv, release path, or raw exception.

A separate controlled pre-activation failure used request ID `c8702eb9-b83f-4962-bd9a-6b0d46a5a1d9`. It returned one NDJSON stream with sequences 1 through 6, both stdout and stderr, and exactly one final result: status `failed`, failed step `before_activation`, error `deployment.step_failed`, and unchanged selection `20260911113254-cad2478678daa750`. Activity `1221` stored the same bounded failure projection and no application output or command text.

### `deployment-http-disconnect`

The selection before interruption was `20260911113254-cad2478678daa750`. The disposable before-activation command wrote its shell PID `6956` and long-running child PID `6957`, then emitted an initial line and heartbeats. The app-dev client received phases 1 through 3 and stdout event 4 for request `12f6c7b3-bbd0-4767-847c-424fec0b86b3`, then was terminated about 4.7 ms after first output was observed. Its response contained no result event.

The first live cleanup attempt exposed that PHP-FPM provides `posix_kill()` without the `pcntl` signal constants. The process runner therefore now uses fixed POSIX signal numbers 15 and 9 instead of extension-defined names. After the correction and topology synchronization, the real disconnect terminated both PID `6956` and PID `6957` within the four-second observation window. No matching process remained.

Exactly one Activity exists for the disconnected request. Activity `1218` completed after 5,919 ms with status `failed`, error `deployment.cancelled`, failed step `before_activation`, and selected release `20260911113254-cad2478678daa750`. The request was not replayed and emitted no success result. `GET /api/v1/instances/2/releases` then returned only current runtime state: retained releases `20260911112649-da4983c364192c4c`, `20260911112837-77716f2d64468457`, `20260911113143-c7da490f6b34e5ad`, `20260911113254-cad2478678daa750`, interrupted release `20260911113526-35787fa0c51cd537`, and `initial`; the current selection remained `20260911113254-cad2478678daa750`. This showed retained preparation without automatic code rollback or history output.

The stream configuration was restored after the interruption and failure observations. Disposable PID and client-output files were removed. Final topology verification passed for attempt `6df5f1f1c246c16e997b8da8c3d78969`.

### Review corrections

The three blocking review cases were repeated through the real Gateway after their corrections.

- Arbitrary binary output: request `22000000-0000-4000-8000-000000000008` emitted exactly 16,384 bytes, all `0xFF`, in one output event. The complete output line was 21,965 bytes. The seven-event stream parsed as NDJSON and ended with `status: succeeded`, selecting release `20260911122423-3cbe9cc122e97714`.
- Silent disconnect: request `22000000-0000-4000-8000-000000000011` entered the named `silent-boundary` step with shell PID `20024` and child PID `20025`. The client was terminated while both processes were active and after receiving only source-preparation, environment-sync, and named pre-activation phase events. The silent command was allowed to reach its next stream boundary, as documented. Activity then completed as `failed`, with `deployment.cancelled`, failure boundary `activation`, and selected release `20260911122622-2596f0770ec0a22d`, which was also the selection before the request. Neither process remained, the response contained no terminal result, and no fresh release was activated.
- Partial release inspection: a disposable `/home/orbit-app-1/releases/orb220-partial-review/.git` directory was present beside valid releases. The releases endpoint returned HTTP/2 200, kept selected release `20260911122005-d331bc404b5b306d`, returned 14 valid retained releases, and omitted `orb220-partial-review`. The directory was then removed.

The silent-disconnect observation also exposed a proxy interaction behind the review finding. Caddy documents that negative `flush_interval` mode keeps a backend request alive after the client leaves. The corrected renderer uses a 1 millisecond positive interval. The first correction used three flushed fragments over 20 milliseconds. It detected the disconnect at the activation boundary for the HTTP/2 discovery client, but the next independent review showed that an HTTP/1.1 disconnect did not reach PHP until a fifth backend write.

The original stream-step configuration was restored, the disposable partial directory and PID files were removed, and the live Caddy fragment was left on the corrected 1 millisecond interval for reviewer inspection.

### Bounded HTTP/1.1 phase probe

The resolver-authorized correction replaced the three-fragment probe with 25 JSON-safe whitespace writes over a bounded 250 millisecond window. Each write is flushed 10 milliseconds apart and checked for a propagated disconnect. The phase object completes the same valid NDJSON line only when the connection remains live. The probe bytes count toward the 32 KiB line limit. The deploy action checks cancellation again before the protected boundary starts.

Request `22000000-0000-4000-8000-000000000013` used `curl --http1.1` through the real Gateway Caddy and PHP-FPM boundary. The selection before the request was `20260911191012-95d7f8420e98fbb4`. The client received HTTP/1.1 200 and three complete phase lines for source preparation, environment synchronization, and the named `silent-boundary` pre-activation step. Each line had exactly 25 leading whitespace probe bytes and parsed as JSON. The response was 435 bytes and contained no terminal result.

The silent command wrote shell PID `28739` and child PID `28740`. The client was terminated 206 milliseconds after the named pre-activation phase was observed and exited 4 milliseconds later. Both processes remained active during the documented silent-command interval. After the command reached its next stream boundary, Activity `1265` completed once after 34,911 milliseconds with status `failed`, error `deployment.cancelled`, failed step `activation`, and selected release `20260911191012-95d7f8420e98fbb4`. The selection was unchanged, so activation did not run. Both PIDs were then absent, which confirmed bounded process-group cleanup. No result event was sent and the request was not replayed.

A preliminary request `22000000-0000-4000-8000-000000000012` terminated the host-side topology command instead of the guest curl process. The guest client remained connected and the deployment succeeded, so that attempt was excluded from disconnect evidence.

Request `22000000-0000-4000-8000-000000000014` rechecked the normal stream with `curl --http2`. All 14 lines parsed as NDJSON with 25 whitespace probe bytes on each phase line. It returned HTTP/2 200, delivered the first application output at 4,294 milliseconds and late output at 7,727 milliseconds, and ended with one successful result at 9,285 milliseconds. The 16,384-byte output event remained one 21,966-byte complete line including its newline and the longer phase probes preserved live delivery.

The original two stream steps were restored. Disposable PID, response, header, timing, and summary files were removed. The active Caddy configuration still uses `flush_interval 1ms`, and final topology verification passed for attempt `6df5f1f1c246c16e997b8da8c3d78969`.

## Local checks

- Focused Gateway acceptance selection before the live correction: 91 tests and 1,058 assertions passed.
- Complete `NativeProcessRunnerTest.php` after the FPM signal correction: 11 tests and 44 assertions passed.
- Final corrected focused API, Activity, deployment, release-inspection, process, Gateway configuration, and route-scope selection: 94 tests and 1,070 assertions passed.
- `cd apps/gateway && composer check`: guidance, Rector, Pint, and PHPStan passed with no changes or errors.
- `composer docs-build` and `composer docs-lint`: passed; generated context remained byte-identical to planning HEAD `8dd3a8844e4d608cadd9efcd3bbc62bbc2e51cd7`.
- `git diff --check`: passed.
- Resolver-correction focused regression: 2 tests and 54 assertions passed.
- Resolver-correction Gateway TIA: 16 tests and 129 assertions passed across `AppInstanceDeploymentsTest.php` and `DeploymentStreamTest.php`.
- Resolver-correction `cd apps/gateway && composer check`: guidance, 12 tests and 267 assertions, Rector, Pint, and PHPStan passed.
- Resolver-correction `composer docs-build` and `composer docs-lint`: passed; generated context remained unchanged.
- `bin/e2e-topology sync ORB-220` and `bin/e2e-topology verify ORB-220`: passed for retained discovery attempt `6df5f1f1c246c16e997b8da8c3d78969` at the clean candidate.
- The prior correction's root Builder gate passed on candidate `467516774482d554c4a1597dd7d9e8653f027cca`, tree `515832cfd9debb4837028e965d03eded2be0bc48`, with `passed: true` and `unchanged: true`. All five strict validations, project checks, and TIA commands exited zero. Gateway TIA selected six affected files: 52 tests and 387 assertions passed; the other four projects had no affected tests. Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/467516774482d554c4a1597dd7d9e8653f027cca/review-wcuo4v2u/result.json`.
- The resolver correction's root Builder gate passed on exact clean candidate `6250160ee3a513a879fe5d3a2e70a5754a8b27ca`, tree `4fe454f795fa16f15c92f19c0794eafd8f98a516`, with `passed: true` and `unchanged: true`. Every strict validation, project check, and TIA command exited zero. Project checks passed 13 CLI tests with 255 assertions, 1 Docs test with 4 assertions, 12 Gateway tests with 267 assertions, 4 E2E tests with 22 assertions, and 7 PHP SDK tests with 93 assertions. The exact-candidate TIA commands found no remaining affected tests after the focused Gateway selection. Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/6250160ee3a513a879fe5d3a2e70a5754a8b27ca/review-u1c2przf/result.json`.

## Documentation

Changed by planning and carried in this candidate:

- `docs/reference/deployments.md` documents the deploy, rollback, and release-inspection HTTP contracts; NDJSON fields and limits; terminal behavior; disconnect cancellation; authorization; sanitized Activity; and Gateway transport limits. Its corrected `step_name` contract matches the implementation.

The plan's audit found no other documentation drift. `docs/generated/context.json` remained unchanged after the planning documentation build.

## Deviations and limitations

- Acceptance item 8 retains stale generic wording for five full no-TIA CI suites. Current policy maps this to focused acceptance tests, the Gateway project check, and the retained Builder's root `composer check` with TIA on the exact clean candidate. The product outcome is unchanged.
- The FPM-only missing-signal-constant defect was found by the required disconnect observation and corrected within the existing process-group cleanup boundary. It adds no new behavior or scope.
- The reviewed proxy configuration used Caddy's negative low-latency mode. Caddy deliberately does not cancel backend requests in that mode, so the corrected candidate uses a 1 millisecond interval and a bounded 250 millisecond phase-line disconnect probe. This adds up to 250 milliseconds before each phase-protected action. It preserves live delivery while giving measured HTTP/1.1 and HTTP/2 disconnects time to reach PHP before the next protected boundary, but it cannot make network-failure detection instantaneous.
- The discovery topology uses disposable fixture state and remains available for review. It is development evidence, not isolated proof.
