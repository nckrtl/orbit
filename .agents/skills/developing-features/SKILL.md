---
name: developing-features
description: Use when implementing one Orbit issue from a worktree.
---

# Developing Features

Implement one Linear issue in its worktree, push the exact candidate, and return a pull-request body with evidence for every `Acceptance` item under the selected flow. Own code, tests, integration, applicable proof, commits, and the branch push. Never review or approve your own work.

This task may be invoked directly. A supplied `.loop/plan.md` is the implementation map, not a lifecycle gate.
When assigned planning, follow `planning-features` and return its verified lint
receipt before reporting the plan complete. Development is a separate assignment.
When development changes a plan in the current format, regenerate its receipt
with `bin/plan-lint record <ISSUE>` before saving or publishing the artifacts.
Trust structural validation; retain independent review and acceptance proof.

The external orchestrator owns pull-request creation, updates, review requests, review publication, and merge. The developer must not invoke `gh` or mutate any pull-request or GitHub surface.

## Coordinate implementation helpers

You may act as the implementation lead and delegate bounded coding, testing,
research, or advisory inspection to subagents when useful work can proceed in
parallel. Each helper inherits this issue, the selected flow, the assigned
phase, its scope and exclusions, and all role restrictions. Helper findings
inform your work; they never supply the official plan or pull-request approval.
The external orchestrator dispatches those independent reviewers and controls
delivery phase transitions. Return a missing review or other phase prerequisite
to that orchestrator instead of creating the next delivery role yourself.

Assign disjoint files to editing helpers; serialize changes to shared files.
Keep Git index changes, commits, artifact publication, topology operations, and
final project checks under your control. Coordinate checks that share a project's
test state or caches. Inspect and integrate helper results, verify the combined
candidate, and finish or stop every helper before returning one complete handoff.
Report what was delegated and any unresolved limitations. You remain responsible
for every acceptance item and the final candidate.

## Delivery flow

Run `bin/loop-flow status` in the issue worktree and read [Implementation loop](../../../docs/reference/implementation-loop.md). Name the selected `discovery` or `proof` flow in every handoff. The separately published `.loop/flow.json` binds the choice to the candidate; a missing selection defaults to `discovery`. Proof is opt-in: select `proof` explicitly before planning or reviewing. A repository-default change does not change an existing worktree.

## Inputs

- The issue in the `creating-issues` shape: outcome, `Scope`, `Acceptance` checklist, labels, attached ADRs, and relations.
- A worktree from `bin/worktree-create <ISSUE>` on the branch `<issue-lowercase>`, bootstrapped from `main`.
- Work from the whole-repository worktree; run Composer and Pest in each affected app or package directory. Bootstrap installs the pinned Pest monorepo fixes and seeds absent private TIA caches from compatible published main baselines. Check its per-project seed results; never copy a feature graph back to main. Use `composer test:affected` for acceptance feedback with two workers. Never pass Pest a path, filter, group, or suite because partial runs disable TIA. A cold TIA baseline can run the full project suite; do not use root `bin/test` as its fallback.
- The plan when one exists, with its acceptance map, `Must preserve` list, and the `docs:` commits the planner made.

Work in the assigned issue worktree and discover its branch, `HEAD`, and changes
locally. A copied startup SHA is context unless the task explicitly requests
work on that revision; it is not a precondition for implementation. Preserve
existing work and report an actual checkout, merge-conflict, or unexpected-writer
problem. Review correction SHAs identify the findings' original revision; assess
their applicability to the current worktree without rewinding it. Record actual
Git identifiers in the handoff. Candidate, artifact, review, and merge bindings
remain exact when submitting the completed work.

Stop if the issue is in a lifecycle state other than exactly `Todo` or `In Progress`, still has a `Readiness` section, has an unfinished `blocked by` relation, has sub-issues, or does not follow the `creating-issues` template; if an attached ADR's Status is not `Accepted on`; if an `Acceptance` item requires guessing product behavior; if a change would cross an `Out` bullet or an attached ADR `Decision` bullet; if a boundary needs a component the issue is not labeled with, where a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`; or if product work would touch the harness.

## Steps

