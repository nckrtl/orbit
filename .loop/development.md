# ORB-219 development record

Flow: `discovery`

Incus: required. Discovery attempt `456e6004680e1ea498978e5b0485ca41` was acquired only after the independent plan review passed. It remains available for reviewer inspection. Discovery development only; isolated acceptance proof not run.

## Candidate scope

The Gateway now has synchronous deploy and explicit code-rollback actions. Deploy captures the configured branch and ordered steps before it enters the shared AppInstance operation owner. Inside that owner it prepares one fresh branch-pinned release, synchronizes the stored environment, runs protected pre-activation scripts, publishes `current` atomically, refreshes only a recorded PHP runtime, and runs protected post-activation scripts. Rollback validates and selects one retained release without fetching, synchronizing environment, or running deployment steps.

Process and SSH invocation now support invocation-local output events and cancellation. One event value is at most 16,384 bytes. The final result independently retains the latest 65,536 bytes from stdout and stderr. Local and remote process groups are terminated on timeout, cancellation, or output-sink failure. Deployment events and command results redact output from normal debug representations.

The implementation adds no HTTP transport, automatic retry, run history, recovery policy, data rollback, inferred command, release deletion, SDK or CLI surface, or E2E harness change.

## Discovery fixture

- AppInstance: `2`, production user `orbit-app-2`, home `/home/orbit-app-2`
- Repository: `ssh://orbit@10.44.0.3/var/tmp/orb219-origin.git`
- Route: `https://orb219.orbit`
- Dedicated runtime: `orbit-orbit-app-2-php8.5-fpm.service`, socket `/run/php/orbit-app-2.sock`
- Initial release commit: `6f66dc8ed21a0626dcfbd5a0697a93678b6cc893` (`v1`)
- Advanced `release` branch commit: `9f415a27781ac75587bdaa38a9bb6fa47d3d647c` (`v2`)
- Final state: empty deployment-step configuration, active Route and PHP service, socket `orbit-app-2:caddy:660`, selected release `20260911091744-112e4a703989c983` at the advanced commit, and HTTP `v2` with status 200

The production user used an ephemeral discovery-only SSH key and host entry to clone the fixture repository from the app-prod Node itself. This topology configuration is not product or harness code.

## Incus observations

### `deployment-branch-source`

A deploy prepared `20260911084545-e496c863ecf072a9` from the configured `release` branch and paused in its first protected pre-activation step. Both Git inspections of that prepared release returned `6f66dc8ed21a0626dcfbd5a0697a93678b6cc893`. While paused, the remote branch advanced to `9f415a27781ac75587bdaa38a9bb6fa47d3d647c`; the prepared release remained pinned and `current` still named the prior release. After release, the result and stored `checkout_path` named the prepared release. A later deploy against `missing-branch` failed in `preparation` with `deployment.prepare_failed`, returned no prepared release, and left the selected release unchanged.

### `deployment-step-context`

The paused invocation had captured steps `captured-context` and `captured-after`. The stored configuration was replaced with a different branch and `replacement-only` command while the invocation was paused. The running invocation still executed only the captured steps. Its output recorded user `orbit-app-2`, the fresh release as `$PWD`, fixed non-interactive Bash, and the same pinned commit before and after the pause. The first output event arrived while the command was still running. Environment synchronization completed before the first configured step.

### `deployment-activation-order`

Release `20260911084725-d56233faae7ced6d` selected commit `9f415a27781ac75587bdaa38a9bb6fa47d3d647c`. The remote order file contained exactly `pre-one`, `pre-two`, `post-one`. `current` and `checkout_path` named that release. The dedicated service stayed active and its then-current master PID remained `4348`, which showed a cache refresh rather than a service reload.

For the non-PHP case, the recorded PHP identity was temporarily cleared and restored after the observation. An empty deployment selected `20260911085807-7cc828aab184245e` with no command or cache result. The unrelated dedicated PHP service retained PID `15552`, and its socket mtime stayed `1789117071`.

