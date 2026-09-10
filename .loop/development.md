# ORB-231 development record

Candidate: `b63133b4ed0dcca4ec46d6e86fefaf41d04df730`
Flow: `discovery`
Incus: required
Discovery retained for review: `6f5b542748a3f7898514dd51a7c06530`

## Implementation

- Added a normalized `snapshot_replacement` proof declaration. It is persisted in the proof lease before construction and is carried through construction inputs, topology state, the input manifest, capture, and equivalence evaluation.
- Routed declared replacement proof through `ColdTopologyConstructor` with the exact candidate and recorded generic-base alias and fingerprint. The recorded schema-2 construction input contains exactly Gateway, app-dev, and app-prod as image-sourced Nodes. Schema-1 retained construction records remain readable without byte-shape changes.
- Added replacement-only strict convergence and verification. It rejects legacy Instance sample state before mutation and accepts only native AppInstance and App-owned Route state. Ordinary compatibility convergence is unchanged.
- Added an atomic replacement installation journal and clean reconstruction installer. It validates the retained declaration and clean merged source, constructs and verifies a separate topology, stages three stopped `-next` VMs, journals destructive intent, swaps under the generation lock, commits the manifest, records clean-reconstruction lineage, and cleans exact old and temporary resources.
- Added retry handling for interrupted construction, staging, swap, manifest commit, lineage recording, rollback, and forward cleanup. Acquisition is blocked during an ambiguous swap and uses the old or new committed generation at safe phases. Refresh and direct promotion refuse an active replacement transaction.
- Routed proof closeout to the replacement installer, retained proof until complete installation and cleanup, exposed active or archived replacement state in issue topology status, and made explicit abandonment clean or roll back its exact replacement resources before proof release.
- Direct `topology-snapshot:promote` rejects a declared replacement immediately after plan loading and before Incus access.

## Focused checks

From `apps/e2e`:

```text
vendor/bin/pest --no-tia --compact \
  tests/Feature/Commands/TopologyCommandsTest.php \
  tests/Feature/Commands/TopologySnapshotCommandsTest.php \
  tests/Unit/E2E/IssueStateTest.php \
  tests/Unit/E2E/ProofCloseoutServiceTest.php \
  tests/Unit/E2E/ProofEquivalenceEvaluatorTest.php \
  tests/Unit/E2E/ProofInputManifestBuilderTest.php \
  tests/Unit/E2E/TopologyConvergerTest.php \
  tests/Unit/E2E/TopologyProofRunnerTest.php \
  tests/Unit/E2E/TopologyReleaserTest.php \
  tests/Unit/E2E/TopologySnapshotPromoterTest.php \
  tests/Unit/E2E/TopologySnapshotReplacementInstallerTest.php \
  tests/Unit/E2E/TopologySnapshotReplacementStoreTest.php \
  tests/Unit/E2E/TopologyVerifierTest.php \
  tests/Unit/E2E/Value/ColdTopologyPlanTest.php \
  tests/Unit/E2E/Value/FeatureTopologyTest.php \
  tests/Unit/E2E/Value/ProofCloseoutRecordTest.php \
  tests/Unit/E2E/Value/ProofEvidenceValueTest.php \
  tests/Unit/E2E/Value/ProofPlanTest.php \
  tests/Unit/E2E/Value/TopologySnapshotReplacementInstallationTest.php \
  tests/Unit/E2E/Value/TopologySnapshotReplacementRecoveryTest.php
```

Result: 337 passed, 1,659 assertions.

`composer check` passed: guidance checks, Rector dry run, Pint, and PHPStan all passed.

From the repository root, `composer docs-lint` passed with zero issues, errors, or warnings. `git diff --check` passed.

## Discovery observations

The repeatable rehearsal is `.loop/discovery-rehearsal.php` (SHA-256 `1dc02a4d5cd269e93ba196e4f73059cb0c37580d7f218b1e2b1f15e61813b1eb`). Run it from the candidate root with `php .loop/discovery-rehearsal.php`. It constructs only issue-owned disposable resources and always invokes exact-operation cleanup. It never stages, renames, deletes, or snapshots `oe-topo-snap` resources.

