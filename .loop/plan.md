# Feature plan

Plan format: 1
Issue: ORB-364
Flow: proof
Review verdict: PASS

## Outcome

Make Gateway hibernator service and timer installation repeatable on Ubuntu, including replacement of existing units.

## Code boundaries

In:
- `apps/gateway/app/Infrastructure/Hibernation/NativeRuntimeHibernatorConverger.php`: publish rendered units through protected temporary regular files and retain failure reporting and cleanup.
- `apps/gateway/tests/Unit/Infrastructure/Hibernation/NativeRuntimeHibernatorConvergerTest.php`: verify contents, execution order, configured account, and temporary-file cleanup on successful and failed writes.

Out:
- Hibernation policy, unit names/directives, and Gateway account selection remain unchanged in the renderer and domain.
- CLI UX and shared hosts remain outside this repair; all machine observations use this issue's own disposable topology.
- Harness code under `apps/e2e` and `bin/e2e-*` remains unchanged.

## Documentation

none: this restores repeatable installation without changing documented hibernation behavior. Audited the relevant installation statements in `docs/reference/app-dev-runtime-hibernation.md`, ADR 0074, and `docs/solutions/remote-file-writes-on-uutils-coreutils.md` against the converger, renderer, tests, and issue contract. The solution already distinguishes regular-file sources from the affected stdin case. Fixed: none. Reported: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Initial, repeated, and changed-content installation succeeds on Ubuntu uutils 0.8.0 with root ownership and 0644 mode | NativeRuntimeHibernatorConverger.php | Incus action `repeatable-install` in `.loop/proof/ORB-364.json`: invoke the real converger with absent units, repeat unchanged, change sweep interval and Gateway account to another existing unprivileged account, compare complete rendered contents and root:root 0644 metadata, assert timer enabled, then restore the normal configuration. Record OS, install version, launcher and candidate identity. |
| 2. Preserve contents, configured account, reload order and timer name | NativeRuntimeHibernatorConverger.php and its unit test | `tests/Unit/Infrastructure/Hibernation/NativeRuntimeHibernatorConvergerTest.php` through Gateway `composer test:affected`; the same Incus action inspects installed units and enabled timer. |
| 3. Preserve failed-step/error identity, suppress reload/enable, and remove temporary input after success and failure | NativeRuntimeHibernatorConverger.php and its unit test | Gateway TIA tests inspect mode-0600 regular-file inputs while each process executes, verify removal afterward, fail each unit write in turn, and exercise thrown process exceptions. Assert no later reload or enable invocation and original provisioning error/result. |

## Incus observations

Incus: required. Flow: proof. Acquire a separate ORB-364 discovery after independent preflight PASS; retain a distinct exact-candidate proof. Use `observed_inputs: false`: this narrowly scoped native converger action runs Gateway CLI PHP only and cannot supply complete app-dev CLI and Gateway FPM observations. Declare `mutates: true` because acceptance removes and rewrites disposable units, and restore normal units before general verification. Use Solo for visible terminal inspection and retain candidate-bound action output. ORB-351 resources are never used for this issue.

## Implementation order

1. Reuse `ProtectedInput::fromString()` and its regular-file stream metadata in the converger, with explicit `finally` cleanup and fixed install argv. Preserve publication step and error codes, including preparation failures.
2. Adapt the existing tests to inspect temporary input during execution and add success, write-failure and thrown-exception cleanup assertions.
3. Run Gateway TIA and project checks. Write the proof fixture, run E2E `composer test:fresh`, commit the clean candidate, run the Beast Builder root gate, and publish immutable artifacts.
4. Run and capture fresh ORB-364 proof, inspect it through Solo, release idle discovery, and obtain independent candidate review before authorized closeout.

## Must preserve

- ADR 0074: the Gateway owns idle sweep, markers and wake. Its existing service/timer continues to execute the sweep as the configured unprivileged Gateway account.
- ADR 0074: no changes to Node or production Processes, Schedules, PHP-FPM, Caddy interception, wake readiness, or app-dev boot intent. This patch only changes the source used to install the existing Gateway units.
- Existing renderer contents, timer name, configured account fallback, synchronous process execution, and root-owned mode-0644 destinations.
- Both writes succeed before daemon-reload and timer enablement; process failure retains `gateway.hibernator_install_failed` and the service/timer step.
- Temporary input remains mode 0600 while in use and is closed after success and every exception; no shell interpolation or unit bytes in argv.

## Open questions

none

## Deviations

none

## Review findings
