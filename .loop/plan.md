# Feature plan

Issue: ORB-120
Review verdict: PENDING

## Outcome

Stop rendering and publishing the node-local Metrics uninstall script, delete both retired script paths during reachable exporter convergence and removal, and keep exporter removal and unreachable-Node residue reporting complete and truthful.

## Code boundaries

In:

- Delete `apps/gateway/app/Infrastructure/Metrics/MetricsUninstallScript.php` and `apps/gateway/tests/Unit/Infrastructure/Metrics/MetricsUninstallScriptTest.php`; no renderer or renderer-only test remains.
- `apps/gateway/app/Infrastructure/Metrics/MetricsExporterSshExecutor.php` and `apps/gateway/tests/Unit/Infrastructure/Metrics/MetricsExporterSshExecutorLifecycleTest.php`: remove the renderer dependency, publication path, rollback publish-or-delete path, their stateful-fake support, and the error codes `metrics.exporter_uninstall_publish_failed`, `metrics.exporter_uninstall_remove_failed`, and `metrics.exporter_uninstall_rollback_failed`. Add one fixed, idempotent `sudo rm -f -- /usr/local/sbin/orbit-metrics-uninstall /usr/local/sbin/orbit-metrics-uninstall.orbit-candidate` operation after snapshotting and before mutation in both `converge()` and `remove()`, mapped to the bounded error code `metrics.retired_artifact_cleanup_failed`. The operation must not test, read, execute, publish, or restore either path. A later lifecycle failure still restores the snapshotted exporter state but does not restore a retired artifact. Keep the exporter package, drop-in, service, firewall, ownership, verification, and rollback lifecycle unchanged.
- `apps/gateway/app/Infrastructure/Metrics/MetricsFootprint.php`: remove `UninstallScript` and script-specific comments while keeping the shared Metrics paths, ownership markers, candidate suffix, package, service, ports, and firewall constants.
- `apps/gateway/app/Domain/Nodes/NodeSideResidue.php`, `apps/gateway/app/Data/Nodes/NodeRoleMutationData.php`, `apps/gateway/tests/Unit/Domain/Nodes/NodeSideResidueTest.php`, and `apps/gateway/tests/Feature/Api/RemoveNodeTest.php`: replace only the follow-up for an unreachable Node that leaves the registry. It must tell the operator to discard the Node or clear the listed leftovers by hand and must not name node-local cleanup. Remove the stale comment and test names for a node-local escape or wipe. Keep the residue list, runtime behavior in `NodeRoleMutationData`, and the still-registered Node follow-up unchanged.
- `apps/e2e/resources/prepared-state.json` and `apps/e2e/tests/Unit/E2E/PreparedStateFingerprintTest.php`: remove the deleted renderer from the sorted prepared-state path list and from the asserted Metrics dependency closure. Keep `MetricsFootprint.php` because its remaining constants still determine prepared guest state.

Out:

- Do not restore or change the old proof plans and fixtures that exercised the uninstall script; ORB-121 removed that historical proof surface first. The issue-local `.loop/proof/ORB-120.json` named in the acceptance map is proof for this issue, not a code boundary.
- Do not add `role:transfer` or another way to move a role between Nodes.
- Do not change harness code under `apps/e2e/app`, `apps/e2e/resources/guest`, or `bin/e2e-*`, including the timeout signal boundary.
- Do not change another issue's fixtures or their pipefail patterns.
- Do not change `docs/reference/metrics.md`; it already documents the exporter footprint without a node-local uninstall script.

## Documentation

none: ORB-120 has no `docs` label because maintained documentation already describes the owned exporter package, systemd drop-in, and firewall rule without claiming that Orbit creates a node-local uninstall script or offers node-local cleanup.

