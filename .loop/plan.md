# Feature plan

Issue: ORB-214
Review verdict: PASS

## Delivery flow

Flow: discovery

## Outcome

Each newly provisioned production PHP AppInstance owns a recorded, independently managed PHP-FPM service whose generated identity, local tuning, activation, retry, and cleanup cannot affect another runtime.

## Code boundaries

In:
- `apps/gateway/database/migrations/` and `apps/gateway/app/Models/AppInstance.php`: add nullable recorded production PHP service, pool, and socket associations so new PHP placements bind remote work to durable identity while existing rows remain on their shared runtime.
- `apps/gateway/app/Domain/AppInstances/`: define the dedicated production runtime identity and narrow provision/remove contract used by production AppInstance lifecycle coordinators.
- `apps/gateway/app/Infrastructure/AppInstances/`: derive and record the fixed service identity before remote mutation; render generated main, pool, and systemd identity; seed one separate local tuning file; publish, validate, activate, recover, and remove only that runtime; route Caddy to the recorded socket; and select dedicated or legacy shared cleanup from the recorded association.
- `apps/gateway/app/Infrastructure/AppDev/AppDevSite.php`, `AppDevSiteRepository.php`, `AppDevPhpFpmConfigRenderer.php`, and `RemoteAppDevPhpFpmManager.php`: keep dedicated production AppInstances out of shared pool publication while retaining them in workload Caddy inventory and preserving development, Gateway-adjacent, legacy Instance, and pre-existing shared AppInstance behavior.
- `apps/gateway/app/Infrastructure/Nodes/RemotePhpPackageManager.php`: provide package-only production installation and verification for dedicated masters without enabling, reloading, or changing another PHP-FPM service; retain the existing shared-service path for development and legacy production.
- `apps/gateway/app/Providers/AppServiceProvider.php`: bind the dedicated production runtime contract to its native implementation.
- `apps/gateway/tests/Feature/Database/`, `apps/gateway/tests/Feature/Infrastructure/AppInstances/ProductionPhpRuntimeTest.php`, `PhpRuntimeSelectionTest.php`, `NativeAppInstanceRemovalProjectorTest.php`, and the affected Node package tests: prove durable association, isolated rendering and activation, byte-preserved tuning, refusal and recovery, targeted cleanup, shared-package behavior, and mixed dedicated/shared/Gateway/development inventory.

Out:
- Existing production rows with null dedicated-runtime associations are not backfilled, converted, adopted, or moved off their shared service.
- Application cloning, deployment, release activation, and the verified OPcache refresh operation remain outside this change; ORB-215 owns cache refresh and completion verification.
- The Gateway stores identity associations only. It does not store or accept PHP or PHP-FPM tuning values.
- Development PHP defaults, pool policy, shared service naming, and cache behavior remain unchanged.
- No public API, PHP SDK, CLI transport, Gateway-owned tuning surface, `apps/e2e` harness code, or `bin/e2e-*` command changes.

## Documentation

- `docs/reference/php-runtime.md`: fixed shared-production drift and now states dedicated production service/socket identity, generated and local configuration ownership, validation and recovery, shared package coexistence, and the separate cache boundary.
- `docs/reference/appinstance-removal.md`: fixed the missing dedicated-runtime removal contract and now states targeted generated projection cleanup, retry, retained content and local tuning, and unchanged unrelated services and caches.
- `docs/domains/applications.md`: fixed the obsolete shared pool/reload description and now states that production PHP preparation records an isolated service association and preserves local tuning on retry.
- `docs/generated/context.json`: regenerate documentation context after the ADR 0045 links and changed production runtime terms.
- Documentation audit reported no unresolved findings. `docs/reference/topology-snapshot.md` intentionally retains both flat shared and dedicated release expectations for the ORB-213 compatibility window; its stated removal boundary is outside this issue.

## Acceptance map

