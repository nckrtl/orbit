# Feature plan

Plan format: 1
Issue: ORB-223
Flow: discovery
Review verdict: PASS

## Outcome

The Gateway lets an agent manage reusable App-owned process and Schedule definitions without changing any existing AppInstance runtime.

## Code boundaries

In:
- `apps/gateway/database/migrations/2026_09_11_010000_create_app_runtime_definitions.php` and `apps/gateway/app/Models/{App,ProcessDefinition,ScheduleDefinition}.php`: persist separate App-owned process and Schedule definition collections with UUID identities, JSON applicability and specifications, per-App/per-kind name uniqueness, App relations, and cascading App deletion.
- `apps/gateway/app/Domain/AppDefinitions/**`, `apps/gateway/app/Data/AppDefinitions/**`, and `apps/gateway/app/Actions/AppDefinitions/**`: define the closed environment values, reuse the accepted Process and Schedule specification limits, expose command-safe list projections and complete item projections, and perform database-only create, full replacement, list, show, and delete operations.
- `apps/gateway/app/Http/Requests/AppDefinitions/**`, `apps/gateway/app/Http/Controllers/Api/AppRuntimeDefinitionsController.php`, and `apps/gateway/routes/api.php`: add strict App-scoped process-definition and schedule-definition resources, reject unknown or duplicate JSON members including nested specification members, bind definition UUIDs within their parent App, and declare `ServingNode::AppOwning` on all five routes for each definition kind.
- `apps/gateway/app/Http/Middleware/RecordCommandActivity.php`: retain safe definition mutation metadata while excluding command content from Activity.
- `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php`, `apps/gateway/tests/Feature/Domain/AppRuntimeDefinitionTest.php`, `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php`, and `apps/gateway/tests/Unit/Architecture/DoctorModelCoverageTest.php`: cover the public contract, validation and no-runtime-side-effect boundary, ownership lifecycle, sanitization, all ten exact App-owning route scopes, and configuration-only Doctor disposition.

Out:
- Runtime installation and live inheritance stay unchanged: definition actions do not call `apps/gateway/app/Infrastructure/**` or the existing Process and Schedule runtime managers and do not reconcile AppInstance copies.
- Candidate process or Schedule overrides are not read or copied, and production clone selection remains outside this issue; `CreateAppInstanceAction` and the provisioning and cloning paths stay unchanged.
- Existing Process and Schedule specifications, desired states, selected releases, and other AppInstance fields stay unchanged; this issue adds no instance-specification edit path.
- Deployment preparation, execution, and release selection stay unchanged.
- `packages/php-sdk/**` and `apps/cli/**` stay unchanged; this issue exposes Gateway HTTP resources only.
- `apps/e2e/**` and `bin/e2e-*` stay unchanged because the issue has no Incus requirement and needs no harness or topology work.

## Documentation

