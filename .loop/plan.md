# Feature plan

Plan format: 1
Issue: ORB-215
Flow: discovery
Review verdict: PASS

## Outcome

The Gateway refreshes and verifies one production AppInstance's owning OPcache without reloading its PHP-FPM service or disturbing another production runtime.

## Code boundaries

In:
- `apps/gateway/app/Domain/AppInstances/ProductionPhpRuntimeManager.php`: add one typed cache-refresh operation for a production AppInstance, keeping cache work inside the existing narrow dedicated-runtime contract.
- `apps/gateway/app/Infrastructure/AppInstances/RemoteProductionPhpRuntimeManager.php`: resolve `ProductionPhpRuntimeIdentity::from()` before transport; serialize with the existing per-user runtime lock; verify the recorded marker, active service, master process, socket ownership, PHP version, FPM SAPI, and worker-to-master association; request `opcache_reset()` only through the recorded FastCGI socket; poll a later runtime observation until that reset is complete; clean temporary probe state; and map unavailable socket, wrong service association, rejected reset, and deadline expiry to distinct bounded failures while retaining generic transport failure separately.
- `apps/gateway/tests/Feature/Infrastructure/AppInstances/ProductionPhpCacheTest.php` and the existing `ProductionPhpRuntimeManager` fake in `NativeAppInstanceRemovalProjectorTest.php`: cover the fixed identity and socket, reset and completion receipts, deadlines, failure mapping, cleanup, unchanged service PIDs, and the absence of CLI reset, service reload or restart, socket scanning, and fallback behavior.

Out:
- No public remote-command, cache, deployment, API, PHP SDK, or CLI surface is added; later deployment and rollback operations consume this internal runtime contract separately.
- Service provisioning, PHP package selection, generated runtime configuration, operator tuning, application cache commands, release activation, release selection, and deployment-step execution stay unchanged.
- No other AppInstance socket, service, master process, OPcache, Route, Caddy projection, source, model state, or application content is changed as a fallback or side effect.
- `apps/e2e` and `bin/e2e-*` harness code stay unchanged; the existing standard discovery topology supplies the required real-machine observations.

## Documentation

Documentation audit:

Scope: ORB-215; the `apps/gateway`, Gateway, and AppInstance context pages, plus the PHP runtime page required by the `docs` label.

Fixed:
- `docs/reference/php-runtime.md`: the production cache section named only a future verified refresh boundary -> it now states recorded service and socket verification, in-FPM reset, later completion observation, the bounded distinct failure conditions, and the prohibition on CLI reset, alternate sockets, and service reload fallback.

Audited without changes:
- `docs/domains/applications.md`: it already routes production AppInstance cache behavior to the PHP runtime reference and does not duplicate the mechanism.
- `docs/reference/deployments.md`: it correctly owns release selection and keeps deployment separate from cache refresh.
- `docs/generated/context.json`: `composer docs-build` rebuilt the context after the page edit and produced no byte change.

Reported:
- none.

