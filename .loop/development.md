# ORB-217 development record

Flow: discovery
Incus: required
Discovery attempt: `122234b075ce356529ecd08304730d0f`
Starting candidate: `03384ad961d433b49aa9471d356ab050a41aca95`
Candidate: `c256dc1d1f543d876491064f9582f3d3a2a2d1c1`
Builder gate: passed (`/home/nckrtl/orbit/.git/orbit-checks/c256dc1d1f543d876491064f9582f3d3a2a2d1c1/review-bf0ybb2q/result.json`)

Discovery was acquired only after independent preflight approval. It remains active for independent review. This is discovery development evidence, not isolated acceptance proof.

## Implementation

- Added the authorized `POST /api/v1/instances/{instance}/deployment-layout` Gateway boundary, durable conversion lifecycle, typed PHP SDK request, and `orbit instance:prepare-deployment` CLI command.
- Conversion records one immutable preflight inventory, moves the existing Git checkout intact, places persistent `.env` and selected SQLite state, adopts the dedicated PHP runtime, prepares Caddy traversal, republishes the Route, and validates the completed layout.
- SQLite conversion owns the same per-AppInstance admission lock as Process add, start, and restart. It rechecks managed Process state and open descriptors before each persistent placement attempt.
- Expected remote refusals return bounded conflicts. Activity records only `sqlite_selected`; request paths and environment values stay out of activity and error output.
- Completed retries validate recorded placement without replacing retained source or later edits. Production removal accepts the nullable branch identity used by existing default-branch AppInstances.

## Discovery observations

All topology commands ran from `/fast/worktrees/orbit/orb-217`. Guest commands used `bin/e2e-topology exec ORB-217 <node> --argv=JSON` or `--argv-file=PATH`.

### production-adoption-preflight

Fixtures 2 and 3 were flat production AppInstances on app-prod Node 3.

- Fixture 2: App/AppInstance/Route `2`, user `orbit-app-2`, home `/home/orbit-app-2`, hostname `orb217.test`, Git HEAD `c83c788f18efac49009aa663cadb3664d69a5a9e`.
- Fixture 3: App/AppInstance/Route `3`, user `orbit-app-3`, home `/home/orbit-app-3`, hostname `orb217-conflict.test`, Git HEAD `8078dca999cad0a31e538e38a469186498a409fe`.
- Before mutation, preflight observed the exact production home, `.env` ownership and hash, Git root/HEAD/status, shared pool tuning, Route identity, Caddy root/upstream, shared socket, every conversion destination, and the selected `storage/app.sqlite` identity and bytes.
- Creating `/home/orbit-app-3/database.sqlite` before conversion made the API return HTTP 409 with `deployment_layout.preflight_refused`. Source, `.env`, SQLite, ignored content, Git state, shared runtime, and Route remained unchanged. The conflict file was then removed.
- Stored/local environment comparison ran before native preflight. Focused API and domain tests cover missing ownership and mismatched values with zero converter effects.

### production-adoption-content

Fixture 2 preserved these identities through conversion:

- `public/index.php`: `55b6ae7434e3ce00de202a2a0f6e6d7382754abd5c578665a110c73632d25620`
- ignored `local.cache`: `615f5750221677526d9e8ce6662cf14dd3815c919a4a6f49454c6f19efe09c1e`
- selected SQLite: `7f474c3e5675f7a6a99028e8505468867b250d763a31774d7c426f7b437773a6`
- `.env`: `834532cab3b7d4450a66c2353e530b12b20f8106210aff6af60e7385e03bda7d`
- Git HEAD remained `c83c788f18efac49009aa663cadb3664d69a5a9e`; the recorded porcelain-v2 status hash remained unchanged. No fetch, reset, clean, checkout, or application command ran.
- `/home/orbit-app-2/current` resolves to `/home/orbit-app-2/releases/initial`.
- Release `.env` resolves to `/home/orbit-app-2/.env`; `storage/app.sqlite` resolves to `/home/orbit-app-2/database.sqlite`.
- Persistent files are owned by `orbit-app-2:orbit-app-2` with mode `0600`. Links are owned by the same production user.

### production-adoption-runtime

- Fixture 2 shared-pool values `pm.max_children = 7`, `pm.process_idle_timeout = 13s`, and `pm.max_requests = 321` were preserved in the dedicated local configuration.
- Dedicated service `orbit-orbit-app-2-php8.5-fpm.service` is active with PID `17549`, restart count `0`, and socket `/run/php/orbit-app-2.sock` owned by `orbit-app-2:caddy` with mode `0660`.
- The unrelated shared PHP master remained PID `885` with restart count `0`.
- After the corrected ACL preparation, `curl --insecure --fail-with-body --silent --show-error --max-time 10 --resolve orb217.test:443:10.44.0.3 https://orb217.test/index.php` exited 0 with `orb217-flat-v1`.
- Fixture 3 passed normal certificate verification and returned `orb217-conflict-v1` before removal. Fixture 2's locally created route certificate remained self-signed, so only that discovery content request used `--insecure`.

### production-adoption-retry

