# Feature plan

Issue: ORB-188
Review verdict: PENDING

## Outcome

An operator can replace the hostname of one active explicit private development Route while the old endpoint stays authoritative until the new workload, Router, certificate, firewall, and Laravel URL projections are ready. The Route records durable forward and rollback checkpoints, retries only the same requested hostname, and keeps every other active Route mutation behind the reconciliation guard.

## Code boundaries

In:
- `apps/gateway/app/Actions/Routes/UpdateRouteAction.php` validates the narrow API case and delegates hostname convergence.
- A bounded Route hostname change state machine and migration persist requested identity, direction, checkpoint, and failure evidence.
- Existing private projection, source-profile, Caddy, certificate, firewall, DNS, and development projection ownership seams gain only the granular operations needed for prepare, publish, cleanup, and rollback.
- `packages/php-sdk` preserves the durable operation fields so CLI Route JSON exposes incomplete progress.
- Focused Gateway tests cover refusal, step ordering, durable failures, retry, rollback interruption, Laravel configuration, no health gate, and preserved exclusions.
- `docs/reference/routes.md` owns the operator-visible cutover and failure contract.

Out:
- Generated hostname changes, target changes, publication changes, Node or Cluster mutations, Router replacement, public Ingress, and AppInstance removal.
- A general workflow engine, background work, application bootstrap, Composer or Artisan execution, and application HTTP health checks.
- Incus harness implementation.
- Production Route hostname changes, tracked by ORB-201.

## Documentation

Audit scope: ORB-188; `docs/reference/routes.md`, `docs/domains/applications.md`, `docs/reference/apps.md`, `docs/architecture.md`, and the accepted ADRs selected by filtered documentation context.

Fixed:
- `docs/reference/routes.md`: replace the blanket active-hostname refusal with the explicit private single-target cutover, durable failure, retry, rollback, cleanup, and remaining-refusal contract.

Reported:
- none.

Verification: `composer docs-build` and `composer docs-lint` after the product and reference changes.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Invalid or occupied hostname has no side effects | Request validation and locked hostname reservation | `tests/Feature/Api/RoutesTest.php`; `explicit-route-hostname-update` |
| Ordered DNS-last cutover and cleanup | Route hostname convergence action plus private projector | `tests/Feature/Domain/ConvergeRouteActionTest.php`; `explicit-route-hostname-update` |
| Bounded failure evidence and verified retry | Route hostname checkpoint enum, action, and SDK Route response | `tests/Feature/Domain/ConvergeRouteActionTest.php`; `route-url-rollback` |
| Pre-publication restoration and resumable rollback | Rollback direction and checkpoints | `tests/Feature/Domain/ConvergeRouteActionTest.php`; `route-url-rollback` |
| HTTP 500 does not gate success | No application request exists in the projector | `route-change-with-application-error` |
| Offline Laravel URL reconciliation | Existing source profile and configurator | `tests/Feature/Infrastructure/AppInstances/LaravelUrlConfigurationTest.php`; `route-url-without-framework-boot` |
| Explicit hostname succeeds; exclusions still refuse | Update action and existing reconciliation guard call sites | `tests/Feature/Domain/RouteMutationReconciliationTest.php` |
| Maintained docs are current | Routes reference and generated context | `composer docs-lint` |
| Repository checks pass | Gateway, SDK, CLI, and root suites | Project `composer check` commands; `PHPRC=/dev/null ORBIT_TEST_PROCESSES=80 bin/test` |

## Implementation order

1. Persist a bounded hostname-change identity, direction, checkpoint, and failure state on Routes.
2. Add granular, idempotent private projection methods for candidate preparation, DNS publication, canonical cleanup, and rollback.
3. Add the synchronous convergence action under the shared development projection owner.
4. Route only the eligible API hostname change through convergence and preserve all refusal branches.
5. Add focused API, domain, infrastructure, and migration evidence.
6. Prepare Incus fixtures and exercise every scenario on discovery after capacity is granted.

## Must preserve

- Generated Route hostname immutability.
- Target, publication, Node, Cluster, Router, and removal reconciliation refusals.
- Route association and ORB-124/ORB-181 removal contracts.
- Existing shared development projection ownership and DNS-last ordering.
- Unrelated application configuration and application content.
- Active AppInstance and Route state when application HTTP returns an error.

## Open questions

- none.

## Deviations

- none.

## Review findings

- pending.
