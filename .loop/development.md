# ORB-224 development record

Flow: discovery
Incus: not required
Candidate: `66b9d12ade0628f36e01319c77551afdf693828c`
Tree: `78061e14f9eaadfd054e2368fcc0453ab64aa654`
Base observed at implementation start: `1e85f18e0a655b90ce6e0c07474cce00abfcf48c`
Reviewed plan artifact: `0e55d15c5606889cffead4f6617cbae28f5f8906`

## Acceptance evidence

1. `packages/php-sdk/tests/Unit/Requests/Apps/RuntimeDefinitionRequestsTest.php` passes. Its 18 tests and 89 assertions cover all ten process/Schedule list, create, show, replace, and remove request classes; exact HTTP methods, routes, route-segment encoding, JSON headers and raw bodies; bodyless operations; bounded readonly item and collection responses; request IDs; response redaction; sensitive request ingress; and structured safe failures.
2. `apps/cli/tests/Feature/Apps/RuntimeDefinitionCommandsTest.php` passes. Its 20 tests and 97 assertions cover both plural list commands and all eight process/Schedule singular modes; exact typed SDK dispatch and raw file content; invalid combinations, App IDs, UUIDs, and files before HTTP; human and JSON request IDs; safe Gateway errors; command-safe list output; and a no-execution filesystem sentinel.
3. `apps/cli/tests/Feature/CommandSurfaceTest.php` passes. Its affected run completed 10 tests and 1,108 assertions, including the exact four new command names, arguments, options, defaults, shared failure boundary, and no-shell invariant.
4. `docs/reference/app-processes-and-schedules.md` was committed by planning at `1e85f18e0a655b90ce6e0c07474cce00abfcf48c`. `composer docs-build` completed without a generated diff, and `composer docs-lint` passed with 0 issues, errors, or warnings.
5. `cd packages/php-sdk && composer check` passed guidance, Rector, Pint, and PHPStan. `cd apps/cli && composer check` passed guidance, Rector, Pint, and Larastan. Root `composer check` passed every validate, project check, and affected-test action for all five projects on the exact clean candidate.

## Development checks

- SDK TDD red: 15 expected missing-class errors after the test-harness dataset correction.
- SDK focused green: 18 tests, 89 assertions.
- SDK broader TIA run: 482 tests, 1,985 assertions.
- CLI TDD red: 20 expected missing-command errors.
- CLI runtime-definition green: 20 tests, 97 assertions.
- CLI command-surface green: 10 tests, 1,108 assertions.
- PHP SDK `composer check`: passed.
- CLI `composer check`: passed after fixing the reported exhaustive-match type.
- `composer docs-build`: passed; generated context unchanged.
- `composer docs-lint`: passed with no findings.
- `git diff --check`: passed before commit.
- Builder gate receipt: `/home/nckrtl/orbit/.git/orbit-checks/66b9d12ade0628f36e01319c77551afdf693828c/review-pbfjpdk0/result.json`.

## Boundaries and deviations

- No Gateway, AppInstance runtime, E2E harness, remote execution, automatic startup, or topology code changed.
- Invalid CLI operation modes and file access fail before connector creation or HTTP.
- List presentation strips commands even if a malformed Gateway response supplies one. Item operations retain the bounded complete response and the SDK redacts credential-shaped specification values.
- The issue's stale no-TIA full-suite wording maps to current TIA development checks and the exact-candidate Builder gate, as approved in the plan. There is no product deviation.
- No implementation helpers were delegated.
- Commit signing was attempted, but the configured `Orbit Developer <developer@example.com>` identity has no secret key. The implementation commit therefore uses the repository's existing unsigned identity convention.

## Discovery

Incus: not required. All acceptance behavior is automated SDK transport, local CLI input/output, maintained documentation, and local quality checks.

Discovery development only; isolated acceptance proof not run.
