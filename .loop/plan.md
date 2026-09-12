# Feature plan

Plan format: 1
Issue: ORB-198
Flow: discovery
Review verdict: PASS

## Outcome

An authorized Gateway caller can clone an eligible development or production candidate into an independently prepared production AppInstance with a stable private preview and no implicit deployment.

## Code boundaries

In:
- `apps/gateway/routes/api.php`, `app/Http/Controllers/Api/`, `app/Http/Requests/AppInstances/`, `app/Data/AppInstances/`, and `app/Http/Middleware/RecordCommandActivity.php`: add the standalone `POST /instances/{candidate}/clone` transport, strict top-level input parsing, ordinary AppInstance response, and redacted activity metadata.
- `apps/gateway/app/Http/Authorization/ServingNode.php`, `ServingNodeResolver.php`, `RequireNodeAccess.php`, and their tests: resolve the candidate and destination Nodes and require access to both without changing the existing any-matching-Node rule for other scopes.
- `apps/gateway/database/migrations/`, `app/Models/AppInstance.php`, and the cloning action/domain types under `app/Actions/AppInstances/` and `app/Domain/AppInstances/`: persist the immutable candidate/request identity and bounded clone checkpoints on the target, reserve one production placement, return completed retries, and reject conflicting retries.
- `apps/gateway/app/Domain/AppInstances/`, including `ProductionAppInstanceSourceLifecycle.php` and `ProductionRouteProjector.php`, `app/Infrastructure/AppInstances/`, including `RemoteProductionAppInstanceSourceLifecycle.php` and `NativeProductionRouteProjector.php`, and `app/Providers/AppServiceProvider.php`: inspect development checkouts or selected production releases without mutation, verify clean Git and repository/branch eligibility, reconstruct and source-safety-check the target release, retain the existing source profile, and checkpoint the dedicated runtime, Transport Layer Security certificate, app-prod firewall, Caddy, and private Domain Name System projections before activation.
- `apps/gateway/app/Actions/Routes/CreateRouteAction.php` and `app/Domain/Routes/`: derive and preflight the explicit private preview from `preview_name` and the destination Node's own TLD before target reservation, then keep the recorded hostname stable across retries and later TLD changes.
- `apps/gateway/app/Domain/AppInstances/Environment/`, `app/Actions/AppInstances/SynchronizeAppInstanceEnvironmentAction.php`, and `app/Models/AppInstanceEnvironmentValue.php`: copy stored values as independent encrypted rows under the two-instance operation lock; add a clone-only provisioning context that derives target placeholders from the exact associated pending Route and reuses remote preflight, rendering, and atomic writing without weakening the public environment endpoints' active-AppInstance and active-Route guard.
- `apps/gateway/app/Domain/AppInstances/Sqlite/`, `app/Infrastructure/AppInstances/RemoteAppInstanceSqliteSeeder.php`, and `app/Actions/AppInstances/InstantiateAppRuntimeDefinitionsAction.php`: invoke the existing optional consistent SQLite seed and production definition-copy boundaries from the clone lifecycle while preserving stopped Process and Schedule state.
- `apps/gateway/tests/Feature/Api/CloneAppInstanceTest.php`, `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php`, and focused authorization, database, environment, source, Route, SQLite, runtime-definition, and infrastructure tests: cover every accepted result, refusal-before-mutation boundary, checkpoint retry, and source/target isolation invariant.

Out:
- Cluster Router projection stays unchanged; cloning accepts only a standalone destination and uses its own TLD.
- First deployment, deployment steps, release activation, rollback, and `current` selection stay unchanged and are never invoked by cloning.
- Framework commands, dependency installation, application health checks, and inferred application setup stay unchanged and are never invoked by cloning.
- Database registration and database-path environment rewriting stay unchanged; cloning supports only the explicit optional SQLite seed.
- Candidate source, environment, database, queue, Process, Schedule, and runtime desired state stay unchanged; eligibility inspection is read-only.
- The transitional direct production AppInstance creation API and its current lifecycle stay unchanged.

## Documentation

