# Feature plan

Plan format: 1
Issue: ORB-73
Flow: discovery
Review verdict: PASS

## Outcome

Expose exactly eight UUID-keyed Gateway Schedule operations with bounded responses, strict input, Node-scoped authorization, filtered collections, and sanitized activity records.

## Code boundaries

In:
- `apps/gateway/routes/api.php`, `apps/gateway/app/Http/Controllers/Api/SchedulesController.php`, `apps/gateway/app/Http/Controllers/Api/ScheduleCompletionsController.php`, `apps/gateway/app/Http/Requests/Schedules/**`, and `apps/gateway/app/Data/Schedules/**`: expose list, add, show, run, logs, complete, remove, and activate; parse each body or query strictly; bind item routes only by UUID; and serialize bounded collection, item, and logs envelopes.
- `apps/gateway/app/Actions/Schedules/**`, `apps/gateway/app/Http/Authorization/**`, and `apps/gateway/app/Domain/Nodes/NodeAccessAuthorizer.php`: reuse the existing Schedule lifecycle actions, add the authorized collection query, resolve Node and AppInstance targets to their serving Node, enforce normal Node access for operator operations, and retain installed-Node-only completion authorization.
- `apps/gateway/app/Http/Middleware/RecordCommandActivity.php` and `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php`: identify the Schedule UUID and target for list, add, show, run, logs, remove, and activate while persisting only bounded sanitized input and result facts; keep completion outside activity recording.
- `apps/gateway/tests/Feature/Api/SchedulesTest.php`: cover all eight routes, strict validation, UUID lookup, active-peer and Node access, collection isolation, response bounds, activity count and redaction, completion authorization, activation, and desired timer state.

Out:
- No Schedule update or rename endpoint, numeric Schedule lookup, command-text or completion activity, run history, or wider response exposure.
- No Doctor repair or mutation, Doctor API or persisted-report change, and no change to Schedule runtime or Doctor infrastructure beyond invoking the existing lifecycle actions.
- No PHP SDK or CLI transport and commands under `packages/php-sdk` or `apps/cli`; those remain with their linked issues.
- No production deployment, `apps/e2e` or `bin/e2e-*` harness change, proof topology, or other Incus resource.

## Documentation