Verification: `composer docs-build` and `composer docs-lint` passed; documentation commit `1d070c5b2427d907eaaad9b7211dc62d78e036f4`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Refreshing one of two warmed dedicated production runtimes serves its changed code while the other runtime keeps its warmed code and master PID. | `ProductionPhpRuntimeManager`, its remote implementation, and `ProductionPhpCacheTest.php`. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Infrastructure/AppInstances/ProductionPhpCacheTest.php`; discovery observation `production-opcache-isolation` creates two production users on the same `app-prod` Node, warms and changes a marker in both runtimes, refreshes only the selected AppInstance, and records that only its response changes while both master PIDs and the control runtime's cached marker stay unchanged. |
| 2. Refresh uses the AppInstance's verified FPM identity and reports success only after an observable bounded completion, not after CLI reset or FastCGI transport success alone. | Canonical `ProductionPhpRuntimeIdentity`, per-user runtime lock, verified FastCGI probe and completion polling in the remote manager, and `ProductionPhpCacheTest.php`. | The same focused test command with filters for `identity`, `receipt`, `completion`, and `deadline`; discovery observation `production-opcache-completion` records the service, socket, master and worker identity, holds an in-flight request while reset becomes pending, confirms refresh does not claim early success, then releases it and records the later completed reset and new response without changing the master PID. |
| 3. An unavailable socket, wrong service association, failed reset, or reset still pending at the deadline returns a distinct bounded failure without touching another socket or reloading a service. | Strict remote preflight, bounded FastCGI client, explicit result mapping, cleanup, and failure cases in `ProductionPhpCacheTest.php`. | The same focused test command with filters for `unavailable`, `association`, `reset`, and `pending`; discovery observation `production-opcache-failure` induces each condition on the selected runtime, records its distinct bounded result, restores only controlled setup state, and records the control runtime's PID, warmed response, socket, and service state as unchanged. |
| 4. Maintained documentation describes cache refresh verification and failure boundaries, and generated context is current. | Documentation section above. | `composer docs-build && composer docs-lint`; `git diff --exit-code 1d070c5b2427d907eaaad9b7211dc62d78e036f4 -- docs/generated/context.json` when implementation needs no later documentation correction. |
| 5. Focused acceptance tests, the changed Gateway checks, and the exact clean-candidate repository gate pass. | Changed Gateway runtime and test boundaries plus repository quality tooling. | Run the focused commands above, then `cd apps/gateway && composer check`; on the committed clean candidate the retained Builder runs root `composer check`, which runs all five project checks and TIA suites and records the exact-head `result.json`. |

## Incus observations

Incus: required; flow: discovery. After independent preflight approval, acquire the standard ORB-215 discovery topology without the `app-prod` extension and use the existing `bin/e2e-topology shell`, `exec`, `sync`, and `verify` commands. Create two controlled plain-PHP AppInstances with separate production users on the same physical `app-prod` Node, then run `production-opcache-isolation`, `production-opcache-completion`, and `production-opcache-failure` as the reproducible observations in the acceptance map. Invoke the new internal runtime operation from the mounted Gateway because the issue adds no public command. Record exact setup, commands, AppInstance and runtime identities, marker values, service PIDs, socket state, reset observations, deadlines, exit codes, bounded errors, restoration, and final topology verification in `.loop/development.md`. Proof instrumentation and `observed_inputs` are not required. Discovery development only; isolated acceptance proof will not run.

## Implementation order

1. Add focused failing cache tests for canonical identity, exact-socket use, successful reset followed by completion observation, incomplete or malformed success receipts, each required failure, deadline behavior, cleanup, and prohibited fallbacks; add the cache method to the runtime contract and its existing test fake.
2. Extend the remote runtime manager with one fixed-argv operation under the existing production-user lock that authenticates the recorded marker, active service and master, exact socket, FPM worker identity, and PHP version before it can request a reset.
3. Send the reset through the recorded Unix socket, require explicit reset acceptance, poll later FastCGI observations until the same runtime reports completed reset state, bound connection, response, and overall time, remove temporary probe state on every exit, and translate only the declared operation outcomes into distinct bounded errors.
4. Acquire standard discovery after plan approval, create both dedicated runtimes on the same `app-prod` Node, execute the three named observations, restore controlled failure setup, run topology verification, and save the exact commands and results in `.loop/development.md`.
5. Run the focused Gateway test, `cd apps/gateway && composer check`, documentation verification, and useful TIA feedback; then commit the clean candidate for the retained Builder's root `composer check` and later implementation handoff.

## Must preserve

- ADR 0045: every production Unix user keeps a separate PHP-FPM master and service, and each production AppInstance remains bound to that user's service, pool, socket, and OPcache while installed PHP packages stay shared by version.
- ADR 0045: refresh reaches only the owning service's OPcache, requires no service reload, verifies reset completion before success, and never resets another production user's cache or reloads another user's service.
- ADR 0045: production runtime provisioning and its service, user, pool, and socket associations remain Orbit-owned, while the operating agent's PHP and PHP-FPM tuning remains Node-owned, starts from defaults, survives later operations, and stays separate from replaceable generated identity.
- ADR 0045: effective generated configuration and required associations remain validated before Orbit activation or reload; no PHP tuning value is added to App or AppInstance storage; existing application content and operator tuning remain unchanged.
- ADR 0046's adjacent deployment boundary remains separate: this operation does not select or activate a release, run application steps, retry a deployment, or roll back code; a later deployment coordinator places the verified refresh after its atomic switch.
- Existing `opcache.validate_timestamps=0`, runtime converge and removal recovery, mixed dedicated and legacy shared-runtime behavior, binary Node access, pinned Gateway SSH, fixed typed remote argv, bounded and redacted command output, and generic transport failures remain unchanged.

## Open questions

none

## Deviations

- Acceptance item 5's stale generic "all five full no-TIA CI suites" venue maps to the current ADR 0059 policy: focused acceptance tests and `cd apps/gateway && composer check` run locally, then the retained Builder runs root `composer check` on the exact clean candidate with all five project checks and TIA. GitHub CI is disabled and is not a merge gate. The issue text should use this current proof wording; the observable acceptance outcome is unchanged.

## Review findings