### cold-replacement-construction

- Candidate: `b63133b4ed0dcca4ec46d6e86fefaf41d04df730`.
- Disposable attempt: `cc4306d9be34027319bbde76ba8422ee`; resource operation: `426d8654811970e08e8962201ab31b70`.
- Recorded origin: `generic-base`; alias: `orbit-base-ubuntu-26.04-runtime`; fingerprint: `4e02d6ce34f5320e7e6cae69d82b018aaeaa52b1f5bcd60117ca7497d5688f0c`.
- Recorded inventory was exactly three image-sourced Nodes at slot 5: Gateway `10.232.5.10`, app-dev `10.232.5.11`, and app-prod `10.232.5.12`. No extension or extra Node existed.

### cold-replacement-proof

- Strict readiness verification passed all probes.
- Strict proof verification passed all probes.
- Both reports observed `active-appinstances=1,route-associations=1`, the registered role assignments, exact candidate source on Gateway and app-dev, and healthy Gateway, VPN, metrics, Laravel, Caddy, and PHP-FPM surfaces.

### cold-replacement-review-isolation

- A review-only marker was written inside the disposable app-dev Node and exited 0.
- The promoted generation before and after was byte-identical: `7940b101271d-a565831a39de`, main `7940b101271db810426fa60de243dfb30f947907`, prepared fingerprint `a565831a39de3c9dbd16b925d27a949760e6819104c0577049ff84aca4fd4242`.
- No live proof or review-modified Node was installed.

### cold-replacement-rollback

- The real-machine rehearsal stopped before staging or swap, then used the exact issue, attempt, and operation identities to clean the disposable topology.
- The old promoted generation stayed available and unchanged after cleanup. Transactional partial-swap rollback and forward-recovery branches were exercised by focused installer and journal tests; discovery did not intentionally disrupt the shared snapshot.

### cold-replacement-reacquire

- Released discovery attempt `8e03cb77b56ff8ce62a2126210ffd820`, including its exact three VMs and network.
- Acquired and retained discovery attempt `6f5b542748a3f7898514dd51a7c06530` from unchanged generation `7940b101271d-a565831a39de` at candidate `b63133b4ed0dcca4ec46d6e86fefaf41d04df730`.
- `bin/e2e-topology verify ORB-231 --worktree=/fast/worktrees/orbit/orb-231 --json` passed. An app-dev `inspect-state` returned `shape: app_instances`, and the topology verifier observed one active AppInstance with one Route association.

### cold-replacement-cleanup

- Exact cleanup removed the disposable app-prod, app-dev, and Gateway VMs plus network `oe-c4eb950202e5`.
- Cleanup refused nothing. A post-cleanup host read found no temporary instances and no temporary network.
- The final ordinary discovery attempt remains available for independent review.

## Documentation

- `docs/reference/incus-topologies.md`: corrected discovery generation validation and documented declared cold construction, evidence, cleanup, and authority boundaries.
- `docs/reference/proof-plans.md`: documented the declaration, three-Node constraint, construction inputs, and clean closeout.
- `docs/reference/topology-snapshot.md`: distinguished replacement from direct promotion, refresh, scenarios, and disaster recovery; documented transactional install and recovery.
- `docs/reference/implementation-loop.md`: documented clean reconstruction after accepted review and verified merge while retaining proof on failure.
- `docs/generated/context.json`: rebuilt scoped documentation context and ADR links.

Audit reported: none.

## Deviations and limits

- Plan deviations: none.
- Delivery used discovery flow. No isolated proof attempt, immutable acceptance capture, shared snapshot replacement, verified merge closeout, or production release was run.
- The retained shared generation predates this candidate's new `inspect-state native` guest-script argument. An exploratory strict-argument call on the reacquired old generation exited 64; the ordinary compatible read then returned native `app_instances`. The candidate's disposable cold replacement used the new script and passed strict native readiness and proof verification.
- The real-machine rehearsal intentionally did not perform a partial shared-generation swap. Crash points, rollback, manifest-commit recovery, lineage recovery, forward cleanup, and exact identity refusal are covered by focused Process-faked service tests.

Discovery development only; isolated acceptance proof not run
