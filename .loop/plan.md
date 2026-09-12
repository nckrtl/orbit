# Feature plan

Plan format: 1
Issue: ORB-246
Flow: discovery
Review verdict: PASS

## Outcome

Ordinary discovery acquisition keeps cloned network identity consistent in Gateway provisioning inputs and saved and running peer endpoints.

## Code boundaries

In:
- `apps/e2e/app/E2E/DiscoveryGuestPreparer.php`: validate the three acquired IPv4 addresses and prepare Gateway identity before peer repair and readiness; execute current guest resources directly rather than relying on snapshot-installed scripts.
- `apps/e2e/resources/guest/retarget-gateway.php`: narrowly update cloned Gateway SQLite inventory and endpoint settings in one transaction, retaining all other fields; return only expected peer endpoints.
- `apps/e2e/resources/guest/retarget-vpn.sh`: validate the expected provisioning endpoint against the saved peer port, fail on missing configuration, and reconcile saved and running state without role convergence.
- `apps/e2e/tests/Unit/E2E/DiscoveryGuestPreparerTest.php`, `TopologyAcquirerTest.php`, and `ConvergenceGuestScriptsTest.php`: executable regression and acquisition failure coverage.

Out:
- No product DNS policy or Gateway/CLI public command changes; no role or workload provisioning.
- No worktree creation changes: acquisition owns preparation, while worktree creation continues to bootstrap only source and dependencies.
- No modification of existing attempts, ORB-242, live fleet, snapshot resources, capacity policy, merge, or deployment.

## Documentation

`docs/reference/incus-topologies.md` describes automatic stored and running clone identity preparation, preservation, and failure behavior.
Audit: Fixed the incomplete acquisition identity description on this page. Related endpoint precedence and Node-retarget references remain accurate; they describe product commands, not an additional acquisition step. Reported: none within this issue's clone-acquisition scope. No other page changes.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Align acquisition inputs and peer endpoints before readiness | DiscoveryGuestPreparer and guest resources | `DiscoveryGuestPreparerTest.php` ordered preparation assertions; `ConvergenceGuestScriptsTest.php` executes the resource against disposable SQLite and peer fixtures; dedicated `bin/e2e-topology acquire ORB-246 /fast/worktrees/orbit/orb-246` on a subnet different from the snapshot, with stored/saved/running comparisons |
| 2. Generate configuration for both peers with current Gateway and retain reachability | Gateway identity publication and peer repair | On ORB-246 discovery invoke actual Gateway `VpnConfigurationRepository::forPeer()` for app-dev and app-prod without changing overrides; compare only endpoint output with saved and running endpoints. Run `bin/e2e-topology verify ORB-246` and record private SSH and internal HTTPS checks. No role restart or full reprovisioning claim |
| 3. Preserve unrelated configuration | Narrow transactional identity updates and endpoint-only peer edits | Executable guest tests compare all fixture fields, roles, workloads, keys, DNS and ports before/after; exercise omitted and snapshot-local overridden endpoints, non-default ports and repeat execution. Disposable acquisition observations compare snapshot-derived peer config content except endpoints, Gateway key hashes and unaffected persisted state, without starting or altering snapshot VMs |
| 4. Fail invalid acquisition with exact rollback | Preparer failures and existing TopologyAcquirer rollback | `ConvergenceGuestScriptsTest.php` rejects invalid/missing/ambiguous inputs without partial database updates; `DiscoveryGuestPreparerTest.php` stops on failed or malformed publication before peer repair; `TopologyAcquirerTest.php` proves no ready record and exact owned-resource cleanup on identity failure, preserving foreign resources |
| 5. Explain automatic preparation | docs/reference/incus-topologies.md | `composer docs-build` and `composer docs-lint`, inspect discovery preparation text |

## Implementation order

1. Add a standalone PHP guest resource using the existing guest-SQLite fixture convention. Validate a complete active three-clone inventory, distinct assigned IPv4 addresses, and endpoint data before transactional writes. Update only cloned public SSH hosts and endpoint host components naming the stored old Gateway (or already-current Gateway). Preserve null/absent settings and overrides. Refuse malformed, secret, or unrelated endpoint values; never guess how to migrate custom endpoints. Keep ports byte-equivalent and return expected per-peer endpoints using existing override/global/fallback precedence.
2. Wire it into identity preparation as the Orbit runtime user before peer repair. Feed the current PHP resource via a guest command rather than requiring a refreshed snapshot. Validate returned endpoint data and call the current peer repair script via stdin. Its optional second expected-endpoint argument selects discovery-only strict mode: fail if peer port and provisioning port disagree or required peer configuration is absent, and reconcile the live endpoint even when its saved endpoint already matches. Preserve keys and all non-endpoint lines. The existing one-argument mode remains unchanged; retain its `repairs the node WireGuard endpoint only when the Gateway address changed` and `exits cleanly on an unprovisioned node without a WireGuard config` tests alongside the strict-mode regressions.
3. Extend executable guest and preparer tests for success, preservation, repeated repair, fallback/overrides, invalid inputs and failure ordering. Extend acquisition rollback tests without changing cleanup authority.
4. After independent preflight PASS and local test success, acquire this issue's own three-Node discovery topology through the ordinary changed acquisition command. Use an ignored `.loop/acquire-observed.php` launcher that boots E2E, calls `topology:acquire`, and forwards Laravel PendingProcess calls to real processes with original argv, stdin and deadlines unchanged. Around only the clone identity repair batches, read this issue's lease and verify each exact VM's ownership before read-only probes. Capture redacted fields or normalized hashes before Gateway publication and after peer repair; never expose secrets, access another attempt, or start/edit snapshot VMs. Retain the exact launcher and successful comparisons in discovery evidence. Run actual Gateway configuration generation for both peers and readiness/reachability checks.
5. Run `composer test:affected`, `composer test`, and `composer check` in apps/e2e; run docs checks. Commit the isolated candidate, run root `composer check` on the clean head, and return it with discovery evidence for independent review. No merge or deployment.

## Must preserve

- ADR 0005: one isolated three-Node profile copied from one pinned generation; host source authoritative; no promoted snapshot mutation; exact ownership-bound rollback. ADR 0006's discovery mount amendment applies.
- ADR 0051: discovery selected, preflight and independent review before acquisition, independent candidate review, no separate proof/fresh-main/snapshot-closeout gate.
- Incus label requires real disposable machine observations; proof instrumentation is not required. Discovery is mutable development evidence, not isolated immutable acceptance proof.
- The harness remains a database-free console application; only the guest resource accesses the cloned Gateway database, as existing guest helpers do. No E2E database service, migration, or dependency is added.
- Preserve keys, private addresses, DNS policy, endpoint and SSH ports, roles, workload placement and unrelated settings. No arbitrary custom endpoint rewrite. Preparation failure never publishes readiness and retains existing exact-attempt rollback.
- Ordinary snapshots may contain older guest scripts; new acquisition preparation must execute current resource bytes. App-prod has no mounted checkout.
- Existing tests, non-discovery convergence, snapshot lifecycle, and active ORB-242 remain unchanged except focused regressions on the shared peer repair script.

## Open questions

none

## Deviations

none

## Review findings
