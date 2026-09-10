# Feature plan

Issue: ORB-213
Review verdict: PENDING

## Outcome

Keep the ordinary three-Node topology sample convergent and strictly verifiable while production creation, release placement, environment ownership, and dedicated PHP services arrive independently.

## Code boundaries

In:
- `apps/e2e/resources/guest/converge-sample-app.sh`: select one complete supported sample-creation contract before mutation, hydrate recorded development and production placements, and conditionally import and synchronize environment values without weakening retries.
- `apps/e2e/app/E2E/TopologyConverger.php`: pass the recorded typed sample placements to hydration without changing the standard topology or convergence lifecycle.
- `apps/e2e/app/E2E/TopologyVerifier.php` and `apps/e2e/resources/guest/verify-topology.sh`: derive the production verification contract from recorded placement and verify either the exact flat/shared layout or the exact release/dedicated layout.
- `apps/e2e/resources/prepared-state.json`: include the production runtime, release, candidate-cloning, deployment, and environment product surfaces that can change prepared sample state.
- `apps/e2e/tests/Unit/E2E/ConvergenceGuestScriptsTest.php`, `TopologyConvergerTest.php`, `TopologyVerifierTest.php`, and `PreparedStateFingerprintTest.php`: cover contract selection, strict placement verification, repeat-safe hydration, and prepared-state inputs.

Out:
- Product code under `apps/cli`, `apps/gateway`, and `packages/php-sdk` remains unchanged; this issue only records those files as prepared-state inputs.
- The proof acquisition, execution, evidence, release, and snapshot lifecycle remains unchanged.
- The registered topology remains the standard `gateway`, `app-dev`, and `app-prod` three-Node topology with its existing role assignments and counts.
- Compatibility paths remain available; their final removal belongs to the later sample-convergence change.

## Documentation

- `docs/reference/topology-snapshot.md`: state the temporary sample contract selection, recorded-placement hydration and verification rules, strict mismatch behavior, and the removal boundary.
- Audit scope: `docs/reference/topology-snapshot.md`, `docs/reference/incus-topologies.md`, `docs/reference/php-runtime.md`, `docs/reference/environment-variables.md`, `docs/reference/apps.md`, and `docs/domains/applications.md`; no drift outside the required topology-snapshot addition was found.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Current main sample topology remains operable with the adapter | `converge-sample-app.sh`, `TopologyConverger.php`, and verifier boundaries | Incus action `production-bridge-current` |
| 2. Flat/shared and release/dedicated placements verify exactly and mismatches fail | `TopologyVerifier.php`, `verify-topology.sh`, and `TopologyVerifierTest.php` | `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Unit/E2E/TopologyVerifierTest.php` |
| 3. Sample mutation selects the complete supported contract once and never falls back after failure | `converge-sample-app.sh` and `ConvergenceGuestScriptsTest.php` | `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Unit/E2E/ConvergenceGuestScriptsTest.php` |
| 4. Hydration uses recorded placement, conditionally imports and syncs environment, and preserves repeated state | `TopologyConverger.php`, `converge-sample-app.sh`, and `TopologyConvergerTest.php` | `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Unit/E2E/TopologyConvergerTest.php`; Incus action `production-bridge-repeat` |
| 5. Prepared-state coverage tracks production runtime, release, cloning, deployment, and environment surfaces while unchanged inputs stay a no-op | `prepared-state.json` and `PreparedStateFingerprintTest.php` | `cd apps/e2e && vendor/bin/pest --no-tia --compact tests/Unit/E2E/PreparedStateFingerprintTest.php` |
| 6. Maintained documentation states temporary compatibility and removal | `docs/reference/topology-snapshot.md` | `composer docs-lint` |
| 7. Harness checks and focused tests pass | All listed harness and test boundaries | `cd apps/e2e && composer check`; focused affected Pest tests; five full no-TIA suites in CI |

## Incus observations

`observed_inputs: true`. Both acceptance actions can collect complete PHP observations on the unchanged gateway, app-dev, and app-prod surfaces. No topology extension is required; the plan uses the standard three-Node recipe and adds only setup or observation actions needed to distinguish current and repeat convergence.

## Implementation order

1. Document the temporary compatibility and strict recorded-placement rules.
2. Add verifier fixtures for both exact production layouts and every required mismatch.
3. Pass validated recorded sample placement from the verifier into the guest probes.
4. Select the sample creation contract before any sample mutation and preserve supported-operation failures.
5. Hydrate the recorded placements and conditionally import and synchronize environment configuration without replacing repeat state.
6. Expand the prepared-state input manifest and its coverage test.
7. Add the two standard-topology proof actions, run focused shell and harness checks, then prove and release the exact signed candidate.

## Must preserve

- ADR 0036: the harness constructs and verifies only AppInstance and Route sample workloads and adds no legacy migration tooling.
- ADR 0037: the standard topology remains the registered fresh `gateway`, `app-dev`, and `app-prod` three-Node topology, and normal proof still starts from the promoted snapshot.
- ADR 0044: environment import stays explicit, synchronization uses recorded placement, stored values remain authoritative, and synchronization never imports local edits.
- ADR 0045: the verifier recognizes a dedicated production user's service, pool, socket, and OPcache as one exact placement while retaining the exact shared placement until product conversion ships.
- ADR 0046: release placement keeps persistent environment and optional SQLite state outside releases, resolves the web root through `current`, and requires explicit deployment.
- ADR 0047: candidate creation remains separate from deployment, copies independent environment configuration, and does not mutate the candidate.
- ADR 0049: `.loop` plans and fixtures remain off the candidate and publish on an immutable artifact ref bound to the exact head.
- ADR 0050: successful proof evidence is captured before proof and discovery resources are released, and review receives archive evidence instead of live proof resources.
- A supported mutation failure is terminal for that convergence attempt and cannot trigger an older fallback path.
- Repeated convergence preserves existing application keys, the database contents, sample identities, and the active release.

## Open questions

- none

## Deviations

- none

## Review findings

- none