Activation initially exposed a fresh-release ACL defect: Caddy returned HTTP 403 because the new document root inherited `u:caddy:---`. The activation script was corrected to validate the selected root and grant Caddy traversal and read access before the atomic pointer switch. The focused infrastructure test was extended. Repeating the real activation returned HTTP 200 and `v2`.

### `deployment-failure-boundaries`

- Fetch failure: boundary `preparation`, error `deployment.prepare_failed`, no release, old selection retained.
- Unavailable stored reference: release `20260911085704-8b33d18ca040e15b`, boundary `environment`, error `env.reference_unavailable`, no application event or step marker, old selection retained. The invalid discovery value was restored.
- Pre-step failure: release `20260911085728-62dbb7a3be8004d1`, boundary `before_activation`, error `deployment.step_failed`, the configured persistent marker remained, the later marker was absent, and old `current` remained selected.
- Cache failure: the dedicated socket was stopped in a controlled window. Release `20260911085750-8ba2d3ee631edeee` became selected, then cache refresh failed at boundary `cache_refresh` with `app-prod.php_cache_socket_unavailable`. The service and owned socket were restored immediately.
- Post-step failure: release `20260911085732-f93ce4dd53f70296` remained selected after boundary `after_activation` reported `deployment.step_failed`; its persistent post-step marker remained and the later step was absent.

No failure path claimed to undo already completed environment, filesystem, or application-command effects.

### `deployment-output-timeout`

Release `20260911084749-5f5d1b028258e700` emitted early stdout at about 1.38 seconds and early stderr at about 1.58 seconds, before command exit. Each larger stream was split into four 16,384-byte events and one 1-byte event. The final stdout was the latest 65,536 `A` bytes and final stderr the latest 65,536 `B` bytes; both older prefix bytes were discarded and `truncated` was true. Final hashes were:

- stdout: `156c38442089c1323d3e3ba549a6ac24341c47e8b6367bec4740c9b8c865826e`
- stderr: `fee47b1f0d7685a226fd5f2b9dd8f525038bbb05fe9d89a5d75c249edac868e3`

The first timeout observation found that terminating local SSH did not reliably terminate its remote child. The protected remote wrapper was corrected to publish its process-group receipt and keep a parent-death watchdog. A second race in normal watchdog cleanup emitted Bash job-control diagnostics; completion-marker cleanup removed that race.

After both corrections, a 1-second step returned in 3.046 seconds including SSH and group cleanup, reported child PID `13276`, boundary `before_activation`, and `deployment.command_timed_out`. Cancellation returned in 2.111 seconds, reported child PID `13618`, boundary `before_activation`, and `deployment.cancelled`. In both cases the remote `sleep 60` group and later-step marker were absent, and old `current` was unchanged. Repeated successful short steps had zero infrastructure stderr.

The first PR review found that this wrapper trusted a process-group receipt in a directory writable by the AppInstance user. The corrected wrapper creates no PID receipt, completion marker, or shared temporary directory. Its privileged control shell derives the session leader from the kernel-reported direct child, verifies the parent PID, process-group ID, and expected numeric user ID, and sends group signals only as `orbit-app-2`. The active Node provides util-linux `setsid --wait`.

The correction rerun first rejected a direct `$!` assumption when live process inspection showed that `sudo` remained the supervisor and its `setsid` child owned the process group. Those exploratory timeout and cancellation groups were terminated as `orbit-app-2`, and their protected command files were removed. The wrapper was then changed to validate the actual direct child instead of treating `$!` as the group ID.

With that correction, the repeated 1-second timeout returned in 2.201 seconds, emitted shell PID `31100`, reported boundary `before_activation` and `deployment.command_timed_out`, and removed that process group and its `sleep 60` child `31101`. Cancellation after the first output returned in 1.541 seconds, emitted shell PID `31503`, reported boundary `before_activation` and `deployment.cancelled`, and removed that group and child `31504`. Both runs emitted stdout only, left zero `.orbit-deployment-group.*` directories and zero `.orbit-deploy.*` command files, skipped the later-step marker, and preserved `current` at `releases/20260911091744-112e4a703989c983`. Caddy kept PID `1057`; the final Route response remained HTTP 200 with body `v2`.