| Criterion | Boundary | Validation |
| --- | --- | --- |
| New production users have separate masters, services, pools, sockets, and OPcache while sharing version packages. | Recorded runtime identity, dedicated renderer/manager, package-only installer, and shared-inventory exclusion under `apps/gateway/app/`; focused production runtime and package tests. | On extended discovery, provision two PHP Apps on `app-prod`; inspect their recorded identities, systemd master PIDs, pools, sockets, OPcache mappings, and shared `php8.5-fpm` package. Run focused `ProductionPhpRuntimeTest.php` and `RemotePhpPackageManagerTest.php`; require full CI. |
| A new runtime gets Orbit defaults plus byte-preserved local tuning and refuses effective identity overrides before activation or reload. | Dedicated runtime renderer/manager and durable AppInstance association; `ProductionPhpRuntimeTest.php`. | On discovery, change one runtime's local tuning, converge it, compare the tuning digest and three master PIDs, then add `user = root` and confirm refusal before any PID changes. Run focused `ProductionPhpRuntimeTest.php`; require full CI. |
| Preparation, recovery, and removal affect only the selected production user's PID and cache on mixed-role Nodes. | Dedicated runtime manager, package-only installer, shared inventory separation, and production lifecycle wiring; production runtime and removal projector tests. | On discovery, make one generated pool file unsafe, confirm convergence refusal, repair its ownership, retry, and compare both dedicated PIDs plus the shared PID. Run focused production runtime, removal projector, and package manager tests; require full CI. |
| Removal deletes only owned runtime projections, retains content and local tuning, resumes partial cleanup, and leaves shared placements manageable. | `NativeAppInstanceRemovalProjector.php`, dedicated manager cleanup, nullable association compatibility, and removal tests. | On discovery, remove one dedicated AppInstance and inspect its retained home, user, runtime directory, and local tuning plus the absence of its generated files, marker, unit, and socket; compare unrelated PIDs. Run focused removal projector tests; require full CI. |
| Interrupted provisioning resumes recorded associations and refuses conflicting files, users, or sockets without adoption or overwrite. | AppInstance migration/model, production provisioner checkpointing, dedicated identity and publication recovery; database and production runtime tests. | On extended discovery, create the selected user's socket on `app-prod-2`, confirm provisioning stops at `source-classified` without changing its recorded identity, remove the conflict, and retry to active. Run focused production identity and runtime tests; require full CI. |
| Maintained docs state dedicated services, generated identity, local tuning, and targeted cleanup with current generated context. | `docs/reference/php-runtime.md`, `docs/reference/appinstance-removal.md`, `docs/domains/applications.md`, and `docs/generated/context.json`. | `composer docs-build`; `composer docs-lint`. |
| Changed project checks and repository CI suites pass. | All changed Gateway and documentation boundaries. | Focused affected Pest files locally; `cd apps/gateway && composer check`; all five full no-TIA CI suites on the submitted candidate. |

## Incus observations

Not applicable; discovery flow. The `app-prod` extension remains declared only to recreate the four-node discovery topology used by the acceptance observations.

## Implementation order

1. Persist nullable service, pool, and socket associations and centralize the fixed identity derived from the recorded production user and selected PHP version.
2. Add package-only installation, generated configuration and unit rendering, local default seeding, effective identity validation, atomic activation, rollback, and conflict refusal for one dedicated runtime.
3. Record associations before remote preparation, route new production AppInstances through the dedicated manager and socket, and exclude them from shared FPM pool publication while preserving old null-associated placements.
4. Route production runtime cleanup through the recorded manager, make partial cleanup idempotent, and retain the production home, content, user, and local tuning.
5. Add focused database and infrastructure coverage for isolation, mixed roles and legacy placements, tuning preservation, conflict refusal, retry, failure recovery, and targeted removal.
6. Run the five reproducible observations on extended discovery, run focused Gateway tests and `composer check`, commit and push the candidate, and publish the discovery-flow artifacts before review.

## Must preserve

- ADR 0011: the AppInstance runtime owns the process behind workload Caddy; production keeps one dedicated system user and home per App on a Node; Orbit changes only its owned placement/runtime association and leaves application deployment separate.
- ADR 0021 as retained by ADR 0045: PHP comes from pinned shared Sury packages; tracing JIT remains off; app-dev timestamp validation and process defaults stay unchanged; CLI PHP defaults stay stock.
- ADR 0034: source classification selects the highest supported PHP version, stores no caller-selected PHP input, prepares no runtime for non-PHP source, and leaves package ownership with the Node application role.
- ADR 0038: AppInstance cleanup remains resumable, leaves unrelated AppInstances and Node-owned resources untouched, and cannot report success before owned runtime cleanup completes.
- ADR 0045: each new production Unix user gets a separate master/service/pool/socket/OPcache; package installation stays shared; operations never reload or reset another service; generated identity remains separate from operator-owned tuning; effective identity is validated before activation; the Gateway stores no tuning values; existing content and tuning remain intact.
- ADR 0049: `.loop` remains absent from feature commits and the complete planning/proof workspace is published on an immutable candidate-bound artifact ref.
- ADR 0051: this work uses discovery-only delivery; acceptance outcomes remain required through reproducible discovery observations, focused tests, full CI, and independent code review, while isolated proof and snapshot work do not run.
- Existing legacy `Instance`, pre-existing null-associated production AppInstance, Gateway PHP, app-dev PHP, source profile, Route publication, certificate, firewall, and retained-content tests remain green.

## Open questions

- none

## Deviations

- none

## Review findings

- none
