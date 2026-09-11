# ORB-222 development record

Flow: discovery
Incus: required
Discovery action: `deployment-cli-lifecycle`
Discovery attempt: `74010d1a0303380e0e7b505fee835236`
Approved plan artifact: `24b9530bccad4e237b9c7b0da42fba92a9a07d65`

## Implementation

- Added `instance:deployment-config INSTANCE [--file=PATH] [--json]` for the typed show and complete replacement SDK operations.
- Added `instance:deploy INSTANCE [--json]` and `instance:rollback INSTANCE --release=NAME [--json]` for one closeable typed deployment stream per invocation.
- Added `instance:releases INSTANCE [--json]` for the typed retained-release operation.
- Added one shared stream renderer. Human output escapes application bytes. JSON output emits only compact NDJSON events with base64 application bytes.
- A succeeded terminal event exits zero. Failed, invalid, truncated, interrupted, and transport outcomes exit nonzero. The renderer closes every stream and does not retry or replay a request.
- Added command-surface and focused feature coverage for all four commands.

## Local acceptance checks

- `cd apps/cli && composer guidance:check`: passed, 13 tests and 255 assertions.
- `cd apps/cli && composer test:affected`: passed for the focused deployment command acceptance tests; the latest focused run reported 17 tests and 87 assertions.
- `cd apps/cli && composer check`: passed, including guidance, Rector, Pint, affected Pest tests, and PHPStan with 0 errors.
- `composer docs-lint`: passed with 0 issues, 0 errors, and 0 warnings.
- `git diff --check`: passed.

## Discovery setup

`bin/e2e-topology acquire ORB-222 /fast/worktrees/orbit/orb-222` created the standard discovery topology. `bin/e2e-topology sync ORB-222` passed. The physical Nodes were Gateway ID 1 (`10.44.0.1`), app-dev ID 2 (`10.44.0.2`), and standalone app-prod ID 3 (`10.44.0.3`).

The disposable app-dev Node hosted a two-commit dumb-HTTPS Git repository at `https://10.44.0.2:8766/orb222.git`. Its temporary certificate was trusted only inside the disposable Gateway and app-prod Nodes. The operator created App ID 2 (`orb222-discovery`) and production AppInstance ID 2 (`e2e-prod`, `orb222.orbit`) through `orbit app:new` and `orbit instance:new`.

The snapshot database had three accepted repository migrations pending. Before exercising the accepted deployment API, the operator recorded `php apps/gateway/artisan migrate:status` and ran `php apps/gateway/artisan migrate --force`; this applied the deployment-layout, deployment-config, and schedule migrations inside the disposable Gateway only. The production fixture also needed one stored `APP_ENV=production` value before deployment environment synchronization. A failed pre-setup attempt retained an unselected release named `20260911214806-d7c9b6f964fdfa83`; it was not selected or used as rollback evidence.

The app-prod fixture needed execute ACL access for Caddy on `/home/orbit-app-2/releases`; the operator added that disposable parent ACL after the first successful deployment. No repository harness file or product Gateway code changed.

## Deployment configuration

The complete configuration file was:

```json
{"branch":"main","steps":[{"name":"delayed-marker","phase":"before_activation","command":"printf \"ORB222-FIRST %s\\n\" \"$(date +%s%3N)\"; sleep 3; printf \"ORB222-COMPLETE %s\\n\" \"$(date +%s%3N)\"","timeout_seconds":30}]}
```

`orbit instance:deployment-config 2 --file=/home/orbit/orb222-deployment.json --json` exited 0 and returned the complete stored object with request ID `2559126d-8e48-4a25-ad20-b7be2925cc2c`. A separate `orbit instance:deployment-config 2 --json` exited 0, returned the same branch and step, and reported request ID `d305f187-f41a-4a37-af81-652028f2ec96`.

## First deployment and incremental output

The first successful operator command was `orbit instance:deploy 2 --no-interaction`. It exited 0 with request ID `f17aef35-517e-40b6-baf5-1ae8b5db69fe` and selected release `20260911214840-16264d91468c0c6d`.

The human stream contained these ordered records:

```text
Phase: Source preparation
Phase: Environment sync
Phase: Before activation [delayed-marker]
stdout: "ORB222-FIRST 178916332291248698\n"
stdout: "ORB222-COMPLETE 1789163325107550582\n"
Phase: Activation
Phase: Php refresh
Result: succeeded
Selected release: 20260911214840-16264d91468c0c6d
Request ID: f17aef35-517e-40b6-baf5-1ae8b5db69fe
```

The second deployment supplied the clearer timing observation below. Its NDJSON output event sequence 4 decoded to `ORB222-FIRST 1789163410648943414` (`1789163410.648943414` seconds). Sequence 5 decoded to `ORB222-COMPLETE 1789163413655888923` (`1789163413.655888923` seconds). The first step output therefore preceded delayed completion by about 3.007 seconds. Both records preceded activation and the terminal result in the operator-visible event order.

## Updated branch deployment

The initial remote `main` commit was `de2648bf3aa870fdf28dde3e4e5ad357f929b2d7`, whose served response was `ORB-222 release one`. The disposable source was changed to `ORB-222 release two`, committed as `0cf7726734c5cb74389b5d8caf9cde88a9dfa617`, pushed to the topology-local remote, and confirmed by `git ls-remote` from app-prod.

`orbit instance:deploy 2 --json --no-interaction` exited 0. It emitted exactly eight compact NDJSON events under request ID `945f1afd-f2a0-4291-a070-f9309d683fc1`: source preparation, environment sync, the named before-activation phase, the first stdout marker, the delayed completion marker, activation, PHP refresh, and the succeeded result. The result selected `20260911215009-3644e958d6abb40b`.

An HTTPS request to `orb222.orbit/index.php` through app-prod then returned:

```text
ORB-222 release two
```

`orbit instance:releases 2 --json` exited 0 and returned:

```json
{"releases":["20260911214806-d7c9b6f964fdfa83","20260911214840-16264d91468c0c6d","20260911215009-3644e958d6abb40b","initial"],"selected_release":"20260911215009-3644e958d6abb40b","request_id":"ffb3932a-f36d-4af9-883d-63edc80c3f28"}
```

## Explicit rollback and persistent SQLite

Before deployment, the persistent database was created at `/home/orbit-app-2/database.sqlite` with one row, `persistent-orb-222`. Its SHA-256 digest was `31a80bf978b3dd95991c86b09af6265e80912b50b7b07c919822f8dd14b18cde`.

The operator ran:

```text
orbit instance:rollback 2 --release=20260911214840-16264d91468c0c6d --no-interaction
```

It exited 0 and returned:

```text
Phase: Rollback
Result: succeeded
Selected release: 20260911214840-16264d91468c0c6d
Request ID: 8a452608-fc08-473f-bbf8-59062cadc75a
```

After rollback, `orb222.orbit/index.php` again returned `ORB-222 release one`. `orbit instance:releases 2 --json` reported selected release `20260911214840-16264d91468c0c6d` with request ID `301ad816-8807-4fc6-8c73-866a1f556b41`. The SQLite path, SHA-256 digest, and query result remained exactly `/home/orbit-app-2/database.sqlite`, `31a80bf978b3dd95991c86b09af6265e80912b50b7b07c919822f8dd14b18cde`, and `persistent-orb-222` before deployment, after the updated deployment, and after rollback.

## Verification and retained state

`bin/e2e-topology verify ORB-222` passed for attempt `74010d1a0303380e0e7b505fee835236` after the lifecycle. The earlier successful release remains selected, the topology-local repository remains available, and discovery remains retained for independent reviewer inspection. Disposable certificate, source-server, fixture, migration, environment, and ACL state will be removed with topology closeout; no host or repository cleanup is pending.

The exact clean candidate is `0eb5fbe6d67ce87ea2ad9b878c0f3d62ea289d84`. Root `composer check` passed all five project validation, project-check, and affected-TIA boundaries on that commit. Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/0eb5fbe6d67ce87ea2ad9b878c0f3d62ea289d84/review-yiestrkx/result.json`.

Discovery development only; isolated acceptance proof not run