- `docs/reference/app-processes-and-schedules.md`: renamed its title and now states the definition fields, applicability values, process and Schedule specification boundaries, HTTP resources, authorization and command-disclosure limits, database-only mutations, App ownership, App deletion, and independence from AppInstance copies.
- `docs/README.md`: routes readers to the updated App process and Schedule definitions and copies reference.
- `docs/generated/context.json`: regenerated the documentation context for the updated title and content.
- Documentation audit fixed the missing App-definition contract in `docs/reference/app-processes-and-schedules.md`. It reported no other issue-scoped drift and needs no follow-up owner.
- The review correction removed a claim that definition storage creates AppInstance copies; copy selection and creation remain outside ORB-223.
- Verification passed again with `composer docs-build` and `composer docs-lint`; regenerated context was unchanged. The documentation commits are `07e88d3d` (`docs: describe App runtime definitions`) and `0a6678b4` (`docs: limit runtime definition behavior`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Both App definition resources expose list, create, item, full replacement, and deletion by UUID with names unique within their App and kind. | Definition migration and models; App-definition actions, data projections, controller, requests, and routes. | `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php` through `cd apps/gateway && composer test:affected`; assert every method, UUID routing, replacement semantics, a same-App/same-kind name conflict, and successful reuse of the same name by another App and by the other definition kind in the same App. |
| 2. Applicability is a nonempty unique set of `development` or `production`; process specifications use accepted runtime fields without target, start state, or host identity; Schedule specifications reuse command, calendar, and timeout limits. | `apps/gateway/app/Domain/AppDefinitions/**`, typed request data, and strict definition requests. | `apps/gateway/tests/Feature/Domain/AppRuntimeDefinitionTest.php` through `cd apps/gateway && composer test:affected`; exercise both valid environments, empty/duplicate/unknown values, both Process runtimes and failure modes, forbidden placement/state fields, and Schedule boundary values. |
| 3. Definition mutations make no remote call or runtime change, and AppInstance removal preserves definitions and unrelated copies. | Database-only definition actions and App ownership relations; existing runtime, release, and AppInstance-removal code remains outside the mutation path. | `apps/gateway/tests/Feature/Domain/AppRuntimeDefinitionTest.php` through `cd apps/gateway && composer test:affected`; bind failing spies for remote/runtime collaborators and compare Process, Schedule, selected-release, desired-state, definition, and unrelated-copy records before and after create, replace, delete, and AppInstance removal. |
| 4. The API uses App-operation authorization, rejects unknown or duplicate members and cross-App UUIDs, and excludes commands from lists, Activity, generic errors, and Doctor diagnostics. | App-scoped route binding and authorization; strict definition request parser; summary/item projections; Activity input handling; hidden model specification data; exhaustive route-scope configuration contract. | `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php` and `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php` through `cd apps/gateway && composer test:affected`; cover Gateway and placed-App access, inaccessible callers, cross-App IDs, raw duplicate/escaped-duplicate and unknown members at root and `spec`, sentinel command absence from every named disclosure surface, and all ten definition routes mapped to `ServingNode::AppOwning`. Retain the placed, unplaced, and fail-closed App invariants in `apps/gateway/tests/Feature/Http/Authorization/ServingNodeResolverTest.php`. |
| 5. Removing an otherwise removable App deletes its definitions, and Doctor classifies definition records as configuration without remote artifacts. | Cascading App foreign keys and relations; existing `RemoveAppAction`; Doctor model-disposition architecture contract. | `apps/gateway/tests/Feature/Domain/AppRuntimeDefinitionTest.php` and `apps/gateway/tests/Unit/Architecture/DoctorModelCoverageTest.php` through `cd apps/gateway && composer test:affected`; assert both definition kinds cascade while existing App removal guards remain, and place both models in Doctor's configuration/owner-input disposition without a family or probe. |
| 6. Maintained documentation describes fields, applicability, ownership, and independent copies, with current generated context. | Pages listed in Documentation. | `composer docs-build` and `composer docs-lint` (passed before plan handoff). |
| 7. Changed-project and repository checks pass. | Whole Gateway change and candidate. | During implementation run `cd apps/gateway && composer test:affected`, `cd apps/gateway && composer check`, and `git diff --check`; after the clean candidate commit, the Builder runs root `composer check` with TIA across all five projects and retains its exact-candidate receipt. |

## Incus observations

Incus: not required. The selected flow is `discovery`; use the local acceptance tests and Builder candidate gate, with no topology or isolated acceptance proof.

## Implementation order

1. Add the focused API, domain, Doctor model-disposition, and exhaustive node-access route-scope cases so the closed schemas, UUID ownership, cross-App and cross-kind name reuse, authorization, lifecycle, disclosure, and no-runtime-side-effect boundaries fail before implementation.
2. Add the two definition tables and models, their App relations, UUID generation, casts and hidden sensitive specifications, cascade-on-App-delete behavior, and database uniqueness constraints.
3. Add the shared definition environment and specification validation layer, using the existing Process runtime rules and Schedule command, calendar, and timeout limits while excluding target, start-state, and derived host identity fields.
4. Add typed create/replacement inputs, command-safe collection output, complete item output, and database-only actions with stable validation and name-conflict errors.
5. Add the App-scoped HTTP routes, full-replacement requests, controller methods, scoped UUID binding, and Activity command exclusion; record `ServingNode::AppOwning` for `list`, `new`, `show`, `update`, and `remove` in both definition route families without changing `ServingNodeResolver` or its placed/unplaced App behavior.
6. Complete the Doctor configuration-only classification, rerun the focused TIA tests after each coherent change, then run the Gateway checks and clean-candidate Builder gate defined in the acceptance map.

## Must preserve

- ADR 0048: an App owns reusable process and Schedule definitions with development and production applicability.
- ADR 0048: changing an App definition must not change existing AppInstance copies or their runtime state, and changing or removing a copy must not change its definition.
- ADR 0048: AppInstance copies keep independent identifiers, runtime artifacts, desired state, and removal lifecycle; this issue does not implement production selection or copying.
- ADR 0048: candidate-specific overrides do not become App definitions, and the operating agent remains responsible for explicit changes to existing copies.
- ADR 0048 and ADR 0038: AppInstance removal retains App definitions while cascading only its AppInstance-owned Process and Schedule records.
- ADR 0013: Schedule definition validation retains the bounded command, native calendar, and execution-timeout limits, and command text remains sensitive application input.
- Existing App-operation authorization continues to derive serving Nodes from App placements and falls back to the active Gateway for an unplaced App; the definition endpoints add no permission model.
- `apps/gateway/tests/Feature/Http/Authorization/ServingNodeResolverTest.php` continues to protect stable distinct placed-App Node resolution, active-Gateway fallback for an unplaced App, and fail-closed behavior when that Gateway is absent.
- Existing App removal guards for Routes, AppInstances, and legacy Instances remain authoritative before the database cascades definitions.
- Existing Process and Schedule routes, actions, runtime managers, desired-state transitions, Doctor families, diagnostics, and remote artifacts remain unchanged.
- List, Activity, model debug output, generic errors, and Doctor diagnostics never reveal definition command content; only an authorized definition item response returns it.

## Open questions

none

## Deviations

- Issue check wording is aligned with the current discovery-flow policy: the stale request for all five full no-TIA CI suites maps to local Gateway TIA checks plus the Builder's exact-clean-candidate root `composer check`, which runs TIA across all five projects. Product acceptance is unchanged; the orchestrator should update the issue text to name this current gate.

## Review findings