The accepted operation budget recorded by the action is the sum of configured step timeouts plus 900 infrastructure seconds. Focused deadline tests cover that calculation and cap propagation.

### `deployment-contention`

A deployment paused after it owned AppInstance `2`. A rollback contender with a one-second remaining operation deadline returned after 1.008 seconds with boundary `operation` and `env.operation_busy`. Before the owner was released, `current` remained `releases/20260911084545-e496c863ecf072a9`. The owner then completed once and selected `20260911090948-ce9ac8ff9499ed3a`.

A separate two-process run left a rollback waiting without a result while deploy owned the instance. After deploy completed once and selected `20260911090734-870966851914bd95`, the queued rollback ran once and selected the supplied retained release. The focused exclusion test confirms that deploy, rollback, layout adoption, removal, environment import/update/sync, and Route convergence receive the same cross-process owner; deployment-configuration replacement remains outside it. The timeout and cancellation observations confirmed that interrupted application commands were neither resumed nor replayed.

### `deployment-code-rollback`

Explicit rollback selected retained releases at both fixture commits and refreshed the dedicated PHP cache without running an application step. In the complete before/after comparison for `20260911085807-7cc828aab184245e`, these hashes stayed byte-identical:

- `.env`: `a4175a06392dec9229d473b2d36e51ae958c33ecb2ce66383880d5a2a18b56dd`
- `database.sqlite`: `c96d21e37708d88aa6674d9990e2e592da2aaff22cf310cdeb2a3ab7b4b06959`
- step sentinel: `c9c124ea56a3cbd3baa1c7746d277f08f8d01205586a4ee7a7f99f1fa8a7d880`
- bare-repository refs: `68918f834c32b77637eb34a33f1ffaab31f0b813179563cc57efdc57182259a7`

The selected release moved atomically from `20260911084545-e496c863ecf072a9` to `20260911085807-7cc828aab184245e`; the target commit was `9f415a27781ac75587bdaa38a9bb6fa47d3d647c`. The PHP master PID stayed `15552` and the service stayed active.

Traversal (`../...`), a retained clone with a foreign `origin`, and a retained release whose `public` root escaped to `/tmp` each failed at `rollback_selection` with `rollback.release_invalid`. `current` stayed on the prior valid release. The two controlled invalid releases were removed after the observation.

### `deployment-serving-continuity`

The final continuity rerun started from a valid Caddy-readable `v1` release and continuously requested `https://orb219.orbit` while a fresh `v2` release was prepared, published, and had its dedicated PHP cache refreshed. All 1,588 responses were complete HTTP 200 bodies: 679 were exactly `v1` and 909 were exactly `v2`. There were no curl failures, partial bodies, HTTP 403 responses, missing-root responses, or service-unavailable responses.

A separate configured post-activation command replaced its selected release entry point with a deliberate application response. Deployment succeeded, and the Route returned body `agent-selected-outage` with HTTP 503. Explicit rollback to clean retained release `20260911090613-a5eb3185fa78850e` restored body `v2` and HTTP 200. This distinguishes the configured command's retained availability effect from pointer-switch or cache-refresh continuity.

## Deterministic runner correction

The second independent PR review accepted the process runner's production rule but found its new final-output regression timing-dependent. On reviewed candidate `6357eeaf53605018a4455f77b88127ca1866384f`, separate focused Pest processes failed intermittently because a real child could emit `completed` while the preceding `isRunning()` sample was still true. That valid ordering requests cancellation, so the sleep-based test did not reliably exercise the completed-process branch it named.

The production `$running &&` cancellation guard remains unchanged. `NativeProcessRunner` now accepts one optional typed process factory and otherwise constructs the same `SymfonyProcess(['setsid', '--', ...$arguments])`. The regression supplies a controllable Symfony Process double. It fixes and asserts this order: start, `isRunning() === false`, final stdout read, empty stderr read, output delivery, and exit code 0. The cancellation callback returns true if invoked, counts its calls, and is asserted to remain unused. The result retains `completed` and succeeds.

