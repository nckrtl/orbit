# Feature plan

Issue: ORB-77
Review verdict: PENDING

## Outcome

Add App-owned Route intent with one exclusive Node-or-Cluster scope and zero or one validated AppInstance target across the Gateway, PHP SDK, and CLI, including generated development hostnames without changing traffic projections or legacy records.

## Code boundaries

In:

- `apps/gateway/database/migrations/`: add `routes` and `route_targets` tables with restrictive App, Node, and Cluster ownership; globally unique normalized hostnames; one non-null Node-or-Cluster scope; stored `generated` or `explicit` provenance; `private` or `public` publication intent; `LifecycleStatus` state and nullable `failed_step` and `error_code`; at most one target row per Route; cascade deletion from Route to target; and target clearing when its AppInstance is removed so the Route retains its scope and intent. Protect immutable App, scope, and provenance columns at the persistence boundary.
- `apps/gateway/app/Models/{App,AppInstance,Cluster,Node,Route,RouteTarget}.php`, `apps/gateway/app/Domain/Routes/**`, `apps/gateway/app/Data/Routes/**`, and `apps/gateway/app/Actions/Routes/**`: add typed relations, hostname normalization, provenance and publication values, exclusive-scope and target validation, stable list/show serialization, idempotent explicit creation, explicit-hostname/publication updates, atomic target replacement or clearing, and Route-only removal. The response carries the Route's App and scope IDs plus a nullable typed target containing only its AppInstance ID; it never returns a backend Node, address, URL, or balancing field.
- `apps/gateway/app/Actions/AppInstances/CreateAppInstanceAction.php`: derive the effective routing scope as the active TLD-bearing Cluster or otherwise the direct Node, reserve generated Route intent before remote source mutation when an effective TLD exists, and attach the target only after the AppInstance is active. An identical AppInstance retry resumes the same generated Route; a Node with no effective TLD creates no Route. Keep the existing managed-clone state machine and source evidence intact.
- `apps/gateway/app/Http/Controllers/Api/RoutesController.php`, `apps/gateway/app/Http/Requests/Routes/**`, and `apps/gateway/routes/api.php`: expose authenticated create, list, show, update, target-set, target-clear, and remove endpoints. Use strict top-level JSON inspection, numeric route parameters, a scalar AppInstance target, and thin controller methods over the Route actions.
- `apps/gateway/app/Http/Authorization/{ServingNode,ServingNodeResolver}.php`, `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php`, `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php`, and `apps/gateway/tests/Feature/Api/RoutesTest.php`: give Route endpoints an explicit direct-Node or Cluster-member access scope, retain request correlation and bounded activity subjects, and prove the complete Route API contract and persistent invariants.
- `apps/gateway/app/Data/Instances/InstanceData.php`, `apps/gateway/app/Data/Workspaces/WorkspaceData.php`, and their existing models and migrations remain production-code unchanged; `apps/gateway/tests/Feature/Api/RoutesTest.php` and `apps/gateway/tests/Feature/Api/WorkspacesTest.php` prove that Route operations neither rewrite nor replace legacy hostname and certificate fields.
- `apps/gateway/tests/Unit/Architecture/DoctorModelCoverageTest.php`: classify `Route` and `RouteTarget` exactly once as typed owner inputs to the existing `instance` family. Do not change `DoctorFamily`, `InstanceDoctorProbe`, or a live inspector in this persistence-only issue.
- `packages/php-sdk/src/Requests/Routes/**`, `packages/php-sdk/src/Responses/Routes/**`, and `packages/php-sdk/src/Support/**`: add seven typed Saloon requests and bounded Route/target DTOs. Validate positive IDs, normalized hostnames, publication values, exclusive scope, non-empty updates, and scalar target input before transport while preserving request IDs and normal error envelopes.
- `packages/php-sdk/.ai/rules/public-contract.md`, `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`, and `packages/php-sdk/tests/Unit/Requests/Routes/RouteRequestsTest.php`: extend the closed SDK surface from 61 to 68 concrete operations, name the seven Route operations, and prove exact methods, endpoints, payload omission, DTO bounds, invalid local input, and the absence of backend and balancing escape hatches.
- `apps/cli/app/Commands/Routes/**`, `apps/cli/tests/Feature/Routes/RouteCommandsTest.php`, and `apps/cli/tests/Feature/CommandSurfaceTest.php`: add `route:new`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:clear`, and `route:remove`. Keep commands HTTP-only; validate all locally decidable IDs, hostnames, publication values, duplicate values, missing updates, and Node-versus-Cluster conflicts before creating a request; and render deterministic human and JSON forms without backend fields.

Out:

- Keep Router and workload Caddy rendering, request forwarding, Gateway dnsmasq, certificate issuance, firewall mutation, and Route health or convergence code unchanged. Route state remains stored control-plane intent for later projection work.
- Keep the Cluster Ingress role, public listener behavior, and public DNS-provider automation unchanged. `public` records publication intent only.
- Enforce one scalar target and one target row per Route. Do not add target arrays, balancing policy, health weighting, failover, draining, or multi-Node pools.
- Do not rename generated Routes after an App slug, App main branch, Node or Cluster TLD, Cluster state, or membership change. Do not add App-update or routing-scope reconciliation.
- Keep AppInstance source creation, registration, conversion, checkout handling, and removal unchanged except for the narrow generated-Route reservation and post-activation target hook. Do not change legacy Instance or Workspace records, conversion logic, `apps/e2e`, or `bin/e2e-*`.

## Documentation

Completed before this acceptance map and committed as `8246920e docs: document Route ownership and targets`:

- `docs/concepts.md` now defines a Route as an App-owned hostname with stored provenance, one exclusive routing scope, and zero or one same-App, same-scope AppInstance target.
- `docs/architecture.md` now describes Route intent and generated Route creation after development source activation, while keeping runtime and traffic projection separate.
- `docs/domains/applications.md` now states the effective-TLD precedence, main and feature hostname shapes, no-TLD behavior, generated scope, and initial target.
- `docs/reference/routes.md` now owns the operator-facing Route record, generated hostname, operation, target-validation, compatibility, and persistence-only limits.
- `docs/README.md` now routes readers to the Route reference.
- `docs/generated/context.json` was regenerated and indexes the Route reference and updated Route relationships.
- Audit scope used ORB-77; component labels `apps/gateway`, `packages/php-sdk`, and `apps/cli`; concepts App, AppInstance, Route, Node, Cluster, Gateway, and Doctor; every page returned by the filtered context command; and the Route pages required by the `docs` label. The audit fixed the four coverage gaps above. No finding needs a separate owner.
- `composer docs-build` completed, `composer docs-lint` passed with zero findings, and `git diff --check -- docs` passed before the documentation commit.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Creating a Route returns the same typed App, exclusive scope, normalized hostname, stored provenance, publication intent, and one AppInstance target through API, SDK, and CLI. | Gateway Route migration, models, data, actions, requests, controller, and routes; SDK Route requests/responses; CLI Route commands | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php`, `cd packages/php-sdk && ./vendor/bin/pest tests/Unit/Requests/Routes/RouteRequestsTest.php`, and `cd apps/cli && ./vendor/bin/pest tests/Feature/Routes/RouteCommandsTest.php` each exit `0` and cover matching typed payloads without inferring provenance. |
| Development AppInstance creation generates the main or feature hostname from the effective Node-or-active-Cluster TLD, and creates no generated Route without an effective TLD. | `CreateAppInstanceAction.php`; Gateway Route hostname/scope domain and actions; `RoutesTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php` covers exact main-branch equality, feature naming, active Cluster precedence, Node fallback, and the no-TLD case and exits `0`. |
| A Route stores exactly one Node or Cluster scope, and target clear retains scope, hostname, and publication intent while reporting no active backend. | Route migration constraints, `Route.php`, `RouteData.php`, create and clear-target actions/requests | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php` covers both scope forms, rejects both-or-neither persistence, clears the target atomically, and exits `0`. |
| Cross-App, out-of-scope, inactive, and direct-Node targets are rejected unchanged, while a valid replacement keeps Route identity. | Route target validator, set-target action/request, scalar Route target data | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php` covers every refused target against a pre-existing target and one valid replacement and exits `0`. |
| A second target, duplicate or malformed hostname, and conflicting App or scope retry fail, while an identical retry returns the existing Route. | Route schema uniqueness, hostname validator, create action/request, one-target constraint | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php` covers normalized hostname collision, malformed labels and length, unsupported multi-target input, conflicting retries, and an identical retry and exits `0`. |
| Removing a Route deletes only its target row and leaves its App, Node, Cluster, AppInstance, and checkout unchanged. | Route and target foreign keys; remove action/controller | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php` snapshots every non-Route record and checkout value, removes the Route, verifies only Route-owned persistence is gone, and exits `0`. |
| Legacy Instance hostname and certificate fields and Workspace hostname remain readable and unchanged by Route operations. | Unchanged `InstanceData.php`, `WorkspaceData.php`, legacy models/migrations; `RoutesTest.php`; `WorkspacesTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Api/RoutesTest.php tests/Feature/Api/WorkspacesTest.php` proves retained response fields before and after Route create, update, and remove and exits `0`. |
| Every Route SDK request and CLI command rejects locally decidable bad input before HTTP, and no target transport exposes backend Node, URL, or balancing input. | SDK Route request constructors/support and tests; CLI Route command validators and tests | `cd packages/php-sdk && ./vendor/bin/pest tests/Unit/Requests/Routes/RouteRequestsTest.php` and `cd apps/cli && ./vendor/bin/pest tests/Feature/Routes/RouteCommandsTest.php` assert no pending request for bad input and inspect every accepted request and response shape; both exit `0`. |
| Doctor classifies Route and route target once as typed inputs to the existing `instance` family and adds no family. | `DoctorModelCoverageTest.php`; unchanged Doctor family and probe code | `cd apps/gateway && ./vendor/bin/pest tests/Unit/Architecture/DoctorModelCoverageTest.php` discovers both new models, proves their one non-overlapping typed-input disposition, preserves eight families, and exits `0`. |
| Maintained documentation states ownership, provenance, generated shapes, scope, and target invariants, with current generated context. | `docs/concepts.md`; `docs/architecture.md`; `docs/domains/applications.md`; `docs/reference/routes.md`; `docs/README.md`; `docs/generated/context.json` | From the repository root, `composer docs-build` and then `composer docs-lint` both exit `0`, and the build leaves no diff in `docs/generated/context.json`. |
| Gateway checks pass. | All `apps/gateway` boundaries above | `cd apps/gateway && composer check` exits `0`. |
| PHP SDK checks pass. | All `packages/php-sdk` boundaries above | `cd packages/php-sdk && composer check` exits `0`. |
| CLI checks pass. | All `apps/cli` boundaries above | `cd apps/cli && composer check` exits `0`. |
| All repository test suites pass. | All implementation and documentation boundaries above | From the repository root, `bin/test` exits `0`. |

