# ORB-131 development record

Flow: discovery
Incus: required
Discovery attempt: `793395418338fbf2ea3a4dcfadcfd339`
Plan review artifact: `820e3a559d94b58567c92fa06ebd777711cfd1ca`
Candidate: `6c2f674cce893595c761948d3fab448bea68ad95`

## Implementation

- Process API target token `instance`, SDK `appInstanceId`, and CLI `--instance` now identify only an AppInstance. Workspace input is rejected before mutation and the public CLI option is absent.
- `ProcessTargetResolver` derives the development Node user, checkout, `.env`, and `app-instance-<id>` certificate scope, or the production Node, dedicated user, home `.env`, and `current` working directory. Inspection and cleanup retain bounded access to incomplete removal state. Legacy owners fail closed.
- `ProcessAdmissionLock` uses protected per-AppInstance `flock` owners in stable ID order. Add holds the owner through locked admission, runtime convergence, and final status. Removal holds its fixed set through the transaction that marks every member `removing`.
- AppInstance runtime cleanup cascades exact-owned Processes in stable order through the existing removal action. Failed rows remain resumable; successful rows are deleted and skipped on retry.
- Runtime, Doctor, authorization, activity, Gateway API, SDK, and CLI surfaces use the AppInstance placement. Process responses retain owner fields and gain no Node field; Process activities identify the AppInstance and target Node.
- Discovery exposed invalid production release-preflight argv (`test -d -- PATH`). GNU `test` returned exit 2. The implementation now uses the already validated absolute path as `test -d PATH`, with exact-argv and missing-`current` regression tests.

## Local acceptance checks

- Gateway acceptance set: `vendor/bin/pest --compact` over the 14 plan paths passed: 227 tests, 1,411 assertions.
- Gateway `composer check`: passed; 12 TIA tests, 267 assertions; Rector, Pint, and PHPStan passed.
- PHP SDK focused Process and object-boundary set: passed; 38 tests, 159 assertions.
- PHP SDK `composer check`: passed; 7 TIA tests, 90 assertions; Rector, Pint, and PHPStan passed.
- CLI focused Process and shared error-rendering set: passed; 106 tests, 511 assertions.
- CLI `composer check`: passed; 13 TIA tests, 255 assertions; Rector, Pint, and PHPStan passed.
- `composer docs-build`: passed and reproduced `docs/generated/context.json` without a tracked diff.
- `composer docs-lint`: `{"tool":"librarian","result":"passed","issues":0,"errors":0,"warnings":0}`.
- `git diff --check`: passed.
- Root Builder `composer check`: passed on exact candidate `6c2f674cce893595c761948d3fab448bea68ad95`; receipt `/home/nckrtl/orbit/.git/orbit-checks/6c2f674cce893595c761948d3fab448bea68ad95/review-i3u6nsl0/result.json`.
- Builder-gate corrections: CLI `CommandSurfaceTest.php` passed (10 tests, 1,031 assertions), and Gateway `RequireActiveWireGuardPeerTest.php` passed (26 tests, 150 assertions) after their stale Workspace option and legacy Instance fixture expectations were aligned with the approved AppInstance-only contract.

## Discovery observations

All observations used the registered discovery topology and existing `shell`, `sync`, and `verify` commands. The final `bin/e2e-topology sync ORB-131` returned `ready 793395418338fbf2ea3a4dcfadcfd339`; final verify returned `verified 793395418338fbf2ea3a4dcfadcfd339`. Discovery remains active for review.

### appinstance-process-context

- Development AppInstance 1 produced Process 9 with owner token/id `instance/1`, Node 2, `User=orbit`, `WorkingDirectory=/home/orbit/apps/laravel-typed/e2e-dev`, and `EnvironmentFile=-/home/orbit/apps/laravel-typed/e2e-dev/.env`.
- Production AppInstance 4 produced systemd Process 4 and Docker Process 5 on Node 3 with `User=orbit-app-2`, `WorkingDirectory=/home/orbit-app-2/current`, and persistent environment file `/home/orbit-app-2/.env`. The AppInstance response recorded `production_home=/home/orbit-app-2` and `production_user=orbit-app-2`.
- Production creation and Process placement remained on the recorded app-prod Node. The topology has co-located app-dev/router roles on its development Node; neither roles nor certificate state changed the production target.

### appinstance-process-tls

- The Process 9 unit `ExecStart` contained only `/home/orbit/.orbit/certificates/app-instance-1/current/cert.pem` and `key.pem` Vite projections.
- The unit contained no legacy `instance-` or `workspace-` certificate scope. Process 9 was then removed successfully.

### appinstance-process-lifecycle

