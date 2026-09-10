# Feature plan

Plan format: 1
Issue: ORB-231
Flow: discovery
Review verdict: PASS

## Outcome

An issue can declare a cold three-Node snapshot replacement before proof construction, retain issue-bound evidence through review, and install a clean verified generation without first removing the promoted snapshot.

## Code boundaries

In:
- `apps/e2e/app/E2E/Value/ProofPlan.php`, topology construction and attempt values, `ProofPlanFile.php`, and `IssueState.php` add one normalized `snapshot_replacement` declaration, persist it before resource creation, and distinguish generic-base replacement inputs from ordinary snapshot clones.
- `apps/e2e/app/E2E/ColdTopologyConstructor.php`, `TopologyProofRunner.php`, `ProofInputManifestBuilder.php`, `ProofCaptureService.php`, and `ProofEquivalenceEvaluator.php` route a declared proof through the shared cold constructor, bind the generic base, exact candidate, exact registered three-Node inventory, normal acceptance, complete inputs, immutable capture, and retained-proof evaluation.
- `apps/e2e/app/E2E/TopologyConverger.php`, `TopologyVerifier.php`, and `apps/e2e/resources/guest/converge-sample-app.sh` add a replacement-specific strict sample mode that accepts only native AppInstance and App-owned Route state while leaving ordinary compatibility convergence unchanged.
- `apps/e2e/app/E2E/ProofCloseoutService.php`, a new bounded cold replacement installer, topology snapshot state stores, and new typed installation and recovery records construct a clean replacement from accepted merged source, verify it, preserve the current generation through preparation, install the new generation transactionally, and resume or recover by exact recorded identity.
- `apps/e2e/app/E2E/TopologySnapshotPromoter.php` and `apps/e2e/app/Console/Commands/TopologySnapshot/PromoteCommand.php` reject `snapshot_replacement: true` before Incus access so direct live-topology promotion cannot bypass clean closeout reconstruction.
- `apps/e2e/app/E2E/TopologyReleaser.php`, `TopologyAcquirer.php`, `TopologySnapshotAvailability.php`, status and closeout services, `apps/e2e/app/Console/Commands/Topology/**`, and `AppServiceProvider.php` expose replacement state through the existing prove, capture, review, closeout, status, and abandonment lifecycle and clean only its exact resources while retaining proof and review archives.
- `apps/e2e/tests/Unit/E2E/**` and `apps/e2e/tests/Feature/Commands/**` cover declaration validation, cold construction, native samples, evidence identity, review isolation, transactional installation, rollback and recovery, reacquisition, exact cleanup, and command refusals, including the issue-named existing suites.

Out:
- Keep the production sample redesign and its candidate-clone, explicit-deployment, release-layout, environment, and dedicated-service transition for later work; ordinary convergence keeps its current compatibility choices.
- Keep generic scenario authority, `bin/e2e-scenarios`, and `apps/e2e/tests/Scenario/**` unchanged; a completed cold scenario never gains replacement or proof authority.
- Keep `TopologySnapshotRebuilder`, `LegacyTopologySnapshotRecovery`, `rebuild`, and `recover-legacy` disaster-recovery semantics unchanged; recovery output never becomes issue proof.
- Keep the shared topology at exactly Gateway, app-dev, and app-prod; no `app-prod-2` or other extra Node enters a promoted generation.
- Keep product behavior and code in `apps/cli`, `apps/gateway`, and `packages/php-sdk` unchanged.
- Add no legacy workload conversion, migration command, migration data path, or fleet cutover behavior.

## Documentation

The issue-scoped audit used component `apps/e2e` and the Gateway, Node, AppInstance, and Route concepts. It found and fixed one existing drift item and wrote the issue outcome in documentation commits `43994439` and `281e8f13`:

- `docs/reference/incus-topologies.md` corrects discovery acquisition to validate the saved generation against its recorded commit, not current `main`, and defines declared cold construction, evidence, installation, cleanup, and the boundary from scenarios and disaster recovery.
- `docs/reference/proof-plans.md` defines `snapshot_replacement`, its three-Node constraints, cold construction input evidence, and clean replacement closeout.
- `docs/reference/topology-snapshot.md` distinguishes declared replacement from ordinary promotion, refresh, initial cold build, and disaster recovery, defines transactional installation and recovery behavior, and makes direct `promote` reject the declaration before Incus access so only clean closeout reconstruction can install it.
- `docs/reference/implementation-loop.md` routes a declared replacement through clean reconstruction and transactional installation after verified merge while preserving the retained proof on failure.
- `docs/generated/context.json` contains the rebuilt context entries and ADR links for these changes.