- Fixture 2 was interrupted at Route projection after `runtime_published` by an exact-root matching defect in the development implementation. Repeating the same request resumed from the durable checkpoint and completed without moving or replacing retained source.
- Discovery then exposed a missing Caddy ACL preparation for the new `current` path. The implementation now calls the existing `ProductionAppInstanceSourceLifecycle::prepareCaddyAccess()` boundary after runtime publication and before Route publication. A focused test proves this order and retry checkpoint.
- The ACL refresh initially recalculated the access-mask bits on the protected SQLite file. The recursive deny pass now uses `setfacl -n`, preserving the mode while granting Caddy access only to the effective document root.
- After completion, a production-owned file containing `post-conversion-edit` was added at `releases/initial/post-conversion.txt`; SHA-256 was `78e2f1e1b4f581b912109dec2e367bd00470024245ae6d078c954c733c8daa4f`.
- Repeating `php /home/orbit/orbit/apps/cli/orbit instance:prepare-deployment 2 --sqlite-source-path=storage/app.sqlite --json` exited 0. The edit hash, `current` target, database mode, service PID `17549`, restart count `0`, and HTTPS body remained unchanged.
- Repeating with a different SQLite selection returned bounded `deployment_layout.request_conflict` and did not mutate the layout.

### production-adoption-conflict

- The occupied `database.sqlite` refusal above left the old fixture unchanged.
- An active owned Process returned HTTP 409 `deployment_layout.process_active` before native preflight.
- Focused tests cover unsupported shared tuning, unsafe path types, every destination conflict, expected exit-42 mapping, transport failures, source/Route drift, and interruption after each durable boundary.
- Normal removal of converted fixture 3 completed through `php /home/orbit/orbit/apps/cli/orbit instance:remove 3 --json`, operation `9d788841-7cad-41ef-ba43-36ac1eb90c8c`.
- Removal deleted `current`, the Route/Caddy entry, service, and socket. It retained exact bytes:
  - `public/index.php`: `f4307b7b8eb748120a76a21dbb1ef645026959770c5c5ef317239c0f539bb020`
  - ignored `local.cache`: `615f5750221677526d9e8ce6662cf14dd3815c919a4a6f49454c6f19efe09c1e`
  - `.env`: `fa46aa2660fd24442fff3a91db54347c100740c62fc0490921dbc26d338f886b`
  - `database.sqlite`: `aa1b1cdd73c1c7d3f9f045b4b904b8f2829d0665ef4e18ccc9437c6ab01228cf`
  - `local.conf`: `3140b79134848c0270b863a337015a9aabcb6e2fdc0c5afd26dbbae8bb5410f8`

### production-adoption-sqlite-precondition

- An active fixture-3 AppInstance Process made conversion return HTTP 409 `deployment_layout.process_active`; no relocation occurred.
- PID `23106`, running as `orbit-app-3`, opened `/home/orbit-app-3/database.sqlite`; descriptor 3 was verified against that file. The real converter returned HTTP 409 `deployment_layout.sqlite_not_quiescent` with a bounded message. The process was then terminated.
- A prior process running as `orbit` could not open the mode-`0600` database and therefore was not evidence of an open handle; that attempt was discarded.
- `AdoptProductionLayoutTest.php` pauses conversion after its clean SQLite snapshot, invokes `StartProcessAction`, and proves the start runs only after persistent placement. `AppInstanceProcessAdmissionConcurrencyTest.php` and `ProcessActionsTest.php` cover add, start, and restart admission, ownership re-resolution, stale owner refusal, and unchanged Node-owned behavior.
- The Python quiescence and placement programs use canonical containment plus descriptor-relative traversal with `O_NOFOLLOW`; placement uses descriptor-relative rename. The embedded preflight, quiescence, and persistent-state programs compile successfully.
- Orbit ran no maintenance, shutdown, queue, migration, framework-cache, or other inferred application command.

## Focused checks

- Gateway focused conversion, API, Process, release-layout, runtime, Route-scope, source-lifecycle, and retention suite: 69 tests, 837 assertions, passed.
- PHP SDK request and exhaustive contract suite: 12 tests, 112 assertions, passed.
- CLI command and exhaustive surface suite: 17 tests, 1071 assertions, passed.
- Embedded Python programs: `PreflightProgram`, `SqliteQuiescenceProgram`, and `PersistentStateProgram` compiled successfully.

## Project checks

- `cd apps/gateway && composer check`: passed (guidance, Rector, Pint, PHPStan).
- `cd packages/php-sdk && composer check`: passed (guidance, Rector, Pint, PHPStan).
- `cd apps/cli && composer check`: passed (guidance, Rector, Pint, PHPStan).
- `composer docs-build`: passed; `docs/generated/context.json` remained byte-identical to planning commit `03384ad961d433b49aa9471d356ab050a41aca95`.
- `composer docs-lint`: passed with zero findings.
- `git diff --check`: passed after staging all candidate files.
- `apps/e2e` and `bin/e2e-*`: unchanged.
- Root `composer check` on clean candidate `c256dc1d1f543d876491064f9582f3d3a2a2d1c1`: passed across all five projects with TIA; Builder receipt `/home/nckrtl/orbit/.git/orbit-checks/c256dc1d1f543d876491064f9582f3d3a2a2d1c1/review-bf0ybb2q/result.json`.

## Documentation

- `docs/reference/deployments.md` documents the explicit API and CLI operation, inventory, retained layout, recovery, conflicts, and SQLite quiescence.
- `docs/domains/applications.md` routes existing flat production instances to the conversion contract and preserves candidate/deployment exclusions.
- `docs/reference/php-runtime.md` documents dedicated runtime adoption, tuning preservation, validation, socket switch, and unrelated-service isolation.
- Audit findings: none outstanding. No implementation deviation required a documentation correction.

## Helpers and limits

- A bounded implementation audit identified ten negative, crash, concurrency, validation, activity-redaction, and completion gaps. The Builder corrected and retested all ten.
- Bounded helpers implemented the Process admission changes and the SDK/CLI transport surfaces. The Builder inspected, integrated, formatted, and verified the combined candidate.
- Fixture 2 remains available for independent discovery inspection. Fixture 3 was intentionally removed to observe normal converted-content retention.
- Discovery development only; isolated acceptance proof not run.
