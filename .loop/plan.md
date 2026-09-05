# Feature plan

Issue: ORB-129
Review verdict: PASS

## Outcome

Keep every promoted sample topology acceptable to active-AppInstance Route preflight by converging one exact private `e2e-dev.orbit` Route through the Orbit CLI and verifying the association before candidate work begins.

## Code boundaries

In:

- `apps/e2e/resources/guest/converge-sample-app.sh` and `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php`: extend only the typed `app_instances` sample path with one fail-closed Route preflight. Resolve the one `laravel-typed` App and active `e2e-dev` AppInstance, use `e2e-dev.orbit` as the fixed harness-owned hostname, and inspect `route:list --json`. When no Route targets `e2e-dev`, create one explicit private Route through `route:new` with the AppInstance target; when one association exists, reuse it only if its App, target at position zero, active-Cluster scope, hostname, explicit provenance, and private publication exactly match the sample topology. Reject malformed output, a conflicting sole association, or multiple associations before any Route mutation. Validate the exact Route again after creation and on every repeated convergence, retain its ID, and keep the existing sample state file limited to source identity.
- `apps/e2e/app/E2E/TopologyVerifier.php`, `apps/e2e/resources/guest/verify-topology.sh`, `apps/e2e/tests/Unit/E2E/TopologyVerifierTest.php`, and the verifier fixtures in `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php`: add one named Gateway probe that reads the topology database without writing it and requires each active AppInstance to have exactly one RouteTarget association. Include the probe in readiness and proof verification, preserve the existing machine-readable evidence shape and retry rules, and cover zero, one, and multiple associations, more than one active AppInstance, and inactive AppInstances.

Out:

- Keep AppInstance and Route product migrations, models, actions, API, SDK, and CLI implementation unchanged. The harness consumes the existing `instance:list`, `route:list`, and `route:new` product interfaces and adds no product compatibility repair.
- Keep ORB-127 feature code, `.loop/plan.md`, `.loop/proof/ORB-127.json`, migration refusal, and proof-phase ordering unchanged. This issue repairs the promoted prerequisite before ORB-127 candidate convergence instead of moving setup or acceptance work ahead of convergence.
- Perform no direct Gateway database repair. Typed sample convergence never inserts, updates, or deletes Route rows through SQLite; the verifier may use the existing read-only topology-database probe pattern.
- Add no Route reconciliation. A sole mismatched Route or multiple associations fail convergence and remain unchanged.
- Keep the legacy `instances` sample path, legacy development and production Instances, Workspace behavior, non-sample product data, topology recipes and roles, Incus command surfaces, proof runner, promotion machinery, and production release behavior unchanged.

## Documentation

Documentation audit scope: ORB-129; pages selected for component `apps/e2e`; and the App, AppInstance, and Route concepts.

Fixed in `1dff330a`:

- `docs/reference/topology-snapshot.md`: replaces the typed-path statement that convergence only validates `e2e-dev` with the exact `e2e-dev.orbit` Route create, reuse, and refusal behavior, and states that topology verification applies ADR 0028's association rule to every active AppInstance.
- `docs/generated/context.json`: indexes the new ADR 0028 link and refreshed topology-snapshot context.

Reported: none.

Verification: `composer docs-build`, `composer docs-lint`, and `git diff --check -- docs` passed; all documentation changes are committed.

## Acceptance map

| Acceptance | Boundary | Focused proof |
| --- | --- | --- |
| Typed sample convergence creates one explicit private Route for an active `e2e-dev` with no association, using a deterministic harness-owned hostname through the supported product interface. | Typed Route preflight in `converge-sample-app.sh`; typed CLI fixtures in `ConvergenceGuestScriptsTest.php`; `.loop/proof/ORB-129.json` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/ConvergenceGuestScriptsTest.php` exits `0` for the zero-association create path and exact post-create response. Incus action `promoted-topology-route-preflight` exits `0` after candidate convergence established the exact `e2e-dev.orbit` Route. |
| Typed sample convergence reuses only the exact sole association and refuses conflicts or multiples without direct database mutation. | Typed Route inspection and fail-closed reuse boundary in `converge-sample-app.sh`; command-transcript and state fixtures in `ConvergenceGuestScriptsTest.php`; `.loop/proof/ORB-129.json` | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/ConvergenceGuestScriptsTest.php` exits `0` for matching reuse plus mismatched App, target, scope, hostname, provenance, publication, target position, malformed response, and multiple-association refusal, with no `route:new` or database write on refusal. Incus action `promoted-topology-route-preflight` exits `0` with the exact retained Route. |
| Topology verification requires exactly one Route association for every active AppInstance, including `e2e-dev`. | Named verifier probe in `TopologyVerifier.php` and `verify-topology.sh`; `TopologyVerifierTest.php` and verifier guest-script fixtures | `cd apps/e2e && ./vendor/bin/pest tests/Unit/E2E/TopologyVerifierTest.php tests/Unit/E2E/ConvergenceGuestScriptsTest.php` exits `0`, proving the probe runs in readiness and proof, accepts one association per active AppInstance, ignores inactive AppInstances, and fails on zero or multiple associations. |
| A second successful convergence creates no duplicate Route. | Idempotent typed Route reuse boundary in `converge-sample-app.sh`; `.loop/proof/ORB-129.json` and its optional self-checking fixture | Incus action `promoted-topology-route-preflight` records the exact Route after proof convergence, reruns typed `create-resources` with the recorded sample commit, and exits `0` only when the same Route ID remains the sole `e2e-dev` association. |
| Harness component and repository checks pass. | All implementation, test, proof-plan, and documentation boundaries | `cd apps/e2e && composer check` and root `bin/test` each exit `0`. |