Audit reported: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. A pre-construction declaration selects an isolated standard cold recipe from the generic base and exact candidate | `ProofPlan`, construction and lease values, `ColdTopologyConstructor`, and `TopologyProofRunner` | `apps/e2e/tests/Unit/E2E/Value/ProofPlanTest.php`, `ProofPlanFileTest.php`, `ColdTopologyConstructorTest.php`, `TopologyProofRunnerTest.php`, and discovery observation `cold-replacement-construction` |
| 2. Replacement proof uses native samples and records normal acceptance, complete inputs, and the exact three-Node inventory without reclassification | Replacement-specific convergence and verification, proof runner, input manifest, capture, state, and command guards | Focused `TopologyConvergerTest.php`, `TopologyVerifierTest.php`, `ProofInputManifestBuilderTest.php`, `ProofCaptureServiceTest.php`, `TopologyProofRunnerTest.php`, replacement command tests, and discovery observation `cold-replacement-proof` |
| 3. Capture precedes review and no live promotion path can install reviewer-modified replacement state | `ProofCaptureService`, proof review records, closeout routing, shared cold constructor, replacement installer, `TopologySnapshotPromoter`, and direct promote command | Focused capture, review, closeout, and installer tests; `apps/e2e/tests/Unit/E2E/TopologySnapshotPromoterTest.php`; a direct-promotion regression in `apps/e2e/tests/Feature/Commands/TopologySnapshotCommandsTest.php`; and discovery observation `cold-replacement-review-isolation` |
| 4. The promoted generation is unchanged before accepted merge, and every direct bypass or failed construction, verification, or installation has refusal, rollback, or explicit recovery without success | Direct promoter and command guard, transactional installation record, topology snapshot manifest and promotion stores, installer recovery, closeout result, and command failure handling | `apps/e2e/tests/Unit/E2E/TopologySnapshotPromoterTest.php`, `apps/e2e/tests/Feature/Commands/TopologySnapshotCommandsTest.php`, focused constructor, installer, recovery, and closeout tests, and discovery observation `cold-replacement-rollback` |
| 5. Successful installation leaves one stopped three-Node generation that ordinary discovery and proof can acquire with native samples | Replacement installer, snapshot generation and availability, `TopologyAcquirer`, ordinary proof construction, and strict topology verification | Focused installer, availability, acquirer, proof-runner, and verifier tests plus discovery observation `cold-replacement-reacquire` |
| 6. Successful closeout or explicit abandonment removes only recorded replacement and replaced-snapshot resources and retains proof and review evidence | Installation cleanup record, `TopologyReleaser`, proof closeout, snapshot stores, exact ownership checks, and archive retention | Focused `TopologyReleaserTest.php`, `ProofCloseoutServiceTest.php`, installer cleanup and retry tests, replacement command tests, and discovery observation `cold-replacement-cleanup` |
| 7. Maintained guidance distinguishes declared replacement from scenarios, refresh, and disaster recovery | Four changed reference pages and generated context listed above | `composer docs-build` and `composer docs-lint` |
| 8. Harness checks pass | All changed `apps/e2e` PHP, guest resources, and tests | `cd apps/e2e && composer check` |

## Incus observations

Incus: required by the issue label. After independent plan review passes, acquire ORB-231 discovery and run the six named `cold-replacement-*` observations on real issue-owned Nodes. The development rehearsal must declare replacement before cold resource creation, bind its temporary resources to the issue attempt, record the promoted generation before and after, and never use `oe-topo-snap` as its mutation target. Use `exec`, `shell`, `sync`, and `verify` for guest checks and record exact host commands, JSON results, candidate and image identities, three-Node inventories, AppInstance and Route observations, installed or rolled-back temporary generation state, and cleanup results in the development record. Keep the final discovery attempt for code review. These are reproducible development observations only. Proof instrumentation, a proof attempt, observed-input collection, candidate convergence, shared snapshot replacement, and immutable acceptance capture are not required in this discovery flow.

## Implementation order

