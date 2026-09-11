# ORB-225 development record

Flow: discovery
Candidate: `be9a490a9d71fb5172f26f908bd3e9218776ba38`
Approved plan artifact: `16c506232ffba703eaf2ba1716c4fccd91c982ca`
Discovery attempt: `9a01614644f6b90f6be26ee10b1606d0`
Prior candidate: `e5e33ee8d3d4230a1f7c0d2190bd8da4b06a6194`
Prior implementation artifact: `0c7456c02c0474ca305d12e34effc2dad339f13a`

## Acceptance evidence

1. Production definition selection

   `apps/gateway/tests/Feature/Domain/InstantiateAppRuntimeDefinitionsTest.php` passed through TIA. Discovery observation `production-definition-selection` first refused a same-name target record with `process.name_taken` and left the capture marker unset. After removal of that deliberate record conflict, target AppInstance 3 captured only the App's production definitions: Docker Process 3, systemd Process 4 with `queue:work --tries=3`, and Schedule `e1db3970-3355-45b7-a335-ca78342b0aba`. The candidate retained `queue:work --tries=1`, and the development-only Vite definition was absent from the target.

2. Stopped independent copies

   Focused tests covered distinct Process and Schedule IDs, both supported Process backends, retained specifications, disabled desired timer state, and installation before the production target became active. Discovery observation `production-definition-stopped-copies` inspected the real app-prod host: `orbit-process-4-queue.service` was exact-owned, inactive, and disabled; Docker container `orbit-process-3-container-worker` returned exact output `3|false` for its Process ownership label and running state; the Schedule service and timer were exact-owned and inactive, and the timer was disabled. `/home/orb225sel/current` did not exist. The systemd, Docker, and Schedule execution sentinels were all absent.

3. One-time capture and resumable retry

   Focused tests covered an empty capture, definition replacement and deletion, a later definition, an operator-edited completed copy, and retry of only the failed copy. Discovery observation `production-definition-retry` captured target 4 at exactly `2026-09-11T22:04:27+00:00`. A foreign `orbit-process-6-z-conflict.service` caused `process.runtime_name_collision` after Process 5 completed, leaving Process 6 failed with retained provenance. Process 5 was explicitly started, its App definition was changed, Process 6's definition was deleted, and a later production definition was added. After only the foreign unit was removed, retry kept the same capture timestamp, left Process 5 active with captured `/bin/sleep 600`, installed Process 6 from its retained copy as stopped, and did not copy the later definition. Real units 5 and 6 had exact ownership markers; unit 5 was active and unit 6 was inactive and disabled.

4. Conflict refusal and removal isolation

   Focused tests covered record conflicts, Process and Schedule artifact conflicts, retry, and existing cascade actions. Discovery observation `production-definition-removal` used those existing cascades for targets 3 and 4. It found zero target Process rows and zero target Schedule rows afterward. All target systemd units, Docker containers, Schedule service/timer/script artifacts, and execution sentinels were absent. At the acceptance checkpoint, all four App definitions remained, and candidate AppInstance 2 and its `queue` Process remained unchanged. The temporary candidate and definition fixtures were removed only after that evidence was recorded so the general topology route probe could return to its baseline.

5. Documentation

   `docs/reference/app-processes-and-schedules.md` describes production-only selection, exclusion of candidate overrides, stopped target-owned copies, operation without a first release or application execution, one-time capture, retry behavior, conflict refusal, and cascade removal. `composer docs-build` confirmed generated context was already current. Root `composer docs-lint` passed with zero findings.

6. Candidate checks

   - Before main integration, final Gateway `composer test:affected` passed; TIA selected `tests/Feature/Domain/InstantiateAppRuntimeDefinitionsTest.php` from the last three changed files. The runner reported 3,298 tests and 18,625 assertions.
   - The focused file contains 6 behavioral tests and previously passed with 67 assertions during development.
   - After main integration, Gateway `composer test:affected` passed with no newly affected tests, while the retained focused acceptance result remains the binding behavioral evidence.
   - Gateway `composer check`: passed `guidance:check` (12 tests, 267 assertions), Rector, Pint, and PHPStan before and after integration.
   - `git diff --check`: passed before both candidate commits.
   - Root `composer docs-build` regenerated identical context bytes, and `composer docs-lint` passed with zero findings on the merged page.
   - Discovery `bin/e2e-topology sync ORB-225`: passed on the merged exact candidate after temporary fixture restoration.
   - Discovery `bin/e2e-topology verify ORB-225`: passed on attempt `9a01614644f6b90f6be26ee10b1606d0` for the merged candidate.
   - Root `composer check`: passed on clean candidate `be9a490a9d71fb5172f26f908bd3e9218776ba38` across CLI, Docs, Gateway, E2E, and PHP SDK with TIA. Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/be9a490a9d71fb5172f26f908bd3e9218776ba38/review-uvrzeent/result.json`.

The issue's retained request for five full no-TIA suites was mapped to the current policy: focused Gateway TIA checks plus the Builder's clean exact-candidate root `composer check`, which runs TIA across all five projects. GitHub CI is disabled.

## Implementation notes

- Capture occurs in one database transaction before remote mutation and records even an empty selection.
- Definition provenance is a nullable scalar with no foreign key. Definition edits or deletion cannot reconcile or remove a captured copy.
- Public Process admission keeps its existing behavior through the extracted `ProcessSpecification` service.
- Only definition-copy installation may resolve a pre-active production target. Ordinary Process and Schedule operations retain the active-target boundary.
- ORB-225 adds no endpoint, clone orchestration, SDK, CLI, E2E harness, or cross-Node behavior. The merged candidate contains the already-landed ORB-224 SDK and CLI definition commands unchanged from main.

## Main conflict resolution

- Actual conflicting main: `921892297737acbcfd37c78a218fb5c7525b10ba`.
- Merge commit: `be9a490a9d71fb5172f26f908bd3e9218776ba38`.
- The only overlapping path was `docs/reference/app-processes-and-schedules.md`.
- Main added the App runtime-definition SDK and CLI command reference. ORB-225 added production-copy capture and retry behavior. The resolution retains both complete sections and adds only the blank Markdown separation between them.
- `.loop/plan.md`, its PASS verdict, and its recorded plan-lint bytes are unchanged. Main integration did not restart preflight or alter the acceptance contract.

## Limitations

This flow used mutable discovery observations. It did not create an isolated proof topology or immutable proof capture. Discovery remains available for independent reviewer inspection.

Discovery development only; isolated acceptance proof not run
