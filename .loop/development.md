# ORB-268 development record

Flow: discovery. Incus: not required. Candidate: 1326ac048e328b49d115c0d0651e4157e4f7e59b.

## Change

- `RouteRemovalGuard::assertAppRemovable()` and `assertClusterRemovable()` no longer call `RouteReconciliationGuard::refuse()`; they return `app.has_routes` / `cluster.has_routes` for Routes of any status.
- `RemoveAppAction` checks AppInstances (`app.has_app_instances`) before the Route guard, then legacy instances.
- `RemoveClusterAction` checks member Nodes (`cluster.not_empty`) before the Route guard.
- Node, app-role, and Cluster Router guards are unchanged.

## Tests added

- `tests/Feature/Domain/RouteRemovalGuardTest.php`: active-Route guard case (App -> `app.has_routes`, Cluster -> `cluster.has_routes`, Node and Router still `route.reconciliation_required`, app role still `hosts Route targets`); action-order case (`app.has_app_instances`, `cluster.not_empty`, then `cluster.has_routes`); existing entry-point case updated for the new App order.
- `tests/Feature/Api/AppsTest.php`: `DELETE /api/v1/apps/{id}` with an active Route returns `app.has_app_instances`, then `app.has_routes` for an App that only owns a Route.
- `tests/Feature/Api/ClustersTest.php`: `DELETE /api/v1/clusters/{id}` with a member Node and an active Route returns `cluster.not_empty`, then `cluster.has_routes` after the member leaves.

## Checks on macOS (developer machine)

- `apps/gateway`: `composer test:affected` ran the full suite because PCOV/Xdebug are absent: 3466 tests, 3207 passed, 203 failed. Every failure is in Linux-only Infrastructure/Unit tests (`stat -c`, systemd, OpenSSL, remote source lifecycle); none in RouteRemovalGuardTest, AppsTest, ClustersTest, ClusterNodesTest, or RouteMutationReconciliationTest. Same macOS-only failure set as before this change.
- `apps/gateway`: `composer check` passed (guidance Pest 22/22, Rector, Pint, PHPStan).
- Builder gate: root `composer check` on beast (Linux); receipt path in the handoff.
