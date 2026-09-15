# ORB-205 development record

Flow: discovery
Incus: not required (issue verification direction + explicit task instruction)
Candidate: 9d9ff1f7a7abf96542374d844a93b0c8fdb9920b
Base: origin/main @ 280e99b64140694abbad3f57c0708d87a35bc058

## Named proofs

- `apps/gateway/tests/Feature/Database/RemoveLegacyApplicationSchemaTest.php`
- `apps/gateway/tests/Feature/Api/LegacyApplicationSurfaceRemovalTest.php`
- `apps/gateway/tests/Unit/Architecture/DoctorModelCoverageTest.php`
- `apps/cli/tests/Feature/CommandSurfaceTest.php`
- Gateway architecture suites and AppInstance/Route/Process coverage via `composer test:affected`
- `composer docs-lint` — `{"tool":"librarian","result":"passed","issues":0,"errors":0,"warnings":0}`
- CLI and PHP SDK guidance suites via project `composer check`

## Builder gate

Passed: `/workspace/.git/orbit-checks/9d9ff1f7a7abf96542374d844a93b0c8fdb9920b/review-ibrk_uzk/result.json`

- Gateway `test:affected`: 3845 passed (fresh graph)
- e2e `test:affected`: 228 passed (`tests/Unit/E2E/ConvergenceGuestScriptsTest.php`)
- CLI / docs / php-sdk `composer check` passed; those projects have no candidate path changes
- warnings: none

Discovery development only; isolated acceptance proof not run.
