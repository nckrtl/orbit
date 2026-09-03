---
name: developing-features
description: Use when implementing one Orbit issue from a worktree.
---

# Developing Features

Implement one Linear issue in its worktree and open a pull request whose body proves every `Acceptance` item. Own code, tests, integration, proof, commits, and the pull request. Never review or approve your own work.

This task may be invoked directly. A supplied `.orbit/plan.md` is the implementation map, not a lifecycle gate.

## Inputs

- The issue in the `creating-issues` shape: outcome, `Scope`, `Acceptance` checklist, labels, attached ADRs, and relations.
- A worktree from `bin/worktree-create <ISSUE> <slug>` on the branch `<issue-lowercase>-<slug>`, bootstrapped from `main`.
- The plan when one exists, with its acceptance map, `Must preserve` list, and the `docs:` commits the planner made.

Stop if the issue is not `Todo`, still has a `Readiness` section, has an unfinished `blocked by` relation, has sub-issues, or does not follow the `creating-issues` template; if an attached ADR's Status is not `Accepted on`; if an `Acceptance` item requires guessing product behavior; if a change would cross an `Out` bullet or an attached ADR `Decision` bullet; if a boundary needs a component the issue is not labeled with; or if product work would touch the harness.

## Steps

1. **Read the contract.** Issue, attached ADRs, nearest `AGENTS.md`, the plan, and `composer docs-context` for the labeled components.
2. **Acquire a topology when needed.** With the `proof:incus` label, run `bin/e2e-topology acquire <ISSUE> <worktree>`. The worktree is mounted on `gateway` and `app-dev`; `app-prod` runs no Orbit code.
3. **Develop.** Work the acceptance map in order. Use `bin/e2e-topology shell <ISSUE> <role>` or `bin/e2e-topology exec <ISSUE> <role> --argv='[...]'` for discovery.
4. **Report harness gaps.** If `apps/e2e` or `bin/e2e-*` prevents product work, stop and report a dedicated harness issue.
5. **Codify.** Put behavior in product code with a test per `Acceptance` item where the proof action is a test. When the plan's Documentation section lists the pages the planner wrote, correct a page only where implementation deviates from what it states, following `writing-documentation`. A deviation that keeps every `Acceptance` item's meaning is recorded under `## Deviations` in the plan and in the pull request body with its reason; a deviation that changes what an `Acceptance` item means is a stop, because the contract changed. When no plan exists, run `auditing-documentation` in the issue's scope, write the pages the `docs` label requires by `writing-documentation` before codifying, and carry the audit's `Fixed` and `Reported` lists into the pull request body. Change no other page. Run focused checks, `composer docs-lint`, each changed project's `composer check`, and root `bin/test`.
6. **Prove the exact commit.** For Incus proof, write `proofs/<ISSUE>.json` with one action per `Acceptance` item whose proof names Incus, merge current `main`, and run `bin/e2e-topology prove <ISSUE>` while discovery remains active. Every action must exit `0`. On diagnosis, inspect with `shell --proof` or `exec --proof`, continue on discovery, then `release <ISSUE> --proof` before proving again. Leave a successful proof unchanged through review and merge. If a later commit changes only documentation, `apps/docs`, or instructions, rerun its checks and `bin/e2e-topology equivalence <ISSUE>`; retain the proof only for `exact` or `equivalent`.
7. **Open the pull request.** Include current `origin/main` before pushing. The body opens with `Issue: <ID>`, then lists every `Acceptance` item in the issue's order, each followed by its evidence: the test, the command output, or the proof action name. It then lists every documentation page changed and why, including the planner's pages, drift fixes from the plan's Documentation section or the no-plan audit, and every deviation with its reason. End with one proof line: `Proved with proofs/<ISSUE>.json at <sha>` or `Automated tests only`. Do not request review until every declared proof action has exited `0`.

## Corrections

Apply findings that cite an `Acceptance` item, ADR bullet, invariant, test, or repository rule. A new requirement is separate Linear work. After a correction, rerun the affected checks, commit, include current `origin/main`, and repeat exact-commit proof where required.

## Harness issues

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. A dedicated harness issue carries the `apps/e2e` label, repository-owner-approved behavior, and issue-specific proof; implement it with unit and feature tests, run `apps/e2e` `composer check`, root `bin/test`, and its declared proof.

## Rules

- One issue per worktree, and one writer per worktree.
- Product feature branches never touch `apps/e2e` or `bin/e2e-*`.
- Proof actions are read-only unless the proof plan sets `"mutates": true`. A plan that removes a node declares the expected final node set.
- Discovery and proof use separate topologies and never share resources across issues.