- `docs/reference/schedules.md`: now states the eight public endpoints, add input and timer-state behavior, UUID and strict-validation boundary, Node access and collection filtering, bounded responses, sanitized activity, and completion exception.
- Documentation audit scope: the `apps/gateway`, Gateway, Schedule, Node, AppInstance, and Doctor contexts. Fixed `docs/reference/schedules.md`, whose Limits section contradicted this issue by denying a public Schedule API. Reported findings: none.
- `composer docs-build` rebuilt the context index without a content change. `composer docs-lint` passed. Documentation commit: `24c40771` (`docs: describe the Schedule API`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Exactly eight UUID-keyed routes; unknown UUID is `404`; malformed, duplicate, escaped-duplicate, unknown, and wrongly typed input is `422` before mutation. | Schedule routes, controllers, requests, and data envelopes. | `apps/gateway/tests/Feature/Api/SchedulesTest.php` through `cd apps/gateway && composer test:affected`. |
| Every operation enforces active-peer and Node access; list filters inaccessible targets; only the installed Node completes. | Schedule authorization scopes, serving-Node resolution, accessible-Node query, and completion controller. | `apps/gateway/tests/Feature/Api/SchedulesTest.php` through `cd apps/gateway && composer test:affected`. |
| Completion creates no Activity; every other operation creates one sanitized Activity without sensitive Schedule or runtime text. | Activity middleware and Schedule target/activity projection. | `apps/gateway/tests/Feature/Api/SchedulesTest.php` through `cd apps/gateway && composer test:affected`. |
| List omits command; authorized show and logs are bounded; validation and exceptions disclose no command or log text. | Schedule response data, request validation, controllers, and error path. | `apps/gateway/tests/Feature/Api/SchedulesTest.php` through `cd apps/gateway && composer test:affected`. |
| Maintained documentation states the public API, authorization, activity, and completion contract; generated context is current. | `docs/reference/schedules.md` and generated documentation context. | `composer docs-build && composer docs-lint`. |
| Gateway checks pass. | All changed Gateway boundaries. | `cd apps/gateway && composer check`. |
| Repository candidate checks pass under the current TIA policy. | Finished clean candidate across all five Composer projects. | Focused `cd apps/gateway && composer test:affected`, then the Builder's root `composer check` receipt for the exact candidate. |
| Activate accepts an empty body, authorizes the AppInstance Node, rejects Node targets, is idempotent, and returns bounded Schedule data. | Activate route/request/controller, serving-Node authorization, existing activation action, and response data. | `apps/gateway/tests/Feature/Api/SchedulesTest.php` through `cd apps/gateway && composer test:affected`. |
| Add defaults optional boolean `start` to true, rejects false for Node targets, exposes independent desired timer state, and run preserves that state. | Add request/data/controller, Schedule response data, and existing add/run actions. | `apps/gateway/tests/Feature/Api/SchedulesTest.php` through `cd apps/gateway && composer test:affected`. |

## Incus observations

Incus: not required. The API contract is observable with the existing fake Schedule runtime in the Gateway feature test, followed by Gateway TIA and quality checks and the Builder's root TIA candidate gate. No topology is required in the discovery flow.

## Implementation order

1. Add `SchedulesTest.php` coverage for the eight-route inventory, response envelopes, strict raw-JSON and empty-body handling, UUID misses, Node-access matrix, list filtering, sanitized activity, completion, activation, and desired timer state.
2. Add the Schedule request and response data types, the authorized list action, the resource and operation controller methods, and the eight explicit UUID-constrained routes while delegating mutations to the existing Schedule actions.
3. Extend serving-Node resolution and collection filtering so Node and AppInstance Schedule targets use binary Node access while completion retains its installed-host rule.
4. Extend activity target and safe-input projection for the seven operator operations, including successful and failed requests, without persisting command, calendar, logs, output, paths, users, or unit text.
5. Run Gateway TIA tests and `composer check`; commit a clean candidate for the Builder's root `composer check` gate.

## Must preserve

- ADR 0013: the UUID remains the only public Schedule identity; the API has no edit, rename, enable, disable, generic command, or numeric lookup; strict parsing rejects omitted-required, duplicate, escaped-duplicate, unknown, and wrongly typed members before mutation.
- ADR 0013: list, add, show, run, logs, complete, and remove retain the existing active-peer and binary Node-access boundary; a collection reveals only addressable targets; completion is installed-Node-only and creates no Activity.
- ADR 0013: operator Activity and generic diagnostics contain no command, calendar, journal, output, path, user, unit, credential, or raw remote-result text; list omits command, and authorized show and logs stay bounded.
- ADR 0047: cloned target processes and Schedules remain stopped until an operator explicitly starts them, and this API work does not change the candidate or its application state during cloning.
- ADR 0048: an AppInstance Schedule can finish installation disabled, only the explicit activate operation enables and starts it, manual run remains separate from timer activation, and Node-owned Schedules retain their enabled-install behavior.
- ADR 0060 and existing completion tests: completion replaces only latest-run time and status, carries no generation or run-history behavior, and a missing or removing Schedule remains safe for late callbacks.
- Existing stable error envelopes, request correlation, thin-controller/final-readonly-action structure, Spatie Data response convention, Schedule lifecycle idempotency, and exact-owned runtime behavior remain unchanged.

## Open questions

none

## Deviations

- Issue acceptance item 7 names five full no-TIA CI suites. The current implementation-loop policy replaces that stale venue with focused Gateway `composer test:affected`, Gateway `composer check`, and the Builder's root `composer check` with TIA on the exact candidate. The acceptance outcome remains unchanged; the orchestrator should align the issue text with this repository policy.

## Review findings
