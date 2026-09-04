# Feature plan

Issue: ORB-111
Review verdict: PENDING

## Outcome

Complete APT Tool removal when dpkg keeps a removed package record so Orbit deletes successful or retried Tool intent and Doctor remains verifiable.

## Code boundaries

In:

- `apps/gateway/app/Infrastructure/Tools/AptToolManager.php`: keep the exact `dpkg-query` command and parse successful output into three closed outcomes: the existing no-record or `unknown ok not-installed` absence, an exact installed record with one safe version, or a recognized removed-but-recorded disposition with one safe retained version. Treat `deinstall ok config-files`, `deinstall ok not-installed`, `purge ok config-files`, and `purge ok not-installed` as absent. Reject every other shape or status.
- `apps/gateway/tests/Feature/Infrastructure/Tools/AptToolManagerTest.php`: move `deinstall ok config-files` out of the malformed-status dataset, cover the complete recognized removed-disposition matrix, and retain focused failure coverage for missing, duplicate, control-bearing, oversized, nonzero, truncated, and unrecognized output.
- `apps/gateway/tests/Feature/Infrastructure/Tools/NativeToolInspectorTest.php`: exercise the real APT adapter through the manager registry and prove that a recognized removed dpkg record becomes `ToolInspectionData(false, null)` instead of `ToolInspectionException`. `NativeToolInspector.php` itself already maps a manager's null version to this result and needs no production change.

Out:

- Keep `AptToolManager::remove` on the fixed `sudo apt-get remove --yes -- <package>` command. Do not use `apt-get purge` or delete Node configuration files.
- Keep `AptToolManager::planRemoval`, its exact-package rule, and its prohibition on `autoremove` and dependency cleanup unchanged.
- Keep `VpToolManager.php` and `ComposerToolManager.php` semantics unchanged. Do not alter install or update version-probe policy beyond the shared APT parser's exact absence dispositions.
- Keep `apps/gateway/app/Actions/Tools/RemoveToolAction.php` ordering unchanged: probe under the existing locks, plan and remove only when installed, probe again, delete the Tool row only after absence, and retain one bounded retryable failure record for real failures. Its existing `RemoveToolActionTest.php` remains the regression proof.

## Documentation

Completed during the bounded planning correction and committed as `43b4f4b1 docs: document APT Tool removal`:

- `docs/reference/tools.md` now tells Tool operators how successful removal and retry behave, states that APT leaves package configuration files on the Node, lists the bounded removal outcomes, and explains the bounded Doctor result before and after the Tool row is deleted.
- `docs/README.md` now routes readers to the Tools reference with the other product reference pages.
- `docs/generated/context.json` was regenerated and now indexes the Tools reference with its Gateway, Node, Doctor, ADR 0001, and ADR 0004 relationships.
- Audit scope covered ORB-111, the `apps/gateway` documentation context, the Gateway, Node, and Doctor concept context, and the Tool removal reference required by the `docs` label. The two reported coverage gaps were fixed; no finding requires a separate owner.
- `composer docs-build` completed and `composer docs-lint` passed with zero issues.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Installing and removing an APT package that ships configuration files succeeds and leaves no Tool row. | `AptToolManager.php`; `.loop/proof/ORB-111.json` and its issue-local fixture | Incus action `apt-remove-config-files`, executed by `bin/e2e-topology prove ORB-111`, installs a package such as `redis-server`, removes it through `orbit tool:remove`, verifies dpkg retains only configuration files and the Tool is absent from `orbit tool:list --json`, then removes only the fixture's residual state and exits `0`. |
| `deinstall ok config-files` with a retained version reports absence instead of `tool.version_probe_failed`. | `AptToolManager.php`; `AptToolManagerTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Infrastructure/Tools/AptToolManagerTest.php` covers the exact retained-version record and exits `0`. |
| Retrying removal deletes a Tool row stranded by this condition without a manual recovery command on the Node. | `AptToolManager.php`; unchanged `RemoveToolAction.php`; `.loop/proof/ORB-111.json` and its fixture | A setup action prepares a second package in the exact failed-row and removed dpkg state. Incus acceptance action `apt-remove-stranded-retry`, executed by `bin/e2e-topology prove ORB-111`, uses only `orbit tool:remove <id>` for recovery, verifies the row is gone, removes only fixture residue, and exits `0`. |
| Doctor reports the `tool` family healthy after successful removal while dpkg retains configuration files. | `AptToolManager.php`; `NativeToolInspectorTest.php`; existing no-row behavior in `ToolDoctorProbeTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Infrastructure/Tools/NativeToolInspectorTest.php` proves the dpkg record is bounded absence; Incus action `apt-remove-config-files` proves the successful removal deleted intent and `orbit doctor --node=<id> --family=tool --json` exits `0` with a healthy family. |
| Missing, duplicate, control-bearing, oversized, and unrecognized installed-version output still fails closed. | `AptToolManager.php`; `AptToolManagerTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Infrastructure/Tools/AptToolManagerTest.php` retains the invalid-output matrix and exits `0`. |
| Tool intent is deleted only after a successful absence probe, while genuine failures retain one bounded retryable record. | Unchanged `RemoveToolAction.php`; existing `RemoveToolActionTest.php` | `cd apps/gateway && ./vendor/bin/pest tests/Feature/Domain/RemoveToolActionTest.php` exits `0`. |
| The Tool removal reference states the successful-removal/config-file behavior and generated context is current. | `docs/reference/tools.md`; `docs/README.md`; `docs/generated/context.json` | From the repository root, `composer docs-build` and then `composer docs-lint` both exit `0`. |
| Gateway checks pass. | All `apps/gateway` boundaries above | `cd apps/gateway && composer check` exits `0`. |
| All repository test suites pass. | All implementation, documentation, and issue-proof boundaries above | From the repository root, `bin/test` exits `0`. |

