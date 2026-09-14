# Feature plan

Plan format: 1
Issue: ORB-336
Flow: discovery
Review verdict: PENDING

## Outcome

Topology convergence creates its sample Cluster, App, AppInstances, and Route through the ADR 0071 command names, so cold construction and snapshot refresh work on a main that carries the app, cluster, route, and instance renames.

## Code boundaries

In:
- `apps/e2e/resources/guest/converge-sample-app.sh` and other guest scripts that still invoke a replaced CLI name
- `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php`
- `apps/e2e/resources/prepared-state.json` only when a pinned `apps/cli`, `apps/gateway`, or `packages/php-sdk` path no longer resolves after ORB-325 to ORB-327
- `apps/e2e/tests/Unit/E2E/PreparedStateFingerprintTest.php` only if a prepared-state path change requires it

Out:
- Product code under `apps/cli`, `apps/gateway`, and `packages/php-sdk`
- The sample application and the workspace sample the legacy family still creates
- The deploy-step switch of ORB-332 and the definition commands of ORB-333
- `workspace:new`, `node:role:add`, `node:access:add`, `gateway:add`, and Gateway `orbit:node-provision`

## Documentation

none: harness scripts are not documented on a maintained page

### Audit

Scope: ORB-336 pages from `composer docs-context -- --component=apps/e2e --concept=Cluster --concept=App --concept=AppInstance --concept=Route`, with a focused grep of `docs/reference/incus-topologies.md` and `docs/reference/topology-snapshot.md`.

Fixed: none

Reported: none

`docs/reference/incus-topologies.md` and `docs/reference/topology-snapshot.md` do not name the replaced commands. Product reference pages already use the ADR 0071 names from ORB-325 and ORB-326. Accepted ADRs that still name old commands stay immutable.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Convergence invokes `cluster:create`, `cluster:node:add`, `app:create`, `instance:create`, and `route:create`, and no replaced name remains in the guest scripts | `apps/e2e/resources/guest/converge-sample-app.sh` and guest-script tests | `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php` |
| A cold scenario converges the three-Node topology from the generic base on a main that carries ORB-325 and ORB-326 | guest convergence script | `bin/e2e-scenarios cold <HEAD> --json` on beast |
| Prepared-state inputs track the renamed product files and unchanged inputs keep refresh a no-op | `apps/e2e/resources/prepared-state.json` | `apps/e2e/tests/Unit/E2E/PreparedStateFingerprintTest.php` |

## Incus observations

Incus: required. The `incus` proof is a cold scenario run on beast, not a discovery topology; do not run `bin/e2e-topology acquire`. Proof instrumentation is not required. Issue `Proof:` venues map to TIA-selected `composer test:affected` in `apps/e2e`, the Builder candidate gate, and that cold-scenario observation. Isolated exact-commit proof is not run.

## Implementation order

1. Rename the guest CLI invocations in `converge-sample-app.sh` to the ADR 0071 names and leave the kept commands unchanged.
2. Update `ConvergenceGuestScriptsTest.php` expectations and fixtures, and assert that no guest script contains a replaced name.
3. Grep `apps/e2e` for remaining replaced names and the later-family names listed in the issue, keeping `orbit:node-provision`.
4. Confirm every prepared-state path still resolves; change the manifest only when a pinned product file moved.
5. Run `apps/e2e` affected tests and `composer check` on beast, then the cold scenario at the pushed head.

## Must preserve

- ADR 0071: one noun family and one verb; create and destroy for Gateway-owned resources; add and remove for associations; no alias for a renamed command
- `workspace:new`, `node:role:add`, `node:access:add`, and `gateway:add` keep their names
- Gateway console command `orbit:node-provision` stays
- Existing guest-script invariants for idempotent sample resources, typed Cluster resume, and Route create-or-reuse
- Prepared-state fingerprint stability when pinned inputs are unchanged

## Open questions

none

## Deviations

none

## Review findings
