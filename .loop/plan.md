# Feature plan

Plan format: 1
Issue: ORB-72
Flow: discovery
Review verdict: PASS

## Outcome

The Gateway stores Node- or AppInstance-owned Schedules and manages their safe native systemd execution, latest result, removal, and verify-only Doctor inspection.

## Code boundaries

In:
- Schedule persistence, UUID and target identity, lifecycle and desired timer state, latest-run fields, validation, stable errors, and model relations in `apps/gateway/database/migrations/**`, `apps/gateway/app/Models/{Schedule,Node,AppInstance}.php`, `apps/gateway/app/Data/Schedules/**`, and `apps/gateway/app/Domain/Schedules/**`.
- Target-derived Node, user, home, working directory, runtime shell, per-run environment, target admission, and placement guards in `apps/gateway/app/Domain/Schedules/**` and the bounded Node and AppInstance mutation actions under `apps/gateway/app/Actions/{Nodes,AppInstances}/**`.
- Protected script, oneshot service, persistent timer, host locking, calendar and artifact inspection, install and rollback, activation, manual run, bounded journald reads, completion reporting, and standalone cleanup in `apps/gateway/app/Actions/Schedules/**`, `apps/gateway/app/Infrastructure/Schedules/**`, and `apps/gateway/app/Providers/AppServiceProvider.php`.
- The private host-Node completion callback boundary in `apps/gateway/app/Http/{Controllers,Requests}/**` and `apps/gateway/routes/api.php`; no operator-facing Schedule route is added.
- AppInstance Schedule admission and resumable cascade cleanup in `apps/gateway/app/Actions/AppInstances/RemoveAppInstanceAction.php` and `apps/gateway/app/Actions/Schedules/**`, plus the `schedule` Doctor family, probe, inspector, issue catalog, dispatch, model disposition, and focused coverage under `apps/gateway/app/{Actions,Domain,Infrastructure}/Doctor/**` and `apps/gateway/tests/**`.

Out:
- Keep Workspace and Orbit-wide targets, a central scheduler, queue, worker, run history, replay, backfill, automatic movement, and failover absent.
- Keep Schedule edit, rename, disable, and in-place specification changes absent; only AppInstance timer activation is added, while remove and add remains the specification-change path.
- Keep the operator-facing public API, PHP SDK, and CLI Schedule surfaces for ORB-73, ORB-3, and ORB-71 unchanged; the private completion callback is the only HTTP boundary in this issue.
- Keep the public CLI off workload Nodes, production deployment and release behavior unchanged, and make no change under `apps/e2e` or `bin/e2e-*`.

## Documentation

Documentation commits: `5ab65755043f48b33c1d9a88739e2c082f4d7673` (`docs: describe Schedule runtime`) and `28f4d898d1ab5146da52027ec5be92ec06b09072` (`docs: correct Schedule recovery limits`).

Audit scope: ORB-72; the `apps/gateway` component and the Schedule, Node, AppInstance, and Doctor concepts selected by `composer docs-context`.

Fixed:
- `docs/reference/schedules.md`: added the exact limits, target contexts, desired timer behavior, systemd artifacts, latest-run callback, matching-add rollback, removal, stable errors, Doctor issues, security boundary, and current surface exclusions.
- `docs/reference/appinstance-removal.md`: added Schedule admission blocking, exact cascade cleanup, late-callback behavior, retry, and isolation alongside Process cleanup.
- `docs/reference/app-processes-and-schedules.md`: corrected its title and routed AppInstance Schedule ownership, stopped installation, activation, execution, reporting, and cleanup to the Schedule reference.
- `docs/concepts.md`: defined Schedule and linked its owning reference page.
- `docs/README.md`: made the Schedule reference discoverable.
- `docs/generated/context.json`: rebuilt the documentation context for the changed title, concepts, links, and new page.

Reported:
- none; the current issue and accepted ADRs determine all in-scope corrections.