- `docs/reference/appinstance-cloning.md`: now describes clone inputs, two-Node access, candidate eligibility, preview derivation and provenance, independent target source/environment/data/runtime state, source preservation, retry, and separate first deployment.
- `docs/generated/context.json`: regenerated so the cloning page is indexed under its App, AppInstance, Cluster, Gateway, Node, Route, and Schedule terms and ADRs 0044, 0047, and 0048.
- Documentation audit scope: ORB-198 context selected with component `apps/gateway` and every named canonical term: App, AppInstance, Node, Route, Cluster, Gateway, and Router. Fixed: `docs/reference/appinstance-cloning.md` covered only optional SQLite seeding and now covers the complete current issue contract. Owner: ORB-198. Audited with no change: all other returned context pages, including the additionally selected `docs/reference/gateway-trust.md`, match current code, tests, accepted ADRs, and higher-authority pages; Router adds no page beyond this filtered result.
- Reported audit findings: none.
- Verification: `composer docs-build` and `composer docs-lint` passed; the two maintained documentation changes are committed as `45ed9bf7` (`docs: describe candidate cloning`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Clone transport accepts only candidate-derived target input and refuses malformed, duplicate, unknown, or forbidden fields before mutation. | Clone route, controller, request, data, activity, and response boundaries. | `apps/gateway/tests/Feature/Api/CloneAppInstanceTest.php` through `cd apps/gateway && composer test:affected`. |
| 2. The caller has access to both Nodes, the candidate is eligible, and existing production placement and active-role rules hold. | Clone authorization scope and the clone action's candidate/destination preflight. | `apps/gateway/tests/Feature/Api/CloneAppInstanceTest.php` plus focused `RequireNodeAccessTest.php` and `ServingNodeResolverTest.php`, through `cd apps/gateway && composer test:affected`. |
| 3. Dirty candidate state, unavailable commits, and a missing selected branch refuse before target preparation without caller-supplied SHA. | Candidate source-inspection domain contract and SSH implementation. | Reproducible discovery observation `candidate-clone-eligibility` on the extended ORB-198 topology, backed by focused infrastructure tests and the Builder gate. |
| 4. The preview uses normalized `preview_name` plus the destination Node's own TLD, is preflighted before reservation, and is stored with explicit provenance. | Clone preview resolver/preflight, Route action, and persisted clone identity. | `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php` through `cd apps/gateway && composer test:affected`. |
| 5. Target source is reconstructed from the selected App repository branch without selecting `current`, with inherited or explicit branch and the existing source profile. | Clone action, candidate source evidence, production source lifecycle, release layout, and classifier. | Reproducible discovery observation `candidate-clone-source` on ORB-198 discovery, backed by focused source-lifecycle tests and the Builder gate. |
| 6. Stored environment entries become independent encrypted target values, placeholders resolve for the target after preflight, and no local environment bytes or cache transfer. | Environment store/copy operation, two-instance lock, clone-only context for the associated pending Route, renderer, preflight, and writer; normal environment requests keep their active-owner guard. | Reproducible discovery observation `candidate-clone-environment` on ORB-198 discovery, backed by `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php` and focused context tests that prove the target hostname resolves from its pending Route and any copy, preflight, render, or write failure leaves both target and Route inactive for retry. |
| 7. SQLite omission creates nothing, while explicit selection installs one consistent seed without cleanup or database-path rewrites. | Clone checkpoint orchestration and existing AppInstance SQLite seeder/transfer contracts. | Reproducible discovery observation `candidate-clone-data` on ORB-198 discovery, backed by focused SQLite tests and the Builder gate. |
| 8. Target preparation installs only production App definitions stopped and provisions only the required default production runtime without candidate artifacts or tuning. | Runtime-definition instantiation, source classification, production runtime identity/manager, and clone checkpoints. | Reproducible discovery observation `candidate-clone-runtime` on ORB-198 discovery, backed by focused definition-copy/runtime tests and the Builder gate. |
| 9. Completion returns one active AppInstance and sole private Route with no selected release, application gate, framework command, deployment, worker, or timer start. | Clone completion transaction after source-safety/Caddy-access, runtime, certificate, firewall, Caddy, and private-DNS checkpoints; AppInstance response, pending-to-active Route publication, and stopped runtime copies. | `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php` with focused failure/checkpoint cases for every projection, plus reproducible discovery observations `candidate-clone-projections` and `candidate-clone-prepared`, through TIA and the Builder gate. |
| 10. Candidate Git, stored environment, data, queues, and desired runtime states remain unchanged while existing work continues. | Read-only candidate inspector, copy/snapshot contracts, and clone orchestration. | Reproducible discovery observation `candidate-clone-source-preserved` on ORB-198 discovery, backed by domain isolation assertions and the Builder gate. |
| 11. Persisted target identity and checkpoints resume an interrupted identical request, reject conflicts, and make completed retry terminal without replacing target state. | AppInstance clone migration/model state and idempotent clone action. | Reproducible discovery observation `candidate-clone-retry` plus `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php`, focused database tests, TIA, and the Builder gate. |
| 12. Maintained documentation describes the clone contract and generated context is current. | `docs/reference/appinstance-cloning.md` and `docs/generated/context.json`. | `composer docs-build` and `composer docs-lint`. |
| 13. Changed Gateway checks and repository checks pass under current local-gate policy. | All changed Gateway boundaries and the exact clean candidate. | `cd apps/gateway && composer test:affected`, `cd apps/gateway && composer check`, then root `composer check` as the Builder gate on the clean committed candidate. |
| 14. Production-candidate cloning to temporary `app-prod-2` preserves live work, and a database-free non-Laravel candidate creates no implicit SQLite file. | Extended discovery topology and the complete clone lifecycle. | Reproducible discovery observations `production-candidate-clone` and `database-free-candidate-clone`, followed by `bin/e2e-topology verify ORB-198` and the Builder gate. |

## Incus observations

- Incus: required. Flow: discovery. After independent plan approval, acquire one ORB-198 discovery topology extended with `app-prod-2`; use a harness-compatible `.loop/proof/ORB-198.json` `extension: app-prod` declaration only to select the fourth Node and retain repeatable action vectors. Do not invoke `prove`, capture isolated proof, or treat the declaration as immutable proof evidence.
- Run `candidate-clone-eligibility` against development candidate variants with staged, unstaged, nonignored untracked, submodule, unavailable-commit, and missing-branch states; record each refusal and absence of target preparation.
- Run `candidate-clone-source`, `candidate-clone-environment`, `candidate-clone-data`, and `candidate-clone-runtime` against a live development candidate; inspect reconstructed release ownership with no `current`, target-resolved environment values, one consistent optional SQLite seed, stopped definition copies, dedicated default PHP runtime when required, and excluded candidate artifacts.
- Run `candidate-clone-projections`; after source and environment preparation, inspect the retained source-safety result, dedicated runtime association, target certificate, app-prod firewall, Caddy site, and private-DNS entry before the final transaction exposes both the AppInstance and Route as active. Force each projection boundary to fail in focused tests and verify its checkpoint remains inactive and resumes without selecting `current`.
- Run `candidate-clone-prepared` and `candidate-clone-source-preserved`; inspect the active target and sole private Route, no selected release or started workers/timers, and unchanged candidate Git, environment, database/queue contents, and desired/running runtime state.
- Interrupt one owned boundary and run `candidate-clone-retry`; verify identical resumption, conflicting refusal, and a completed retry that preserves subsequent target edits and final hostname.
- Run `production-candidate-clone` from the live production candidate to `app-prod-2`, then run `database-free-candidate-clone` for a file-based non-Laravel source without `sqlite_source_path`; verify the source workload remains running and the second target has no `database.sqlite`.
- End with `bin/e2e-topology sync ORB-198` and `bin/e2e-topology verify ORB-198`. Record `Discovery development only; isolated acceptance proof not run` in the implementation handoff.

## Implementation order

1. Add strict clone request parsing and a clone-specific authorization scope that resolves both source and destination Nodes and requires access to each before controller execution.
2. Add the persisted clone identity/checkpoint fields and model support, then cover migration safety, immutable retry matching, placement uniqueness, completed retries, and conflict behavior.
3. Add the read-only candidate source inspector for development checkouts and selected production releases, including complete Git dirtiness, submodule, remote commit, selected-branch, placement, and source-profile evidence.
4. Add the clone action's mutation-free preflight for destination role/placement, Node-owned preview hostname, Route occupancy, and initial or terminal retry classification; after those checks pass, reserve the target and associate its explicit Route in pending state so the stored hostname is authoritative before any target environment rendering.
5. Reuse the production source and release-layout services to prepare the target user and repository-derived release, persist branch/source-profile evidence, run the existing source-safety and Caddy-access validation, and keep `current` absent.
6. Copy stored environment rows under the shared operation owner; resolve target placeholders through a clone-only context that requires the exact pending Route and accepted clone checkpoint, then reuse preflight/render/write. Keep `SynchronizeAppInstanceEnvironmentAction` and every public environment endpoint on the existing active-owner context. Checkpoint environment completion only after the atomic write, then add the optional consistent SQLite seed without changing stored database-path values.
7. Instantiate production App Process and Schedule definitions in stopped state; checkpoint the default dedicated runtime, certificate, and app-prod firewall; publish Caddy and private DNS through `ProductionRouteProjector`; then atomically mark the exact pending Route and target active. A failure at any boundary keeps both inactive, preserves `current` absence, and resumes without replacing completed state.
8. Add the API/domain/infrastructure tests, create the extended discovery declaration and repeatable observations, run Gateway TIA and project checks, and retain exact results for the Builder's clean-candidate gate.

## Must preserve

- ADR 0047: require an existing eligible development or production candidate; reject dirty or repository-unavailable source; inherit or explicitly select an existing branch; reconstruct from the App repository; duplicate stored environment; optionally snapshot one SQLite database or create none; keep all candidate state unchanged; copy only production App definitions stopped; exclude candidate overrides, generated artifacts, dependencies, and local tuning; preflight and store one private preview; keep deployment separate; and preserve completed target state on retry.
- ADR 0044: keep one encrypted stored value per target key, preserve literal values and supported expressions, omit values from responses/activity/diagnostics, resolve references from the target's exact associated pending Route during clone provisioning, run remote access/path/identity/capacity preflight before decryption, replace `.env` atomically, import no local edits, run no framework/cache/process side effects, and preserve the normal environment operations' active-owner guard.
- ADR 0048: select only production-applicable App definitions, never candidate overrides, create independent target-owned Process and Schedule copies from target identity, leave existing copies and App definitions independent, preserve completed copies on retry, and install Process services and Schedule timers stopped/disabled.
- ADR 0045: bind each production target to its own Unix user, service, pool, socket, and OPcache while sharing packages by version; seed only Orbit defaults; keep generated identity separate from preserved local tuning; validate associations; and never alter another production user's service, cache, or tuning.
- ADR 0032: explicit branch input takes precedence and must exist, inherited candidate selection remains distinct, branch intent stays recorded and independent of target/Route identity, and a changed retry is refused rather than treated as an update.
- ADR 0034: classify valid Composer source as PHP, select the highest supported matching PHP version with PHP 8.5 as the unconstrained default, expose no caller PHP input, and prepare no PHP for non-PHP source.
- ADR 0028: associate at most one Route with the target and establish exactly one Route before exposing the AppInstance as active.
- ADR 0030: retain source-safety, runtime, network, Transport Layer Security, and firewall validation before activation; checkpoint the existing dedicated runtime, certificate, app-prod firewall, Caddy, and private-DNS boundaries; then complete AppInstance and Route activation without an application response or health gate, dependency/key/database requirement, inferred setup, framework bootstrap, or selected `current` release.
- ADR 0029: keep the sole Route authoritative for target Laravel URL configuration and preserve unrelated stored application settings through destination-specific environment resolution.
- ADR 0046: keep persistent environment and optional SQLite data outside releases, leave `current` absent until an explicit first deployment, run no application or deployment command during cloning, and do not replace target data during later deployment or rollback.
- ADR 0031 as superseded by ADR 0047: do not revive its direct-creation source selection inside candidate cloning, and preserve the transitional direct production creation API, its initial-source evidence, and its retry behavior unchanged.
- ADR 0038: retain automatic removal ownership for every cloned AppInstance Process and Schedule copy, resumable cleanup, and isolation from Node-owned or other AppInstance runtime state.
- Existing direct creation, registration, Route, environment, SQLite, production layout/deployment, runtime-definition, Process/Schedule, removal, access-control, response-redaction, and activity tests remain green.

## Open questions

none

## Deviations

The issue's generic "all five full no-TIA CI suites" proof wording is superseded by the current repository policy. Development uses Gateway TIA and its project check, and the Builder runs root `composer check` with TIA on the exact clean candidate. GitHub CI is disabled. The acceptance outcome is unchanged; the orchestrator should align the issue text with this current check venue.

## Review findings
