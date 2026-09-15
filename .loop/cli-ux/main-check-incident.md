# Main-check prerequisite

Historical incident. ORB-205 resolved the legacy fixture failure. The current foundation candidate has a passing Builder receipt at `checks/builder-6c8e23c9-passed.json`. Earlier failures below remain failure evidence for their original candidates.

At b58b6077 on Beast, all five project quality checks passed. Gateway TIA had
one failure (962 passed) in `RemoveNodeTest.php:953`: the offline AppProd role
fixture creates a legacy Instance, while Node removal now refuses remaining
Instances. The tested action and fixture are byte-identical to origin/main
85283bef. Runtime consumer removal shipped under ORB-203; ORB-205 is In Progress
and explicitly owns remaining legacy models, relationships, dead code and
fixtures. No Gateway fix was introduced into the CLI foundation.

Three E2E fixture failures at ConvergenceGuestScriptsTest.php:3150,3170,3191
matched ORB-349's endpoint-shape repair. Main85283bef contains that repair and
ORB-204's Doctor cleanup. Both were merged cleanly into79a12944; current-head
checks and registry reconciliation follow that merge. The old failed receipts
are retained and do not become success when a later repair lands.

Earlier macOS root checks also failed on Linux-only harness assumptions and
PHP128MB cold-TIA memory. Those environment failures are separate from the
Beast correctness failures. Final all-project checks run on Beast.

Original receipt:
`/home/nckrtl/orbit/.git/orbit-checks/b58b6077b1190e20c3494d5934972221481dfb24/review-01d8p9tw/result.json`.
Private local copy and failure logs:
`/Users/nckrtl/.codex/work/cli-ux-restoration/evidence/beast-builder-b58b6077/`.

No proof resources were acquired: snapshot status is missing and ORB-351
status is absent. ORB-91 owns recovery. The complete goal still includes
foundation acceptance/review/closeout, shared primitives, all command groups,
and integrated adoption. No command is marked compliant.

Current-head result at79a129447f58cec2cfb7fef3d922256e4162cf11: E2E TIA passes
228 tests/1338 assertions. The only failed gate action is Gateway TIA: the same
legacy Instance fixture,1 failed and3324 passed (20553 assertions,1400 affected).
All project Composer validations and quality checks pass. The candidate tree
remained unchanged. Current receipt is retained under the corresponding Beast
`orbit-checks/79a129447f58cec2cfb7fef3d922256e4162cf11/review-gsx5oy32/result.json`;
a copy is `checks/builder-79a12944-failed.json`.

At f59e9a1b, after accepted ORB-256 main d3121757 integration, the same sole
Gateway failure remains: RemoveNodeTest.php:953, 1 failed and 3396 passed
(20787 assertions, 1425 affected). All other checks pass. Receipt:
`/home/nckrtl/orbit/.git/orbit-checks/f59e9a1bd83b2055fb7f422b5cac9e63380b50af/review-48gxn4_x/result.json`.
Local copy: `checks/builder-f59e9a1b-failed.json`. This is not a passing gate.
