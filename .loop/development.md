# ORB-73 development record

Issue: ORB-73
Flow: discovery
Candidate: 516ac6a289ea6d64a33ba136c9dd992c9fc5d3a5
Prior candidate: 865ba03bc55ef69e573ea1133ccebfd93509674a
Initial base: 56a172875ccde244ae4f33e78453c7a1554a0316
Integrated main: fd65f8da80f151ea148a63f4b322c58f7f9e0284
Incus: not required

## Implementation

- Added exactly eight UUID-keyed Schedule routes for list, add, show, run, logs, complete, remove, and activate.
- Added strict Schedule body and query requests, bounded response data, and authorized collection filtering.
- Kept operator authorization on the target Node and completion authorization on the installed host Node.
- Added sanitized Schedule activity projection for the seven operator routes and kept completion outside activity recording.
- Reused the existing Schedule lifecycle actions and fake runtime. Updated activation to return `schedule.target_invalid` for Node-owned Schedules.

## Acceptance evidence

1. `apps/gateway/tests/Feature/Api/SchedulesTest.php` verifies the exact route inventory, UUID-only lookup, unknown UUID `404`, and strict malformed, duplicate, escaped-duplicate, unknown, and wrongly typed input rejection before mutation.
2. `apps/gateway/tests/Feature/Api/SchedulesTest.php` verifies active-peer and Node-access enforcement on all eight operations, target-Node collection filtering, and installed-host completion authorization.
3. `apps/gateway/tests/Feature/Api/SchedulesTest.php` verifies seven sanitized operator Activities and no completion Activity. It checks that command, calendar, logs, output, path, user, and unit sentinels are absent.
4. `apps/gateway/tests/Feature/Api/SchedulesTest.php` verifies that list omits command, show is authorized, logs are limited to 1–1000 lines, and sensitive input is absent from validation and activity data.
5. `docs/reference/schedules.md` documents the public API, authorization, activity, completion, and timer-state behavior. `composer docs-lint` passed with zero issues, errors, or warnings.
6. Gateway `composer check` passed: 12 guidance tests with 267 assertions, Rector with zero changes, Pint, and PHPStan with zero errors.
7. Gateway `composer test:affected` passed after the final implementation change: 3,310 tests and 18,843 assertions. The exact-candidate Builder root gate passed all 15 validate, quality, and affected-test commands across the five Composer projects.
8. `apps/gateway/tests/Feature/Api/SchedulesTest.php` verifies empty-body and idempotent AppInstance activation, owning-Node authorization, and `schedule.target_invalid` for a Node target.
9. `apps/gateway/tests/Feature/Api/SchedulesTest.php` verifies `start` defaults to true, disabled AppInstance installation, disabled Node rejection, exposed desired timer state, and manual run preserving timer intent.

## Checks

- `cd apps/gateway && vendor/bin/pint --dirty --format agent`: passed.
- `cd apps/gateway && composer test:affected`: passed, 3,310 tests and 18,843 assertions on the final implementation changes.
- `cd apps/gateway && composer check`: passed.
- `composer docs-lint`: passed with zero issues, errors, or warnings.
- `git diff --cached --check`: passed before commit.
- Original Builder gate: passed at candidate `865ba03bc55ef69e573ea1133ccebfd93509674a` with `unchanged: true` and receipt `/home/nckrtl/orbit/.git/orbit-checks/865ba03bc55ef69e573ea1133ccebfd93509674a/review-h_rujnov/result.json`.
- Corrected Builder gate: passed at candidate `516ac6a289ea6d64a33ba136c9dd992c9fc5d3a5` with `unchanged: true` and receipt `/home/nckrtl/orbit/.git/orbit-checks/516ac6a289ea6d64a33ba136c9dd992c9fc5d3a5/review-9mm87fmv/result.json`.

## Main integration

- Fetched and merged current main `fd65f8da80f151ea148a63f4b322c58f7f9e0284` after GitHub reported the original candidate unmergeable.
- Main added candidate-clone authorization, activity, routes, tests, and documentation. Six files overlapped with ORB-73; five merged automatically.
- The only textual conflict was the import block in `apps/gateway/app/Http/Middleware/RecordCommandActivity.php`. The resolution retains main's `RouteHostname` and `GitBranchName` imports and ORB-73's `ScheduleOperationException` and `ScheduleTargetType` imports. Both clone and Schedule input projections remain present.
- The combined serving-node enum and resolver retain `CandidateClone` and `ScheduleOwning`; the combined activity target resolver retains clone and Schedule subjects; routes retain clone and all eight Schedule endpoints; the route-scope inventory retains both entries.
- The merged Gateway TIA run selected the main integration surface. It passed 3,408 of 3,410 tests, with two unrelated WireGuard enablement-state datasets reporting transient fixture errors. Neither branch changed WireGuard code or tests. The required TIA retry selected that unsuccessful file and passed all 46 tests with 269 assertions without a source change.
- `cd apps/gateway && composer check` passed after resolution. Root `composer docs-lint` passed with zero findings.
- The approved plan SHA-256 remains `8762b14da96ced98c1605006e20f5aa7b120553e638dbe9e6cc90a996260fa0d`; preflight was not restarted and no acceptance meaning changed.

## Documentation

- `docs/reference/schedules.md`: the planning commit describes the eight operations, strict validation, UUID identity, Node authorization, collection filtering, bounded responses, sanitized Activity behavior, completion exception, activation, and desired timer state.
- Documentation audit findings: none remain. No implementation-time documentation correction was needed.

## Deviations and limits

- The issue's stale full no-TIA suite wording maps to Gateway TIA, Gateway quality checks, and the Builder root TIA gate under current repository policy, as approved in the plan.
- Discovery development only; isolated acceptance proof was not run.
- No helpers were used.
