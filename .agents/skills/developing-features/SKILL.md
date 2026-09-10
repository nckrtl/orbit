---
name: developing-features
description: Use when implementing one Orbit issue from a worktree.
---

# Developing Features

Implement one Linear issue in its worktree, push the exact candidate, and return a pull-request body that proves every `Acceptance` item. Own code, tests, integration, proof, commits, and the branch push. Never review or approve your own work.

This task may be invoked directly. A supplied `.loop/plan.md` is the implementation map, not a lifecycle gate.

The external orchestrator owns pull-request creation, updates, review requests, review publication, and merge. The developer must not invoke `gh` or mutate any pull-request or GitHub surface.

## Delivery flow

Run `bin/loop-flow status` in the issue worktree and read [Implementation loop](../../../docs/reference/implementation-loop.md). Name the selected `discovery` or `proof` flow in every handoff. The separately published `.loop/flow.json` binds the choice to the candidate; a missing selection defaults to `discovery`. Proof is opt-in: select `proof` explicitly before planning or reviewing. A repository-default change does not change an existing worktree.

## Inputs

- The issue in the `creating-issues` shape: outcome, `Scope`, `Acceptance` checklist, labels, attached ADRs, and relations.
- A worktree from `bin/worktree-create <ISSUE> <slug>` on the branch `<issue-lowercase>-<slug>`, bootstrapped from `main`.
- Work from the whole-repository worktree; run Composer and Pest in each affected app or package directory. Bootstrap installs the pinned Pest monorepo fixes and seeds absent private TIA caches from compatible published main baselines. Check its per-project seed results; never copy a feature graph back to main. Use `composer test:affected` for optional TIA feedback with two workers, and focused explicit-path tests for acceptance. A cold TIA baseline can run the full project suite; do not use root `bin/test` as its fallback.
- The plan when one exists, with its acceptance map, `Must preserve` list, and the `docs:` commits the planner made.

Stop if the issue is in a lifecycle state other than exactly `Todo` or `In Progress`, still has a `Readiness` section, has an unfinished `blocked by` relation, has sub-issues, or does not follow the `creating-issues` template; if an attached ADR's Status is not `Accepted on`; if an `Acceptance` item requires guessing product behavior; if a change would cross an `Out` bullet or an attached ADR `Decision` bullet; if a boundary needs a component the issue is not labeled with, where a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`; or if product work would touch the harness.

## Steps

1. **Read the contract.** Issue, attached ADRs, nearest `AGENTS.md`, the plan, and `composer docs-context` for the labeled components when the issue has any; never run it unfiltered.
2. **Acquire a topology when needed.** In `discovery`, require completed preflight and an independent `PASS` before acquisition. In `proof`, acquire with the `proof:incus` label. In either case, run `bin/e2e-topology acquire <ISSUE> <worktree>`. An extended topology requires its `.loop/proof/<ISSUE>.json` extension declaration before acquisition; acquisition never adds an extension later. The worktree is mounted on `gateway` and `app-dev`; app-prod Nodes run no Orbit code.
3. **Develop.** Work the acceptance map in order. Use `bin/e2e-topology shell <ISSUE> <role>` or `bin/e2e-topology exec <ISSUE> <role> --argv='[...]'` for discovery.
4. **Report harness gaps.** If `apps/e2e` or `bin/e2e-*` prevents product work, stop and report a dedicated harness issue.
5. **Codify.** Put behavior in product code with a test per `Acceptance` item where the proof action is a test. When the plan's Documentation section lists the pages the planner wrote, correct a page only where implementation deviates from what it states, following `writing-documentation`. A deviation that keeps every `Acceptance` item's meaning is recorded under `## Deviations` in the plan and in the pull request body with its reason; a deviation that changes what an `Acceptance` item means is a stop, because the contract changed. When no plan exists, run `auditing-documentation` in the issue's scope, write the pages the `docs` label requires by `writing-documentation` before codifying, and carry the audit's `Fixed` and `Reported` lists into the pull request body. Change no other page. Run focused tests, `composer docs-lint` when documentation changes, and each changed project's `composer check`. The reviewer runs root `composer check` across all projects with TIA; root `bin/test` is only for an explicit full local run or failure diagnosis.
6. **Prove the exact commit (proof flow only).** Skip this step entirely in `discovery`. For Incus proof, run `vendor/bin/pest --no-tia --compact tests/Unit/E2E/ProofFixtureShellContractTest.php` from `apps/e2e` after writing the fixtures. Write `.loop/proof/<ISSUE>.json` with one action per `Acceptance` item whose proof names Incus. Set `observed_inputs: true` when the actions support complete PHP observations on the required surfaces; verify the planner’s decision, or make it when no plan exists. Record any opt-out reason in the handoff. Follow the collection and cleanup requirements in [proof plans](../../../docs/reference/proof-plans.md); never silently disable collection after a failure. Merge current `main`, commit the candidate without `.loop/`, publish the artifacts with `bin/loop-artifacts publish <ISSUE>`, and run `bin/e2e-topology prove <ISSUE>` while discovery remains active. Every action must exit `0`. On diagnosis, inspect with `shell --proof` or `exec --proof`, continue on discovery, then `release <ISSUE> --proof` before proving again. After success, run `bin/e2e-topology release <ISSUE> --proof --capture`, then release idle discovery with `bin/e2e-topology release <ISSUE>`. Verify the evidence archive and resource absence. Keep the ignored local workspace and published artifact ref. For later commits, including main integration, follow retained-proof evaluation below.
7. **Hand off the exact candidate.** Include current `origin/main` only in `proof`. In `discovery`, do not fetch or integrate main merely because it advanced; integrate only when an actual merge conflict blocks landing. In either flow, commit every product and documentation change without `.loop/`, publish the complete `.loop/` workspace with `bin/loop-artifacts publish <ISSUE>`, push the branch, and verify the local head equals the remote branch head. The proposed body opens with `Issue: <ID>`, then lists every `Acceptance` item in the issue's order, each followed by its evidence: the test, the command output, or the proof action name. It then lists every documentation page changed and why, including the planner's pages, drift fixes from the plan's Documentation section or the no-plan audit, every reported finding from either audit with its owner, and every deviation with its reason, or `Documentation: none: <why>` when the `docs` label is absent and no page changed, or `Documentation: unchanged: <why>` when the label is present and the pages already state the outcome. Name the selected flow. In `discovery`, end with `Discovery development only; isolated acceptance proof not run` and list the actual tests and discovery observations. In `proof`, end with one proof line: `Proved with .loop/proof/<ISSUE>.json at <sha>` or `Automated tests only`. In `proof`, do not hand off until every declared proof action has exited `0`. In `discovery`, keep discovery available for reviewer inspection, then release it during closeout; no proof or snapshot action is required.

   Return the pushed head SHA, branch and base, the complete proposed body, exact command/check/proof results, documentation and deviation report, retained-proof binding when applicable, and every limitation. The external orchestrator uses that payload to create or update the pull request and request review. Include the artifact ref and SHA in the handoff and proposed PR body. The independently approved candidate is the merge head; no removal commit or second approval is needed. Artifact changes require a new candidate and artifact publication before review.