- The issue-scoped audit covered the `apps/gateway` and `apps/e2e` component contexts, the `Node` concept context, and `docs/reference/metrics.md`. No scoped page conflicts with the issue or needs a separate owner.
- `composer docs-build` regenerated identical context, and `composer docs-lint` passed with zero issues. No file under `docs/` changed.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| A converged Metrics exporter leaves neither the uninstall script nor its candidate on the app-dev Node. | `MetricsUninstallScript.php` deletion; `MetricsExporterSshExecutor.php`; `MetricsFootprint.php`; `.loop/proof/ORB-120.json` | Incus action `metrics-uninstall-script-absent` runs on `app-dev` after the candidate convergence with `sh -c 'test ! -e /usr/local/sbin/orbit-metrics-uninstall && test ! -e /usr/local/sbin/orbit-metrics-uninstall.orbit-candidate'` and exits `0` under `bin/e2e-topology prove ORB-120`. The candidate's fixed cleanup makes this action executable even when the fresh proof topology was cloned from a promoted generation that carried either retired path. |
| Removing a reachable Node's Metrics exporter succeeds, deletes both retired paths without inspecting them, and preserves the supported exporter rollback contract. | `MetricsExporterSshExecutor.php`; `MetricsExporterSshExecutorLifecycleTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Unit/Infrastructure/Metrics/MetricsExporterSshExecutorLifecycleTest.php` proves convergence and removal each issue the one exact `sudo rm -f --` command, map its failure to `metrics.retired_artifact_cleanup_failed`, never issue a read, test, execute, publish, or restore command for either path, and keep the existing drop-in, service, firewall, and rollback cases passing, then exits `0`. |
| Gateway and E2E application and test sources contain no uninstall renderer, shared path constant, retired error-code name, or node-local cleanup guidance; the two path names remain only in the executor's authorized cleanup and its lifecycle test. | All `apps/gateway` and `apps/e2e` boundaries above | From the repository root, `! git grep -n -E 'MetricsUninstallScript|exporter_uninstall|node-local (escape|wipe|cleanup)' -- apps/gateway/app apps/gateway/tests apps/e2e/app apps/e2e/tests apps/e2e/resources/prepared-state.json` exits `0` with no output, and `test "$(git grep -l 'orbit-metrics-uninstall' -- apps/gateway/app apps/gateway/tests apps/e2e/app apps/e2e/tests apps/e2e/resources/prepared-state.json)" = "$(printf '%s\n%s' apps/gateway/app/Infrastructure/Metrics/MetricsExporterSshExecutor.php apps/gateway/tests/Unit/Infrastructure/Metrics/MetricsExporterSshExecutorLifecycleTest.php)"` exits `0`. |
| Forced removal of an unreachable Node deletes its registry state, lists the stranded exporter, and gives only manual-clear-or-discard guidance. | `NodeSideResidue.php`; `NodeSideResidueTest.php`; `RemoveNodeTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Unit/Domain/Nodes/NodeSideResidueTest.php tests/Feature/Api/RemoveNodeTest.php` proves the exporter remains in `retained_on_node`, the Node row is removed, and `follow_up` names no node-local cleanup, then exits `0`. |
| Offline removal of a role from an unreachable Node that remains registered keeps its existing follow-up. | `NodeSideResidue.php`; `NodeSideResidueTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Unit/Domain/Nodes/NodeSideResidueTest.php tests/Feature/Domain/RemoveNodeRoleActionTest.php` proves the role-only follow-up remains `Orbit still manages this node. Clean up only the leftovers listed above; provision the node again when it is reachable.` and the unreachable role removal keeps the Node registered, then exits `0`. |
| Gateway, E2E, and repository checks pass. | All implementation and issue-proof boundaries above | `cd apps/gateway && composer check`, `cd apps/e2e && composer check`, and root `bin/test` each exit `0`. |

## Implementation order

1. Revise `MetricsExporterSshExecutorLifecycleTest.php` so its stateful SSH fake no longer stores script contents and its lifecycle cases require the exact non-inspecting cleanup command during convergence and reachable removal, including bounded cleanup-failure and later-recovery cases. Remove the renderer test file, and update the residue and API expectations and stale comment-only names for the new Node-departure follow-up while retaining the role-only follow-up assertion and behavior.
2. Delete `MetricsUninstallScript.php`; remove its constructor dependency and all publication and rollback branches from `MetricsExporterSshExecutor.php`; add the single fixed cleanup operation to `converge()` and `remove()`; remove `MetricsFootprint::UninstallScript` and the comments that describe the escape script.
3. Remove the deleted renderer path from `apps/e2e/resources/prepared-state.json` and its exact closure assertion, preserving sort order and the remaining Metrics prepared-state inputs.
4. Run the focused Gateway and E2E tests from the acceptance map, then run the zero-match and cleanup-path allowlist proofs. Correct only the listed boundaries.
5. Add `.loop/proof/ORB-120.json` with no fixture and one `metrics-uninstall-script-absent` acceptance action on `app-dev`. Run `bin/e2e-topology prove ORB-120` on a fresh proof topology and retain the exact successful evidence; do not change the harness or another issue's proof artifacts.
6. Run `cd apps/gateway && composer check`, `cd apps/e2e && composer check`, root `bin/test`, root `composer docs-lint`, and `git diff --check` before implementation handoff.