Verification: `composer docs-build` passed, then `composer docs-lint` passed with no findings.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. UUID identity, one Node or AppInstance target, per-target name uniqueness, host, timeout, lifecycle, resumable identical add, and retry conflict | Schedule migration, model, identity, and lifecycle actions | `apps/gateway/tests/Feature/Domain/ScheduleLifecycleTest.php` |
| 2. Exact name, calendar, command, and timeout limits and default | Schedule value objects and admission validation | `apps/gateway/tests/Feature/Domain/ScheduleValidationTest.php` |
| 3. Authoritative Node and AppInstance execution context with no caller-supplied placement or identity | Schedule target resolver and admission guard | `apps/gateway/tests/Feature/Domain/ScheduleTargetResolverTest.php` |
| 4. Derived login-shell contexts and production `current` resolution across release switches | Schedule renderer and runtime context resolution | Discovery observation `schedule-execution-context` after `bin/e2e-topology sync ORB-72` |
| 5. One UUID-named protected script, oneshot service, and persistent timer with derived context and timeout | Schedule renderer and remote runtime manager | Discovery observation `schedule-install-artifacts` after `bin/e2e-topology sync ORB-72` |
| 6. Caller command only in protected script and never in infrastructure argv or units | Schedule renderer and fixed invocation builders | `apps/gateway/tests/Unit/Domain/Schedules/ScheduleRendererTest.php` |
| 7. Host systemd calendar validation, non-overlap, persistent timer, and non-blocking manual run | Remote Schedule runtime manager | Discovery observation `schedule-run` after `bin/e2e-topology sync ORB-72` |
| 8. Exact-service journald reads with line, byte, deadline, complete-line, and truncation limits | Schedule logs reader | Discovery observation `schedule-logs` after `bin/e2e-topology sync ORB-72` |
| 9. One authenticated host callback replaces only latest receipt time and status, with no retry or history | Completion action, private request boundary, and callback renderer | `apps/gateway/tests/Feature/Domain/ScheduleLifecycleTest.php` |
| 10. Exact rollback for an existing projection, first-add cleanup, and refusal of conflicting artifacts | Schedule projection transaction and ownership inspector | Discovery observation `schedule-add-rollback` after `bin/e2e-topology sync ORB-72` |
| 11. Standalone removing state, timer-only stop, direct service inspection, active-command wait, exact cleanup, and retry | Standalone Schedule removal action | Discovery observation `schedule-removal` after `bin/e2e-topology sync ORB-72` |
| 12. Node, host, and stable execution-context mutation guards with AppInstance cascade exception | Schedule target-use guard and Node/AppInstance integrations | `apps/gateway/tests/Feature/Domain/ScheduleLifecycleTest.php` |
| 13. AppInstance cascade removes exact Schedule state without waiting for active commands | AppInstance removal and Schedule cascade action | Discovery observation `schedule-appinstance-cascade` after `bin/e2e-topology sync ORB-72` |
| 14. Source refusal is non-mutating; accepted fixed sets block attachment and isolate unrelated Schedules | Shared Schedule admission lock and AppInstance removal acceptance | `apps/gateway/tests/Feature/Domain/ScheduleLifecycleTest.php`; discovery observation `schedule-cascade-isolation` |
| 15. Failed cascade stays resumable, reports no success, retries unfinished work, and preserves unknown artifacts | Schedule cascade action and AppInstance removal checkpoints | Discovery observation `schedule-cascade-retry` after `bin/e2e-topology sync ORB-72` |
| 16. Late or racing callback cannot recreate state or bypass removal | Completion action and removal transaction locking | `apps/gateway/tests/Feature/Domain/ScheduleLifecycleTest.php` |
| 17. Closed stable Schedule operation error catalog | Schedule error enum or catalog and exception mapping | `apps/gateway/tests/Unit/Domain/Schedules/ScheduleErrorCodeTest.php` |
| 18. Explicit `schedule` Doctor model disposition, family enum, dispatch, filters, and order without count coupling | Doctor family, dispatcher, model partition, and tests | `apps/gateway/tests/Unit/Architecture/DoctorModelCoverageTest.php` |
| 19. Closed Schedule Doctor issue catalog and read-only real inspection | Schedule Doctor probe and state inspector | Discovery observation `schedule-doctor-drift` after `bin/e2e-topology sync ORB-72` |
| 20. Bounded redacted Doctor, Activity, error, and diagnostic values | Schedule probe, issue data, exceptions, and activity redaction | `apps/gateway/tests/Feature/Domain/ScheduleDoctorProbeTest.php` |
| 21. Maintained Schedule docs and generated context cover the complete behavior | Pages listed in Documentation | `composer docs-lint` |
| 22. Gateway quality checks pass | All Gateway changes | `cd apps/gateway && composer check` |
| 23. Exact clean candidate passes the retained Builder gate with TIA | Whole candidate | Root `composer check` receipt for the exact committed candidate plus the focused tests in this map |
| 24. AppInstance `start=false` installs active but disabled before a first release; run and activate require `current` | Schedule add, target resolver, and runtime manager | Discovery observation `schedule-stopped-install` after `bin/e2e-topology sync ORB-72` |
| 25. Explicit AppInstance activation is verified, idempotent, and restores prior desired and actual state on failure | Schedule activation action and rollback | Discovery observation `schedule-activation-retry` after `bin/e2e-topology sync ORB-72` |
| 26. Node timers always start, AppInstance manual runs preserve intent, and Doctor accepts requested disabled state | Schedule admission, run action, and Doctor probe | `apps/gateway/tests/Feature/Domain/ScheduleDoctorProbeTest.php`; discovery observation `schedule-timer-intent` |
| 27. Remove and add changes an AppInstance Schedule specification while matching retry preserves timer intent | Schedule add matching and standalone remove actions | `apps/gateway/tests/Feature/Domain/ScheduleLifecycleTest.php` |