## Implementation order

1. Keep documentation commit `1dff330a` as the reader-facing contract. Add `.loop/proof/ORB-129.json` with no setup, `mutates` absent or false, and one `promoted-topology-route-preflight` acceptance action on `app-dev`; use an optional sibling fixture only if the exact before-and-after Route assertion cannot remain readable in argv.
2. Extend the typed sample CLI fixture with valid Route list and create responses plus command recording. Add cases for absence, exact reuse, every enumerated mismatch, malformed relationships, and multiple target associations, while proving the legacy path and existing typed Cluster and source convergence stay unchanged.
3. Implement the typed Route preflight in `converge-sample-app.sh`. Keep `e2e-dev.orbit` as one constant, create only after zero associations through `route:new`, validate the exact response and subsequent list, and fail before Route mutation for every nonzero incompatible association count.
4. Add the active-AppInstance association probe to `TopologyVerifier` and `verify-topology.sh`. Query the Gateway database read-only, count RouteTarget rows per active AppInstance, emit the standard evidence on success, and let the existing verifier report a failed probe for zero, multiple, malformed, or unavailable evidence.
5. Complete focused verifier coverage for probe scheduling, evidence retention, readiness retry, proof single-pass behavior, and zero/one/multiple association data. Update fixed probe counts and expected probe maps without weakening source, role, end-state, or network verification.
6. Run the focused tests, `cd apps/e2e && composer check`, root `bin/test`, `composer docs-build`, `composer docs-lint`, and `git diff --check`. Prove the exact clean candidate on a fresh proof topology, retain the zero-exit action and plan fingerprint for review, and leave the proved topology immutable for promotion.

## Must preserve

- ADR 0005, complete profile: retain the one `gateway_app-dev_app-prod` profile, its ordered Gateway, app-dev, and app-prod Nodes, deterministic identities, isolated feature networks, and coordinated three-VM snapshot generation.
- ADR 0005, snapshot authority: keep the promoted manifest and exact Incus objects authoritative for snapshot state and Git authoritative for source; discovery may mount the worktree, while proof synchronizes the exact candidate from Git.
- ADR 0005, refresh and promotion: a changed prepared-state fingerprint converges and verifies before coordinated snapshots; a matching fingerprint remains a no-op; only a complete candidate that passes readiness and smoke gates may replace the promoted generation; failure retains the old promoted generation and evidence; cold construction never replaces a promoted generation.
- ADR 0006, separate attempts: discovery remains mutable diagnostic context, proof remains a fresh disposable attempt with no host mount, and both purposes keep separate identities and state.
- ADR 0006, exact proof order: proof synchronizes and verifies the exact candidate, runs repository convergence before declared setup and acceptance, records every action, and treats every nonzero exit or post-identity failure as diagnosis. ORB-129 changes convergence content, not this order.
- ADR 0006, immutable evidence and cleanup: a proved topology remains immutable and bound to the exact candidate and plan; a new candidate makes prior proof stale; promotion releases the successful proof and retained discovery topology by exact inventory.
- ADR 0028: an AppInstance has at most one Route; every active AppInstance has exactly one; Orbit establishes the association before active exposure; zero association is limited to creation, failed activation, or removal; and target replacement, clearing, or Route removal cannot strand an active AppInstance. The harness repairs only its sample prerequisite through existing product commands and never weakens or bypasses these rules.
- Existing typed sample convergence keeps the exact `laravel-typed` App identity, active `e2e-development` Cluster membership and Router, active `e2e-dev` source identity, canonical checkout, selected branch, starting commit, effective root, and atomic sample-state file. Route failure does not rewrite that source state.
- Existing legacy sample convergence keeps its two Instances, Workspace, PHP ordering, `laravel.internal` production hostname, hydration, re-projection, metrics, internal TLS, and permission behavior.
- `TopologyVerifier` keeps complete inventory and network identity checks, end-state probe filtering, concurrent execution, bounded readiness retries, proof single-pass behavior, exact source identity, standard evidence parsing, and failure reporting. The new association probe does not hide or replace another probe.
- External command output remains strictly validated, sensitive output remains redacted, guest scripts remain root-safe and `orbit`-user compatible, and every failure stays nonzero and fail closed.
- `.loop/proof/ORB-129.json` remains promotable: its acceptance action is idempotent after convergence, declares no reusable-state mutation, uses no secret stdin, and binds proof to the exact plan and candidate.
- AppInstance and Route product code, ORB-127 artifacts, unrelated components, GitHub, Linear, production release, and every Out boundary remain unchanged by implementation.

## Open questions

- none; the corrected ORB-129 contract, accepted ADRs 0005, 0006, and 0028, the ORB-127 restart handoff, existing typed sample and verifier seams, and the named automated and Incus proofs determine the implementation.

## Deviations

- none.

## Review findings