## Corrections

Apply findings that cite an `Acceptance` item, ADR bullet, invariant, test, or repository rule. A new requirement is separate Linear work. After a correction, rerun the affected checks and commit. In `proof`, include current `origin/main` and repeat exact-commit proof where required. In `discovery`, actual conflicts require fetching and merging main, resolving conflicts, running affected checks, publishing artifacts for the new head, and pushing again. Return the resolution diff for reviewer inspection without restarting preflight or adding proof checks.

## Retained proof after a candidate changes

This section applies only to `proof`. Discovery-only delivery never runs equivalence or candidate convergence, including after conflict resolution.

A movement of `main` requires integration and a new evidence decision, not automatic release of a successful proof. Preserve the previous reviewed head, its included-main SHA, and the complete reviewer handoff. Integrate current `origin/main`, commit, and publish the unchanged artifacts for the new candidate. Run focused affected tests and changed-project checks on the clean combined candidate, then run `bin/e2e-topology equivalence <ISSUE> --json` against captured evidence. A missing current-main ancestor must be repaired before this evaluation.

- `exact` or `equivalent` with `promotion_path: retained-proof`: keep the acceptance proof.
- `equivalent` with `promotion_path: candidate-convergence`: keep the acceptance proof and run `bin/e2e-topology candidate <ISSUE>`. Require successful current-candidate convergence and general verification; do not rerun feature setup or acceptance. Release successful candidate resources with `--candidate` before review and retain the recorded result. A failed candidate attempt needs explicit diagnosis and release with `--candidate` before retry.
- `stale` or `indeterminate`: follow the report's `next_action`, resolve listed errors, release the old proof if its lease remains, and run complete proof on the integrated candidate.

Changed paths alone never establish equivalence. An uninstrumented proof cannot acquire observations retrospectively, and changing its plan requires a new proof. Return the immutable equivalence report and fingerprint, selected promotion path, any candidate evidence, prior review handoff, new head/base, and current-head check results. Push and verify the pushed head before review; the reviewer runs the local gate. Let `reviewing-pull-requests` determine what assessment can be reused; approval never transfers between heads.

## Harness issues

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. A dedicated harness issue carries the `apps/e2e` label, repository-owner-approved behavior, and issue-specific proof; implement it with unit and feature tests, run focused harness tests, `apps/e2e` `composer check`, and its declared evidence under the selected flow. Require a passing local review gate. A discovery-flow harness issue uses discovery observations; isolated proof is not a hidden exception.

## Rules

- One issue per worktree, and one writer per worktree at a time: the planner, the plan reviewer, and the implementer take turns in it.
- Product feature branches never touch harness code as defined above.
- Proof actions are read-only unless the proof plan sets `"mutates": true`. A plan that removes a node declares the expected final node set.
- Discovery and proof use separate topologies and never share resources across issues.
- The developer may push the issue branch with Git, but every pull-request and GitHub mutation belongs to the external orchestrator.
