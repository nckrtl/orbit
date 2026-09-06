---
name: merging-pull-requests
description: Use to close out an Orbit pull request after the external orchestrator merges its exact approved head.
---

# Closing Out Pull Requests

Close out one independently approved pull request after the external orchestrator merges it. This task does not review, approve, or merge; it starts only after exact-head gates are satisfied.

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
3. **Verify the external merge.** Require authoritative read-only GitHub state to report that the pull request merged the exact second-approved removal head into `main`; fetch `origin/main`, require the recorded merge commit to have that removal head as its exact second parent, and require its tree to equal the accepted head's tree. Do not run a merge command or mutate the pull-request surface. A missing, ambiguous, differently parented, or tree-mismatched merge is a stop.
4. **Promote the proof.** Before Incus promotion, locate the primary checkout
   through `git worktree list --porcelain` and inspect its `HEAD`, local `main`,
   and fetched `origin/main`. The promoter reads local `main`. When the
   external merge is verified and local `main` is an ancestor of `origin/main`,
   bring it forward, read all three references back, and retry promotion: with
   `main` checked out and the working tree clean, run
   `git -C <primary> merge --ff-only origin/main`; with a detached `HEAD` that
   equals `origin/main`, run `git -C <primary> branch -f main origin/main`. Do
   not reset or clean the checkout. Any other mismatch remains a stop; this
   repair never waives proof or merge lineage.
   For Incus proof, run
   `bin/e2e-topology-snapshot promote <ISSUE>`. When the retained proof plan
   declares `mutates: true`, run
   `bin/e2e-topology-snapshot refresh --main-sha=<current origin/main>` instead,
   with the primary checkout at that commit, then
   `bin/e2e-topology release <ISSUE>` and `bin/e2e-topology release <ISSUE> --proof`,
   and record the proved attempt, accepted head, merge commit, and promoted
   generation in the closeout handoff
   ([ADR 0035](../../../docs/decisions/0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot.md)).
   Do not substitute a refresh when `main` differs or another promotion
   precondition fails. Promotion requires the merge tree to equal the exact
   accepted head and records proved, accepted, and merged lineage plus the
   retained runtime fingerprint. Stop closeout until the exact candidate has a
   promotable retained proof or a mutating plan.
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

All GitHub and merge evidence is read-only. The only mutations are proof promotion and resource cleanup; none of the current-main, exact-head, second-approval, proof-equivalence, promotion-lineage, or cleanup gates may be weakened.