## Implementation order

1. Start from the committed `docs/reference/tools.md` behavior contract and change it only if implementation reveals a deviation; regenerate context and rerun documentation lint after any such correction.
2. Add the recognized removed-disposition matrix and the preserved invalid-output matrix to `AptToolManagerTest.php`; add APT-backed absence coverage to `NativeToolInspectorTest.php` without changing the inspector seam.
3. Refactor only `AptToolManager::installedVersion` enough to validate one exact status/version record before returning the raw version for `install ok installed` or null for the four closed removed dispositions. Preserve the existing exact no-record and one-line `unknown ok not-installed` cases.
4. Run both focused infrastructure test files and the unchanged `RemoveToolActionTest.php`; correct only the APT parser or its new focused expectations.
5. Replace the inherited ORB-121 proof artifacts under `.loop/proof/` with `.loop/proof/ORB-111.json` and one self-checking fixture. Use a setup mode to strand one package before acceptance, then use separate acceptance modes for `apt-remove-config-files` and `apt-remove-stranded-retry`. Keep the harness unchanged. Make each acceptance mode verify its dpkg state, Tool-row state, bounded retry result, and Doctor result before success-only fixture cleanup removes the test package and retained configuration. Leave the plan non-mutating so the proved topology remains eligible for promotion.
6. Run `cd apps/gateway && composer check`, root `bin/test`, root `composer docs-lint`, and `git diff --check`. Then run `bin/e2e-topology prove ORB-111` on a fresh proof topology and retain the exact successful evidence for review.

## Must preserve

- ADR 0001, “Model managed intent”: a Tool row remains managed intent for one Node, manager, and package. The parser must not scan, infer, or adopt host inventory.
- ADR 0001, “Preserve package ownership during removal”: APT still proves an exact removal plan, removes only the recorded package, never runs `autoremove`, never purges configuration as part of this issue, and deletes the Tool row only after successful absence verification.
- ADR 0001, “Serialize mutations and retain recoverable state”: the existing identity and manager locks remain in force; a real failed probe or removal keeps one bounded failed record; a retry probes live state first; a successful removal deletes the row.
- ADR 0001, “Treat caller input as a security boundary” and “Split component ownership”: callers gain no argv, option, repository, or privilege input; unsafe or ambiguous dpkg output still fails closed; raw stdout, stderr, and retained versions do not enter public errors or activity; the policy stays in the Gateway.
- ADR 0004, “Keep Doctor verify-only”: `NativeToolInspector` continues to call only the read-only installed-version probe and returns only installed state plus a nullable normalized version. Doctor does not mutate the package, configuration files, Tool row, or manager state.
- ADR 0004, “Return bounded deterministic reports”: recognized removed dpkg records yield only bounded absence. Stranded Tool intent remains `tool.not_installed` drift, while deleted intent leaves the `tool` family healthy when no other finding exists. Neither the raw dpkg status nor the retained package version enters the Doctor report.
- ADR 0004, “Preserve the Tool contract”: while a stranded Tool row still exists, a recognized removed dpkg record is verifiable absence and the Tool probe may report `tool.not_installed` drift; it must not become `tool.inspection_failed`. After a successful retry deletes the row, the `tool` family is healthy with no managed resource to inspect.
- The installed-version parser keeps the exact successful no-record stderr match, the exact one-line `unknown ok not-installed` case, the exact `install ok installed` status, one safe retained version for every two-line record, trailing-newline normalization, and rejection of missing, extra, control-bearing, oversized, truncated, nonzero, or unknown output.
- `AptToolManager` package validation, fixed argv, Debian version normalization, sanitized exceptions, candidate probing, mutation commands, and removal-plan parsing remain unchanged.
- Existing `RemoveToolActionTest.php` invariants remain green: absent intent deletes without planning or mutation; unsafe plans and genuine probe/removal failures retain bounded state; successful removal performs the second absence probe before deletion; sibling Tool and manager rows remain intact.
- VP and Composer managers, the CLI and PHP SDK transport, the E2E harness, and production release remain outside this change. The Incus proof uses only the repository's issue-local proof plan and fixtures.

## Open questions

- none; ORB-111, the accepted ADRs, the current dpkg output contract, and the existing removal and Doctor tests determine the implementation and proof boundaries.

## Deviations

- none.

## Review findings

- addressed: Acceptance 7, the `docs` label, and the Durable knowledge rule are now covered by the committed Tools reference, README route, regenerated context, successful docs build and lint, and the updated Documentation section.
- addressed: Acceptance 4 now preserves ADR 0004, “Return bounded deterministic reports,” with the required bounded-absence, stranded-drift, deleted-intent health, and no-raw-status-or-version invariants.
