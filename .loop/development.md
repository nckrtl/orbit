# ORB-217 development record

Flow: discovery
Incus: required
Discovery attempt: `122234b075ce356529ecd08304730d0f`
Starting candidate: `03384ad961d433b49aa9471d356ab050a41aca95`
Previous reviewed candidate: `8f26b321242cabfe1e5207fe6e4a9fd00683ee3d`
Candidate: `ec6d6b94efe4b212bd9a802938f006044b183d8b`
Candidate tree: `15dcd62063dec5c4105d5c401ddc6da409dcc423`
Builder gate: passed (`/home/nckrtl/orbit/.git/orbit-checks/ec6d6b94efe4b212bd9a802938f006044b183d8b/review-z2uyp711/result.json`)

Discovery was acquired only after independent preflight approval. It remains active for independent review. This is discovery development evidence, not isolated acceptance proof.

## Implementation

- Added the authorized `POST /api/v1/instances/{instance}/deployment-layout` Gateway boundary, durable conversion lifecycle, typed PHP SDK request, and `orbit instance:prepare-deployment` CLI command.
- Conversion records one immutable preflight inventory, moves the existing Git checkout intact, places persistent `.env` and selected SQLite state, adopts the dedicated PHP runtime, prepares Caddy traversal, republishes the Route, and validates the completed layout.
- SQLite conversion owns the same per-AppInstance admission lock as Process add, start, and restart. It rechecks managed Process state and open descriptors before each persistent placement attempt.
- Expected remote refusals return bounded conflicts. Activity records only `sqlite_selected`; request paths and environment values stay out of activity and error output.
- Completed retries validate recorded placement without replacing retained source or later edits. Production removal accepts the nullable branch identity used by existing default-branch AppInstances.

## Review corrections

- SQLite preflight, quiescence, and persistent placement now refuse `-wal`, `-shm`, and `-journal` sidecars beside either the selected source or `database.sqlite`. Persistent placement checks before moving `.env` and checks again immediately before the database rename.
- Preflight now verifies the same source ownership, document-root containment, safe-ancestor, and no-symlink prerequisites that later Caddy access preparation requires. These checks run before the conversion records `accepted` or performs an effect.
- Shared-pool inventory omits only exact generated `env[HOME]`, `env[USER]`, `env[PATH]`, and `php_admin_value[opcache.validate_timestamps] = 0` values. It carries other `env[...]` and `php_admin_value[...]` directives into dedicated `local.conf`; identity-changing HOME or USER values still refuse.
- Native focused tests execute the embedded SQLite sidecar and source-access programs against disposable files. The runtime-tuning program is executed against a representative shared pool and proves custom directives are preserved while exact generated values are omitted.
- The second correction resolves the document root through the same inspected source path and refuses a selected SQLite source that equals or lies below it. The executable test proves exit 42 without changing source, SQLite, environment, or conversion paths. The ownership fixture now supplies a deliberately different expected group and no longer depends on membership in `www-data`.
- `docs/reference/deployments.md` now states the exact production-home ownership rule, the document-root no-symlink rule with Laravel's `public/storage` example, and the requirement to select SQLite outside the served tree.

## Discovery observations

All topology commands ran from `/fast/worktrees/orbit/orb-217`. Guest commands used `bin/e2e-topology exec ORB-217 <node> --argv=JSON` or `--argv-file=PATH`.

### production-adoption-preflight

Fixtures 2 and 3 were flat production AppInstances on app-prod Node 3.