1. **Read the contract.** Issue, attached ADRs, nearest `AGENTS.md`, the plan, and `composer docs-context` for the labeled components when the issue has any; never run it unfiltered.
2. **Resolve Incus and acquire when required.** Check the `incus` label separately from the selected flow. For an `incus` issue in either flow, require completed preflight and an independent `PASS`, then run `bin/e2e-topology acquire <ISSUE> <worktree>` for discovery. Explicit `proof` adds a separate proof topology in step 6. Without the label, use local checks and skip topology operations; if acceptance actually needs real machines, report the label mismatch before omitting those checks. The label never changes the flow. An extended topology requires its `.loop/proof/<ISSUE>.json` extension declaration before acquisition; acquisition never adds an extension later. The worktree is mounted on `gateway` and `app-dev`; app-prod Nodes run no Orbit code.
3. **Develop.** Work the acceptance map in order. When using Incus, use `bin/e2e-topology shell <ISSUE> <role>` or `bin/e2e-topology exec <ISSUE> <role> --argv='[...]'` for discovery.
4. **Report harness gaps.** If `apps/e2e` or `bin/e2e-*` prevents product work, stop and report a dedicated harness issue.
5. **Codify.** Put behavior in product code with a test per `Acceptance` item where the proof action is a test. When the plan's Documentation section lists the pages the planner wrote, correct a page only where implementation deviates from what it states, following `writing-documentation`. A deviation that keeps every `Acceptance` item's meaning is recorded under `## Deviations` in the plan and in the pull request body with its reason; a deviation that changes what an `Acceptance` item means is a stop, because the contract changed. When no plan exists, run `auditing-documentation` in the issue's scope, write the pages the `docs` label requires by `writing-documentation` before codifying, and carry the audit's `Fixed` and `Reported` lists into the pull request body. Change no other page. Run `composer test:affected`, `composer docs-lint` when documentation changes, and each changed project's `composer check`. Root `bin/test` is only for an explicit repository-wide TIA run or failure diagnosis.
6. **Prove the exact commit (Incus with explicit proof flow only).** Skip this step in `discovery` and for automated-only issues without `incus`. For Incus proof, run `composer test:fresh` from `apps/e2e` after writing the fixtures. Write `.loop/proof/<ISSUE>.json` with one action per `Acceptance` item whose proof names Incus. Set `observed_inputs: true` when the actions support complete PHP observations on the required surfaces; verify the planner’s decision, or make it when no plan exists. Record any opt-out reason in the handoff. Follow the collection and cleanup requirements in [proof plans](../../../docs/reference/proof-plans.md); never silently disable collection after a failure. Merge current `main`, commit the candidate without `.loop/`, publish the artifacts with `bin/loop-artifacts publish <ISSUE>`, and run `bin/e2e-topology prove <ISSUE>` while discovery remains active. Every action must exit `0`. On diagnosis, inspect with `shell --proof` or `exec --proof`, continue on discovery, then `release <ISSUE> --proof` before proving again. After success, run `bin/e2e-topology capture <ISSUE>` and verify the immutable evidence archive, exact candidate, plan, manifest, complete zero-exit actions, and retained standard or extended inventory. Then release only idle discovery with `bin/e2e-topology release <ISSUE>` before review. Keep the captured successful proof topology, ignored local workspace, and published artifact ref for interactive review and closeout. For later commits, including main integration, follow retained-proof evaluation below.
7. **Hand off the exact candidate.** Include current `origin/main` only in `proof`. In `discovery`, do not fetch or integrate main merely because it advanced; integrate only when an actual merge conflict blocks landing. In either flow, commit every product and documentation change without `.loop/`, require a clean worktree, and run root `composer check` on that exact candidate. If the candidate gate fails, fix the failure, commit the correction, and rerun it before handoff. Retain the successful `role: builder` receipt. Then publish the complete `.loop/` workspace with `bin/loop-artifacts publish <ISSUE>`, push the branch, and verify the local head equals the remote branch head. The proposed body opens with `Issue: <ID>`, then lists every `Acceptance` item in the issue's order, each followed by its evidence: the test, the command output, or the proof action name. It then lists every documentation page changed and why, including the planner's pages, drift fixes from the plan's Documentation section or the no-plan audit, every reported finding from either audit with its owner, and every deviation with its reason, or `Documentation: none: <why>` when the `docs` label is absent and no page changed, or `Documentation: unchanged: <why>` when the label is present and the pages already state the outcome. Name the selected flow. In `discovery`, end with `Discovery development only; isolated acceptance proof not run` and list the actual tests and discovery observations. In `proof`, end with one proof line: `Proved with .loop/proof/<ISSUE>.json at <sha>` or `Automated tests only`. In `proof`, do not hand off until every declared proof action has exited `0`, capture has succeeded, idle discovery is released, and the successful proof topology remains available for reviewer `shell --proof --review-action=<ID>` and `exec --proof --review-action=<ID>` actions. In `discovery` with `incus`, keep discovery available for reviewer inspection, then release it during closeout; no proof or snapshot action is required. Without `incus`, report `Incus: not required` and the local acceptance checks.

   Use the [shared review handoff](../../../docs/reference/implementation-loop.md#review-handoff): short acceptance rows may refer to detailed checks in the published development record instead of copying full logs or issue text. Apply the current TIA-only check policy to stale generic full-suite wording; do not run full suites merely because a retained plan copied it. Put `Builder gate: passed (<receipt>)` in the Checks row and include the same receipt path in the implementation handoff. Return the pushed head SHA, branch and base, the complete proposed body, exact command/check/proof results, documentation and deviation report, retained-proof binding when applicable, and every limitation. The external orchestrator publishes that complete body and reads it back before requesting review. Include the artifact ref and SHA in the handoff and proposed PR body. The independently approved candidate is the merge head; no removal commit or second approval is needed. Artifact changes require a new candidate and artifact publication before review.

## Corrections

Apply findings that cite an `Acceptance` item, ADR bullet, invariant, test, or repository rule. A new requirement is separate Linear work. After a correction, rerun the affected checks, commit, and run a fresh Builder candidate gate before handoff. In `proof`, a code or configuration fix found during interactive review requires a fresh complete proof and capture from the corrected candidate; release the obsolete successful attempt with `release <ISSUE> --proof --replace` before proving again, while preserving its captured evidence and review record. Include current `origin/main` and repeat exact-commit proof where required. In `discovery`, actual conflicts require fetching and merging main, resolving conflicts, running affected checks, publishing artifacts for the new head, and pushing again. Return the resolution diff for reviewer inspection without restarting preflight or adding proof checks.

## Retained proof after a candidate changes

This section applies only to `proof`. Discovery-only delivery never runs equivalence or candidate convergence, including after conflict resolution.

A movement of `main` requires integration and a new evidence decision, not automatic release of a successful proof. Preserve the previous reviewed head, its included-main SHA, and the complete reviewer handoff. Integrate current `origin/main`, commit, and publish the unchanged artifacts for the new candidate. Run `composer test:affected` and changed-project checks on the clean combined candidate, then run `bin/e2e-topology equivalence <ISSUE> --json` against captured evidence. A missing current-main ancestor must be repaired before this evaluation.

- `exact` or `equivalent` with `promotion_path: retained-proof`: keep the acceptance proof.
- `equivalent` with `promotion_path: candidate-convergence`: keep the acceptance proof and run `bin/e2e-topology candidate <ISSUE>`. Require successful current-candidate convergence and general verification; do not rerun feature setup or acceptance. Release successful candidate resources with `--candidate` before review and retain the recorded result. A failed candidate attempt needs explicit diagnosis and release with `--candidate` before retry.
- `stale` or `indeterminate`: follow the report's `next_action`, resolve listed errors, release the old proof if its lease remains, and run complete proof on the integrated candidate.

Changed paths alone never establish equivalence. An uninstrumented proof cannot acquire observations retrospectively, and changing its plan requires a new proof. Return the immutable equivalence report and fingerprint, selected promotion path, any candidate evidence, prior review handoff, new head/base, and current-head check results. Push and verify the pushed head before review; the reviewer validates the Builder gate receipt. Let `reviewing-pull-requests` determine what assessment can be reused; approval never transfers between heads.

## Harness issues

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. A dedicated harness issue carries the `apps/e2e` label, repository-owner-approved behavior, and issue-specific proof; implement it with unit and feature tests, run `composer test:affected`, `apps/e2e` `composer check`, and its declared evidence under the selected flow. Require a passing Builder candidate gate. A discovery-flow harness issue uses discovery observations; isolated proof is not a hidden exception.

## Rules

- One issue and one integration owner per worktree: planning, formal review, and implementation take turns. During implementation, helpers may edit disjoint files under the implementation lead's coordination; no two agents edit the same file concurrently.
- Product feature branches never touch harness code as defined above.
- Proof actions are read-only unless the proof plan sets `"mutates": true`. A plan that removes a node declares the expected final node set.
- Discovery and proof use separate topologies and never share resources across issues.
- The developer may push the issue branch with Git, but every pull-request and GitHub mutation belongs to the external orchestrator.
