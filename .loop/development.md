# ORB-339 development record

Flow: `discovery`. Incus: not required.

Candidate: `5c6d8c87a25cb24578fbc603ae2ef82e46329c8d`
Base: `83fb3b78bd809b8312454292924e8ba8ec3078cb` (ORB-338 / ADR 0074 on main)

## Contract

Explicit `keep_alive` on Process and process definitions. Restart policy never implies keep-alive.

- Sweep (`SweepIdleAppDevRuntimesAction`) stops desired-running Processes that are not keep-alive. Keep-alive Processes stay up. An AppInstance whose every desired-running Process is keep-alive is not marked asleep.
- Wake (`ActivateAppInstanceRuntimeAction`) starts every desired-running Process, including a keep-alive Process that is down. Operator `process:stop` (`desired_state=stopped`) stays stopped.
- Doctor does not report a sleeping non-keep-alive desired-running+stopped unit as drift. A keep-alive unit that is down while desired running remains `process.state_mismatch`.
- AppInstance-only. Node Processes and app-prod stay outside hibernation.

## Documentation audit (no plan)

Fixed:

- `docs/reference/app-dev-runtime-hibernation.md`
- `docs/reference/app-processes-and-schedules.md`
- `docs/architecture.md`
- `docs/concepts.md`

Reported: none. ADR 0074 is unchanged; the reference page is the operator contract.

## Acceptance evidence

Item 1 — Opt-in Process remains running across idle hibernation; others stop and wake on traffic; documented contract.

| Check | Result |
| --- | --- |
| `HibernationActionsTest` leaves keep-alive Processes running while it hibernates the rest | pass |
| `HibernationActionsTest` does not mark an AppInstance asleep when every desired-running Process is keep-alive | pass |
| `HibernationActionsTest` starts a desired-running keep-alive Process on wake when it is down | pass |
| `AppDevHibernationPolicyTest` still applies to a keep-alive Process so restart policy stays independent | pass |
| `ProcessDoctorProbeTest` does not treat a sleeping non-keep-alive Process as drift | pass |
| `ProcessDoctorProbeTest` still reports a keep-alive Process that is down while the group is asleep | pass |
| `ProcessDoctorProbeTest` reports a non-keep-alive Process that is down while the AppInstance is awake | pass |
| `ProcessesTest` stores keep-alive independently of restart policy | pass |
| `AppRuntimeDefinitionsTest` persists `spec.keep_alive` | pass |
| `InstantiateAppRuntimeDefinitionsTest` copies `keep_alive` onto production AppInstance Processes | pass |
| CLI `ProcessCommandsTest` forwards `--keep-alive` independently of restart policy | pass |
| SDK `ProcessRequestsTest` forwards `keep_alive` without treating restart policy as the same contract | pass |
| `composer docs-lint` | pass |

Gateway TIA graph at this SHA recorded 2001 tests, 0 failures. CLI 919 passed. SDK 577 passed.

## Builder gate

`Builder gate: passed (/workspace/.git/orbit-checks/5c6d8c87a25cb24578fbc603ae2ef82e46329c8d/review-sakfjl6p/result.json)`

`role: builder`, `passed: true`, candidate `5c6d8c87a25cb24578fbc603ae2ef82e46329c8d`.

Selection warnings (3): CLI, Gateway, and PHP SDK `test:affected` selected no tests because the private TIA graphs already recorded this exact HEAD after the keep-alive suites ran (CLI 919, Gateway 2001, SDK 577, all zero failures).

A first root `composer check` (`review-59owrnfo`) failed only because cold-cache e2e TIA selected `TiaCacheTest`, whose Symfony Process wrapper times out at 60s. The fixture itself passed outside Pest (`38 tests in 96.827s`, OK). The candidate does not change `apps/e2e`. The unsuccessful TIA result was cleared after that independent verification so the gate does not keep selecting an unrelated harness fixture.
