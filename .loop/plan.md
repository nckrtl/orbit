# Feature plan

Issue: ORB-111
Review verdict: FIX

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

No maintained page changed during planning because the direct task limits this pass to `.loop/plan.md`.

Read-only issue audit:

- Scope: ORB-111; the `apps/gateway` documentation context; the Gateway, Node, and Doctor concept context; and the Tool removal reference required by the `docs` label.
- Reported — owner ORB-111: `docs/reference/tools.md` does not exist, so maintained documentation does not state that successful APT removal deletes the Tool row while dpkg may retain configuration files. After plan approval, create this reference for Tool operators, describe successful removal and bounded retry behavior without restating ADR decisions, and state that retained configuration files remain on the Node.
- Reported — owner ORB-111: route the new Tool reference from `docs/README.md`, then regenerate `docs/generated/context.json` with `composer docs-build` so its Gateway, Node, Doctor, and ADR relationships are current.
- No other in-scope drift was found. `composer docs-lint` passed with zero issues on the planning head.

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

1. Create `docs/reference/tools.md`, route it from `docs/README.md`, regenerate `docs/generated/context.json`, run `composer docs-build` and `composer docs-lint`, and commit the maintained documentation as the planning contract requires before product code.
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

- FIX — Acceptance 7, the `docs` label, and the repository Durable knowledge rule require the maintained pages for this outcome during planning. The branch has no documentation commit between its base and head, `docs/reference/tools.md` is absent, and the Documentation section defers all three named documentation changes until implementation. Create and commit `docs/reference/tools.md`, route it from `docs/README.md`, regenerate and commit `docs/generated/context.json`, run `composer docs-build` and `composer docs-lint`, and update the Documentation section before the second review.
- FIX — Acceptance 4 changes the observable Doctor family status and therefore touches ADR 0004, “Return bounded deterministic reports,” but `Must preserve` does not name that decision. Add it with the invariant that recognized removed dpkg records yield only bounded absence, that stranded intent remains `tool.not_installed` drift while deleted intent leaves the family healthy, and that neither the raw dpkg status nor retained version enters the report.