## Implementation order

1. Start from the committed Route documentation contract. Change it only if implementation reveals a deviation, then regenerate context, rerun both documentation commands, and commit that correction as a further `docs:` commit.
2. Add `RoutesTest.php` cases for schema ownership, normalization, idempotent create, exclusive scope, one target, target replacement and clearing, removal isolation, generated names, and legacy compatibility. Add the Route migration, enums and validators, models and relations, typed data, and actions until this persistence/domain slice passes.
3. Integrate generated Route reservation with `CreateAppInstanceAction`: compute effective TLD and scope before source mutation, retain resumable Route intent with interrupted AppInstance creation, and attach the target only after active source evidence. Rerun `RoutesTest.php` and the existing `AppInstancesTest.php` to preserve the source state machine.
4. Add the seven strict Gateway requests, controller methods, named API routes, Route access resolution, and activity subject resolution. Update the node-access route matrix, then rerun `RoutesTest.php`, `NodeAccessRouteScopeTest.php`, and the legacy Workspace proof.
5. Extend Doctor model coverage with `Route` and `RouteTarget` as `instance` typed inputs and run the focused architecture test without adding a family or live Route inspector.
6. Add the seven SDK requests and bounded responses with local validation, extend the 68-operation guidance contract and its test, and make `RouteRequestsTest.php` pass with exact transport and rejection coverage.
7. Add the seven thin CLI commands and deterministic renderers, update the exact command vocabulary and signature matrix, and make `RouteCommandsTest.php` and `CommandSurfaceTest.php` pass with no-request assertions for locally invalid input.
8. Run all focused proofs from the acceptance map, the three component `composer check` commands, root `bin/test`, root `composer docs-build`, root `composer docs-lint`, and `git diff --check`. Confirm the generated context is clean and no product path outside the listed boundaries changed.

