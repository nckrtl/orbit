---
name: merging-pull-requests
description: Use for deterministic closeout of an approved Orbit pull request.
---

# Merging Pull Requests

Execute deterministic closeout for one independently approved pull request.
This task does not provide review or approval; it acts only after exact-head
gates are satisfied.

The execution mode is `merge-and-closeout` by default, or `closeout-only` when the caller supplies it. Both modes verify the same exact removal head and second approval. `merge-and-closeout` performs the guarded merge before promotion and cleanup. `closeout-only` is recovery for an exact head already merged manually: it makes no pull-request or GitHub mutation, records the merge command as skipped exactly once, and performs only proof promotion, resource cleanup, and direct read-back mutations after its read-only gates pass.

## Steps

1. **Verify the candidate.** Record the exact current head SHA and its single
   parent. Require that parent to be the exact independently approved head which
   still carries `.loop/`. Require the entire parent-to-head difference to be
   deletions below `.loop/`, require the head to carry no `.loop/` entry, and
   require a second independent `Approved.` review bound to the removal head.
   Also require target `main`, green CI for that SHA, no actionable comments,
   and no later commit. With the `proof:incus` label, require
   an active immutable proved topology plus a valid current proof-input
   manifest. The exact head must either be the proved candidate (or have its
   exact tree) or carry an `exact`/`equivalent` retained-proof report.
   For every candidate, if `main` moved after approval, stop until a head that
   includes current `origin/main` carries a new approval, a green CI run, and,
   with Incus proof, a new equivalence decision or complete proof.
2. **Verify the removal.** Run `git diff <approved-sha> <head> --name-status`
   and fail unless every line has status `D` and a path below `.loop/`; fail if
   the diff is empty, any path remains below `.loop/` at the head, or the approved
   workspace head is not the head's only parent. Do not accept an equivalent tree
   produced by a different commit sequence.
3. **Merge or verify a manual merge.** In `merge-and-closeout` mode, run
   `gh pr merge <n> --merge --match-head-commit <sha>` and verify the merge
   commit on `origin/main`. A concurrent push must make the command fail closed.
   In caller-supplied `closeout-only` mode, do not invoke `gh` or any merge
   command. Require authoritative read-only GitHub state to report that the pull
   request merged the exact second-approved removal head into `main`; fetch
   `origin/main`, require the recorded merge commit to have that removal head as
   its exact second parent, and require its tree to equal the accepted head's
   tree. Record the merge-command step as skipped exactly once. A missing,
   ambiguous, differently parented, or tree-mismatched merge is a stop.
4. **Promote the proof.** For Incus proof, run
   `bin/e2e-topology-snapshot promote <ISSUE>`. Do not substitute a refresh when
   the proof plan mutates state, `main` differs, or another promotion precondition
   fails. Promotion requires the merge tree to equal the exact accepted head
   and records proved, accepted, and merged lineage plus the retained runtime
   fingerprint. Stop closeout until the exact candidate has a promotable
   retained proof.
   Follow any extra closeout step in a harness issue's repository-owner-approved,
   issue-specific proof contract.
5. **Clean repository resources.** Run
   `bin/worktree-remove <ISSUE> <slug>`, where `<ISSUE>` is the PR body's
   `Issue:` line and `<slug>` is the branch name after `<issue-lowercase>-`,
   then verify topology, worktree, and local branch cleanup. Read back the
   promoted snapshot identity, absence of issue topologies, absence of the
   worktree and local branch, and absence of `.loop/` from the merged tree.
   Return any reported documentation findings for separate handling; do not
   widen closeout into a Linear mutation.

Run each mutation as a bounded command and fail closed. Do not report success from
exit status alone; verify GitHub, `origin/main`, topology snapshot identity, and cleanup state directly.

In `closeout-only`, all GitHub and merge evidence is read-only and the only mutations are promotion and cleanup. Mode selection never weakens current-main, exact-head, second-approval, proof-equivalence, promotion-lineage, or cleanup gates.
