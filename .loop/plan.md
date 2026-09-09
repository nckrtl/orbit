# Feature plan

Issue: ORB-172
Review verdict: Direct implementation under the adopted no-plan path

## Outcome

One request-scoped, non-expiring owner serializes all Router transitions for one Cluster. A contender either enters within the command deadline and reloads every Router-dependent fact or receives the retryable `cluster.router_busy` conflict without mutation. Different Clusters continue independently.

## Code boundaries

In:
- `apps/gateway/app/Domain/Clusters`: narrow per-Cluster Router operation-owner contract
- `apps/gateway/app/Infrastructure/Clusters`: native descriptor lock, bounded contention, and scoped reentrancy
- Cluster Router set, clear, Router baseline, and Router-dependent Cluster update entry points
- request-scoped provider binding, focused Gateway regressions, `docs/reference/routes.md`, and issue-local proof fixtures

Out:
- Route creation and removal races, general Route reconciliation, per-Route ownership, Router state-machine changes, and generic workflow machinery
- node, source, Metrics, or development projection owner implementation; stored-data migration; and Incus harness changes

## Documentation

Audit scope: ORB-172; `docs/architecture.md`, `docs/concepts.md`, `docs/reference/routes.md`, and the governing accepted ADRs returned by filtered `composer docs-context`.

Fixed:
- `docs/reference/routes.md`: describe per-Cluster Router ownership, bounded busy refusal, retry from fresh state, owner ordering, failure release, and sequential recovery.

Reported:
- none

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Fresh set/set and set/clear interleavings preserve the live transition | set and clear acquire before reloading Cluster, Node, assignments, and guards | `ClusterRouterTest.php`, `RouteMutationReconciliationTest.php` |
| Request-scoped native descriptor owner | domain contract, native lock, and provider binding | `NativeClusterRouterOperationLockTest.php` |
| Bounded same-Cluster contention and HTTP 409 mapping | native lock uses `CommandDeadline::cap(30.0)` and stable refusal | `NativeClusterRouterOperationLockTest.php`, `ClusterRouterTest.php` |
| Set, clear, Router baseline, and Cluster state or TLD updates participate | action and baseline entry points | `ClusterRouterTest.php`, `ClustersTest.php`, `RouteMutationReconciliationTest.php` |
| Lifecycle evidence, active-last cleanup, and sequential retry remain authoritative | existing assignment lifecycle plus owner lifetime | `ClusterRouterTest.php`, `ClusterNetworkMigrationTest.php` |
| Total owner order and no transaction across SSH | Cluster owner encloses Router baseline and Metrics follow-up outside short transactions | `ClusterRouterTest.php`, `NodeRoleBaselinesTest.php`, `NativeClusterRouterOperationLockTest.php` |
| Routes reference states the operator contract | maintained reference | `composer docs-lint` |
| Native contention and topology restoration | extended `app-prod` discovery and immutable proof | Incus action `cluster-router-contention` |
| Repository verification | Gateway and root suites | `cd apps/gateway && composer check`, `PHPRC=/dev/null ORBIT_TEST_PROCESSES=80 bin/test` |

## Implementation order

1. Audit and update the Routes reference.
2. Add and bind the Cluster Router owner.
3. Move set, clear, state or TLD update, and Router baseline work inside the owner with fresh reads.
4. Add focused ownership, timeout, lifecycle, ordering, and transaction regressions.
5. Run focused tests, docs build and lint, and the Gateway project check; commit the product/docs checkpoint.
6. After capacity approval, build and exercise the extended discovery action before the serialized final evidence window.

## Must preserve

- Candidate, active, removing, and failed Router evidence; the active unique index; replacement-before-cleanup; non-active-first and active-last clear; identical retry; current Route guards; no database transaction across SSH; and independent Cluster ownership.
- Total order: node lifecycle or role, app-dev source, Cluster Router, Metrics lifecycle or credential, development projection, then remote host.

## Open questions

- none

## Deviations

- none

## Review findings

- none; independent review belongs to the external orchestrator.