- Fixture 2: App/AppInstance/Route `2`, user `orbit-app-2`, home `/home/orbit-app-2`, hostname `orb217.test`, Git HEAD `c83c788f18efac49009aa663cadb3664d69a5a9e`.
- Fixture 3: App/AppInstance/Route `3`, user `orbit-app-3`, home `/home/orbit-app-3`, hostname `orb217-conflict.test`, Git HEAD `8078dca999cad0a31e538e38a469186498a409fe`.
- Before mutation, preflight observed the exact production home, `.env` ownership and hash, Git root/HEAD/status, shared pool tuning, Route identity, Caddy root/upstream, shared socket, every conversion destination, and the selected `storage/app.sqlite` identity and bytes.
- Creating `/home/orbit-app-3/database.sqlite` before conversion made the API return HTTP 409 with `deployment_layout.preflight_refused`. Source, `.env`, SQLite, ignored content, Git state, shared runtime, and Route remained unchanged. The conflict file was then removed.
- Stored/local environment comparison ran before native preflight. Focused API and domain tests cover missing ownership and mismatched values with zero converter effects.
- Corrected-candidate fixture 4 used App/AppInstance/Route `4`, user `orbit-app-4`, home `/home/orbit-app-4`, hostname `orb217-review.test`, and Git HEAD `3be39207c65bd044c7091a3b6b21bed41192cc92`. Its baseline content hashes were `648f41f9af48a4fca4fee50976304c50d9556b4134403f0f07ed3c0285034b7b` for `public/index.php`, `4b0a51a556edf046290a7e9d8bbcb62cf9db456beb70ce8a5ba1ae6f2bc1a76e` for SQLite, and `c71e916146481eb3cde738052aa2da961e65825ce2a40fee074a0d76c5768d5d` for `.env`.
- A production-owned `public/storage -> ../storage` link returned HTTP 409 with request `1785f0a3-5e53-4c98-8a16-1be24ed8dce3`. A `root:root` descendant returned HTTP 409 with request `4300377f-7fea-42d1-8223-752aea975989`. Both responses used `deployment_layout.preflight_refused`; no layout row, `releases/`, or `current` appeared, the hashes stayed unchanged, and HTTPS continued to return `orb217-review-v1`. The fixtures were removed before successful conversion.
- Fresh fixture 5 used App/AppInstance/Route `5`, user `orbit-app-5`, home `/home/orbit-app-5`, hostname `orb217-served-sqlite.test`, document root `public`, selected SQLite `public/app.sqlite`, and Git HEAD `92b8a72137eb7bd425f4f5d226c3086bc5c366a6`. The preflight returned bounded HTTP 409 `deployment_layout.preflight_refused` with request `b356e856-4323-4b1b-b1ca-e264ce33a286`, and the CLI exited 1. No deployment-layout row, `releases/`, `current`, or `database.sqlite` appeared. The checkout path, Route state, flat Caddy root, and shared socket stayed unchanged. HTTPS returned `2175` before and after refusal. Hashes remained `c0313c879589b437656c4dc90d5480537e40205b8a9ed4a8e3bb58200f9a480c` for `public/index.php`, `c7a10dda0b6456b612563c9bde2fb098ef98ef6a78b26e383d3f7f28ca14c9f2` for SQLite, and `e9069212c49d946ac7cf3113cbfcb4f50cc99b486a64764754be0056a9aebdbc` for `.env`.

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
- Fixture 4 added `env[APP_FLAG] = enabled` and `php_admin_value[memory_limit] = 1G` to its shared pool. Conversion request `99238b4b-7bc5-4307-8208-121469293aa2` preserved both exact lines in `/etc/orbit/php-fpm/orbit-app-4/local.conf` and omitted the generated HOME, USER, PATH, and OPcache lines. Dedicated service `orbit-orbit-app-4-php8.5-fpm.service` was active with PID `28749` and zero restarts. Shared `php8.5-fpm.service` remained active with PID `885` and zero restarts. The dedicated socket was `orbit-app-4:caddy` mode `0660`, and HTTPS returned `orb217-review-v1`.

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
- On fixture 4, a production-owned source `storage/app.sqlite-wal` returned HTTP 409 with request `80531f83-6855-4634-be5e-b672ec20bd75`. After its removal, a production-owned destination `database.sqlite-journal` returned HTTP 409 with request `df24daf0-8136-476e-b498-f5c74897b1a9`. Both responses used `deployment_layout.preflight_refused`; no layout row or filesystem move appeared, hashes and HTTPS content stayed unchanged, and the sidecars were removed. The later successful relocation kept the SQLite hash `4b0a51a556edf046290a7e9d8bbcb62cf9db456beb70ce8a5ba1ae6f2bc1a76e`, created the authenticated source link to `/home/orbit-app-4/database.sqlite`, and left no source or destination sidecar.

## Focused checks

- Gateway focused conversion, API, Process, release-layout, runtime, Route-scope, source-lifecycle, and retention suite after the second review correction: 72 tests, 882 assertions, passed.
- PHP SDK request and exhaustive contract suite: 12 tests, 112 assertions, passed.
- CLI command and exhaustive surface suite: 17 tests, 1071 assertions, passed.
- Embedded Python programs: `PreflightProgram`, `SqliteQuiescenceProgram`, and `PersistentStateProgram` compiled successfully after correction.

## Project checks

- Corrected `cd apps/gateway && composer check`: passed (guidance, Rector, Pint, PHPStan).
- `cd packages/php-sdk && composer check`: passed (guidance, Rector, Pint, PHPStan).
- `cd apps/cli && composer check`: passed (guidance, Rector, Pint, PHPStan).
- Corrected `composer docs-build`: passed; `docs/generated/context.json` remained unchanged.
- Corrected `composer docs-lint`: passed with zero findings.
- `git diff --check`: passed after staging all candidate files.
- `apps/e2e` and `bin/e2e-*`: unchanged.
- Root `composer check` on clean corrected candidate `ec6d6b94efe4b212bd9a802938f006044b183d8b`: passed across all five projects with TIA; Builder receipt `/home/nckrtl/orbit/.git/orbit-checks/ec6d6b94efe4b212bd9a802938f006044b183d8b/review-z2uyp711/result.json`.
- The corrected branch push was verified: local HEAD and `refs/heads/orb-217` on origin both equal `ec6d6b94efe4b212bd9a802938f006044b183d8b`.

## Documentation

- `docs/reference/deployments.md` documents the explicit API and CLI operation, inventory, retained layout, recovery, conflicts, and SQLite quiescence.
- `docs/domains/applications.md` routes existing flat production instances to the conversion contract and preserves candidate/deployment exclusions.
- `docs/reference/php-runtime.md` documents dedicated runtime adoption, tuning preservation, validation, socket switch, and unrelated-service isolation.
- Audit findings: none outstanding. No implementation deviation required a documentation correction.

## Helpers and limits

- A bounded implementation audit identified ten negative, crash, concurrency, validation, activity-redaction, and completion gaps. The Builder corrected and retested all ten.
- Bounded helpers implemented the Process admission changes and the SDK/CLI transport surfaces. The Builder inspected, integrated, formatted, and verified the combined candidate.
- Fixtures 2, 4, and 5 remain available for independent discovery inspection. Fixture 3 was intentionally removed to observe normal converted-content retention.
- The correction commit is unsigned because the configured `Orbit Developer <developer@example.com>` identity has no GPG secret key. The signed commit attempt failed before it wrote a commit object; product verification is unaffected.
- Discovery development only; isolated acceptance proof not run.