1. Extend the proof-plan and persisted construction contracts with the normalized cold replacement declaration, exact generic-base inputs, registered three-Node constraints, schema compatibility, and refusal of extension, absent-Node, scenario, rebuild, or post-construction reclassification.
2. Route declared proof construction through `ColdTopologyConstructor`, produce ordinary issue topology, proof result, input manifest, capture, and equivalence records from that origin, and add strict AppInstance and App-owned Route convergence and verification for replacement attempts only.
3. Add an atomic replacement installation journal and service that reconstructs from accepted merged source and recorded inputs, verifies the clean stopped candidate beside the current generation, performs the exact swap, publishes the generation only after all boundaries agree, and retains retryable recovery state for partial failure.
4. Make `TopologySnapshotPromoter` and its direct command refuse the declaration before Incus access, then integrate declared replacement into closeout, status, exact abandonment, and cleanup so successful installation or abandonment removes only captured identities and preserves immutable proof and review archives; keep ordinary proof, refresh, scenario, and disaster-recovery paths unchanged.
5. Add focused value, service, command, rollback, retry, reacquisition, and exact-cleanup tests, then run the six discovery observations, `cd apps/e2e && composer check`, and `composer docs-lint`.

## Must preserve

- ADR 0015: proof identity remains the proved candidate, accepted candidate, and included main; retention depends on complete recorded input equivalence, not SHA or ancestry alone.
- ADR 0015: the immutable manifest keeps normalized policy and schema versions, exact Git tree inputs and modes, declared extra inputs, optional complete observations, completeness checks, and a canonical fingerprint; unknown or incomplete input stays fail closed.
- ADR 0015: equivalence remains explainable as exact, equivalent, stale, or indeterminate; relevant runtime drift requires complete fresh proof, while accepted and merged trees and promotion inputs remain exact and independently reviewed.
- ADR 0015: cold replacement changes only proof construction; fixture staging, convergence, declared zero-exit acceptance, general verification, capture, evidence immutability, exact cleanup, and production separation remain intact.
- ADR 0036: replacement samples use AppInstance as the only runnable application model and App-owned Route as the hostname and traffic contract; the harness constructs, refreshes, and verifies those contracts without legacy fallback in replacement mode.
- ADR 0036: Orbit supplies no legacy Instance or Workspace compatibility or migration facility here, and operators retain ownership of fleet migration, data, cutover, recovery, and deployment preparation.
- ADR 0037: replacement authority is explicit before construction, reuses the shared cold constructor, uses only the registered Gateway, app-dev, and app-prod recipe, and starts from the generic base and exact candidate without inherited application records, source, or runtime.
- ADR 0037: replacement proof remains issue-owned with declared acceptance, immutability, diagnosis, and exact cleanup, while promotion waits for accepted review, verified merge, and ADR 0015 source acceptance.
- ADR 0037: direct live-topology promotion cannot install a declared replacement; only clean reconstruction after accepted review and verified merge can reach the transactional installer.
- ADR 0037: the current promoted generation remains usable until transactional replacement succeeds; the installed replacement becomes the single ordinary discovery and proof source, and exact cleanup retires old snapshot resources without workload conversion.
- ADR 0037: ordinary scenarios remain disposable and cannot acquire proof or promotion authority after they run.
- ADR 0056: this retained-proof lifecycle applies only when a future issue selects proof delivery; ORB-231 itself stays in discovery flow and adds no proof or snapshot gate to its own delivery.
- ADR 0056: successful evidence, complete action results, topology identity, and the input manifest are captured before interactive access, and every proof Node remains retained through review.
- ADR 0056: reviewers may change live state through ordinary access, but captured evidence remains immutable and separate review actions and findings stay bound to the exact issue, candidate, and attempt; required failures prevent approval.
- ADR 0056: a code or configuration fix is reproducible from the candidate and declared inputs and receives fresh proof; equivalence or a reviewer machine edit never substitutes for it.
- ADR 0056: reviewer-modified state never becomes the shared snapshot; closeout uses clean merged-source construction and releases retained proof only after successful installation, while failed installation retains evidence and recovery state.
- Existing private atomic JSON writes, exact resource IDs, ownership metadata, operation and generation locks, capacity accounting, secret redaction, bounded guest execution, stopped shared VMs, and orphan-network exclusions remain intact.

## Open questions

none

## Deviations

none

## Review findings