- Development AppInstance 3: systemd Process 2 and Docker Process 3 completed add, list, start, three-line bounded logs, restart, stop, and later cascade removal.
- Production AppInstance 4: systemd Process 4 and Docker Process 5 completed stopped installation, list, start, three-line bounded logs, restart, and stop. Systemd reported `active`; Docker reported `running`.
- Bounded logs returned the systemd journal line and exactly three Docker output lines. API output kept AppInstance owner fields and no Node field.
- The first production start found the GNU `test` argv defect described above. After the code correction and local regression test, both production runtimes started successfully on the same topology.
- Exact runtime ownership and rollback/redaction branches are covered by `RemoteProcessRuntimeManagerTest.php` and API security tests. The real ownership collision in the retry observation below also confirmed fail-closed behavior.

### appinstance-process-cascade

- Forced development removal operation `7ed36c4e-529a-48f1-aecf-c09abc5b3b85` removed AppInstance 3, Processes 2 and 3, the exact unit/container, and its source checkout.
- Normal production removal operation `0dfc4433-b8af-4070-ab3c-dd07c4cc1bca` completed after the controlled retry. It removed Processes 5 and 7 and their exact container/unit. `/home/orbit-app-2` remained, while `current` was cleared as required.
- A separate normal removal operation `1b5407c8-56f6-413a-96a0-150be4e13600` removed a running Process 8 before completing AppInstance 6 removal.

### appinstance-process-cascade-isolation

- Registered checkout AppInstance 7 and linked-worktree AppInstances 8 and 9 from one repository. Processes 10, 11, and 12 covered running/stopped systemd and stopped Docker artifacts.
- Forced removal of worktree AppInstance 8 completed for that member. Forced checkout removal operation `b8fb84e5-eb0f-4613-bb11-a975ed4072c5` then accepted the remaining fixed set (`total=2`) and completed both members.
- All three Process records and exact artifacts disappeared. The managed source paths for accepted members disappeared.
- Outside AppInstance 1 and Process 13 remained listable. Transient service `orb131-set-decoy.service` stayed active and container `orb131-set-decoy` stayed running. Both decoys and Process 13 were removed during observation cleanup.

### appinstance-process-cascade-retry

- Replaced the stopped owned container for Process 5 with a same-name unlabeled decoy. Accepted production removal stopped at `runtime_cleanup`, returned `instance.removal_incomplete`, retained the removing AppInstance, retained the decoy, and left later running Process 7 untouched.
- Doctor reported Process 5 as bounded `process.inspection_failed`/`unverifiable`; no artifact was adopted or deleted.
- After deleting only the decoy, the identical removal request resumed operation `0dfc4433-b8af-4070-ab3c-dd07c4cc1bca`, skipped completed source work, removed unfinished Processes 5 and 7, and completed once.

### appinstance-process-doctor

- Healthy Process reports on Nodes 2 and 3 each returned `healthy=true`, two checked Processes, zero drift, and zero unverifiable results.
- During incomplete production removal, Doctor returned one bounded `process.inspection_failed` issue for the ownership mismatch and no raw command, path, label, or exception output.
- API activity request `e730d675-cdfc-424b-899a-cb3b941b9e5b` recorded `process:start` with AppInstance subject 6 and target Node 2. Request `e89cb98c-8e88-4f28-abf7-1ad46479e461` recorded the same AppInstance/Node attribution for `process:list`.
- Focused Doctor and inspector tests cover missing runtime, desired-state mismatch, inactive Node, and failed/removing states without mutation.

### appinstance-process-release-context

- Production Process 4 installed stopped before `current` existed. Its first start was refused. This exposed and then validated the corrected release-preflight argv.
- For Process 7, release A wrote `release-A` and ran with PID 7731. Moving `current` to release B left PID 7731 and the `release-A` marker unchanged.
- Explicit stop/start selected release B, wrote `release-B`, and ran with PID 8128. Cleanup restored the initial source layout before AppInstance removal.

### admission and preflight observation

- A dirty source file made AppInstance 6 removal return `instance.remove_refused` before acceptance. Process 8 stayed recorded, desired-running, and active. Removing the temporary file allowed later removal.
- `AppInstanceProcessAdmissionConcurrencyTest.php` uses controlled worker barriers for both serialized orderings: completed add before acceptance is cascaded, while acceptance before add produces no Process row or runtime mutation.

## Limitations and deviations

- No product-contract deviation. The implementation matches all 16 acceptance outcomes.
- The registered snapshot needed the already-tracked `2026_09_10_000000_add_production_php_runtime_identity_to_app_instances` migration before creating a production sample. It was applied only inside discovery.
- One Laravel production setup attempt could not activate through the existing creation path, so the production observations used a non-Laravel App with the same production placement and release layout. This did not change the Process contract.
- Two early exploratory topology `exec` calls used the nonexistent CLI `artisan` path and recorded guest exits 127 and 1. All acceptance observations used successful topology shells, and both final topology `sync` and `verify` exited zero.
- The issue's stale no-TIA wording was not followed. The three changed-project checks use current TIA policy. The independent reviewer will run root `composer check` with TIA as the exact-candidate review gate.

Discovery development only; isolated acceptance proof not run
