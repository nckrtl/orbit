---
name: merging-pull-requests
description: Use for deterministic closeout of an approved Orbit pull request.
---

# Merging Pull Requests

Execute deterministic closeout for one independently approved pull request.
This task does not provide review or approval; it acts only after exact-head
gates are satisfied and may be followed directly by an external coordinator.

## Steps

1. **Verify the candidate.** The pull request targets `main`, CI is green for
   the current head, an independent `Approved.` review applies to that head, no
   actionable comments remain, and no later commit exists. For `Proof: incus`,
   require an active proved topology whose candidate equals `main` plus the
   current head. If `main` moved, stop until current-head proof exists.
2. **Merge.** Run `gh pr merge <n> --merge` and verify the merge commit on
   `origin/main`.
3. **Promote or refresh.** For Incus proof, run
   `bin/e2e-standby promote <ISSUE>`. If promotion is invalid because the proof
   plan mutates state or `main` differs, run `bin/e2e-standby refresh` instead.
   For a harness change, refresh the primary standby with the merge SHA.
4. **Clean repository resources.** Run
   `bin/worktree-remove <ISSUE> <slug>`, then verify topology, worktree, and local
   branch cleanup.

Run each mutation as a bounded command and fail closed. Do not report success
from exit status alone; verify GitHub, `origin/main`, standby identity, and cleanup state directly.
