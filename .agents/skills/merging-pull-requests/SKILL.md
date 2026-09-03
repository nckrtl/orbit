---
name: merging-pull-requests
description: Use for deterministic closeout of an approved Orbit pull request.
---

# Merging Pull Requests

Execute deterministic closeout for one independently approved pull request.
This task does not provide review or approval; it acts only after exact-head
gates are satisfied.

## Steps

1. **Verify the candidate.** Record the exact current head SHA. Require target
   `main`, green CI for that SHA, an independent `Approved.` review bound to that
   SHA, no actionable comments, and no later commit. With the `proof:incus` label, require
   an active immutable proved topology plus a valid current proof-input
   manifest. The exact head must either be the proved candidate (or have its
   exact tree) or carry an `exact`/`equivalent` retained-proof report.
   For every candidate, if `main` moved after approval, stop until a head that
   includes current `origin/main` carries a new approval, a green CI run, and,
   with Incus proof, a new equivalence decision or complete proof.
2. **Merge the bound head.** Run
   `gh pr merge <n> --merge --match-head-commit <sha>` and verify the merge
   commit on `origin/main`. A concurrent push must make the command fail closed.
3. **Promote the proof.** For Incus proof, run
   `bin/e2e-topology-snapshot promote <ISSUE>`. Do not substitute a refresh when
   the proof plan mutates state, `main` differs, or another promotion precondition
   fails. Promotion requires the merge tree to equal the exact accepted head
   and records proved, accepted, and merged lineage plus the retained runtime
   fingerprint. Stop closeout until the exact candidate has a promotable
   retained proof.
   Follow any extra closeout step in a harness issue's repository-owner-approved,
   issue-specific proof contract.
4. **Clean repository resources.** Run
   `bin/worktree-remove <ISSUE> <slug>`, where `<ISSUE>` is the PR body's
   `Issue:` line and `<slug>` is the branch name after `<issue-lowercase>-`,
   then verify topology, worktree, and local branch cleanup.

Run each mutation as a bounded command and fail closed. Do not report success from
exit status alone; verify GitHub, `origin/main`, topology snapshot identity, and cleanup state directly.
