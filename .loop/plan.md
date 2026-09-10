# Feature plan

Issue: ORB-216
Review verdict: PASS

## Outcome

A production AppInstance stages replaceable code in a safe release layout with persistent environment, optional SQLite, and local tuning state outside releases, while the absent `current` link leaves code unselected until an explicit deployment.

## Code boundaries

In:
- `apps/gateway/app/Domain/AppInstances/ProductionAppInstanceSourceLifecycle.php`, a release-layout domain boundary beside it if separation is needed, and `apps/gateway/app/Infrastructure/AppInstances/RemoteProductionAppInstanceSourceLifecycle.php`: prepare and resume the fresh production-home layout, stage initial source beneath `releases/`, link the staged release to the home `.env`, leave `current` absent, and validate ownership, types, containment, and retry markers before publication.
- `apps/gateway/app/Models/AppInstance.php`, `apps/gateway/app/Infrastructure/AppDev/AppDevSiteRepository.php`, and `apps/gateway/app/Infrastructure/AppDev/AppDevCaddyConfigRenderer.php`: derive a production effective web-root path through `current` without requiring creation to select code, validate any selected release beneath the recorded home, and make Caddy resolve the root symlink before PHP path dispatch while preserving development rendering. ORB-216 supplies the projector and validation behavior for a selected-layout fixture; it does not create or replace `current` during provisioning.
- `apps/gateway/app/Domain/AppInstances/Removal/ProductionAppInstanceContentRetention.php`, `apps/gateway/app/Infrastructure/AppInstances/RecordedProductionAppInstanceContentRetention.php`, `apps/gateway/app/Infrastructure/AppInstances/NativeAppInstanceRemovalProjector.php`, and the corresponding service binding in `apps/gateway/app/Providers/AppServiceProvider.php`: authenticate and clear only the owned `current` serving projection during resumable removal while retaining the home releases, `.env`, optional `database.sqlite`, and local runtime tuning.
- `apps/gateway/tests/Feature/Infrastructure/AppInstances/ProductionReleaseLayoutTest.php`, `RemoteProductionAppInstanceSourceLifecycleTest.php`, `NativeAppInstanceRemovalProjectorTest.php`, `RecordedProductionAppInstanceContentRetentionTest.php`, and focused AppDev Caddy/model tests: cover staged source with no initial `current`, fresh and resumed layout, unsafe names and links, projector behavior against test-selected releases, PHP real-path rendering across fixture switches, retention cleanup, and unchanged development and legacy paths.

Out:
- Existing flat production homes are not converted; the layout code recognizes only fresh or already authenticated release layouts, leaving conversion to its separate lifecycle.
- No deployment or application command is executed, no release is selected through `current`, no later release is fetched or activated, and no rollback operation is added. The explicit first switch remains in ORB-219.
- Retained releases are not removed automatically.
- Development checkout placement, path derivation, Caddy behavior, and source removal remain unchanged.

## Documentation

## Documentation audit

Scope: ORB-216; `apps/gateway`, AppInstance, Effective web root, and Route context pages, plus the production release page required by the `docs` label.

Fixed:
- `docs/reference/deployments.md`: the ADR-owned detail page was absent -> it now defines the production home, staged initial source without `current`, explicit deployment selection, release environment link, optional SQLite location, path refusal boundary, Caddy/PHP real-path behavior, retained content, ownership, and limits.
- `docs/domains/applications.md`: production creation showed `current` as caller-supplied root, described a flat clone, and assigned later source changes outside Orbit -> it now describes staged source with no `current`, the future relative application root beneath an explicitly selected release, Orbit-owned release operations, and agent-owned application steps and recovery.
- `docs/concepts.md`: Effective web root did not distinguish production selection -> it now resolves production roots beneath the selected release and routes to the owning reference.
- `docs/reference/environment-variables.md`: production synchronization named only the home `.env` -> it now states how release links resolve that file and that literal database paths receive no inferred rewrite or placeholder.
- `docs/reference/appinstance-removal.md`: production retention named only generic application content -> it now distinguishes cleanup of the owned `current` serving link from retention of releases, environment, optional SQLite, and local tuning.
- `docs/README.md`: application references did not route readers to the production release layout -> it now links the owning page.
- `docs/generated/context.json`: rebuilt after the new page, concept, component, and ADR references.