## Must preserve

- ADR 0003, “Keep the runtime boundary closed”: the Metrics role still owns the selected Nodes' packaged `prometheus-node-exporter`, Orbit systemd drop-in, service lifecycle, WireGuard binding, and Metrics-owned firewall rule. Shared packages and unrelated runtime state stay outside Metrics ownership, and repeated convergence remains idempotent.
- ADR 0003, “Select exporters from role state and explicit preference”: exporter selection and preference rules do not change, prospective projection still converges the selected exporters before publication, and the current Metrics Node remains selected.
- ADR 0003, “Preserve state unless purge is explicit”: normal reachable Metrics removal still stops and removes owned containers, exporters, generated configuration, publication, and firewall rules while preserving shared packages, Docker, volumes, credentials, and exporter preferences. Purge behavior and ownership checks do not change.
- ADR 0003, “Split component ownership”: the Gateway keeps exporter policy and fixed remote lifecycle operations; `apps/e2e` changes only its recorded prepared-state source closure and its unit assertion.
- `MetricsExporterSshExecutor` continues to fail closed on configuration or firewall ownership drift, use fixed typed argv and protected input, publish the exporter drop-in through its candidate path, verify configuration, service, and firewall state, and restore the exact snapshotted exporter state after failure. The two retired paths are not supported exporter state and are never restored after their authorized cleanup.
- Reachable exporter removal still disables the service only when an owned drop-in existed, removes that drop-in and an exact owned firewall rule, verifies their absence, and maps every failure to a bounded exporter error code.
- Node removal from the registry still reports the sorted, unique exporter residue plus every removed role's residue. Role removal from an unreachable but retained Node still omits the fleet-owned exporter and returns `FOLLOW_UP_ROLE_REMOVED` unchanged.
- `apps/e2e/resources/prepared-state.json` remains valid schema-2 JSON with a sorted, wildcard-free path list, and `PreparedStateFingerprintTest.php` continues to prove the complete guest dependency closure and exclude lifecycle-only harness files.
- Nodes reached by Metrics exporter convergence or removal lose exactly `/usr/local/sbin/orbit-metrics-uninstall` and `/usr/local/sbin/orbit-metrics-uninstall.orbit-candidate` through one idempotent fixed-argv delete. Orbit does not inspect, execute, publish, or restore either retired path, and no replacement command, background cleanup job, or compatibility path is introduced.
- The E2E harness, timeout and signal behavior, CLI and PHP SDK contracts, other issues' fixtures, and production release behavior remain unchanged.

## Open questions

- none; ORB-120, accepted ADR 0003, the current executor and residue tests, and the existing Incus proof machinery determine the implementation and proof.

## Deviations

- none.

## Review findings

- addressed: BLOCK — Nick explicitly authorized exact cleanup of the retired script and candidate on pre-existing Nodes and waived the former Scope Out exclusion. The executor boundary now requires one fixed, non-inspecting, idempotent delete during candidate convergence and reachable removal. That makes the absence action executable on a fresh clone of the current promoted generation without proof setup mutation, while keeping the proved generation eligible for immutable promotion.
- addressed: FIX — The boundaries now include the stale comments in `NodeSideResidue.php` and `NodeRoleMutationData.php` and the stale test name in `NodeSideResidueTest.php`. The static proof rejects the renderer, old error codes, and node-local escape, wipe, or cleanup wording everywhere in the scoped sources, and separately allowlists the two authorized path literals only in the executor and its lifecycle test.