## Must preserve

- ADR 0009, “Close domain ownership and identity”: an App owns each Route; the Route owns its hostname, publication intent, lifecycle state, and target; a target references one AppInstance rather than an independent Node and stays within the Route's App and routing scope. The schema may support later evolution, but this issue exposes at most one target and no balancing policy.
- ADR 0009, “Generate development Routes and accept explicit Routes”: exact main-branch equality produces `<app-slug>.<effective-tld>`, every other development name produces `<instance-name>.<app-slug>.<effective-tld>`, no effective TLD produces no generated hostname, and explicit input does not prove DNS ownership.
- ADR 0009, “Keep placement and runtime on Nodes,” “Use independent AppInstance clones,” “Route through one Cluster Router,” and “Publish DNS as a routing projection”: Route persistence must not change placement, source, runtime prerequisites, Router state, Caddy, certificates, firewalls, health, or DNS.
- ADR 0017, “Keep Cluster membership optional” and “Retain the Node TLD and give the active Cluster precedence”: AppInstance ownership stays App plus Node, and the effective development TLD stays `active Node Cluster TLD ?? Node TLD` without storing Cluster identity on AppInstance.
- ADR 0017, “Give every Route exactly one routing scope”: a Route stores one direct Node or one active TLD-bearing Cluster, never both; a direct Route target belongs to its Node; a Cluster Route target belongs to a member Node; and clearing the target retains Route identity, hostname ownership, scope, and publication intent.
- ADR 0017, “Reconcile membership and Cluster lifecycle before traffic cutover” and “Preserve standalone resources during migration”: this issue performs no routing cutover or scope reconciliation and does not relabel, move, convert, or delete legacy or AppInstance source.
- ADR 0016, “Reconcile slug changes without moving placement” and “Apply main-branch changes prospectively”: provenance is an immutable stored `generated` or `explicit` value. Existing generated hostnames do not change in this issue after App slug, main-branch, or scope changes, and explicit hostnames never change implicitly.
- ADR 0011, “Separate Ingress, Router, and workload ownership” and “Preserve Route independence and defer balancing policy”: public publication stays intent only; target transport contains no public edge, backend Node, URL, address, Caddy, certificate, or balancing input; and Ingress remains unaware of target placement.
- ADR 0004, “Use eight ordered families,” “Partition every persisted model,” and “Keep Doctor verify-only”: Route and RouteTarget are typed `instance` inputs exactly once, not a ninth family or checked resource, and this issue adds no observation, report, mutation, or persisted Doctor state.
- The existing `reserved -> checkout_prepared -> source_resolved -> active` managed-clone lifecycle, immutable checkout path, branch and starting-commit evidence, retry checks, source-only removal boundary, and AppInstance API response remain intact.
- `TopLevelJsonObjectInspector` continues to reject malformed objects, duplicate top-level keys, and unsupported fields; all new API endpoints retain active-peer authentication, explicit binary node-access scope, stable request IDs, bounded errors, sanitized activity input, and numeric route binding.
- Legacy `InstanceData` continues to return `hostname` and `certificate_mode`; `WorkspaceData` continues to return `hostname`; their models, source, runtime, and API identity remain separate from Route records.
- The SDK remains framework-neutral and typed, preserves explicit non-null caller values, omits only contract-defined nulls, disables no TLS verification, retains request correlation and redaction, and exposes no raw response escape hatch.
- The CLI remains stateless and HTTP-only, exposes `--json` on every Route command, preserves the exact visible command vocabulary and request-ID error behavior, and performs no SSH or infrastructure mutation.
- The E2E harness, proof files, GitHub, Linear, production release, dependency manifests, and unrelated components remain unchanged.

## Open questions

- none; ORB-77, the five accepted attached ADRs, the landed AppInstance and Cluster contracts, and the named focused tests determine the persistence, transport, documentation, and proof boundaries.

## Deviations

- none.

## Review findings