Reported:
- none.

Verification: `composer docs-build` and `composer docs-lint`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| A new home has `releases/`, persistent destinations, staged source, and no premature `current` or SQLite file. | Production source lifecycle and release-layout infrastructure; `ProductionReleaseLayoutTest.php`. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Infrastructure/AppInstances/ProductionReleaseLayoutTest.php`; after discovery acquisition, create a fresh production fixture, assert `test ! -e <home>/current && test ! -L <home>/current`, and inspect its home with `bin/e2e-topology exec ORB-216 app-prod --argv='["sudo","find","/home/orbit-app-INSTANCE","-maxdepth","2","-printf","%P %y %l\\n"]'`, recording the resolved instance ID in `.loop/development.md`. |
| Every release resolves `.env` to the home file, while SQLite remains opt-in and stored literals remain literal. | Production release-layout preparation plus the unchanged environment context at the production home; `ProductionReleaseLayoutTest.php`. | The same focused test command with filters `environment link` and `SQLite`; in discovery, resolve each observed release `.env` with `sudo readlink -f`, assert the home `database.sqlite` is absent after preparation, then synchronize a literal `DB_DATABASE` value and read back only the protected configuration behavior through the existing environment API. |
| The effective root stays below `current` and its selected release; absent selection and unsafe path shapes fail closed. | `AppInstance::effectiveRoot()`, source-layout guards, site repository, and Caddy projection; `ProductionReleaseLayoutTest.php`. | The same focused test command with filter `effective root`; in discovery, first assert provisioning left `current` absent, then install a valid `current` link only as controlled observation-fixture setup, resolve it and the configured root with `sudo readlink -f` and `sudo realpath -e`, and record bounded refusals for parent traversal, an escaped release link, or conflicting `releases`, `.env`, and `current` entries. No product deployment or activation action runs. |
| PHP requests follow the selected release's real path, including through Caddy root-symlink resolution. | `AppDevSiteRepository`, `AppDevCaddyConfigRenderer`, and selected-layout validation; `ProductionReleaseLayoutTest.php` plus the focused AppDev infrastructure test. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Infrastructure/AppInstances/ProductionReleaseLayoutTest.php tests/Feature/Infrastructure/AppDev/AppDevInfrastructureTest.php`; in discovery, use controlled fixture setup to point `current` at two compatible retained releases whose entry point includes different release-local PHP content, and assert consecutive HTTPS responses return the old then new complete marker while `readlink -f` names the matching release. This observes projector behavior only; ORB-216 adds no switch action. |
| Removal retains production content and tuning, clears serving projections, and leaves development and legacy management intact. | Production content retention, removal projector integration, and existing development/legacy branches; `NativeAppInstanceRemovalProjectorTest.php` and `RecordedProductionAppInstanceContentRetentionTest.php`. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Infrastructure/AppInstances/NativeAppInstanceRemovalProjectorTest.php tests/Feature/Infrastructure/AppInstances/RecordedProductionAppInstanceContentRetentionTest.php tests/Feature/Domain/AppInstanceRemovalCoordinatorTest.php`; in discovery, remove the production fixture, verify `current` and owned Caddy/certificate/runtime projections are absent, verify retained files and local tuning hashes are unchanged, and run one existing development removal retry observation. |
| Maintained documentation describes the layout and retained-code boundary, with current generated context. | Documentation section above. | `composer docs-build && composer docs-lint`; `git diff --exit-code HEAD -- docs/generated/context.json` after the documentation commit. |
| Changed project checks and repository suites pass. | All changed Gateway PHP and test boundaries. | `cd apps/gateway && composer check`; focused commands above; `cd apps/gateway && composer test:affected` when a valid local TIA baseline is available; all five full no-TIA GitHub Actions jobs on the submitted candidate. |

## Incus observations

Incus observations: not applicable; discovery flow. The implementer will acquire discovery only after preflight review and will record the reproducible staged-without-selection layout, path-refusal, selected-layout projector, PHP fixture-switch, retention, and development-regression observations named in the acceptance map. Fixture setup may create or replace `current` only to observe downstream path behavior; no Orbit deployment action will perform the switch. Discovery development only; isolated acceptance proof will not run.

## Implementation order

1. Add focused release-layout tests that execute the production filesystem contract and important retry, ownership, type, containment, missing-selection, and database-absence failures.
2. Move initial production source below `releases/`, link its `.env` to the persistent home file, leave `current` absent after source resolution, and make identical retries authenticate the same staged layout without adopting conflicting content.
3. Resolve production model output and Caddy roots through the future `current` path, validate selected-layout fixtures without creating or replacing that link, enable Caddy root-symlink resolution for production PHP, and prove development rendering stays unchanged.
4. Extend production retention finalization to remove only the authenticated `current` projection after Route cleanup while retaining release, environment, database, and tuning content across retries.
5. Run all focused Gateway tests, discovery observations, Gateway `composer check`, and useful TIA; then commit and publish the candidate and artifacts for review and CI.

## Must preserve

- ADR 0031: initial production source is cloned before Laravel URL and Route publication, default or explicit branch selection and exact starting commit remain recorded, retries preserve unrelated content and do not reset an active placement, Git mutation remains limited to initial preparation, and removal retains production application content.
- ADR 0034: valid Composer metadata determines PHP project status and the highest supported compatible version, absent constraints default to PHP 8.5, callers do not select PHP, non-PHP source gets no runtime, and the Node role continues to own installed PHP packages.
- ADR 0038: removal still applies source preflight before child cleanup, blocks new children after acceptance, removes all owned Process and Schedule intent and runtime artifacts, retains resumable failure state, and leaves Node-owned, unrelated, and unrecognized artifacts untouched.
- ADR 0044: the Gateway remains authoritative for environment configuration; synchronization resolves only supported references, preserves stored literals, derives the home `.env` and production user from recorded placement, validates before decryption, replaces atomically, leaves the file unchanged on confirmed failure, and runs no application command or process restart.
- ADR 0045: every new production user keeps its dedicated service, pool, socket, and OPcache; installed packages stay shared; another user's service and cache remain untouched; generated runtime identity stays separate from preserved operator tuning; and removal retains local tuning.
- ADR 0046: releases stay within the recorded production home; environment configuration and optional SQLite remain outside replaceable code; provisioning and cloning leave `current` absent and require an explicit first deployment; the web root resolves within the active release after selection; deployment owns the atomic switch; failures before that switch do not change `current`; retained releases remain available; no application command, data replacement, automatic rollback, or deployment-history store is introduced; and existing flat production content remains unchanged pending explicit adoption.
- Existing legacy Instance Caddy and removal behavior, development checkout/worktree paths and removal safety, Route target cleanup ordering, environment-operation exclusion, source-operation locking, bounded error output, and idempotent production provisioning retries.

## Open questions

- none.

## Deviations

- `NativeProductionAppInstanceProvisioner` now records new production `checkout_path` values as `<production-home>/releases/initial`, and `AppInstanceEnvironmentContextResolver` recognizes that recorded release checkout while continuing to target the home `.env`. This small code-boundary expansion records the actual Git checkout and lets environment, model, and Caddy projection preserve legacy flat production homes where `checkout_path === production_home`, without a migration or inferred adoption from a coincidental `releases/` directory. Acceptance meaning is unchanged.

## Review findings

- none.
