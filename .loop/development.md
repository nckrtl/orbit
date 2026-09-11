# ORB-239 development record

Flow: `discovery`

Incus: not required. The issue has `maintenance:monorepo` and `apps/e2e` labels, but no `incus` label, and its acceptance is automated. Discovery development only; isolated acceptance proof not run.

No approved feature plan exists. The interrupted setup left an unfilled plan template, which is not part of this development artifact.

## Incident and classification

ORB-238 merged as `f9ae1b841412661df409a64b2e9db37da9db230c`. Main maintenance then ran `composer test:affected` in `apps/e2e` with TIA and exited 1. The retained failure log is `/home/nckrtl/orbit/.git/orbit-tia/v1/run-gqo56h81/apps-e2e-tia.log`.

The run had two correctness failures:

- `StaticProofInputPolicyTest` found six tracked PHPUnit configuration files with indeterminate classification, including the guidance, cold-scenario, and snapshot-scenario files named by ORB-239.
- `ComposerConfigurationTest` required the optional text `TIA mode` in captured output from an intentionally failing guidance subprocess, even though the configured command still used `--tia --fresh`.

Classification: source correctness failure on main, limited to `apps/e2e`. The main merge hold remains valid until the reviewed repair merges and the original E2E failure is verified on main containing that repair.

## Candidate scope

Candidate `3b61d768a812f432de70bbdd24cbedc1f9d13226` has tree `2862ae3c57c292adf367be96c5953f6e546cfe8a` and one commit over base `f9ae1b841412661df409a64b2e9db37da9db230c`.

- `StaticProofInputPolicy::VERSION` advances from 3 to 4.
- The static policy classifies `phpunit.xml`, `phpunit.xml.dist`, and named variants such as `phpunit.guidance.xml`, `phpunit.scenario-cold.xml`, and `phpunit.scenario-snapshot.xml` as non-runtime.
- The policy unit data adds direct guidance and cold-scenario cases. Its tracked-repository invariant also covers the snapshot-scenario path and every other tracked path.
- The corrupt-guidance regression continues to require a nonzero failure, `Failed asserting`, and absence of the partial-run warning, without requiring Pest's optional heading.
- No Gateway behavior, Doctor family, proof-topology behavior, schema, dependency, or maintained documentation changes.

## Acceptance evidence

1. Named PHPUnit guidance and scenario inputs are non-runtime and all tracked paths are determinate. `apps/e2e/tests/Unit/E2E/StaticProofInputPolicyTest.php` covers direct named examples and walks the complete tracked tree. The TIA-enabled E2E test run passed.
2. The corrupt-guidance command remains exactly `vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact`. `apps/e2e/tests/Feature/Configuration/ComposerConfigurationTest.php` asserts the command, the intentional failure, `Failed asserting`, and absence of `TIA does not apply to partial runs`; it no longer depends on the optional heading. The TIA-enabled E2E test run passed.
3. `cd apps/e2e && composer test` exited 0 in TIA mode: 1,416 tests and 7,804 assertions passed. This retained development result was already recorded in PR #287 before this artifact handoff.
4. E2E quality checks passed, and the exact-candidate Builder gate passed every strict Composer validation, project `composer check`, and project `composer test:affected` step across all five projects. All Pest commands were TIA-only; no path, filter, group, suite, or `--no-tia` option was used.
5. `git diff --name-only origin/main...HEAD` lists only three `apps/e2e` PHP files. No maintained page under `docs/` changed.

## Checks

- Main failure reproduction: `cd apps/e2e && composer test:affected` at `f9ae1b841412661df409a64b2e9db37da9db230c` exited 1 with 2 failures and 1,416 passes; retained log `/home/nckrtl/orbit/.git/orbit-tia/v1/run-gqo56h81/apps-e2e-tia.log`.
- Focused candidate acceptance: `cd apps/e2e && composer test` exited 0 in TIA mode with 1,416 tests and 7,804 assertions.
- Changed project: `cd apps/e2e && composer check` exited 0. On the exact candidate, the Builder's E2E check ran 12 guidance tests with 136 assertions in TIA mode, then Rector, Pint, and PHPStan passed.
- Candidate diff: `git diff --check origin/main...HEAD` exited 0.
- Builder gate: passed (`/home/nckrtl/orbit/.git/orbit-checks/3b61d768a812f432de70bbdd24cbedc1f9d13226/review-4tqec2km/result.json`). The receipt has `role: builder`, exact candidate and tree bindings, `passed: true`, `unchanged: true`, and 15 zero-exit checks.
- Builder per-project outcomes: CLI check 13 tests/255 assertions and no later affected tests; Docs check 1 test/6 assertions plus fresh affected run 58 tests/132 assertions; Gateway check 12 tests/267 assertions and no later affected tests; E2E check 12 tests/136 assertions and no later affected tests; PHP SDK check 7 tests/94 assertions plus fresh affected run 427 tests/1,700 assertions. Rector, Pint, and PHPStan passed where configured.

The passing exact-candidate gate was reused. No broad check was rerun solely to recreate evidence.

## Documentation audit

Scope: ORB-239 and the pages selected by `composer docs-context -- --component=apps/e2e --concept=TIA`.

Fixed:

- none

Reported:

- none

Verification: the issue has no `docs` label, explicitly excludes documentation changes, and the candidate changes no maintained page. No documentation build or lint rerun was needed for this handoff.

## Deviations and limitations

- Deviations: none.
- The implementation has no approved plan artifact. Review must use the current Linear contract, this development record, the exact candidate, and the retained Builder receipt.
- Discovery flow and the missing `incus` label require no topology, proof plan, equivalence, candidate convergence, or snapshot action.
- A passing repair candidate does not clear the retained failure on main. After merge, maintenance must rerun the original E2E TIA check on main containing the repair and inspect the resulting cache publication before the orchestrator lifts the merge hold.