The new test first failed with `Unknown named parameter $processFactory`, before the construction seam existed. After implementation and formatting, 100 separate focused Pest processes all exited zero. The complete real-process runner suite still covers default factory execution, protected input, output bounds, live cancellation, timeout, output-sink failure, and operating-system process-group cleanup.

The adopted resolver condition required no new Incus observation because this correction changes neither the runtime polling rule nor the remote wrapper or group logic. The existing successful `deployment-output-timeout` record above remains applicable, and discovery attempt `456e6004680e1ea498978e5b0485ca41` remains available for review.

## Local checks

- Deterministic final-output regression: `100` of `100` separate focused Pest processes passed after formatting.
- Complete `NativeProcessRunnerTest.php`: `11` passed, `44` assertions, with `--fail-on-warning`.
- Final focused deployment, exclusion, process, and SSH tests: `37` passed, `198` assertions, with `--fail-on-warning`.
- Production/AppInstance regression selection: `337` passed, `2,362` assertions.
- `cd apps/gateway && composer analyse`: passed with zero errors.
- `cd apps/gateway && composer check`: passed (`guidance:check`, Rector, Pint, and Larastan).
- `composer docs-build`: rebuilt `docs/generated/context.json`; it remained byte-identical to planning HEAD `850e6a342dcafbd4e20111bafc2a22471401e629`.
- `composer docs-lint`: passed with zero findings.
- `git diff --check`: passed.
- `bin/e2e-topology sync ORB-219` and `bin/e2e-topology verify ORB-219`: passed for discovery attempt `456e6004680e1ea498978e5b0485ca41`.
- Prior reviewed candidate root `composer check`: passed at `6357eeaf53605018a4455f77b88127ca1866384f` with an unchanged tree. Its later review found the intermittent test described above, so that receipt is preserved as history and is not the submitted gate.
- Final exact-candidate root `composer check`: passed at `52716ebc169bfd4340ad269690cd3399d001eb41`, tree `be2ec40a8ef56d06bc57d6c9397a082813f94477`, with `passed: true` and `unchanged: true`. All five strict validations, project checks, and TIA commands exited zero. Gateway TIA ran 45 affected test files: 858 tests and 6,389 assertions passed; the other four projects had no affected tests. Builder receipt: `/home/nckrtl/orbit/.git/orbit-checks/52716ebc169bfd4340ad269690cd3399d001eb41/review-r0xt_gja/result.json`.

Artifact publication and pushed-head binding are recorded in the implementation handoff.

## Documentation

Changed by planning and carried in this candidate:

- `docs/reference/deployments.md` documents deployment order, the exact event and retained-output limits, truncation, interruption, failure-side persistence, explicit retained-code rollback, operation exclusion, and serving continuity.

Audited without further changes:

- `docs/architecture.md`
- `docs/domains/applications.md`
- `docs/reference/environment-variables.md`
- `docs/reference/php-runtime.md`
- `docs/reference/app-processes-and-schedules.md`
- `docs/reference/appinstance-removal.md`
- `docs/reference/routes.md`
- `docs/generated/context.json`

Reported documentation findings: none.

## Deviations and limitations

- Acceptance item 11 retains the approved plan's policy correction: focused acceptance tests and the Gateway check run locally, followed by the retained Builder's root `composer check` with TIA on the exact clean candidate. GitHub CI is disabled. The product outcome is unchanged.
- Review correction: activation failures now re-inspect the selected release and synchronize `checkout_path` when inspection succeeds; a failed inspection preserves the original failure and last known selection. Native process cancellation is evaluated only while the process is still running, so final output cannot replace a completed command result with cancellation.
- Resolver correction: the final-output regression now drives the exit-sample/read order deterministically through a default-preserving process factory. No polling, process-group, or remote-wrapper behavior changed, so the adopted restart condition required no new Incus observation.
- Discovery development only; isolated acceptance proof not run.
