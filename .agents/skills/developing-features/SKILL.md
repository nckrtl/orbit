---
name: developing-features
description: Use when implementing one Orbit issue from a worktree.
---

# Developing Features

Implement one Linear issue or equivalent written contract in its repository
worktree and open a pull request. Own code, tests, integration, proof, commits,
and the pull request for this task.

This role may be invoked directly. A supplied `.orbit/plan.md` is implementation
guidance, not a required lifecycle gate.

## Inputs

Require:

- a bounded issue or written contract with observable acceptance criteria;
- a bootstrapped worktree on a branch from `main`;
- applicable ADRs and repository guidance; and
- any supplied implementation plan or review findings.

Stop if the contract requires guessing product behavior, conflicts with an
accepted ADR, or mixes product work with a harness change.

## Steps

1. **Read the contract.** Read the issue, relevant ADRs, nearest `AGENTS.md`,
   supplied plan, and acceptance-to-proof mapping.
2. **Acquire a topology when needed.** For `Proof: incus`, run
   `bin/e2e-topology acquire <ISSUE> <worktree>`. The worktree is mounted on
   `gateway` and `app-dev`; `app-prod` runs no Orbit code.
3. **Develop.** Use `bin/e2e-topology shell <ISSUE> <role>` for an interactive
   node shell or `bin/e2e-topology exec <ISSUE> <role> --argv='[...]'` for
   scripted checks. Iterate until the requested behavior is correct.
4. **Report harness gaps.** If `apps/e2e` or `bin/e2e-*` prevents product work,
   stop and report a dedicated harness issue. Do not modify the harness from a
   product feature branch.
5. **Codify.** Put required behavior in product code with tests. Run focused
   checks, each changed project's `composer check`, and root `bin/test`.
6. **Prove the exact commit.** For Incus proof, write
   `proofs/<ISSUE>.json` with one acceptance action per criterion, merge current
   `main`, release any discovery attempt, and run
   `bin/e2e-topology prove <ISSUE>`. A diagnosis attempt cannot become proof;
   fix, commit, and prove again. Leave successful proof resources unchanged.
7. **Open the pull request.** Push and use a short body: what changed, why, and
   one proof line—`Proved with proofs/<ISSUE>.json at <sha>`,
   `Harness: bin/e2e-live <sha> passed`, or `Automated tests only`.

## Corrections

Apply concrete defects against the issue, ADRs, repository invariants, tests, or
proof. A genuinely new requirement becomes separate Linear work. After a valid
correction, rerun affected checks, create a new commit, and repeat exact-commit
proof where required.

## Harness issues

Harness code is everything under `apps/e2e` and `bin/e2e-*`, except
`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`.

For a dedicated harness issue:

- implement with unit and feature tests;
- run `apps/e2e` `composer check` and root `bin/test`;
- prove with `bin/e2e-live <full sha>`; and
- use `Harness: bin/e2e-live <sha> passed` in the pull request.

## Rules

- Product feature branches never touch `apps/e2e` or `bin/e2e-*`.
- Proof actions are read-only unless the proof plan explicitly sets
  `"mutates": true`.
- A plan that removes a node declares the expected final node set.
- One issue per worktree and topology; never reuse proof resources across
  issues.
