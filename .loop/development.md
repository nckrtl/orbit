# ORB-349 development record

Flow: discovery. Incus: not required.

Candidate: `067837e875236fdef112bbb0eb1300b70d128c03`
Base: `origin/main` `25038b816423859874caa73e9c5dafba463ecb9e`

## Change

`apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php` endpoint-shape cases now seed `COMMAND_SURFACE` with `instance:clone`, `instance:deploy`, and `instance:deploy-step:create` so create-resources selects the candidate contract after ORB-332.

## Acceptance checks

1. `accepts a valid application domain on Routes and production AppInstances` and `accepts hostname only when domain is absent` write `production.hostname` = `e2e-prod.orbit.test` under both fixtures.
2. `refuses a present invalid domain without falling back to hostname` still exits non-zero and writes no sample state (invalid Route domain and invalid production domain).
3. Focused Pest:

```
cd apps/e2e && vendor/bin/pest --compact --filter='accepts a valid application domain|accepts hostname only when domain|refuses a present invalid domain' tests/Unit/E2E/ConvergenceGuestScriptsTest.php
{"tool":"pest","result":"passed","tests":4,"passed":4,"assertions":12,"duration_ms":1595}
```

## Project checks

```
cd apps/e2e && composer check
{"tool":"pest","result":"passed","tests":12,"passed":12,"assertions":136}
{"tool":"rector","result":"passed","totals":{"changed_files":0,"errors":0}}
{"tool":"pint","result":"passed"}
{"tool":"phpstan","result":"passed","errors":0}
```

`composer test:affected` in this environment has no published TIA baseline. A cold TIA run executed 1543 tests; 1542 passed. `TiaCacheTest` `it isolates background checks while preserving cache lifecycle and failure reporting` exceeded its 180s process timeout. That test is outside this issue's scope.

## Documentation audit

Scope: ORB-349 (`apps/e2e`, Route, App instance). No `docs` label.

Fixed: none.

Reported: none.

Verification: no page changed; `composer docs-lint` not required.

Discovery development only; isolated acceptance proof not run.
