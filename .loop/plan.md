# Feature plan

Issue: ORB-173
Review verdict: Direct implementation under the no-plan path

## Outcome

One request-scoped, cross-process owner serializes development Caddy and private DNS aggregate publication through Route and AppInstance activation. Metrics publication participates in the same owner, and lock contention has a bounded retryable response.

## Code boundaries

In:
- `apps/gateway/app/Domain/AppDev`: narrow projection-owner contract
- `apps/gateway/app/Infrastructure/AppDev`: native lock and app-dev Caddy/DNS entrypoint ownership
- `apps/gateway/app/Infrastructure/AppInstances`: fresh completion facts and activation lifetime
- `apps/gateway/app/Infrastructure/Metrics`: converge, remove, retract, and rollback lifetime
- `apps/gateway/app/Providers`: request-scoped binding
- focused Gateway tests, `docs/reference/routes.md`, and issue-local proof fixtures

Out:
- Cluster Router serialization, Metrics credential serialization, per-Router locks, generic workflows, stored migrations, lock-path replacement, completed-publication crash recovery, and harness code

## Documentation

Audit scope: ORB-173; `docs/architecture.md`, `docs/concepts.md`, `docs/domains/applications.md`, `docs/reference/apps.md`, `docs/reference/metrics.md`, `docs/reference/routes.md`, and routed proof/topology references.

Fixed:
- `docs/reference/routes.md`: add the shared publication lifetime, HTTP 409 contention response, retry behavior, and Gateway worker rollout using the retained lock path.

Reported:
- none

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Same-Cluster overlap retains workload and Router sites | completion owner plus fresh aggregate selection | `ProvisionDevelopmentAppInstanceTest.php`, `NativeDevelopmentRouteProjectorTest.php` |
| Different-Cluster overlap retains exact DNS records | completion owner plus DNS entrypoint ownership | `ProvisionDevelopmentAppInstanceTest.php`, `AppDevInfrastructureTest.php` |
| Request-scoped reentrant non-expiring native owner | domain contract, native flock, provider binding | `AppDevInfrastructureTest.php`, `ProvisionDevelopmentAppInstanceTest.php` |
| Bounded contention maps to HTTP 409 | native owner with `CommandDeadline::cap(30.0)` | `AppDevInfrastructureTest.php`, `ProvisionDevelopmentAppInstanceTest.php` |
| Every Caddy/DNS and Metrics publisher participates | app-dev managers and Metrics publication manager | `AppDevInfrastructureTest.php`, `MetricsPublicationManagerTest.php` |
| Compatible owner order and inner host locks | existing outer source/role/lifecycle callers plus projection entrypoints | `ProvisionDevelopmentAppInstanceTest.php`, `ClusterRouterTest.php`, `NodeRoleBaselinesTest.php`, `MetricsPublicationManagerTest.php` |
| Retained lock path and rollout | native owner and Routes reference | `AppDevInfrastructureTest.php`, `composer docs-lint` |
| Live same- and different-Router contention | issue-local extended Incus fixture | action `development-projection-contention` |
| Repository verification | Gateway and root suites | `cd apps/gateway && composer check`, `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and update the Routes reference.
2. Add and bind the request-scoped native projection owner.
3. Enter the owner at app-dev Caddy, private DNS, AppInstance completion, and Metrics publication boundaries.
4. Add focused reentrancy, contention, ordering, failure-release, fresh-state, and aggregate-preservation regressions.
5. Run focused tests, documentation checks, and the Gateway project check.
6. Commit the product/docs checkpoint, then build and exercise the extended discovery action.

## Must preserve

- `$ORBIT_HOME/.dnsmasq-projections.lock`, DNS-last activation order, current Route/AppInstance lifecycle evidence, Metrics lifecycle and credentials, current host-lock paths, source/role/lifecycle owners outside the projection owner, and all accepted ADR boundaries.

## Open questions

- none

## Deviations

- none

## Review findings

- none; independent review belongs to the external orchestrator.
