# ORB-3 development record

Issue: ORB-3
Flow: discovery
Incus: not required

## Binding

- Candidate: `d65a4413c6403e70f07a60d4e308f948aba9dc78`
- Candidate tree: `58a1576ff98ef8d7f1e7fa15e7207f7c137a9207`
- Branch/base: `orb-3` → `main`
- Included main: `0b28840b507777cfac05405758ed6e19e4cd1894`
- Previous implementation candidate: `bb94b6e075e9697c7eaefc613f3c5ed176f80f61`
- Previous implementation artifact: `188e99d6c3a8e2f5796195ecc4f569d18960c888`
- Approved plan candidate: `ee98819d6a19f80d57b4c74f7c8d92b03ce07edd`
- Approved plan artifact: `c2fb062cb48020636212dac9585147bd0c17e417`
- Approved review plan SHA-256: `8354683467d6177d66bece6f8bfca2818ca07f2a563bca74e58ae0f0b767b5f8`
- Builder gate: `/home/nckrtl/orbit/.git/orbit-checks/d65a4413c6403e70f07a60d4e308f948aba9dc78/review-ybb91ekx/result.json`

## Delivered behavior

- Added typed list, add, show, run, logs, complete, remove, and activate requests for Schedules.
- Added distinct Node and AppInstance target values. Add accepts only those two target types and preserves omitted options separately from explicit values.
- Added immutable bounded Schedule item, collection, log, and completion responses. Commands and logs are redacted before their final length checks.
- Preserved completion as `204 No Content`. Its value-only response reads a validated request ID from `X-Orbit-Request-Id`; the other seven operations retain JSON `data` and `meta.request_id` envelopes.
- Added strict nested Schedule collection parsing without changing permissive collection parsing for older request families.
- Added the shared Doctor family enum in current Gateway order and reused it for family reports and issue resource types.
- Updated the operation inventory from current main's pre-Schedule count of 89 by the eight discovered Schedule request types, for 97 combined operations.
- Updated the package README and public-contract guidance. Deployment restrictions remain unchanged.

## Acceptance evidence

1. `tests/Unit/Requests/Schedules/ScheduleRequestsTest.php` verifies all eight methods, paths, queries, bodies, raw URL encoding, the empty completion response, and header-only completion correlation.
2. The same request suite verifies distinct Node and AppInstance payloads, null-only omission, explicit invalid forwarding, and the closed add-target union.
3. `tests/Unit/Responses/Schedules/ScheduleResponsesTest.php` verifies command and 1 MiB log bounds, redaction-before-bound behavior, UUID and scalar validation, and strict malformed nested rejection.
4. `tests/Unit/Responses/Doctor/DoctorReportResponseTest.php` derives coverage from `DoctorFamily::cases()` and verifies the exact ordered tokens `node`, `role`, `app`, `instance`, `workspace`, `schedule`, `tool`, `process`, and `firewall` without a numeric family invariant.
5. `tests/Unit/RepositoryGuidanceTest.php` derives 97 operations from current main's pre-Schedule count of 89 plus the exact eight Schedule request classes.
6. `docs/reference/schedules.md`, `packages/php-sdk/README.md`, and generated documentation context describe the typed Schedule operations and Schedule-enabled Doctor family set. Documentation build and lint passed.
7. The PHP SDK project check passed guidance, Rector, Pint, and PHPStan.
8. `packages/php-sdk/.ai/rules/public-contract.md` permits only the shipped typed Schedule transport and current Doctor family set while retaining the existing deployment and candidate-clone limits.
9. The final fresh package TIA run passed 529 tests and 2,190 assertions. The exact clean candidate passed the root Builder gate across all five Composer projects.
10. The request and response suites verify omitted versus explicit `start`, `POST /api/v1/schedules/{uuid}/activate`, and independent desired-timer, lifecycle, and last-run status tokens.

## Checks

- Initial red TIA run: expected failures and errors because the new Schedule classes did not exist.
- Final `cd packages/php-sdk && vendor/bin/pest --parallel --processes=2 --tia --fresh --compact`: passed 529 tests and 2,190 assertions.
- Final request-focused `cd packages/php-sdk && composer test:affected`: passed 11 tests and 62 assertions.
- Final response-focused `cd packages/php-sdk && composer test:affected`: passed 30 tests and 117 assertions after the only fresh-run test expectation was corrected.
- `cd packages/php-sdk && composer check`: passed guidance with 7 tests and 113 assertions, Rector with zero changes, Pint, and PHPStan with zero errors.
- `composer docs-build`: passed and left `docs/generated/context.json` unchanged.
- `composer docs-lint`: passed with zero issues, errors, or warnings.
- `git diff --check -- packages/php-sdk`: passed before commit.
- Root `composer check`: passed all 15 validation, project-check, and affected-TIA commands on the exact clean candidate. The receipt records `role: builder`, `passed: true`, and `unchanged: true`.

## Main conflict resolution

- GitHub reported previous candidate `bb94b6e075e9697c7eaefc613f3c5ed176f80f61` unmergeable after ORB-227 landed on main.
- Merged current `origin/main` at `0b28840b507777cfac05405758ed6e19e4cd1894` as the second parent of merge candidate `d65a4413c6403e70f07a60d4e308f948aba9dc78`.
- The only content conflicts were `packages/php-sdk/.ai/rules/public-contract.md` and `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`.
- The resolution retains main's candidate-clone request, AppInstance inventory entry, transport guidance, CLI work, tests, and cloning documentation. It also retains every ORB-3 Schedule request, response, test, Doctor token, and guide assertion.
- The shared inventory now derives 97 concrete operations from current main's 89 pre-Schedule operations plus the eight Schedule requests. `packages/php-sdk/README.md` states the same combined total.
- Conflict-focused `cd packages/php-sdk && composer test:affected` passed 12 tests and 138 assertions, selecting the repository-guidance and candidate-clone request tests.
- Post-resolution `cd packages/php-sdk && composer check` passed guidance with 7 tests and 115 assertions, Rector, Pint, and PHPStan.
- Post-resolution `composer docs-build` and `composer docs-lint` passed; generated context remained unchanged and docs lint reported zero findings.
- `git diff --check origin/main...HEAD` passed. The feature diff against included main contains only the ORB-3 maintained page and PHP SDK paths.
- The corrected exact-candidate root Builder gate passed all 15 commands and recorded `passed: true` and `unchanged: true`.

## Documentation

- `docs/reference/schedules.md`: planned maintained reference for the eight Schedule operations, typed targets, bounded responses, and Doctor family contract. Commits: `a02b1ffc` and acronym correction `ee98819d`.
- `packages/php-sdk/README.md`: package-facing operation and response summary.
- `packages/php-sdk/.ai/rules/public-contract.md`: contributor contract and derived public-operation inventory.
- Documentation audit findings: none.

## Deviations and limits

- Deviations from the approved plan: none.
- Main integration changed only the shared inventory total from the original 88 + 8 to current main's 89 + 8. This preserves the acceptance outcome and is not a product-contract deviation.
- No Gateway, CLI, deployment, E2E harness, proof fixture, or topology path changed.
- No helper agents were used.
- No Incus topology was acquired because ORB-3 has no `incus` label and every acceptance result is observable with local PHP tests.
- This flow provides local automated discovery evidence, not isolated acceptance proof.

Discovery development only; isolated acceptance proof not run