## Incus observations

Incus: required by the issue label. Use the selected `discovery` flow only after independent preflight approval. Acquire with `bin/e2e-topology acquire ORB-72 /fast/worktrees/orbit/orb-72`, then run `bin/e2e-topology sync ORB-72` before the first observation and `bin/e2e-topology verify ORB-72` after the observation set.

Drive each named scenario through Gateway Schedule actions on the `gateway` Node and inspect the target with `bin/e2e-topology exec ORB-72 gateway|app-dev|app-prod --argv-file=PATH`. Store each exact argv document and its result under `.loop/runtime/orb-72-discovery/` so the implementation handoff can reproduce execution context, artifacts, runs, logs, failure injection and rollback, standalone and cascading cleanup, Doctor drift, disabled installation, activation recovery, and desired timer state. Exercise both development and production AppInstance contexts where the criterion distinguishes them, and use a Node-owned Schedule for Node-only behavior and isolation.

Discovery development only; isolated acceptance proof not run. Do not create `.loop/proof/ORB-72.json`, proof fixtures, observed-input instrumentation, a proof topology, candidate convergence, equivalence evidence, or snapshot-closeout work.

## Implementation order

1. Add focused persistence and validation tests, then create the Schedule migration, model relations, lifecycle and timer-state enums, validated specification values, and closed operation-error catalog.
2. Add target-resolution and lifecycle guard tests, then derive Node, development AppInstance, and production AppInstance execution contexts; share Schedule admission locking with accepted AppInstance removal sets; block Node removal and stable placement or identity changes while preserving production release switches through `current`.
3. Add renderer and infrastructure unit tests, then build the protected wrapper, service and timer renderers, fixed SSH and sudo invocations, host lock, ownership inspection, calendar validation, bounded journal reader, and exact snapshot, publish, verify, and rollback transaction.
4. Add lifecycle tests, then implement add and matching retry, explicit AppInstance activation, non-blocking manual run, standalone removal, latest-run completion, and the private active-peer callback route without normal operator Activity.
5. Extend AppInstance removal tests and checkpoints, then add Schedule cascade cleanup after source preflight, block new attachment for every accepted fixed-set member, preserve unrelated and unknown artifacts, and make failure and callback races resumable and non-recreating.
6. Add Doctor probe and architecture tests, then add the ordered `schedule` family, explicit model disposition, stable issue catalog, read-only inspector, bounded comparisons, filter dispatch, and service bindings.
7. Run every focused test in the acceptance map, `cd apps/gateway && composer check`, `composer docs-lint`, and `git diff --check`; after preflight approval, acquire discovery and record every named Incus observation before preparing the clean candidate for the Builder's exact-head root gate.

## Must preserve

- ADR 0060: the host Node makes one completion report; the recorded host authenticates it; the Gateway replaces only receipt-time latest status; there is no generation, run identity, ordering, retry, or history; callback failure does not affect execution; removal inspects systemd directly.
- ADR 0048: an AppInstance Schedule is an independent target-derived copy; definition changes do not reconcile it; matching retries preserve completed state; stopped installation is valid; activation explicitly enables and starts its timer; manual run remains separate from activation.
- ADR 0047: production cloning and source behavior remain unchanged, target Schedules stay stopped until explicitly started, and the candidate's running state is never copied or changed by this runtime work.
- ADR 0046: production releases remain selected through the AppInstance's `current` link under its recorded home; Schedule execution follows that stable boundary without starting deployments, selecting releases, changing persistent data, or taking over application recovery.
- ADR 0038: accepted AppInstance removal cascades through every owned Schedule, starts only after source preflight, blocks new children, disables timers, does not wait for active commands, leaves no owned state on success, keeps failures resumable, and preserves Node-owned, other-instance, and unknown artifacts.
- ADR 0004: Doctor remains synchronous, in-memory, deterministic, verify-only, and read-only apart from its normal bounded request Activity; Schedule is one explicit family and checked model; all values and failures stay bounded and redacted; dispatch uses family tokens and canonical order, not a numeric count.
- ADR 0013 where not superseded: Gateway intent projects to native systemd on the derived host; exactly owned UUID paths and fixed argv protect caller command text; calendar validation, locking, candidate verification, atomic publication, rollback, bounded logs, manual run, standalone removal, and Schedule Doctor inspection keep their native and security boundaries.
- Existing Process admission, AppInstance removal, production deployment and rollback, Node removal, active-peer authorization, Activity redaction, SSH pinning, and generic Doctor tests remain green; no Schedule change weakens their ownership or retry guarantees.

## Open questions

none

## Deviations

- The implementation repairs pre-existing Doctor architecture-test drift. `AppInstanceDeploymentLayout` is classified as an excluded operational record, consistent with `AppInstanceRemoval`, and model discovery now ignores directories. This changes no product behavior and keeps the explicit checked-model contract enforceable.

## Review findings
