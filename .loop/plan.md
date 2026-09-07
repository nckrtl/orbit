# Feature plan

Issue: ORB-151
Review verdict: PASS

## Outcome

Permanent E2E fixture-contract tests validate the fixtures an issue branch carries and pass when the repository carries no `.loop` workspace.

## Code boundaries

In:

- `apps/e2e/tests/Unit/E2E/ProofFixtureContractTest.php`: remove the helpers, behavioral cases, and dataset coupled to one issue-local fixture.
- `apps/e2e/tests/Unit/E2E/ProofFixtureShellContractTest.php`: retain the dynamic shell-fixture scan, remove direct execution of one issue-local fixture, and reject literal fixture-path dependencies in permanent contract tests.

Out:

- Keep proof fixture staging and execution behavior unchanged.
- Keep ORB-132 and ORB-125 product behavior unchanged.
- Do not change maintained documentation because ADR 0022 already states the contract.

## Documentation

- Audit scope: fixture-contract tests and ADR 0022.
- Documentation: unchanged; the implementation restores the accepted contract and adds no public behavior.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Permanent fixture-contract tests inspect available `.loop/proof/*.sh` fixtures dynamically and contain no literal reference to an individual `.loop/proof/<fixture>` path. | `ProofFixtureContractTest.php`, `ProofFixtureShellContractTest.php` | Both focused Pest files |
| The E2E test suite passes after the issue-local `.loop` workspace is deleted. | Complete `apps/e2e` project and repository | `cd apps/e2e && composer check`; root `bin/test` on the deletion-only head |

## Implementation order

1. Remove the dedicated extended-runtime fixture helpers and cases from both permanent contract-test files.
2. Add one generic contract test that scans permanent fixture-contract test sources and rejects literal individual proof-fixture paths while allowing the existing dynamic wildcard scan.
3. Run the focused tests, `apps/e2e` `composer check`, and root `bin/test` with the ORB-151 workspace present, then repeat the required checks after the deletion-only commit.

## Must preserve

- ADR 0022: `.loop` is issue-local, `main` carries no `.loop`, fixture checks name no individual fixture, and the removal commit deletes `.loop` and nothing else.
- The dynamic early-exit pipeline check continues to scan every shell fixture carried by the current issue branch.
- No product or harness behavior changes.

## Open questions

none

## Deviations

none

## Review findings
