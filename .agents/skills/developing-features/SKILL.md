---
name: developing-features
description: Use when implementing one Orbit issue from a worktree.
---

# Developing Features

Implement one current Linear issue or equivalent written contract in its
worktree and open a pull request. Own code, tests, integration, proof, commits,
and the pull request.

This task may be invoked directly. A supplied `.orbit/plan.md` is implementation
guidance, not a required lifecycle gate. Never review or approve your own pull
request.

## Inputs

Require:

- a bounded issue with observable criteria;
- a bootstrapped worktree on a branch from `main`;
- applicable ADRs and repository guidance; and
- any supplied Feature plan or review findings.

Stop if the contract requires guessing product behavior, conflicts with an
accepted ADR, or mixes product work with a harness change.

## Steps

1. **Read the contract.** Read the issue, ADRs, nearest `AGENTS.md`, supplied
   plan, documentation-impact classification, relevant context from
   `composer docs-context`, and acceptance-to-proof mapping.
2. **Acquire a topology when needed.** For `Proof: incus`, run
   `bin/e2e-topology acquire <ISSUE> <worktree>`. The worktree is mounted on
   `gateway` and `app-dev`; `app-prod` runs no Orbit code.
3. **Develop.** Use `bin/e2e-topology shell <ISSUE> <role>` or
   `bin/e2e-topology exec <ISSUE> <role> --argv='[...]'`. Iterate until the
   requested behavior is correct.
4. **Report harness gaps.** If `apps/e2e` or `bin/e2e-*` prevents product work,
   stop and report a dedicated harness issue. Do not modify harness from a
   product feature branch.
5. **Codify.** Put required behavior in product code with tests. Reconcile
   required documentation in the same pull request, or preserve the issue's
   explicit `none` rationale. Run focused checks, `composer docs-lint`, each
   changed project's `composer check`, and root `bin/test`.
6. **Prove the exact commit.** For Incus proof, write
   `proofs/<ISSUE>.json`, merge current `main`, and run
   `bin/e2e-topology prove <ISSUE>` while discovery remains active. Every
   action must exit `0`. On diagnosis, inspect the failed proof explicitly with
   `shell --proof` or `exec --proof`, continue development on discovery, then
   `release <ISSUE> --proof` before proving again. Leave a successful proof
   unchanged through review and merge. If a later committed correction changes
   only documentation, `apps/docs`, or instructions, rerun its applicable
   checks and run `bin/e2e-topology equivalence <ISSUE>`. Retain the proof only
   for `exact` or `equivalent`; `stale` or `indeterminate` requires release and
   a complete fresh proof.
7. **Open the pull request.** Push and use a short body with what changed, why,
   and one proof line: `Proved with proofs/<ISSUE>.json at <sha>` or
   `Automated tests only`. Do not start review until every declared proof
   action has exited `0`.

## Corrections

Apply concrete defects against the issue, ADRs, repository invariants, tests, or
proof. A genuinely new requirement becomes separate Linear work. After a valid
correction, rerun affected checks, create a new commit, and repeat exact-commit
proof where required.

## Harness issues

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except
`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`.

For a dedicated harness issue:

- require repository-owner-approved behavior and issue-specific proof before implementation;
- implement with unit and feature tests;
- run `apps/e2e` `composer check` and root `bin/test`;
- run its declared focused or Incus proof; and
- follow any additional lifecycle checks stated in that issue's approved proof contract.

## Rules

- Do not run concurrent writers in one worktree.
- Product feature branches never touch `apps/e2e` or `bin/e2e-*`.
- Proof actions are read-only unless the proof plan sets `"mutates": true`.
- A plan that removes a node declares the expected final node set.
- One issue per worktree. Discovery and proof use separate topologies and never
  reuse proof resources across issues.
- Do not create a meaningless documentation diff when impact is `none`. Do not
  leave durable behavior, terminology, operational contracts, agent context, or
  reusable knowledge stale when impact is `required`.
